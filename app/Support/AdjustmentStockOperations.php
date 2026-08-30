<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;

/**
 * Mutasi stok untuk dokumen koreksi (penyesuaian & stock opname).
 *
 * Posting memakai selisih terhadap snapshot `system_quantity`, bukan menimpa
 * nilai stok secara absolut. Dengan begitu penjualan yang terjadi antara
 * pembukaan dokumen dan posting tetap terhitung benar.
 *
 * Tiga jenis sasaran didukung:
 *  - produk biasa  : langsung pada `products.stock_quantity`
 *  - produk varian : pada `product_variants`, induk disinkronkan dari penjumlahan
 *  - produk batch  : FIFO pada `product_batches`, produk disinkronkan dari penjumlahan
 */
final class AdjustmentStockOperations
{
    /**
     * Stok berjalan milik sasaran baris (produk atau varian).
     */
    public static function currentQuantity(Product $product, ?ProductVariant $variant = null): string
    {
        $raw = $variant !== null ? $variant->stock_quantity : $product->stock_quantity;

        return bcadd((string) $raw, '0', ProductQuantity::SCALE);
    }

    /**
     * Selisih = hasil hitung - stok sistem saat baris dibuat. Boleh negatif.
     */
    public static function variance(string $countedQuantity, string $systemQuantity): string
    {
        return bcsub($countedQuantity, $systemQuantity, ProductQuantity::SCALE);
    }

    /**
     * Tolak produk yang tidak layak dikoreksi lewat dokumen ini.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertAdjustable(Store $store, Product $product, ?ProductVariant $variant = null): void
    {
        if ((int) $product->store_id !== (int) $store->id) {
            throw new \InvalidArgumentException("Produk «{$product->name}» tidak termasuk toko ini.");
        }
        if (! $product->track_stock) {
            throw new \InvalidArgumentException("Produk «{$product->name}» tidak mencatat stok, sehingga tidak perlu dikoreksi.");
        }
        if ($product->unlimited_stock) {
            throw new \InvalidArgumentException("Produk «{$product->name}» berstok tidak terbatas, sehingga tidak perlu dikoreksi.");
        }
        if ($product->is_bundle) {
            throw new \InvalidArgumentException("Produk «{$product->name}» adalah paket. Koreksi stok dilakukan pada produk komponennya.");
        }

        if ($product->has_variants) {
            if ($variant === null) {
                throw new \InvalidArgumentException("Produk «{$product->name}» memiliki varian. Pilih varian yang akan dikoreksi.");
            }
            if ((int) $variant->product_id !== (int) $product->id) {
                throw new \InvalidArgumentException("Varian «{$variant->name}» bukan milik produk «{$product->name}».");
            }
        } elseif ($variant !== null) {
            throw new \InvalidArgumentException("Produk «{$product->name}» tidak memiliki varian.");
        }
    }

    /**
     * Terapkan satu baris koreksi ke stok lalu catat mutasinya.
     *
     * Produk harus sudah dikunci `lockForUpdate()` oleh pemanggil.
     *
     * @throws \RuntimeException
     */
    public static function applyLine(
        Store $store,
        User $user,
        Product $product,
        StockAdjustment $document,
        StockAdjustmentLine $line,
        ?ProductVariant $variant = null,
    ): void {
        $varianceQty = bcadd((string) $line->variance_quantity, '0', ProductQuantity::SCALE);

        if (bccomp($varianceQty, '0', ProductQuantity::SCALE) === 0) {
            return;
        }

        $movementType = $document->type === StockAdjustment::TYPE_OPNAME
            ? StockMovement::TYPE_OPNAME
            : StockMovement::TYPE_ADJUSTMENT;

        $notes = $document->adjustment_number.' — '.StockAdjustment::reasonLabel((string) $document->reason_code);

        self::mutate($store, $user, $product, $variant, $line, $varianceQty, $movementType, $notes, $document);
    }

    /**
     * Kembalikan efek satu baris koreksi yang sudah diposting.
     *
     * @throws \RuntimeException
     */
    public static function reverseLine(
        Store $store,
        User $user,
        Product $product,
        StockAdjustment $document,
        StockAdjustmentLine $line,
        ?ProductVariant $variant = null,
    ): void {
        $varianceQty = bcadd((string) $line->variance_quantity, '0', ProductQuantity::SCALE);

        if (bccomp($varianceQty, '0', ProductQuantity::SCALE) === 0) {
            return;
        }

        $reversal = bcmul($varianceQty, '-1', ProductQuantity::SCALE);

        self::mutate(
            $store,
            $user,
            $product,
            $variant,
            $line,
            $reversal,
            StockMovement::TYPE_ADJUSTMENT_REVERSAL,
            'Pembatalan '.$document->adjustment_number,
            $document,
        );
    }

    /**
     * Inti mutasi: memilih jalur varian, batch, atau produk biasa.
     *
     * @throws \RuntimeException
     */
    private static function mutate(
        Store $store,
        User $user,
        Product $product,
        ?ProductVariant $variant,
        StockAdjustmentLine $line,
        string $delta,
        string $movementType,
        string $notes,
        StockAdjustment $document,
    ): void {
        $useBatch = $variant === null && BatchTracking::enabled($store);

        if ($variant !== null) {
            $balanceAfter = self::mutateVariant($product, $variant, $delta);
        } elseif ($useBatch) {
            $balanceAfter = self::mutateBatch($product, $delta, $document, $line);
        } else {
            $balanceAfter = self::mutateProduct($product, $delta);
        }

        // Biaya dibekukan saat posting; selisih minus tidak menggeser average cost.
        if ($movementType !== StockMovement::TYPE_ADJUSTMENT_REVERSAL) {
            $unitCost = InventoryCosting::currentAverageCost($product);
            $line->update([
                'unit_cost' => $unitCost,
                'variance_value' => InventoryCosting::extendedCost($delta, $unitCost),
            ]);
        }

        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'user_id' => $user->id,
            'quantity_change' => $delta,
            'type' => $movementType,
            'reference_type' => StockAdjustmentLine::class,
            'reference_id' => $line->id,
            'balance_after' => $balanceAfter,
            'notes' => $notes,
        ]);
    }

    /**
     * @throws \RuntimeException
     */
    private static function mutateProduct(Product $product, string $delta): string
    {
        $current = bcadd((string) $product->stock_quantity, '0', ProductQuantity::SCALE);
        $target = bcadd($current, $delta, ProductQuantity::SCALE);

        self::assertNotNegative($target, $product->name, $current, $delta);

        $product->update(['stock_quantity' => $target]);

        return (string) $product->fresh()->stock_quantity;
    }

    /**
     * Koreksi pada varian; stok induk disinkronkan dari penjumlahan seluruh varian.
     *
     * @throws \RuntimeException
     */
    private static function mutateVariant(Product $product, ProductVariant $variant, string $delta): string
    {
        $current = bcadd((string) $variant->stock_quantity, '0', ProductQuantity::SCALE);
        $target = bcadd($current, $delta, ProductQuantity::SCALE);

        self::assertNotNegative($target, $product->name.' — '.$variant->name, $current, $delta);

        $variant->update(['stock_quantity' => $target]);

        $sum = (string) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
        $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $sum)]);

        return (string) $variant->fresh()->stock_quantity;
    }

    /**
     * Koreksi pada produk bermode batch.
     *
     * Selisih minus memotong batch secara FIFO (ED terdekat lebih dulu);
     * selisih plus membentuk batch baru bertanda nomor dokumen.
     *
     * @throws \RuntimeException
     */
    private static function mutateBatch(Product $product, string $delta, StockAdjustment $document, StockAdjustmentLine $line): string
    {
        TransferStockOperations::ensureBatchAligned($product);

        if (bccomp($delta, '0', ProductQuantity::SCALE) > 0) {
            ProductBatch::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'quantity_remaining' => $delta,
                'expiry_date' => $line->expiry_date,
                'batch_code' => $line->batch_code ?? substr('ADJ '.$document->adjustment_number, 0, 120),
            ]);
        } else {
            $remaining = bcmul($delta, '-1', ProductQuantity::SCALE);

            $batches = ProductBatch::query()
                ->where('product_id', $product->id)
                ->where('quantity_remaining', '>', 0)
                ->where('status', ProductBatch::STATUS_AVAILABLE)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if (ProductQuantity::cmp($remaining, '0.0000') <= 0) {
                    break;
                }
                $batchRem = ProductQuantity::normalizeStorageAmount($product, $batch->quantity_remaining);
                if (ProductQuantity::cmp($batchRem, '0.0000') <= 0) {
                    continue;
                }
                $take = ProductQuantity::min($remaining, $batchRem);
                if (ProductQuantity::cmp($take, '0.0000') > 0) {
                    $batch->decrement('quantity_remaining', $take);
                    $remaining = ProductQuantity::sub($remaining, $take);
                }
            }

            if (ProductQuantity::cmp($remaining, '0.0000') > 0) {
                throw new \RuntimeException(
                    "Stok batch «{$product->name}» tidak mencukupi untuk koreksi sebesar {$delta}."
                );
            }
        }

        $synced = (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
        $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $synced)]);

        return (string) $product->fresh()->stock_quantity;
    }

    /**
     * @throws \RuntimeException
     */
    private static function assertNotNegative(string $target, string $label, string $current, string $delta): void
    {
        if (bccomp($target, '0', ProductQuantity::SCALE) < 0) {
            throw new \RuntimeException(
                "Koreksi «{$label}» membuat stok menjadi negatif (stok kini {$current}, selisih {$delta})."
            );
        }
    }
}
