<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tampilan halaman pelanggan (menu digital Scan Meja & tautan Pesan Antar) yang bisa
 * diatur pemilik toko: foto latar header (sampul), logo khusus menu, dan banner geser.
 *
 * Disimpan di settings toko (path disk public): scan_meja_cover, scan_meja_logo,
 * dan scan_meja_banners (daftar berurutan, paling banyak MAX_BANNERS).
 */
class ScanMejaAppearance
{
    public const MAX_BANNERS = 5;

    /** Ukuran banner yang disarankan (rasio 5:2). Ditampilkan di form dan dipakai slider. */
    public const BANNER_WIDTH = 1200;

    public const BANNER_HEIGHT = 480;

    /** Batas ukuran berkas banner dalam KB. */
    public const BANNER_MAX_KB = 2048;

    private const BANNER_KEY = 'scan_meja_banners';

    /**
     * URL yang dipakai halaman menu tamu. Logo menu kosong → pakai logo toko.
     *
     * @return array{cover_url: ?string, logo_url: ?string, banner_urls: list<string>}
     */
    public static function urls(Store $store): array
    {
        $settings = $store->settings ?? [];

        return [
            'cover_url' => self::url($settings['scan_meja_cover'] ?? null),
            'logo_url' => self::url($settings['scan_meja_logo'] ?? null) ?? self::url($store->logo),
            'banner_urls' => array_column(self::banners($store), 'url'),
        ];
    }

    /**
     * Data untuk form pengaturan (Manajemen Meja & Pengantaran).
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
            'banners' => self::banners($store),
            'banner_limits' => [
                'max' => self::MAX_BANNERS,
                'width' => self::BANNER_WIDTH,
                'height' => self::BANNER_HEIGHT,
                'max_mb' => (int) (self::BANNER_MAX_KB / 1024),
            ],
        ];
    }

    /**
     * Validasi lalu simpan sampul, logo, dan banner dari form "Atur tampilan".
     * Dipakai bersama oleh halaman Manajemen Meja dan Pengantaran.
     */
    public static function save(Store $store, Request $request): void
    {
        $request->validate([
            'cover' => 'nullable|file|image|mimes:jpeg,jpg,png,webp|max:3072',
            'logo' => 'nullable|file|image|mimes:jpeg,jpg,png,webp|max:1024',
            'remove_cover' => 'nullable|boolean',
            'remove_logo' => 'nullable|boolean',
            'banners' => 'nullable|array|max:'.self::MAX_BANNERS,
            'banners.*' => 'file|image|mimes:jpeg,jpg,png,webp|max:'.self::BANNER_MAX_KB,
            'remove_banners' => 'nullable|array',
            'remove_banners.*' => 'string|max:150',
        ], [
            'banners.max' => 'Banner paling banyak '.self::MAX_BANNERS.' gambar.',
            'banners.*.image' => 'Banner harus berupa gambar JPG, PNG, atau WebP.',
            'banners.*.mimes' => 'Banner harus berupa gambar JPG, PNG, atau WebP.',
            'banners.*.max' => 'Ukuran tiap banner paling besar '.(int) (self::BANNER_MAX_KB / 1024).' MB.',
            'banners.*.file' => 'Banner gagal diunggah. Coba pilih ulang gambarnya.',
        ]);

        $settings = $store->settings ?? [];

        // Banner: hitung dulu hasil akhirnya, baru menyentuh berkas, supaya penolakan tidak menghapus apa pun.
        $current = self::bannerPaths($settings);
        $remove = array_map('strval', (array) $request->input('remove_banners', []));
        $kept = array_values(array_filter($current, fn (string $path) => ! in_array(basename($path), $remove, true)));
        $uploads = array_values((array) $request->file('banners', []));

        if (count($kept) + count($uploads) > self::MAX_BANNERS) {
            throw ValidationException::withMessages([
                'banners' => 'Banner paling banyak '.self::MAX_BANNERS.' gambar. Hapus dulu banner lama sebelum menambah yang baru.',
            ]);
        }

        foreach (['cover' => 'scan_meja_cover', 'logo' => 'scan_meja_logo'] as $field => $key) {
            $old = $settings[$key] ?? null;
            $replace = $request->hasFile($field);

            if (($replace || $request->boolean('remove_'.$field)) && $old) {
                Storage::disk('public')->delete($old);
                unset($settings[$key]);
            }

            if ($replace) {
                $settings[$key] = $request->file($field)->store('scan-meja/'.$store->id, 'public');
            }
        }

        foreach (array_diff($current, $kept) as $path) {
            Storage::disk('public')->delete($path);
        }
        foreach ($uploads as $file) {
            $kept[] = $file->store('scan-meja/'.$store->id.'/banner', 'public');
        }
        if ($kept === []) {
            unset($settings[self::BANNER_KEY]);
        } else {
            $settings[self::BANNER_KEY] = $kept;
        }

        $store->update(['settings' => $settings]);
    }

    /**
     * Banner yang berkasnya masih ada, berurutan. `id` = nama berkas, dipakai form untuk menghapus.
     *
     * @return list<array{id: string, url: string}>
     */
    private static function banners(Store $store): array
    {
        $out = [];
        foreach (self::bannerPaths($store->settings ?? []) as $path) {
            $url = self::url($path);
            if ($url !== null) {
                $out[] = ['id' => basename($path), 'url' => $url];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private static function bannerPaths(array $settings): array
    {
        $paths = $settings[self::BANNER_KEY] ?? [];

        return is_array($paths) ? array_values(array_filter($paths, fn ($p) => is_string($p) && $p !== '')) : [];
    }

    private static function url(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
