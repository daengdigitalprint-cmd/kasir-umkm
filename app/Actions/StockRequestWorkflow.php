<?php

namespace App\Actions;

use App\Events\StockRequestUpdated;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StoreLocationType;
use App\Support\TransferStockOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F6 — Permintaan Stok antar lokasi.
 *
 * requested → (pemasok setujui, jumlah boleh direvisi) approved + Transfer Stok terkirim
 *           → completed saat peminta menerima transfer.
 * requested → rejected (pemasok) / cancelled (peminta). Tolak & batal tidak mengubah stok.
 */
final class StockRequestWorkflow
{
    /**
     * @param  list<array{product_id: int|string, quantity: int|float|string}>  $items
     */
    public function create(User $user, Store $requester, Store $supplier, array $items, ?string $notes = null): StockRequest
    {
        if ((int) $requester->id === (int) $supplier->id) {
            throw ValidationException::withMessages(['to_store_id' => 'Pilih lokasi pemasok yang berbeda.']);
        }
        if (! $supplier->is_active || ! StoreLocationType::sharesOrganization($requester, $supplier)) {
            throw ValidationException::withMessages(['to_store_id' => 'Lokasi pemasok harus lokasi aktif dalam grup usaha yang sama.']);
        }

        $ids = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id);
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Tambahkan minimal satu barang.']);
        }
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['items' => 'Barang yang sama tidak boleh dua kali. Gabung jumlahnya dalam satu baris.']);
        }

        $products = Product::query()->where('store_id', $requester->id)->whereIn('id', $ids)->get()->keyBy('id');

        $resolved = [];
        foreach (array_values($items) as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            if (! $product || ! self::isTransferable($product)) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'Barang tidak valid untuk permintaan stok.']);
            }

            $quantity = ProductQuantity::normalizeStorageAmount($product, $item['quantity']);
            if (ProductQuantity::cmp($quantity, '0') <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "Jumlah «{$product->name}» harus lebih dari 0."]);
            }

            $match = $this->matchingProduct($supplier, $product);
            if (! $match) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => "«{$product->name}» tidak ada di {$supplier->name} (SKU/barcode harus sama dan satuan dasar sama).",
                ]);
            }

            $resolved[] = compact('product', 'quantity', 'match');
        }

        $request = DB::transaction(function () use ($user, $requester, $supplier, $resolved, $notes) {
            $request = StockRequest::create([
                'organization_id' => $requester->organization_id,
                'from_store_id' => $requester->id,
                'to_store_id' => $supplier->id,
                'status' => StockRequest::STATUS_REQUESTED,
                'notes' => $notes,
                'requested_by' => $user->id,
            ]);

            foreach ($resolved as $row) {
                $request->lines()->create([
                    'requester_product_id' => $row['product']->id,
                    'supplier_product_id' => $row['match']->id,
                    'quantity_requested' => $row['quantity'],
                    'stock_at_request' => (string) $row['product']->stock_quantity,
                    'min_stock_at_request' => (string) $row['product']->min_stock_level,
                    'base_unit' => $row['product']->baseUnit(),
                ]);
            }

            return $request;
        });

        StockRequestUpdated::dispatchFor($request, 'created');

        return $request;
    }

    /**
     * Setujui (jumlah per baris boleh direvisi; 0 = tidak dikirim), lalu terbitkan
     * Transfer Stok dari pemasok ke peminta yang langsung berstatus Dikirim.
     *
     * @param  array<int, int|float|string>  $approved  line_id => jumlah disetujui
     */
    public function approve(StockRequest $request, User $user, array $approved): StockRequest
    {
        $result = DB::transaction(function () use ($request, $user, $approved) {
            $locked = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isRequested()) {
                throw ValidationException::withMessages(['request' => 'Permintaan ini sudah diproses.']);
            }

            $supplier = $locked->toStore;
            $requester = $locked->fromStore;
            $lines = $locked->lines()->with(['requesterProduct', 'supplierProduct'])->get();

            $unknown = collect(array_keys($approved))->map(fn ($id) => (int) $id)->diff($lines->pluck('id'));
            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Ada baris yang bukan bagian dari permintaan ini.']);
            }

            // Kunci produk pemasok berurutan sebelum cek stok & potong stok.
            $supplierIds = $lines->pluck('supplier_product_id')->filter()->unique()->sort()->values();
            $supplierProducts = Product::query()->whereIn('id', $supplierIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $toSend = [];
            foreach ($lines as $line) {
                $requesterProduct = $line->requesterProduct;
                $raw = $approved[$line->id] ?? $line->quantity_requested;
                if (! is_numeric($raw) || (float) $raw < 0) {
                    throw ValidationException::withMessages(['lines' => "Jumlah disetujui «{$requesterProduct?->name}» tidak valid."]);
                }

                $source = $supplierProducts->get($line->supplier_product_id);
                $quantity = $source
                    ? ProductQuantity::normalizeStorageAmount($source, $raw)
                    : ProductQuantity::normalizeStorageAmount($requesterProduct, $raw);
                $line->update(['quantity_approved' => $quantity]);

                if (ProductQuantity::cmp($quantity, '0') <= 0) {
                    continue;
                }

                if (! $source || (int) $source->store_id !== (int) $supplier->id || ! self::isTransferable($source)) {
                    throw ValidationException::withMessages(['lines' => "Barang pemasok untuk «{$requesterProduct?->name}» tidak lagi tersedia."]);
                }
                if (! $requesterProduct || ! self::isTransferable($requesterProduct)
                    || $requesterProduct->baseUnit() !== $source->baseUnit()) {
                    throw ValidationException::withMessages(['lines' => "«{$source->name}» di lokasi peminta tidak cocok lagi (nonaktif atau satuan berubah)."]);
                }
                if (! $requesterProduct->allows_decimal && ProductQuantity::cmp($quantity, (string) (int) floor((float) $quantity)) !== 0) {
                    throw ValidationException::withMessages(['lines' => "«{$source->name}» di lokasi peminta tidak mendukung jumlah desimal."]);
                }
                if (ProductQuantity::lt((string) $source->stock_quantity, $quantity)) {
                    throw ValidationException::withMessages([
                        'lines' => "Stok «{$source->name}» tidak cukup (tersisa {$source->stock_quantity} {$source->baseUnit()}).",
                    ]);
                }

                $toSend[] = ['source' => $source, 'destination' => $requesterProduct, 'quantity' => $quantity];
            }

            if ($toSend === []) {
                throw ValidationException::withMessages(['lines' => 'Setujui minimal satu barang, atau tolak permintaan ini.']);
            }

            $transfer = StockTransfer::create([
                'from_store_id' => $supplier->id,
                'to_store_id' => $requester->id,
                'user_id' => $user->id,
                'notes' => trim("Dari permintaan stok {$locked->request_number}. ".($locked->notes ?? '')),
                'status' => StockTransfer::STATUS_DRAFT,
            ]);

            foreach ($toSend as $item) {
                $unitCost = InventoryCosting::currentAverageCost($item['source']);
                $transferLine = StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'from_product_id' => $item['source']->id,
                    'to_product_id' => $item['destination']->id,
                    'quantity' => $item['quantity'],
                    'base_unit' => $item['source']->baseUnit(),
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => InventoryCosting::extendedCost($item['quantity'], $unitCost, 2),
                ]);
                $transferLine->setRelation('transfer', $transfer);
                TransferStockOperations::deductAtSource($supplier, $user, $item['source'], $item['quantity'], $transferLine);
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_SENT,
                'sent_at' => now(),
                'sent_by' => $user->id,
            ]);

            $locked->update([
                'status' => StockRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'stock_transfer_id' => $transfer->id,
            ]);

            return $locked;
        });

        StockRequestUpdated::dispatchFor($result, 'approved');

        return $result;
    }

    public function reject(StockRequest $request, User $user, string $reason): StockRequest
    {
        $result = DB::transaction(function () use ($request, $user, $reason) {
            $locked = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isRequested()) {
                throw ValidationException::withMessages(['request' => 'Permintaan ini sudah diproses.']);
            }

            $locked->update([
                'status' => StockRequest::STATUS_REJECTED,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejected_reason' => mb_substr(trim($reason), 0, 500),
            ]);

            return $locked;
        });

        StockRequestUpdated::dispatchFor($result, 'rejected');

        return $result;
    }

    public function cancel(StockRequest $request): StockRequest
    {
        $result = DB::transaction(function () use ($request) {
            $locked = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isRequested()) {
                throw ValidationException::withMessages(['request' => 'Permintaan yang sudah diproses tidak bisa dibatalkan.']);
            }

            $locked->update(['status' => StockRequest::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $locked;
        });

        StockRequestUpdated::dispatchFor($result, 'cancelled');

        return $result;
    }

    /**
     * Ikuti status Transfer Stok hasil permintaan: diterima → Selesai,
     * dibatalkan → permintaan ikut Dibatalkan. Panggil di dalam transaksi transfer.
     */
    public static function syncFromTransfer(StockTransfer $transfer): void
    {
        $request = StockRequest::query()
            ->where('stock_transfer_id', $transfer->id)
            ->where('status', StockRequest::STATUS_APPROVED)
            ->first();
        if (! $request) {
            return;
        }

        if ($transfer->status === StockTransfer::STATUS_RECEIVED) {
            $request->update(['status' => StockRequest::STATUS_COMPLETED, 'completed_at' => now()]);
            StockRequestUpdated::dispatchFor($request, 'completed');
        } elseif ($transfer->status === StockTransfer::STATUS_CANCELLED) {
            $request->update(['status' => StockRequest::STATUS_CANCELLED, 'cancelled_at' => now()]);
            StockRequestUpdated::dispatchFor($request, 'cancelled');
        }
    }

    /** Produk padanan di lokasi lain: SKU (tanpa beda huruf) lalu barcode, satuan dasar harus sama. */
    public function matchingProduct(Store $store, Product $product): ?Product
    {
        $query = Product::query()
            ->where('store_id', $store->id)
            ->where('track_stock', true)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->where('is_active', true);

        $match = null;
        $sku = trim((string) $product->sku);
        if ($sku !== '') {
            $match = (clone $query)->whereRaw('LOWER(TRIM(sku)) = ?', [mb_strtolower($sku)])->first();
        }
        $barcode = trim((string) $product->barcode);
        if (! $match && $barcode !== '') {
            $match = (clone $query)->where('barcode', $barcode)->first();
        }

        return $match && $match->baseUnit() === $product->baseUnit() ? $match : null;
    }

    public static function isTransferable(Product $product): bool
    {
        return $product->is_active && $product->track_stock && ! $product->has_variants && ! $product->is_bundle;
    }

    /** Hanya baris dengan jumlah disetujui > 0 yang ikut dikirim (untuk tampilan). */
    public static function lineSent(StockRequestLine $line): bool
    {
        return $line->quantity_approved !== null && ProductQuantity::cmp((string) $line->quantity_approved, '0') > 0;
    }
}
