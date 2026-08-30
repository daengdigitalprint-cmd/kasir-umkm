<?php

namespace App\Services;

use App\Models\BackupHistory;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Service untuk backup lengkap data toko dalam format JSON.
 * Mencakup: transaksi, produk, pelanggan, supplier, pembelian, stok, akunting, dll.
 */
class StoreBackupService
{
    /**
     * Generate backup lengkap untuk toko dalam format JSON.
     *
     * @return array Data backup terstruktur
     */
    public function generateBackup(Store $store): array
    {
        $store->load('organization:id,name', 'subscriptionPlan:id,name,slug');

        $backup = [
            'backup_version' => '1.0.0',
            'generated_at' => now()->toIso8601String(),
            'store' => $this->exportStore($store),
            'products' => $this->exportProducts($store),
            'product_categories' => $this->exportProductCategories($store),
            'customers' => $this->exportCustomers($store),
            'suppliers' => $this->exportSuppliers($store),
            'transactions' => $this->exportTransactions($store),
            'purchases' => $this->exportPurchases($store),
            'purchase_returns' => $this->exportPurchaseReturns($store),
            'stock_movements' => $this->exportStockMovements($store),
            'stock_transfers' => $this->exportStockTransfers($store),
            'pre_orders' => $this->exportPreOrders($store),
            'discounts' => $this->exportDiscounts($store),
            'cash_shifts' => $this->exportCashShifts($store),
            'accounting' => $this->exportAccounting($store),
        ];

        return $backup;
    }

    /**
     * Generate backup, simpan ke storage, dan catat ke backup_histories.
     */
    public function saveAndRecord(
        Store $store,
        string $triggerType = 'manual',
        ?int $userId = null,
    ): BackupHistory {
        $start = microtime(true);

        $history = BackupHistory::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'filename' => '',
            'filepath' => '',
            'file_size' => 0,
            'status' => 'pending',
            'trigger_type' => $triggerType,
        ]);

        try {
            $backup = $this->generateBackup($store);

            $filename = sprintf(
                'backup-%s-%s.json',
                $store->slug,
                now()->format('Y-m-d_His')
            );
            $directory = "backups/store-{$store->id}";
            $filepath = "{$directory}/{$filename}";

            Storage::makeDirectory($directory);
            Storage::put($filepath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $fileSize = Storage::size($filepath);
            $recordsCount = $this->countRecords($backup);

            $history->update([
                'filename' => $filename,
                'filepath' => $filepath,
                'file_size' => $fileSize,
                'status' => 'completed',
                'duration_ms' => $durationMs,
                'records_count' => $recordsCount,
                'records_summary' => $this->buildSummary($backup),
            ]);

            $this->cleanOldBackups($store->id);

            return $history->refresh();
        } catch (\Throwable $e) {
            $history->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            throw $e;
        }
    }

    /**
     * Restore data dari file backup ke database.
     */
    public function restoreFromBackup(Store $store, string $filepath): array
    {
        if (! Storage::exists($filepath)) {
            throw new \RuntimeException('File backup tidak ditemukan.');
        }

        $json = Storage::get($filepath);
        $backup = json_decode($json, true);

        if (! $backup || ! isset($backup['backup_version'], $backup['store'])) {
            throw new \RuntimeException('File backup tidak valid atau rusak.');
        }

        $log = ['restored' => [], 'errors' => []];

        DB::transaction(function () use ($store, $backup, &$log) {
            // Hapus data lama toko (kecuali users & store itu sendiri)
            $this->wipeStoreData($store, $log);

            // Restore data dari backup
            $this->restoreStoreData($store, $backup, $log);
        });

        // Catat restore sebagai backup trigger
        BackupHistory::create([
            'store_id' => $store->id,
            'filename' => basename($filepath),
            'filepath' => $filepath,
            'file_size' => Storage::size($filepath),
            'status' => 'completed',
            'trigger_type' => 'restore',
            'records_summary' => ['restore_log' => $log],
        ]);

        return $log;
    }

    /**
     * Bersihkan backup lama, simpan N terakhir per toko.
     */
    public function cleanOldBackups(int $storeId, int $keep = 7): void
    {
        $directory = "backups/store-{$storeId}";

        if (! Storage::exists($directory)) {
            return;
        }

        $files = collect(Storage::files($directory))
            ->map(fn ($path) => [
                'path' => $path,
                'time' => Storage::lastModified($path),
            ])
            ->sortByDesc('time')
            ->skip($keep)
            ->pluck('path');

        foreach ($files as $path) {
            Storage::delete($path);
        }
    }

    private function countRecords(array $backup): int
    {
        $count = 0;
        foreach ($backup as $key => $value) {
            if (is_array($value) && ! isset($value['id'])) {
                $count += count($value);
            } elseif (is_array($value) && $key === 'accounting') {
                foreach ($value as $sub => $items) {
                    if (is_array($items)) {
                        $count += count($items);
                    }
                }
            }
        }

        return $count;
    }

    private function buildSummary(array $backup): array
    {
        $summary = [];
        $mapping = [
            'products' => 'Produk',
            'customers' => 'Pelanggan',
            'suppliers' => 'Supplier',
            'transactions' => 'Transaksi',
            'purchases' => 'Pembelian',
            'purchase_returns' => 'Retur',
            'stock_movements' => 'Stok',
            'stock_transfers' => 'Transfer',
            'pre_orders' => 'Pre-order',
            'discounts' => 'Diskon',
            'cash_shifts' => 'Shift',
        ];

        foreach ($mapping as $key => $label) {
            if (isset($backup[$key]) && is_array($backup[$key])) {
                $summary[$label] = count($backup[$key]);
            }
        }

        if (isset($backup['accounting'])) {
            $coa = count($backup['accounting']['chart_of_accounts'] ?? []);
            $journals = count($backup['accounting']['journal_entries'] ?? []);
            $summary['Akun'] = $coa;
            $summary['Jurnal'] = $journals;
        }

        return $summary;
    }

    private function wipeStoreData(Store $store, array &$log): void
    {
        $models = [
            \App\Models\TransactionItem::class => 'transaction_items',
            \App\Models\Transaction::class => 'transactions',
            \App\Models\PurchaseReturnLine::class => 'purchase_return_lines',
            \App\Models\PurchaseReturn::class => 'purchase_returns',
            \App\Models\PurchaseLine::class => 'purchase_lines',
            \App\Models\Purchase::class => 'purchases',
            \App\Models\StockMovement::class => 'stock_movements',
            \App\Models\StockTransferLine::class => 'stock_transfer_lines',
            \App\Models\StockTransfer::class => 'stock_transfers',
            \App\Models\PreOrderLine::class => 'pre_order_lines',
            \App\Models\PreOrder::class => 'pre_orders',
            \App\Models\Discount::class => 'discounts',
            \App\Models\CashShift::class => 'cash_shifts',
            \App\Models\JournalLine::class => 'journal_lines',
            \App\Models\JournalEntry::class => 'journal_entries',
            \App\Models\ChartOfAccount::class => 'chart_of_accounts',
            \App\Models\ProductBatch::class => 'product_batches',
            \App\Models\ProductBundleItem::class => 'product_bundle_items',
            \App\Models\ProductVariant::class => 'product_variants',
            \App\Models\Product::class => 'products',
            \App\Models\ProductCategory::class => 'product_categories',
            \App\Models\Customer::class => 'customers',
            \App\Models\Supplier::class => 'suppliers',
        ];

        foreach ($models as $modelClass => $table) {
            try {
                $modelClass::where('store_id', $store->id)->delete();
                $log['restored'][] = "Dihapus: {$table}";
            } catch (\Throwable $e) {
                $log['errors'][] = "Gagal hapus {$table}: {$e->getMessage()}";
            }
        }
    }

    private function restoreStoreData(Store $store, array $backup, array &$log): void
    {
        // Restore products
        foreach ($backup['products'] ?? [] as $p) {
            $product = $store->products()->create([
                'name' => $p['name'],
                'sku' => $p['sku'],
                'barcode' => $p['barcode'] ?? null,
                'description' => $p['description'] ?? null,
                'category' => $p['category'] ?? null,
                'price' => $p['price'],
                'cost_price' => $p['cost_price'] ?? 0,
                'stock' => $p['stock'] ?? 0,
                'track_stock' => $p['track_stock'] ?? true,
                'allows_decimal' => $p['allows_decimal'] ?? false,
                'is_active' => $p['is_active'] ?? true,
                'is_bundle' => $p['is_bundle'] ?? false,
                'has_variants' => $p['has_variants'] ?? false,
                'image_url' => $p['image_url'] ?? null,
                'unit' => $p['unit'] ?? null,
                'pharmacy_sale_class' => $p['pharmacy_sale_class'] ?? null,
                'pharmacy_permit_number' => $p['pharmacy_permit_number'] ?? null,
            ]);

            foreach ($p['variants'] ?? [] as $v) {
                $product->variants()->create([
                    'name' => $v['name'],
                    'sku' => $v['sku'],
                    'barcode' => $v['barcode'] ?? null,
                    'price' => $v['price'],
                    'cost_price' => $v['cost_price'] ?? 0,
                    'stock' => $v['stock'] ?? 0,
                    'attributes' => $v['attributes'] ?? null,
                ]);
            }

            foreach ($p['batches'] ?? [] as $b) {
                $product->batches()->create([
                    'batch_number' => $b['batch_number'],
                    'product_variant_id' => $b['product_variant_id'] ?? null,
                    'quantity' => $b['quantity'],
                    'cost_price' => $b['cost_price'] ?? 0,
                    'expiry_date' => $b['expiry_date'] ?? null,
                    'received_at' => $b['received_at'] ?? null,
                ]);
            }
        }
        $log['restored'][] = 'Produk dipulihkan';

        // Restore customers
        foreach ($backup['customers'] ?? [] as $c) {
            $store->customers()->create([
                'name' => $c['name'],
                'email' => $c['email'] ?? null,
                'phone' => $c['phone'] ?? null,
                'address' => $c['address'] ?? null,
            ]);
        }
        $log['restored'][] = 'Pelanggan dipulihkan';

        // Restore suppliers
        foreach ($backup['suppliers'] ?? [] as $s) {
            $store->suppliers()->create([
                'name' => $s['name'],
                'email' => $s['email'] ?? null,
                'phone' => $s['phone'] ?? null,
                'address' => $s['address'] ?? null,
                'notes' => $s['notes'] ?? null,
            ]);
        }
        $log['restored'][] = 'Supplier dipulihkan';

        // Restore discounts
        foreach ($backup['discounts'] ?? [] as $d) {
            $store->discounts()->create([
                'name' => $d['name'],
                'code' => $d['code'] ?? null,
                'type' => $d['type'],
                'value' => $d['value'],
                'minimum_purchase' => $d['minimum_purchase'] ?? 0,
                'max_uses' => $d['max_uses'] ?? null,
                'used_count' => $d['used_count'] ?? 0,
                'start_date' => $d['start_date'] ?? null,
                'end_date' => $d['end_date'] ?? null,
                'is_active' => $d['is_active'] ?? true,
            ]);
        }
        $log['restored'][] = 'Diskon dipulihkan';
    }

    private function exportStore(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'business_type' => $store->business_type,
            'description' => $store->description,
            'address' => $store->address,
            'phone' => $store->phone,
            'email' => $store->email,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'settings' => $store->settings,
            'tax_enabled' => $store->tax_enabled,
            'tax_rate' => $store->tax_rate,
            'tax_name' => $store->tax_name,
            'organization' => $store->organization ? [
                'id' => $store->organization->id,
                'name' => $store->organization->name,
            ] : null,
            'subscription_plan' => $store->subscriptionPlan ? [
                'name' => $store->subscriptionPlan->name,
                'slug' => $store->subscriptionPlan->slug,
            ] : null,
            'subscription_end' => $store->subscription_end?->toIso8601String(),
            'created_at' => $store->created_at->toIso8601String(),
            'updated_at' => $store->updated_at->toIso8601String(),
        ];
    }

    private function exportProducts(Store $store): array
    {
        return $store->products()
            ->with(['variants', 'bundleItems.componentProduct', 'batches'])
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'description' => $p->description,
                'category' => $p->category,
                'price' => $p->price,
                'cost_price' => $p->cost_price,
                'stock' => $p->stock,
                'track_stock' => $p->track_stock,
                'allows_decimal' => $p->allows_decimal,
                'is_active' => $p->is_active,
                'is_bundle' => $p->is_bundle,
                'has_variants' => $p->has_variants,
                'image_url' => $p->image_url,
                'unit' => $p->unit,
                'pharmacy_sale_class' => $p->pharmacy_sale_class,
                'pharmacy_permit_number' => $p->pharmacy_permit_number,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'price' => $v->price,
                    'cost_price' => $v->cost_price,
                    'stock' => $v->stock,
                    'attributes' => $v->attributes,
                ])->toArray(),
                'bundle_items' => $p->bundleItems->map(fn ($bi) => [
                    'component_product_id' => $bi->component_product_id,
                    'component_product_name' => $bi->componentProduct?->name,
                    'component_variant_id' => $bi->component_variant_id,
                    'quantity' => $bi->quantity,
                    'sort_order' => $bi->sort_order,
                ])->toArray(),
                'batches' => $p->batches->map(fn ($b) => [
                    'id' => $b->id,
                    'batch_number' => $b->batch_number,
                    'product_variant_id' => $b->product_variant_id,
                    'quantity' => $b->quantity,
                    'cost_price' => $b->cost_price,
                    'expiry_date' => $b->expiry_date?->toDateString(),
                    'received_at' => $b->received_at?->toIso8601String(),
                ])->toArray(),
                'created_at' => $p->created_at->toIso8601String(),
                'updated_at' => $p->updated_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportProductCategories(Store $store): array
    {
        return $store->productCategories()
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportCustomers(Store $store): array
    {
        return $store->customers()
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'address' => $c->address,
                'created_at' => $c->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportSuppliers(Store $store): array
    {
        return $store->suppliers()
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'address' => $s->address,
                'notes' => $s->notes,
                'created_at' => $s->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportTransactions(Store $store): array
    {
        return $store->transactions()
            ->with(['items.product', 'items.productVariant', 'customer', 'discount'])
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'customer_name' => $t->customer?->name ?? $t->customer_name,
                'status' => $t->status,
                'payment_method' => $t->payment_method,
                'order_type' => $t->order_type,
                'table_reference' => $t->table_reference,
                'subtotal' => $t->subtotal,
                'tax_amount' => $t->tax_amount,
                'discount_amount' => $t->discount_amount,
                'service_charge_amount' => $t->service_charge_amount,
                'service_charge_percent' => $t->service_charge_percent,
                'tip_amount' => $t->tip_amount,
                'total_amount' => $t->total_amount,
                'amount_paid' => $t->amount_paid,
                'change_amount' => $t->change_amount,
                'payment_details' => $t->payment_details,
                'discount_code' => $t->discount?->code,
                'offline_sale_id' => $t->offline_sale_id,
                'items' => $t->items->map(fn ($i) => [
                    'product_name' => $i->product_name,
                    'product_sku' => $i->product_sku,
                    'variant_label' => $i->variant_label,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'total_price' => $i->total_price,
                    'modifiers' => $i->modifiers,
                ])->toArray(),
                'completed_at' => $t->completed_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPurchases(Store $store): array
    {
        return $store->purchases()
            ->with(['supplier', 'lines.product', 'lines.productVariant'])
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'purchase_number' => $p->purchase_number,
                'supplier_name' => $p->supplier?->name,
                'purchase_date' => $p->purchase_date?->toDateString(),
                'notes' => $p->notes,
                'subtotal' => $p->subtotal,
                'tax_amount' => $p->tax_amount,
                'total_amount' => $p->total_amount,
                'status' => $p->status,
                'lines' => $p->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'product_variant_name' => $l->productVariant?->name,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                    'total_price' => $l->total_price,
                    'batch_number' => $l->batch_number,
                    'expiry_date' => $l->expiry_date?->toDateString(),
                ])->toArray(),
                'created_at' => $p->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPurchaseReturns(Store $store): array
    {
        return $store->purchaseReturns()
            ->with(['supplier', 'lines.product'])
            ->orderBy('id')
            ->get()
            ->map(fn ($pr) => [
                'id' => $pr->id,
                'return_number' => $pr->return_number,
                'supplier_name' => $pr->supplier?->name,
                'return_date' => $pr->return_date?->toDateString(),
                'reason' => $pr->reason,
                'total_amount' => $pr->total_amount,
                'status' => $pr->status,
                'lines' => $pr->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                    'total_price' => $l->total_price,
                ])->toArray(),
                'created_at' => $pr->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportStockMovements(Store $store): array
    {
        return $store->stockMovements()
            ->with(['product', 'productVariant'])
            ->orderBy('id')
            ->get()
            ->map(fn ($sm) => [
                'id' => $sm->id,
                'product_name' => $sm->product?->name,
                'variant_name' => $sm->productVariant?->name,
                'type' => $sm->type,
                'quantity' => $sm->quantity,
                'notes' => $sm->notes,
                'reference_type' => $sm->reference_type,
                'reference_id' => $sm->reference_id,
                'created_at' => $sm->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportStockTransfers(Store $store): array
    {
        return StockTransfer::query()
            ->where('from_store_id', $store->id)
            ->orWhere('to_store_id', $store->id)
            ->with(['fromStore:id,name', 'toStore:id,name', 'lines.product'])
            ->orderBy('id')
            ->get()
            ->map(fn ($st) => [
                'id' => $st->id,
                'from_store_name' => $st->fromStore?->name,
                'to_store_name' => $st->toStore?->name,
                'notes' => $st->notes,
                'status' => $st->status,
                'lines' => $st->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'quantity' => $l->quantity,
                ])->toArray(),
                'sent_at' => $st->sent_at?->toIso8601String(),
                'received_at' => $st->received_at?->toIso8601String(),
                'created_at' => $st->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportPreOrders(Store $store): array
    {
        return $store->preOrders()
            ->with(['customer', 'lines.product'])
            ->orderBy('id')
            ->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'pre_order_number' => $po->pre_order_number,
                'customer_name' => $po->customer?->name ?? $po->customer_name,
                'deposit_received' => $po->deposit_received,
                'estimated_total' => $po->estimated_total,
                'expected_pickup_at' => $po->expected_pickup_at?->toIso8601String(),
                'notes' => $po->notes,
                'status' => $po->status,
                'lines' => $po->lines->map(fn ($l) => [
                    'product_name' => $l->product?->name,
                    'quantity' => $l->quantity,
                    'price' => $l->price,
                ])->toArray(),
                'created_at' => $po->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportDiscounts(Store $store): array
    {
        return $store->discounts()
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'type' => $d->type,
                'value' => $d->value,
                'minimum_purchase' => $d->minimum_purchase,
                'max_uses' => $d->max_uses,
                'used_count' => $d->used_count,
                'start_date' => $d->start_date?->toDateString(),
                'end_date' => $d->end_date?->toDateString(),
                'is_active' => $d->is_active,
                'created_at' => $d->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportCashShifts(Store $store): array
    {
        return $store->cashShifts()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($cs) => [
                'id' => $cs->id,
                'user_name' => $cs->user?->name,
                'opening_cash' => $cs->opening_cash,
                'closing_cash_expected' => $cs->closing_cash_expected,
                'closing_cash_counted' => $cs->closing_cash_counted,
                'difference_amount' => $cs->difference_amount,
                'closing_notes' => $cs->closing_notes,
                'opened_at' => $cs->opened_at?->toIso8601String(),
                'closed_at' => $cs->closed_at?->toIso8601String(),
                'created_at' => $cs->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function exportAccounting(Store $store): array
    {
        return [
            'chart_of_accounts' => $store->chartOfAccounts()
                ->orderBy('code')
                ->get()
                ->map(fn ($coa) => [
                    'id' => $coa->id,
                    'code' => $coa->code,
                    'name' => $coa->name,
                    'type' => $coa->type,
                    'is_system' => $coa->is_system,
                    'created_at' => $coa->created_at->toIso8601String(),
                ])
                ->toArray(),
            'journal_entries' => $store->journalEntries()
                ->with('lines.chartOfAccount')
                ->orderBy('id')
                ->get()
                ->map(fn ($je) => [
                    'id' => $je->id,
                    'date' => $je->date?->toDateString(),
                    'reference_type' => $je->reference_type,
                    'reference_id' => $je->reference_id,
                    'memo' => $je->memo,
                    'lines' => $je->lines->map(fn ($l) => [
                        'account_code' => $l->chartOfAccount?->code,
                        'account_name' => $l->chartOfAccount?->name,
                        'debit' => $l->debit,
                        'credit' => $l->credit,
                        'memo' => $l->memo,
                    ])->toArray(),
                    'created_at' => $je->created_at->toIso8601String(),
                ])
                ->toArray(),
        ];
    }
}
