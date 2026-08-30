<?php

namespace App\Support;

use App\Models\Store;

class BatchTracking
{
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        if (PharmacyFeatures::enabled($store)) {
            return true;
        }

        return (bool) ($store->settings['batch_tracking_enabled'] ?? false);
    }

    /** Hari ke depan (termasuk yang sudah lewat ED) untuk peringatan dashboard & status “peringatan” di laporan. */
    public static function expiryWarnDays(?Store $store): int
    {
        if (! $store) {
            return 30;
        }

        $d = (int) ($store->settings['batch_expiry_warn_days'] ?? 30);

        return max(1, min(365, $d));
    }
}
