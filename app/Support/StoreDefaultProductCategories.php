<?php

namespace App\Support;

use App\Models\ProductCategory;
use App\Models\Store;

/**
 * Membuat baris product_categories default per jenis usaha toko (selaras dengan BusinessTypePresets::catalog).
 */
class StoreDefaultProductCategories
{
    public static function ensureForStore(Store $store): void
    {
        // Pakai catalog() (sudah dipakai ProductController) supaya tidak bergantung pada helper terpisah yang bisa tidak ikut ter-deploy.
        $names = BusinessTypePresets::catalog($store->business_type ?? 'general')['categories'];

        foreach ($names as $i => $name) {
            ProductCategory::query()->firstOrCreate(
                ['store_id' => $store->id, 'name' => $name],
                ['sort_order' => $i],
            );
        }
    }
}
