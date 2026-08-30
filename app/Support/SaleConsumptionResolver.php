<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductSaleConsumption;
use App\Models\ProductVariant;
use App\Models\SaleConsumptionAllocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Konsumsi komponen penjualan berbasis konfigurasi ProductSaleConsumption.
 *
 * Untuk komponen production_output: FIFO dari ProductionBatch.
 * Untuk komponen packaging: pengurangan stok reguler.
 * Seluruh operasi harus dalam satu DB transaction.
 */
final class SaleConsumptionResolver
{
    /**
     * Muat baris konsumsi untuk produk/varian di toko tertentu.
     */
    public static function loadRows(Store $store, Product $product, ?ProductVariant $variant): Collection
    {
        return ProductSaleConsumption::where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->when(
                $variant,
                fn ($q) => $q->where('product_variant_id', $variant->id),
                fn ($q) => $q->whereNull('product_variant_id'),
            )
            ->with('component')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Cek apakah produk menggunakan konfigurasi konsumsi.
     */
    public static function hasConfig(Store $store, Product $product, ?ProductVariant $variant): bool
    {
        return self::loadRows($store, $product, $variant)->isNotEmpty();
    }

    /**
     * Validasi ketersediaan stok semua komponen konsumsi.
     *
     * @throws \RuntimeException
     */
    public static function validateStock(
        Store $store,
        Product $product,
        ?ProductVariant $variant,
        string $saleQuantity,
    ): void {
        $rows = self::loadRows($store, $product, $variant);
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $component = $row->component;
            if (! $component) {
                throw new \RuntimeException(
                    "Komponen konsumsi untuk \"{$product->name}\" tidak ditemukan."
                );
            }

            $need = bcmul($saleQuantity, $row->quantity, ProductQuantity::SCALE);

            if (InventoryType::isProductionOutput($component)) {
                $available = ProductionBatch::where('product_id', $component->id)
                    ->where('store_id', $store->id)
                    ->where('status', ProductionBatch::STATUS_COMPLETED)
                    ->sum('output_quantity_remaining');

                if (ProductQuantity::cmp($available, $need) < 0) {
                    $deficit = ProductQuantity::sub($need, $available);
                    $unit = $row->base_unit ?? $component->base_unit ?? 'pcs';
                    throw new \RuntimeException(
                        "Stok \"{$component->name}\" tidak mencukupi. "
                        ."Dibutuhkan {$need} {$unit}, tersedia {$available} {$unit}. "
                        ."Kekurangan {$deficit} {$unit}."
                    );
                }
            } else {
                if ($component->track_stock && ! $component->unlimited_stock) {
                    if (ProductQuantity::cmp($component->stock_quantity, $need) < 0) {
                        $deficit = ProductQuantity::sub($need, $component->stock_quantity);
                        $unit = $component->base_unit ?: 'pcs';
                        throw new \RuntimeException(
                            "Stok \"{$component->name}\" tidak mencukupi. "
                            ."Kekurangan {$deficit} {$unit}."
                        );
                    }
                }
            }
        }
    }

    /**
     * Konsumsi semua komponen untuk satu item penjualan.
     *
     * Mengembalikan data HPP untuk snapshot, atau null jika tidak ada konfigurasi.
     *
     * @return array{unit_cost: string, total_cost: string, gross_profit: string}|null
     */
    public static function consume(
        Store $store,
        User $user,
        Product $product,
        ?ProductVariant $variant,
        string $saleQuantity,
        TransactionItem $transactionItem,
        string $transactionNumber,
    ): ?array {
        $rows = self::loadRows($store, $product, $variant);
        if ($rows->isEmpty()) {
            return null;
        }

        $totalCost = '0.000000';

        foreach ($rows as $row) {
            $component = $row->component;
            $need = bcmul($saleQuantity, $row->quantity, ProductQuantity::SCALE);

            if (InventoryType::isProductionOutput($component)) {
                $totalCost = bcadd(
                    $totalCost,
                    self::consumeProductionOutput($store, $user, $component, $need, $transactionItem, $transactionNumber),
                    InventoryCosting::COST_SCALE,
                );
            } else {
                $totalCost = bcadd(
                    $totalCost,
                    self::consumePackaging($store, $user, $component, $need, $transactionItem, $transactionNumber),
                    InventoryCosting::COST_SCALE,
                );
            }
        }

        $unitCost = InventoryCosting::unitCostFromTotal($totalCost, $saleQuantity);
        $totalCostRounded = InventoryCosting::extendedCost($saleQuantity, $unitCost, 2);
        $lineRevenue = $transactionItem->total_price;
        $grossProfit = ProductQuantity::sub($lineRevenue, $totalCostRounded);

        return [
            'unit_cost' => $unitCost,
            'total_cost' => $totalCostRounded,
            'gross_profit' => $grossProfit,
        ];
    }

    /**
     * FIFO konsumsi dari ProductionBatch.
     * Mengembalikan total biaya konsumsi.
     */
    private static function consumeProductionOutput(
        Store $store,
        User $user,
        Product $component,
        string $need,
        TransactionItem $transactionItem,
        string $transactionNumber,
    ): string {
        $remaining = $need;
        $totalCost = '0.000000';

        // FIFO: batch tertua (id terkecil) pertama
        $batches = ProductionBatch::where('product_id', $component->id)
            ->where('store_id', $store->id)
            ->where('status', ProductionBatch::STATUS_COMPLETED)
            ->where('output_quantity_remaining', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if (ProductQuantity::cmp($remaining, '0') <= 0) {
                break;
            }

            $available = ProductQuantity::normalizeStorageAmount($component, $batch->output_quantity_remaining);
            if (ProductQuantity::cmp($available, '0') <= 0) {
                continue;
            }

            $take = ProductQuantity::min($remaining, $available);
            if (ProductQuantity::cmp($take, '0') <= 0) {
                continue;
            }

            $batch->decrement('output_quantity_remaining', $take);

            $lineCost = InventoryCosting::extendedCost($take, $batch->unit_cost, 2);
            $totalCost = bcadd($totalCost, $lineCost, InventoryCosting::COST_SCALE);

            SaleConsumptionAllocation::create([
                'transaction_item_id' => $transactionItem->id,
                'production_batch_id' => $batch->id,
                'component_product_id' => $component->id,
                'quantity_consumed' => $take,
                'unit_cost' => $batch->unit_cost,
                'total_cost' => $lineCost,
            ]);

            $remaining = ProductQuantity::sub($remaining, $take);
        }

        if (ProductQuantity::cmp($remaining, '0') > 0) {
            throw new \RuntimeException(
                "Stok batch \"{$component->name}\" tidak mencukupi saat pemrosesan penjualan."
            );
        }

        // Sinkronkan stok produk dari total batch
        $synced = (string) ProductionBatch::where('product_id', $component->id)
            ->where('status', ProductionBatch::STATUS_COMPLETED)
            ->sum('output_quantity_remaining');

        $component->update(['stock_quantity' => $synced]);

        // Catat mutasi stok
        $balanceAfter = (string) $component->fresh()->stock_quantity;
        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $component->id,
            'product_variant_id' => null,
            'user_id' => $user->id,
            'quantity_change' => ProductQuantity::sub('0.0000', $need),
            'type' => StockMovement::TYPE_SALE,
            'reference_type' => Transaction::class,
            'reference_id' => $transactionItem->transaction_id,
            'balance_after' => $balanceAfter,
            'notes' => $transactionNumber,
        ]);

        return $totalCost;
    }

    /**
     * Pengurangan stok kemasan (packaging) — flat deduction, bukan batch.
     * Mengembalikan total biaya konsumsi.
     */
    private static function consumePackaging(
        Store $store,
        User $user,
        Product $component,
        string $need,
        TransactionItem $transactionItem,
        string $transactionNumber,
    ): string {
        if ($component->track_stock && ! $component->unlimited_stock) {
            $component->decrement('stock_quantity', $need);
        }

        $unitCost = InventoryCosting::currentAverageCost($component);
        $lineCost = InventoryCosting::extendedCost($need, $unitCost, 2);

        SaleConsumptionAllocation::create([
            'transaction_item_id' => $transactionItem->id,
            'production_batch_id' => null,
            'component_product_id' => $component->id,
            'quantity_consumed' => $need,
            'unit_cost' => $unitCost,
            'total_cost' => $lineCost,
        ]);

        $balanceAfter = (string) $component->fresh()->stock_quantity;
        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $component->id,
            'product_variant_id' => null,
            'user_id' => $user->id,
            'quantity_change' => ProductQuantity::sub('0.0000', $need),
            'type' => StockMovement::TYPE_SALE,
            'reference_type' => Transaction::class,
            'reference_id' => $transactionItem->transaction_id,
            'balance_after' => $balanceAfter,
            'notes' => $transactionNumber.' (packaging)',
        ]);

        return $lineCost;
    }
}
