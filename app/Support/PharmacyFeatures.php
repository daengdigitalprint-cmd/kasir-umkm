<?php

namespace App\Support;

use App\Models\Store;

class PharmacyFeatures
{
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return ($store->business_type ?? '') === 'pharmacy';
    }
}
