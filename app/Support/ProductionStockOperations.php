<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductionBatch;

/**
 * Penyelarasan sisa batch produksi dengan stok produk hasil produksi.
 *
 * Produk `production_output` punya dua catatan kuantitas:
 *  - `products.stock_quantity`      → angka yang dilihat kasir
 *  - `production_batches.output_quantity_remaining` → sisa per batch (dasar FIFO & HPP)
 *
 * Penjualan memakai sisa batch sebagai sumber kebenaran lalu menimpa
 * `stock_quantity` dengan totalnya. Karena itu setiap koreksi stok manual
 * (edit produk, penyesuaian stok, stok opname) wajib ikut menggeser sisa batch;
 * kalau tidak, stok yang sudah dikoreksi akan hidup lagi pada transaksi berikutnya.
 */
final class ProductionStockOperations
{
    /**
     * Total sisa seluruh batch produksi yang sudah selesai.
     *
     * Bisa negatif bila ada batch yang pernah dipakai melewati sisanya
     * (produk dengan `allow_negative_stock`).
     */
    public static function remainingTotal(Product $product): string
    {
        return bcadd(
            (string) self::completedBatches($product)->sum('output_quantity_remaining'),
            '0',
            ProductQuantity::SCALE,
        );
    }

    /**
     * Geser sisa batch agar totalnya sama dengan stok produk hasil koreksi.
     *
     * Kekurangan dipotong FIFO (batch tertua lebih dulu); kelebihan ditambahkan
     * ke batch terbaru. Target nol menolkan semua batch sekaligus sehingga sisa
     * negatif dari penjualan minus ikut bersih.
     *
     * Produk tanpa batch selesai dilewati — tidak ada layer biaya untuk digeser.
     *
     * @return bool true bila ada sisa batch yang berubah
     */
    public static function syncBatchesToStock(Product $product, string $target): bool
    {
        if (! InventoryType::isProductionOutput($product)) {
            return false;
        }

        $batches = self::completedBatches($product)->lockForUpdate()->get();
        if ($batches->isEmpty()) {
            return false;
        }

        $target = bcadd($target, '0', ProductQuantity::SCALE);

        if (ProductQuantity::cmp($target, '0.0000') <= 0) {
            return self::zeroAll($batches);
        }

        $diff = ProductQuantity::sub($target, self::remainingTotal($product));

        if (ProductQuantity::cmp($diff, '0.0000') === 0) {
            return false;
        }

        if (ProductQuantity::cmp($diff, '0.0000') > 0) {
            $batches->last()->increment('output_quantity_remaining', $diff);

            return true;
        }

        self::reduceFifo($batches, ProductQuantity::sub('0.0000', $diff));

        return true;
    }

    /**
     * Samakan `stock_quantity` dengan total sisa batch.
     *
     * Dipakai setelah sisa batch digeser dari luar alur penjualan.
     */
    public static function syncStockFromBatches(Product $product): string
    {
        $total = self::remainingTotal($product);
        $product->update(['stock_quantity' => $total]);

        return $total;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProductionBatch>  $batches
     */
    private static function zeroAll($batches): bool
    {
        $changed = false;

        foreach ($batches as $batch) {
            if (ProductQuantity::cmp((string) $batch->output_quantity_remaining, '0.0000') !== 0) {
                $batch->update(['output_quantity_remaining' => '0.0000']);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Potong sisa batch dari yang tertua. Sisa kekurangan yang tidak tertutup
     * dibebankan ke batch terbaru, sama seperti perilaku penjualan minus.
     *
     * @param  \Illuminate\Support\Collection<int, ProductionBatch>  $batches
     */
    private static function reduceFifo($batches, string $shortage): void
    {
        foreach ($batches as $batch) {
            if (ProductQuantity::cmp($shortage, '0.0000') <= 0) {
                break;
            }

            $available = bcadd((string) $batch->output_quantity_remaining, '0', ProductQuantity::SCALE);
            if (ProductQuantity::cmp($available, '0.0000') <= 0) {
                continue;
            }

            $take = ProductQuantity::min($shortage, $available);
            $batch->decrement('output_quantity_remaining', $take);
            $shortage = ProductQuantity::sub($shortage, $take);
        }

        if (ProductQuantity::cmp($shortage, '0.0000') > 0) {
            $batches->last()->decrement('output_quantity_remaining', $shortage);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ProductionBatch>
     */
    private static function completedBatches(Product $product)
    {
        return ProductionBatch::query()
            ->where('product_id', $product->id)
            ->where('store_id', $product->store_id)
            ->where('status', ProductionBatch::STATUS_COMPLETED)
            ->orderBy('id');
    }
}
