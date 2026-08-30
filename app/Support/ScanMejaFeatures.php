<?php

namespace App\Support;

use App\Models\Store;

class ScanMejaFeatures
{
    /**
     * Cek apakah fitur Scan Meja (Menu Digital) aktif untuk store.
     */
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return (bool) ($store->settings['scan_meja_enabled'] ?? false);
    }

    /**
     * Cek apakah fitur Scan Meja tersedia untuk tipe bisnis tertentu.
     * Hanya untuk F&B / Bakery.
     */
    public static function availableFor(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return in_array($store->business_type, ['fnb', 'bakery'], true);
    }
}
