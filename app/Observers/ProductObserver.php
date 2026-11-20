<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\CacheKeys;

/**
 * Invalidate cache master (kategori produk) & laporan saat produk berubah.
 * Stok produk itu sendiri TIDAK di-cache — hanya agregat/statis yang di-flush.
 */
class ProductObserver
{
    /**
     * Nama kategori (kolom `category`) selalu mengikuti kategori terpilih
     * (`product_category_id`). Diskon per kategori & laporan membaca nama ini,
     * sedangkan kasir mengelompokkan menu memakai id-nya.
     */
    public function saving(Product $product): void
    {
        if ($product->product_category_id === null) {
            return;
        }

        if ($product->isDirty('product_category_id') || blank($product->category)) {
            $name = ProductCategory::query()->whereKey($product->product_category_id)->value('name');
            if ($name !== null) {
                $product->category = $name;
            }
        }
    }

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
