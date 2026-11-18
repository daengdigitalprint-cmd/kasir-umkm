<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/**
 * Tampilan menu digital (Scan Meja) yang bisa diatur pemilik toko:
 * foto latar header (sampul) dan logo khusus menu.
 *
 * Disimpan di settings toko: scan_meja_cover & scan_meja_logo (path disk public).
 */
class ScanMejaAppearance
{
    /**
     * URL yang dipakai halaman menu tamu. Logo menu kosong → pakai logo toko.
     *
     * @return array{cover_url: ?string, logo_url: ?string}
     */
    public static function urls(Store $store): array
    {
        $settings = $store->settings ?? [];

        return [
            'cover_url' => self::url($settings['scan_meja_cover'] ?? null),
            'logo_url' => self::url($settings['scan_meja_logo'] ?? null) ?? self::url($store->logo),
        ];
    }

    /**
     * Data untuk form pengaturan di halaman Manajemen Meja.
     *
     * @return array<string, mixed>
     */
    public static function forStore(Store $store): array
    {
        $settings = $store->settings ?? [];

        return [
            'store_name' => $store->name,
            'cover_url' => self::url($settings['scan_meja_cover'] ?? null),
            'logo_url' => self::url($settings['scan_meja_logo'] ?? null),
            'store_logo_url' => self::url($store->logo),
        ];
    }

    private static function url(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
