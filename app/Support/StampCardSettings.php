<?php

namespace App\Support;

use App\Models\Store;

/**
 * Aturan Kartu Stempel per toko.
 * Sakelar modul: stores.settings['stamp_card_enabled'] (bawaan nonaktif, semua paket).
 * Aturan: stores.settings['stamp_card'].
 */
final class StampCardSettings
{
    public const DEFAULTS = [
        // Nama kartu yang tampil di HP pelanggan.
        'title' => 'Kartu stempel',
        // Jumlah stempel untuk 1 hadiah.
        'target' => 10,
        // transaction = 1 stempel per transaksi; item = 1 stempel per porsi produk/kategori terpilih.
        'mode' => 'transaction',
        // Belanja minimal agar dapat stempel (mode transaction).
        'min_amount' => 0,
        'product_ids' => [],
        'category_ids' => [],
        'reward' => '1 menu gratis',
        // Stempel hangus bila pelanggan tidak datang selama N bulan (0 = tidak hangus).
        'expiry_months' => 0,
    ];

    public static function enabled(?Store $store): bool
    {
        return (bool) ($store?->settings['stamp_card_enabled'] ?? false);
    }

    /** @return array<string, mixed> */
    public static function all(?Store $store): array
    {
        $raw = is_array($store?->settings['stamp_card'] ?? null) ? $store->settings['stamp_card'] : [];

        return self::normalize($raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function normalize(array $raw): array
    {
        $merged = array_merge(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
        $merged['title'] = trim((string) $merged['title']) !== '' ? mb_substr(trim((string) $merged['title']), 0, 60) : self::DEFAULTS['title'];
        $merged['reward'] = mb_substr(trim((string) $merged['reward']), 0, 120);
        $merged['target'] = max(2, min(30, (int) $merged['target']));
        $merged['min_amount'] = max(0, (int) $merged['min_amount']);
        $merged['expiry_months'] = max(0, (int) $merged['expiry_months']);
        $merged['mode'] = in_array($merged['mode'], ['transaction', 'item'], true) ? $merged['mode'] : 'transaction';
        $merged['product_ids'] = array_values(array_map('intval', (array) $merged['product_ids']));
        $merged['category_ids'] = array_values(array_map('intval', (array) $merged['category_ids']));

        return $merged;
    }

    public static function target(?Store $store): int
    {
        return self::all($store)['target'];
    }

    /** @param  array<string, mixed>  $values */
    public static function update(Store $store, array $values): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $settings['stamp_card'] = self::normalize(array_merge(self::all($store), $values));
        $store->settings = $settings;
        $store->save();
    }
}
