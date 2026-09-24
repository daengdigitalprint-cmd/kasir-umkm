<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\CacheKeys;

/**
 * Invalidate cache master (kategori produk) & laporan saat produk berubah.
 * Stok produk itu sendiri TIDAK di-cache — hanya agregat/statis yang di-flush.
 */
class ProductObserver
{
    public function created(Product $product): void
    {
        $this->flush($product);
    }

    public function updated(Product $product): void
    {
        $this->flush($product);
    }

    public function deleted(Product $product): void
    {
        $this->flush($product);
    }

    private function flush(Product $product): void
    {
        if ($product->store_id) {
            CacheKeys::forgetStoreMasters((int) $product->store_id);
            CacheKeys::forgetStoreReports((int) $product->store_id);
        }
    }
}
