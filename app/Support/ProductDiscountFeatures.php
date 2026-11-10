<?php

namespace App\Support;

use App\Models\Store;

/**
 * Saklar modul Diskon per Produk (diskon manual per baris keranjang di POS).
 *
 * Disimpan di stores.settings["product_discount_enabled"], default nonaktif;
 * toko harus mengaktifkannya di Pengaturan. Hanya mengatur diskon per baris —
 * kode promo (menu Diskon), voucher loyalitas, dll. tidak terpengaruh.
 */
class ProductDiscountFeatures
{
    public const SETTING_KEY = 'product_discount_enabled';

    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return (bool) ($store->settings[self::SETTING_KEY] ?? false);
    }
}
