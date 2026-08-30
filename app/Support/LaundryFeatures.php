<?php

namespace App\Support;

use App\Models\Store;

class LaundryFeatures
{
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return ($store->business_type ?? '') === 'laundry';
    }
}
