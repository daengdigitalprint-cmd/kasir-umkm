<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\User;

final class TransferStockOperations
{
    public static function ensureBatchAligned(Product $product): void
    {
        $batchSum = (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
        $stockStr = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);
        if (ProductQuantity::cmp($batchSum, '0.0000') === 0 && ProductQuantity::cmp($stockStr, '0.0000') > 0) {
            if (PharmacyFeatures::enabled($product->store)) {
                throw new \RuntimeException("Stok batch «{$product->name}» apotek belum memiliki batch dan ED.");
            }
            ProductBatch::create(['store_id' => $product->store_id, 'product_id' => $product->id, 'quantity_remaining' => $stockStr, 'expiry_date' => null, 'batch_code' => null]);

            return;
        }
        if (ProductQuantity::cmp($batchSum, $stockStr) !== 0) {
            throw new \RuntimeException("Stok batch tidak sinkron untuk «{$product->name}» (total batch {$batchSum} vs stok {$stockStr}). Rapatkan stok batch di produk asal dulu.");
        }
    }

    public static function deductAtSource(Store $store, User $user, Product $product, string $qty, StockTransferLine $line): void
    {
        if (! $product->track_stock) {
            throw new \InvalidArgumentException('Produk asal tidak men-track stok.');
        }
        if ($product->has_variants || $product->is_bundle) {
            throw new \InvalidArgumentException('Transfer stok belum mendukung varian atau paket.');
        }
        if ((int) $product->store_id !== (int) $store->id) {
            throw new \InvalidArgumentException('Produk asal tidak termasuk toko pengirim.');
        }

        if (BatchTracking::enabled($store)) {
            self::ensureBatchAligned($product);
            $remaining = $qty;
            $batches = ProductBatch::query()->where('product_id', $product->id)->where('quantity_remaining', '>', 0)
                ->where('status', ProductBatch::STATUS_AVAILABLE)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($batches as $batch) {
                if (ProductQuantity::cmp($remaining, '0.0000') <= 0) {
                    break;
                }
                if (PharmacyFeatures::enabled($store) && $batch->expiry_date === null) {
                    throw new \RuntimeException("Batch «{$product->name}» apotek tanpa ED tidak dapat ditransfer.");
                }
                $take = ProductQuantity::min($remaining, ProductQuantity::normalizeStorageAmount($product, $batch->quantity_remaining));
                if (ProductQuantity::cmp($take, '0.0000') <= 0) {
                    continue;
                }
                $batch->decrement('quantity_remaining', $take);
                if (PharmacyFeatures::enabled($store)) {
                    $line->batchAllocations()->create(['batch_code' => $batch->batch_code, 'expiry_date' => $batch->expiry_date, 'quantity' => $take]);
                }
                $remaining = ProductQuantity::sub($remaining, $take);
            }
            if (ProductQuantity::cmp($remaining, '0.0000') > 0) {
                throw new \RuntimeException("Stok batch «{$product->name}» tidak mencukupi untuk transfer.");
            }
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining'))]);
        } else {
            if (ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                throw new \RuntimeException("Stok «{$product->name}» tidak mencukupi.");
            }
            $product->decrement('stock_quantity', $qty);
        }

        StockMovement::create(['store_id' => $store->id, 'product_id' => $product->id, 'product_variant_id' => null, 'user_id' => $user->id, 'quantity_change' => bcsub('0.0000', $qty, ProductQuantity::SCALE), 'type' => StockMovement::TYPE_TRANSFER_OUT, 'reference_type' => StockTransferLine::class, 'reference_id' => $line->id, 'balance_after' => (string) $product->fresh()->stock_quantity, 'notes' => $line->transfer->transfer_number]);
    }

    public static function restoreAtSource(Store $store, User $user, Product $product, string $qty, StockTransferLine $line): void
    {
        if ((int) $product->store_id !== (int) $store->id) {
            throw new \InvalidArgumentException('Produk asal tidak termasuk toko pengirim.');
        }
        if (BatchTracking::enabled($store)) {
            self::ensureBatchAligned($product);
            $allocations = PharmacyFeatures::enabled($store) ? $line->batchAllocations : collect();
            if ($allocations->isEmpty()) {
                ProductBatch::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity_remaining' => $qty, 'expiry_date' => null, 'batch_code' => substr('BATAL '.$line->transfer->transfer_number, 0, 120)]);
            } else {
                foreach ($allocations as $allocation) {
                    ProductBatch::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity_remaining' => $allocation->quantity, 'expiry_date' => $allocation->expiry_date, 'batch_code' => $allocation->batch_code]);
                }
            }
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining'))]);
        } else {
            $product->increment('stock_quantity', $qty);
        }

        StockMovement::create(['store_id' => $store->id, 'product_id' => $product->id, 'product_variant_id' => null, 'user_id' => $user->id, 'quantity_change' => $qty, 'type' => StockMovement::TYPE_TRANSFER_REVERSAL, 'reference_type' => StockTransferLine::class, 'reference_id' => $line->id, 'balance_after' => (string) $product->fresh()->stock_quantity, 'notes' => $line->transfer->transfer_number]);
    }

    public static function addAtDestination(Store $store, User $user, Product $product, string $qty, StockTransferLine $line): void
    {
        if (! $product->track_stock) {
            throw new \InvalidArgumentException('Produk tujuan tidak men-track stok.');
        }
        if ($product->has_variants || $product->is_bundle) {
            throw new \InvalidArgumentException('Transfer stok belum mendukung varian atau paket.');
        }
        if ((int) $product->store_id !== (int) $store->id) {
            throw new \InvalidArgumentException('Produk tujuan tidak termasuk toko penerima.');
        }

        if (BatchTracking::enabled($store)) {
            $allocations = PharmacyFeatures::enabled($store) ? $line->batchAllocations : collect();
            if (PharmacyFeatures::enabled($store) && ($allocations->isEmpty() || $allocations->contains(fn ($allocation) => $allocation->expiry_date === null))) {
                throw new \RuntimeException("Alokasi batch transfer «{$product->name}» apotek wajib ada dan memiliki ED.");
            }
            if ($allocations->isEmpty()) {
                ProductBatch::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity_remaining' => $qty, 'expiry_date' => null, 'batch_code' => substr('TRF '.$line->transfer->transfer_number, 0, 120)]);
            } else {
                foreach ($allocations as $allocation) {
                    ProductBatch::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity_remaining' => $allocation->quantity, 'expiry_date' => $allocation->expiry_date, 'batch_code' => $allocation->batch_code]);
                }
            }
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining'))]);
        } else {
            $product->increment('stock_quantity', $qty);
        }

        StockMovement::create(['store_id' => $store->id, 'product_id' => $product->id, 'product_variant_id' => null, 'user_id' => $user->id, 'quantity_change' => $qty, 'type' => StockMovement::TYPE_TRANSFER_IN, 'reference_type' => StockTransferLine::class, 'reference_id' => $line->id, 'balance_after' => (string) $product->fresh()->stock_quantity, 'notes' => $line->transfer->transfer_number]);
    }
}
