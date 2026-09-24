<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\JournalEntry;
use App\Models\LoyaltyPointLedger;
use App\Models\PreOrder;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductionBatch;
use App\Models\ProductVariant;
use App\Models\SaleConsumptionAllocation;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionDeletionLog;
use App\Models\TransactionItem;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\ProductQuantity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    /**
     * Update data transaksi (pelanggan, metode bayar, catatan).
     */
    public function update(Request $request, Transaction $transaction)
    {
        if ($transaction->store_id !== auth()->user()->store_id) {
            abort(403);
        }

        if ($transaction->is_void) {
            return response()->json(['message' => 'Transaksi yang sudah di-void tidak dapat diedit.'], 422);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['message' => 'Hanya transaksi selesai yang dapat diedit.'], 422);
        }

        // Cek apakah shift kasir sudah ditutup
        if ($transaction->cashShift && $transaction->cashShift->closed_at) {
            return response()->json(['message' => 'Transaksi tidak dapat diedit karena shift kasir sudah ditutup.'], 422);
        }

        // Cek apakah periode akuntansi sudah ditutup
        $store = auth()->user()->store;
        $lockMessage = \App\Support\Accounting\AccountingPeriodGuard::posLockMessage($store, $transaction->completed_at ?? $transaction->created_at);
        if ($lockMessage) {
            return response()->json(['message' => $lockMessage], 422);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'required|string|in:cash,card,ewallet,transfer,other,credit',
            'notes' => 'nullable|string|max:500',
        ]);

        // Snapshot data sebelum perubahan untuk audit trail (before/after)
        $before = [
            'customer_id' => $transaction->customer_id,
            'payment_method' => $transaction->payment_method,
            'notes' => $transaction->notes,
        ];

        $oldCustomerId = $transaction->customer_id;

        DB::transaction(function () use ($transaction, $validated, $oldCustomerId) {
            // Update pelanggan — update statistik pelanggan lama dan baru
            if (array_key_exists('customer_id', $validated) && $validated['customer_id'] != $oldCustomerId) {
                // Kurangi statistik pelanggan lama
                if ($oldCustomerId) {
                    $oldCustomer = Customer::lockForUpdate()->find($oldCustomerId);
                    if ($oldCustomer) {
                        $oldCustomer->update([
                            'total_spent' => max(0, $oldCustomer->total_spent - (float) $transaction->total_amount),
                            'total_orders' => max(0, $oldCustomer->total_orders - 1),
                        ]);
                    }
                }

                // Tambah statistik pelanggan baru
                if (! empty($validated['customer_id'])) {
                    $newCustomer = Customer::lockForUpdate()->find($validated['customer_id']);
                    if ($newCustomer) {
                        $newCustomer->update([
                            'total_spent' => $newCustomer->total_spent + (float) $transaction->total_amount,
                            'total_orders' => $newCustomer->total_orders + 1,
                        ]);
                    }
                }
            }

            $transaction->update([
                'customer_id' => $validated['customer_id'] ?? $transaction->customer_id,
                'payment_method' => $validated['payment_method'],
                'notes' => $validated['notes'] ?? $transaction->notes,
            ]);
        });

        \App\Services\ActivityLogger::log(
            store: $transaction->store_id,
            action: 'transaction.updated',
            description: "Mengubah data transaksi {$transaction->transaction_number}",
            subject: $transaction,
            metadata: [
                'transaction_number' => $transaction->transaction_number,
                'before' => $before,
                'after' => $validated,
                'changed_fields' => array_keys(array_diff_assoc($validated + ['customer_id' => null], $before + ['customer_id' => null])),
            ]
        );

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'transaction' => $transaction->fresh(['customer']),
        ]);
    }

    /**
     * Void transaksi — membatalkan transaksi tanpa menghapus data.
     * Stok, diskon, loyalitas, jurnal dibalikkan, tetapi record transaksi tetap ada.
     */
    public function void(Request $request, Transaction $transaction)
    {
        if ($transaction->store_id !== auth()->user()->store_id) {
            abort(403);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['message' => 'Hanya transaksi yang sudah selesai yang dapat di-void.'], 422);
        }

        if ($transaction->status === 'void') {
            return response()->json(['message' => 'Transaksi ini sudah di-void sebelumnya.'], 422);
        }

        // Cek apakah shift kasir sudah ditutup
        if ($transaction->cashShift && $transaction->cashShift->closed_at) {
            return response()->json(['message' => 'Transaksi tidak dapat di-void karena shift kasir sudah ditutup.'], 422);
        }

        // Cek apakah periode akuntansi sudah ditutup
        $store = auth()->user()->store;
        $lockMessage = \App\Support\Accounting\AccountingPeriodGuard::posLockMessage($store, $transaction->completed_at ?? $transaction->created_at);
        if ($lockMessage) {
            return response()->json(['message' => $lockMessage], 422);
        }

        $validated = $request->validate([
            'void_reason' => 'required|string|max:500',
        ]);

        $transaction->load([
            'items.product',
            'items.productVariant',
            'items.allocations',
            'items.allocations.productionBatch',
            'items.allocations.componentProduct',
            'customer',
            'discount',
        ]);

        // Pre-load bundle items, component products & variants to avoid N+1 in loop
        $bundleComponentIds = collect();
        $bundleVariantIds = collect();
        $itemVariantIds = collect();

        foreach ($transaction->items as $item) {
            $product = $item->product;
            if ($product && $product->is_bundle) {
                $items = ProductBundleItem::where('bundle_product_id', $product->id)->get();
                $item->setRelation('bundleItems', $items);
                foreach ($items as $bi) {
                    $bundleComponentIds->push($bi->component_product_id);
                    if ($bi->component_product_variant_id) {
                        $bundleVariantIds->push($bi->component_product_variant_id);
                    }
                }
            } elseif ($product && $product->has_variants && $item->product_variant_id) {
                $itemVariantIds->push($item->product_variant_id);
            }
        }

        $componentProducts = Product::query()->whereIn('id', $bundleComponentIds->unique())->get()->keyBy('id');
        $bundleVariants = ProductVariant::query()->whereIn('id', $bundleVariantIds->unique())->get()->keyBy('id');
        $itemVariants = ProductVariant::query()->whereIn('id', $itemVariantIds->unique())->get()->keyBy('id');

        try {
            $restorationDetails = DB::transaction(function () use ($transaction, $store, $validated, $componentProducts, $bundleVariants, $itemVariants) {
                $details = [
                    'products_restored' => 0,
                    'bundles_restored' => 0,
                    'consumptions_reversed' => 0,
                    'batch_units_returned' => 0,
                    'discount_reversed' => false,
                    'loyalty_points_reversed' => 0,
                    'customer_updated' => false,
                    'journal_deleted' => false,
                    'pre_order_unlinked' => false,
                ];

                // 1. Balikkan alokasi konsumsi bahan baku
                foreach ($transaction->items as $item) {
                    foreach ($item->allocations as $allocation) {
                        if ($allocation->production_batch_id && $allocation->productionBatch) {
                            $allocation->productionBatch->increment(
                                'output_quantity_remaining',
                                $allocation->quantity_consumed
                            );
                            $details['batch_units_returned']++;

                            $batchProduct = $allocation->componentProduct;
                            if ($batchProduct) {
                                $synced = (string) ProductionBatch::where('product_id', $batchProduct->id)
                                    ->where('status', ProductionBatch::STATUS_COMPLETED)
                                    ->sum('output_quantity_remaining');
                                $batchProduct->update(['stock_quantity' => $synced]);
                            }
                        }

                        if (! $allocation->production_batch_id && $allocation->componentProduct) {
                            $comp = $allocation->componentProduct;
                            if ($comp->track_stock && ! $comp->unlimited_stock) {
                                $comp->increment('stock_quantity', $allocation->quantity_consumed);
                            }
                        }

                        if ($allocation->componentProduct) {
                            StockMovement::create([
                                'store_id' => $transaction->store_id,
                                'product_id' => $allocation->component_product_id,
                                'product_variant_id' => null,
                                'quantity_change' => $allocation->quantity_consumed,
                                'type' => StockMovement::TYPE_ADJUSTMENT,
                                'reference_type' => Transaction::class,
                                'reference_id' => $transaction->id,
                                'user_id' => auth()->id(),
                                'notes' => 'Pembalikan konsumsi void transaksi: '.$transaction->transaction_number,
                            ]);
                        }

                        $details['consumptions_reversed']++;
                    }
                }

                // 2. Kembalikan stok produk & varian
                foreach ($transaction->items as $item) {
                    $product = $item->product;
                    if (! $product || ! $product->track_stock) {
                        continue;
                    }

                    if ($product->is_bundle) {
                        foreach ($item->bundleItems as $bi) {
                            $compQty = bcmul((string) $item->quantity, (string) $bi->quantity, ProductQuantity::SCALE);
                            $compProduct = $componentProducts->get($bi->component_product_id);
                            if (! $compProduct || ! $compProduct->track_stock || $compProduct->unlimited_stock) {
                                continue;
                            }

                            if ($compProduct->has_variants && $bi->component_product_variant_id) {
                                $variant = $bundleVariants->get($bi->component_product_variant_id);
                                if ($variant) {
                                    $variant->increment('stock_quantity', $compQty);
                                    $totalStock = $compProduct->variants()->sum('stock_quantity');
                                    $compProduct->update(['stock_quantity' => $totalStock]);
                                }
                            } else {
                                $compProduct->increment('stock_quantity', $compQty);
                            }

                            StockMovement::create([
                                'store_id' => $transaction->store_id,
                                'product_id' => $bi->component_product_id,
                                'product_variant_id' => $bi->component_product_variant_id,
                                'quantity_change' => $compQty,
                                'type' => StockMovement::TYPE_ADJUSTMENT,
                                'reference_type' => Transaction::class,
                                'reference_id' => $transaction->id,
                                'user_id' => auth()->id(),
                                'notes' => 'Pembalikan bundle void transaksi: '.$transaction->transaction_number,
                            ]);

                            $details['bundles_restored']++;
                        }
                    } else {
                        if ($product->has_variants && $item->product_variant_id) {
                            $variant = $itemVariants->get($item->product_variant_id);
                            if ($variant) {
                                $variant->increment('stock_quantity', $item->quantity);
                                $totalStock = $product->variants()->sum('stock_quantity');
                                $product->update(['stock_quantity' => $totalStock]);
                            }
                        } else {
                            $product->increment('stock_quantity', $item->quantity);
                        }

                        StockMovement::create([
                            'store_id' => $transaction->store_id,
                            'product_id' => $product->id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_change' => $item->quantity,
                            'type' => StockMovement::TYPE_ADJUSTMENT,
                            'reference_type' => Transaction::class,
                            'reference_id' => $transaction->id,
                            'user_id' => auth()->id(),
                            'notes' => 'Void transaksi: '.$transaction->transaction_number,
                        ]);

                        $details['products_restored']++;
                    }
                }

                // 3. Batalkan diskon
                if ($transaction->discount_id && $transaction->discount) {
                    $discount = Discount::find($transaction->discount_id);
                    if ($discount && $discount->used_count > 0) {
                        $discount->decrement('used_count');
                        $details['discount_reversed'] = true;
                    }
                }

                // 4. Batalkan poin loyalitas
                if ($transaction->customer_id) {
                    $earnedLedger = LoyaltyPointLedger::where('customer_id', $transaction->customer_id)
                        ->where('source_type', Transaction::class)
                        ->where('source_id', $transaction->id)
                        ->where('type', 'earn')
                        ->where('points_remaining', '>', 0)
                        ->first();

                    if ($earnedLedger) {
                        $pointsToReverse = (int) $earnedLedger->points_remaining;
                        $earnedLedger->update(['points_remaining' => 0]);

                        $customer = Customer::lockForUpdate()->find($transaction->customer_id);
                        if ($customer) {
                            $newBalance = max(0, $customer->points_balance - $pointsToReverse);
                            $newEarnedTotal = max(0, $customer->points_earned_total - $pointsToReverse);

                            LoyaltyPointLedger::create([
                                'store_id' => $transaction->store_id,
                                'customer_id' => $customer->id,
                                'type' => 'adjust',
                                'points' => -$pointsToReverse,
                                'balance_after' => $newBalance,
                                'points_remaining' => 0,
                                'expires_at' => null,
                                'description' => 'Pembatalan poin void transaksi #'.$transaction->transaction_number,
                                'source_type' => Transaction::class,
                                'source_id' => $transaction->id,
                                'created_by' => auth()->id(),
                                'integrity_hash' => null,
                            ]);

                            $customer->update([
                                'points_balance' => $newBalance,
                                'points_earned_total' => $newEarnedTotal,
                            ]);

                            $details['loyalty_points_reversed'] = $pointsToReverse;
                        }
                    }
                }

                // 5. Perbarui statistik pelanggan
                if ($transaction->customer_id && $transaction->customer) {
                    $customer = Customer::lockForUpdate()->find($transaction->customer_id);
                    if ($customer) {
                        $newTotalSpent = max(0, $customer->total_spent - (float) $transaction->total_amount);
                        $newTotalOrders = max(0, $customer->total_orders - 1);
                        $customer->update([
                            'total_spent' => $newTotalSpent,
                            'total_orders' => $newTotalOrders,
                        ]);
                        $details['customer_updated'] = true;
                    }
                }

                // 6. Hapus entri jurnal
                $journalEntries = JournalEntry::query()
                    ->where('reference_type', Transaction::class)
                    ->where('reference_id', $transaction->id)
                    ->get();
                foreach ($journalEntries as $entry) {
                    AccountingPeriodGuard::assertDateOpen($store, $entry->entry_date);
                }
                $journalDeleted = JournalEntry::query()->whereKey($journalEntries->modelKeys())->delete();
                $details['journal_deleted'] = $journalDeleted > 0;

                // 7. Lepaskan pre-order
                $preOrderUpdated = PreOrder::where('fulfilled_transaction_id', $transaction->id)
                    ->update([
                        'fulfilled_transaction_id' => null,
                        'status' => 'open',
                    ]);
                $details['pre_order_unlinked'] = $preOrderUpdated > 0;

                // 8. Tandai transaksi sebagai void (TIDAK menghapus).
                // Status diubah ke 'void' agar semua query laporan yang memfilter
                // status 'completed' otomatis mengecualikan transaksi ini.
                $transaction->update([
                    'status' => 'void',
                    'is_void' => true,
                    'void_reason' => $validated['void_reason'],
                    'void_at' => now(),
                    'void_by' => auth()->id(),
                ]);

                return $details;
            });

            // Log aktivitas
            \App\Services\ActivityLogger::log(
                store: $transaction->store_id,
                action: 'transaction.voided',
                description: "Membatalkan (void) transaksi {$transaction->transaction_number}",
                subject: $transaction,
                metadata: [
                    'transaction_number' => $transaction->transaction_number,
                    'total_amount' => $transaction->total_amount,
                    'void_reason' => $validated['void_reason'],
                    'items_count' => $transaction->items->count(),
                ]
            );

            return response()->json([
                'message' => 'Transaksi berhasil dibatalkan (void).',
                'details' => $restorationDetails,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error voiding transaction: '.$e->getMessage(), [
                'transaction_id' => $transaction->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat membatalkan transaksi: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus transaksi permanen — HANYA untuk Superadmin/Owner.
     * Untuk penggunaan normal, gunakan method void().
     */
    public function destroy(Request $request, Transaction $transaction)
    {
        if ($transaction->store_id !== auth()->user()->store_id) {
            abort(403);
        }

        // Hanya owner/admin/superadmin yang boleh hapus permanen
        $user = auth()->user();
        if (! $user->isOwner() && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Hanya owner, admin, atau superadmin yang dapat menghapus transaksi permanen. Gunakan fitur Void untuk membatalkan transaksi.'], 403);
        }

        if ($transaction->status !== 'completed') {
            return response()->json(['message' => 'Hanya transaksi yang sudah selesai yang dapat dihapus.'], 422);
        }

        $store = auth()->user()->store;

        $transaction->load([
            'items.product',
            'items.productVariant',
            'items.allocations',
            'items.allocations.productionBatch',
            'items.allocations.componentProduct',
            'customer',
            'discount',
        ]);

        // Pre-load bundle items, component products & variants to avoid N+1 in loop
        $bundleComponentIds = collect();
        $bundleVariantIds = collect();
        $itemVariantIds = collect();

        foreach ($transaction->items as $item) {
            $product = $item->product;
            if ($product && $product->is_bundle) {
                $bundleItems = ProductBundleItem::where('bundle_product_id', $product->id)->get();
                $item->setRelation('bundleItems', $bundleItems);
                foreach ($bundleItems as $bi) {
                    $bundleComponentIds->push($bi->component_product_id);
                    if ($bi->component_product_variant_id) {
                        $bundleVariantIds->push($bi->component_product_variant_id);
                    }
                }
            } elseif ($product && $product->has_variants && $item->product_variant_id) {
                $itemVariantIds->push($item->product_variant_id);
            }
        }

        $componentProducts = Product::query()->whereIn('id', $bundleComponentIds->unique())->get()->keyBy('id');
        $bundleVariants = ProductVariant::query()->whereIn('id', $bundleVariantIds->unique())->get()->keyBy('id');
        $itemVariants = ProductVariant::query()->whereIn('id', $itemVariantIds->unique())->get()->keyBy('id');

        try {
            $restorationDetails = DB::transaction(function () use ($transaction, $store, $componentProducts, $bundleVariants, $itemVariants) {
                $details = [
                    'products_restored' => 0,
                    'bundles_restored' => 0,
                    'consumptions_reversed' => 0,
                    'batch_units_returned' => 0,
                    'discount_reversed' => false,
                    'loyalty_points_reversed' => 0,
                    'customer_updated' => false,
                    'journal_deleted' => false,
                    'pre_order_unlinked' => false,
                ];

                // 1. Balikkan alokasi konsumsi bahan baku (production_output + packaging)
                foreach ($transaction->items as $item) {
                    foreach ($item->allocations as $allocation) {
                        // Kembalikan ke production batch
                        if ($allocation->production_batch_id && $allocation->productionBatch) {
                            $allocation->productionBatch->increment(
                                'output_quantity_remaining',
                                $allocation->quantity_consumed
                            );
                            $details['batch_units_returned']++;

                            $batchProduct = $allocation->componentProduct;
                            if ($batchProduct) {
                                $synced = (string) ProductionBatch::where('product_id', $batchProduct->id)
                                    ->where('status', ProductionBatch::STATUS_COMPLETED)
                                    ->sum('output_quantity_remaining');
                                $batchProduct->update(['stock_quantity' => $synced]);
                            }
                        }

                        // Kembalikan stok packaging
                        if (! $allocation->production_batch_id && $allocation->componentProduct) {
                            $comp = $allocation->componentProduct;
                            if ($comp->track_stock && ! $comp->unlimited_stock) {
                                $comp->increment('stock_quantity', $allocation->quantity_consumed);
                            }
                        }

                        // Catat stock movement reversal
                        if ($allocation->componentProduct) {
                            StockMovement::create([
                                'store_id' => $transaction->store_id,
                                'product_id' => $allocation->component_product_id,
                                'product_variant_id' => null,
                                'quantity_change' => $allocation->quantity_consumed,
                                'type' => StockMovement::TYPE_ADJUSTMENT,
                                'reference_type' => Transaction::class,
                                'reference_id' => $transaction->id,
                                'user_id' => auth()->id(),
                                'notes' => 'Pembalikan konsumsi transaksi: '.$transaction->transaction_number,
                            ]);
                        }

                        $details['consumptions_reversed']++;
                    }
                }

                // 2. Kembalikan stok produk & varian
                foreach ($transaction->items as $item) {
                    $product = $item->product;
                    if (! $product || ! $product->track_stock) {
                        continue;
                    }

                    // Jika bundle, kembalikan stok setiap komponen bundle
                    if ($product->is_bundle) {
                        foreach ($item->bundleItems as $bi) {
                            $compQty = bcmul((string) $item->quantity, (string) $bi->quantity, ProductQuantity::SCALE);
                            $compProduct = $componentProducts->get($bi->component_product_id);
                            if (! $compProduct || ! $compProduct->track_stock || $compProduct->unlimited_stock) {
                                continue;
                            }

                            if ($compProduct->has_variants && $bi->component_product_variant_id) {
                                $variant = $bundleVariants->get($bi->component_product_variant_id);
                                if ($variant) {
                                    $variant->increment('stock_quantity', $compQty);
                                    $totalStock = $compProduct->variants()->sum('stock_quantity');
                                    $compProduct->update(['stock_quantity' => $totalStock]);
                                }
                            } else {
                                $compProduct->increment('stock_quantity', $compQty);
                            }

                            StockMovement::create([
                                'store_id' => $transaction->store_id,
                                'product_id' => $bi->component_product_id,
                                'product_variant_id' => $bi->component_product_variant_id,
                                'quantity_change' => $compQty,
                                'type' => StockMovement::TYPE_ADJUSTMENT,
                                'reference_type' => Transaction::class,
                                'reference_id' => $transaction->id,
                                'user_id' => auth()->id(),
                                'notes' => 'Pembalikan bundle transaksi: '.$transaction->transaction_number,
                            ]);

                            $details['bundles_restored']++;
                        }
                    } else {
                        // Produk biasa / dengan varian
                        if ($product->has_variants && $item->product_variant_id) {
                            $variant = $itemVariants->get($item->product_variant_id);
                            if ($variant) {
                                $variant->increment('stock_quantity', $item->quantity);
                                $totalStock = $product->variants()->sum('stock_quantity');
                                $product->update(['stock_quantity' => $totalStock]);
                            }
                        } else {
                            $product->increment('stock_quantity', $item->quantity);
                        }

                        StockMovement::create([
                            'store_id' => $transaction->store_id,
                            'product_id' => $product->id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_change' => $item->quantity,
                            'type' => StockMovement::TYPE_ADJUSTMENT,
                            'reference_type' => Transaction::class,
                            'reference_id' => $transaction->id,
                            'user_id' => auth()->id(),
                            'notes' => 'Hapus transaksi: '.$transaction->transaction_number,
                        ]);

                        $details['products_restored']++;
                    }
                }

                // 3. Batalkan diskon (kurangi used_count)
                if ($transaction->discount_id && $transaction->discount) {
                    $discount = Discount::find($transaction->discount_id);
                    if ($discount && $discount->used_count > 0) {
                        $discount->decrement('used_count');
                        $details['discount_reversed'] = true;
                    }
                }

                // 4. Batalkan poin loyalitas
                if ($transaction->customer_id) {
                    $earnedLedger = LoyaltyPointLedger::where('customer_id', $transaction->customer_id)
                        ->where('source_type', Transaction::class)
                        ->where('source_id', $transaction->id)
                        ->where('type', 'earn')
                        ->where('points_remaining', '>', 0)
                        ->first();

                    if ($earnedLedger) {
                        $pointsToReverse = (int) $earnedLedger->points_remaining;
                        $earnedLedger->update(['points_remaining' => 0]);

                        $customer = Customer::lockForUpdate()->find($transaction->customer_id);
                        if ($customer) {
                            $newBalance = max(0, $customer->points_balance - $pointsToReverse);
                            $newEarnedTotal = max(0, $customer->points_earned_total - $pointsToReverse);

                            LoyaltyPointLedger::create([
                                'store_id' => $transaction->store_id,
                                'customer_id' => $customer->id,
                                'type' => 'adjust',
                                'points' => -$pointsToReverse,
                                'balance_after' => $newBalance,
                                'points_remaining' => 0,
                                'expires_at' => null,
                                'description' => 'Pembatalan poin transaksi #'.$transaction->transaction_number,
                                'source_type' => Transaction::class,
                                'source_id' => $transaction->id,
                                'created_by' => auth()->id(),
                                'integrity_hash' => null,
                            ]);

                            $customer->update([
                                'points_balance' => $newBalance,
                                'points_earned_total' => $newEarnedTotal,
                            ]);

                            $details['loyalty_points_reversed'] = $pointsToReverse;
                        }
                    }
                }

                // 5. Perbarui statistik pelanggan
                if ($transaction->customer_id && $transaction->customer) {
                    $customer = Customer::lockForUpdate()->find($transaction->customer_id);
                    if ($customer) {
                        $newTotalSpent = max(0, $customer->total_spent - (float) $transaction->total_amount);
                        $newTotalOrders = max(0, $customer->total_orders - 1);
                        $customer->update([
                            'total_spent' => $newTotalSpent,
                            'total_orders' => $newTotalOrders,
                        ]);
                        $details['customer_updated'] = true;
                    }
                }

                // 6. Hapus entri jurnal
                $journalEntries = JournalEntry::query()
                    ->where('reference_type', Transaction::class)
                    ->where('reference_id', $transaction->id)
                    ->get();
                foreach ($journalEntries as $entry) {
                    AccountingPeriodGuard::assertDateOpen($store, $entry->entry_date);
                }
                $journalDeleted = JournalEntry::query()->whereKey($journalEntries->modelKeys())->delete();
                $details['journal_deleted'] = $journalDeleted > 0;

                // 7. Lepaskan pre-order yang terkait
                $preOrderUpdated = PreOrder::where('fulfilled_transaction_id', $transaction->id)
                    ->update([
                        'fulfilled_transaction_id' => null,
                        'status' => 'open',
                    ]);
                $details['pre_order_unlinked'] = $preOrderUpdated > 0;

                // 8. Hapus allocation records
                SaleConsumptionAllocation::whereHas('transactionItem', function ($q) use ($transaction) {
                    $q->where('transaction_id', $transaction->id);
                })->delete();

                // 9. Hapus transaction items
                TransactionItem::where('transaction_id', $transaction->id)->delete();

                // 10. Hapus transaksi itu sendiri
                $transaction->delete();

                return $details;
            });

            // Log penghapusan
            TransactionDeletionLog::create([
                'store_id' => $transaction->store_id,
                'transaction_id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'deleted_by' => auth()->id(),
                'total_amount' => $transaction->total_amount,
                'items_count' => $transaction->items->count(),
                'restoration_details' => $restorationDetails,
                'notes' => 'Penghapusan permanen dari halaman laporan penjualan',
            ]);

            // Log aktivitas
            \App\Services\ActivityLogger::log(
                store: $transaction->store_id,
                action: 'transaction.deleted',
                description: "Menghapus transaksi {$transaction->transaction_number}",
                subject: $transaction,
                metadata: [
                    'transaction_number' => $transaction->transaction_number,
                    'total_amount' => $transaction->total_amount,
                    'items_count' => $transaction->items->count(),
                ]
            );

            return response()->json([
                'message' => 'Transaksi berhasil dihapus permanen.',
                'details' => $restorationDetails,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error deleting transaction: '.$e->getMessage(), [
                'transaction_id' => $transaction->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menghapus transaksi: '.$e->getMessage(),
            ], 500);
        }
    }
}
