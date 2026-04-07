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
        $names = BusinessTypePresets::defaultProductCategoryNames($store->business_type ?? 'general');

        foreach ($names as $i => $name) {
            ProductCategory::query()->firstOrCreate(
                ['store_id' => $store->id, 'name' => $name],
                ['sort_order' => $i],
            );
        }
    }
}
