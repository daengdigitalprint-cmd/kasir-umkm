<?php

namespace App\Support;

use App\Models\Store;

class FnBFeatures
{
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return ($store->business_type ?? '') === 'fnb';
    }

    public static function defaultServiceChargePercent(?Store $store): float
    {
        if (! $store) {
            return 0.0;
        }

        $v = $store->settings['default_service_charge_percent'] ?? 0;

        return max(0.0, min(100.0, (float) $v));
    }

    /** Tampilkan tombol tipe pesanan (Makan di tempat / Bungkus / Antar). Default true. */
    public static function orderTypesEnabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return (bool) ($store->settings['fnb_order_types_enabled'] ?? true);
    }

    /** Tampilkan fitur bagi tagihan (split bill). Default true. */
    public static function splitBillEnabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return (bool) ($store->settings['fnb_split_bill_enabled'] ?? true);
    }

    /**
     * Modul Open Bill (pesanan belum bayar): aktif otomatis untuk F&B,
     * jenis usaha lain dapat mengaktifkannya lewat setting `open_bill_enabled`.
     */
    public static function openBillEnabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return self::enabled($store) || (bool) ($store->settings['open_bill_enabled'] ?? false);
    }
}
