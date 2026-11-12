<?php

namespace App\Support;

use App\Http\Controllers\StoreSettingsController;
use App\Models\Store;
use App\Services\ModuleService;

/**
 * Katalog "Fitur Tambahan": fitur lanjutan yang bisa dinyalakan satu per satu,
 * terutama dari Mode Sederhana (lihat UiMode).
 *
 * Mengaktifkan fitur = menyalakan saklar modulnya (bila ada) dan menambahkan
 * menunya ke settings.simple_extras supaya tampil di Mode Sederhana.
 */
class OptionalFeatures
{
    public const GROUPS = [
        'stok' => 'Stok lanjutan',
        'pembelian' => 'Pembelian & retur',
        'pelanggan' => 'Pelanggan & promo',
        'dapur' => 'Dapur, meja & antrean',
        'keuangan' => 'Keuangan & pengawasan',
        'outlet' => 'Multi-outlet',
    ];

    /**
     * key => definisi fitur.
     *   menus    : menu_key sidebar yang dibuka fitur ini
     *   setting  : saklar di stores.settings (opsional)
     *   advanced : perlu paket dengan fitur retail lanjutan (GrowUp ke atas)
     *
     * @var array<string, array{label: string, description: string, group: string, menus: list<string>, setting?: string, advanced?: bool}>
     */
    public const FEATURES = [
        'stock_history' => [
            'label' => 'Riwayat Stok',
            'description' => 'Lihat setiap keluar-masuk stok per produk, lengkap dengan sumbernya.',
            'group' => 'stok',
            'menus' => ['stock_movements'],
        ],
        'stock_correction' => [
            'label' => 'Penyesuaian Stok & Opname',
            'description' => 'Koreksi stok dengan dokumen bernomor dan hitung fisik stok secara berkala.',
            'group' => 'stok',
            'menus' => ['stock_adjustment', 'stock_opnames'],
            'advanced' => true,
        ],
        'recipes' => [
            'label' => 'Resep',
            'description' => 'Atur bahan baku per menu supaya stok bahan berkurang otomatis saat terjual.',
            'group' => 'stok',
            'menus' => ['recipes'],
            'setting' => 'recipes_enabled',
        ],
        'productions' => [
            'label' => 'Produksi',
            'description' => 'Catat pembuatan produk jadi dari bahan baku beserta biaya produksinya.',
            'group' => 'stok',
            'menus' => ['productions'],
            'setting' => 'productions_enabled',
        ],
        'returns' => [
            'label' => 'Retur Pembelian & Penjualan',
            'description' => 'Kembalikan barang ke supplier atau terima retur dari pelanggan, stok ikut disesuaikan.',
            'group' => 'pembelian',
            'menus' => ['purchase_returns', 'sales_returns'],
            'advanced' => true,
        ],
        'pre_orders' => [
            'label' => 'Pre-order',
            'description' => 'Terima pesanan di muka dan pantau sampai barang diserahkan.',
            'group' => 'pembelian',
            'menus' => ['pre_orders'],
            'advanced' => true,
        ],
        'receivables' => [
            'label' => 'Piutang Pelanggan',
            'description' => 'Jual dengan bayar nanti, catat cicilan, dan pantau piutang yang jatuh tempo.',
            'group' => 'pelanggan',
            'menus' => ['receivables'],
            'setting' => 'receivables_enabled',
        ],
        'loyalty' => [
            'label' => 'Loyalitas',
            'description' => 'Beri poin untuk pelanggan setia dan kirim promo kepada mereka.',
            'group' => 'pelanggan',
            'menus' => ['loyalty'],
            'advanced' => true,
        ],
        'discounts' => [
            'label' => 'Diskon',
            'description' => 'Buat aturan diskon dan promo yang otomatis berlaku di kasir.',
            'group' => 'pelanggan',
            'menus' => ['discounts'],
            'advanced' => true,
        ],
        'scan_meja' => [
            'label' => 'Scan Meja (Menu Digital)',
            'description' => 'Pelanggan memesan sendiri lewat QR di meja, pesanan masuk ke kasir.',
            'group' => 'dapur',
            'menus' => ['tables', 'table_orders'],
            'setting' => 'scan_meja_enabled',
        ],
        'kitchen_queue' => [
            'label' => 'Antrian Dapur',
            'description' => 'Tampilkan pesanan ke layar dapur dan tandai saat selesai dibuat.',
            'group' => 'dapur',
            'menus' => ['kitchen_queue'],
            'setting' => 'kitchen_queue_enabled',
        ],
        'queues' => [
            'label' => 'Antrian Pelanggan',
            'description' => 'Nomor antrean untuk layanan seperti barbershop, klinik, atau servis.',
            'group' => 'dapur',
            'menus' => ['queues'],
            'setting' => 'queue_module_enabled',
        ],
        'cash_transactions' => [
            'label' => 'Kas Masuk/Keluar',
            'description' => 'Catat uang masuk dan keluar laci kasir di luar penjualan.',
            'group' => 'keuangan',
            'menus' => ['cash_transactions'],
            'advanced' => true,
        ],
        'activity_logs' => [
            'label' => 'Log Aktivitas',
            'description' => 'Lihat siapa mengubah apa dan kapan, untuk pengawasan toko.',
            'group' => 'keuangan',
            'menus' => ['activity_logs'],
        ],
        'kpi' => [
            'label' => 'KPI',
            'description' => 'Pantau target dan pencapaian penjualan toko.',
            'group' => 'keuangan',
            'menus' => ['kpi'],
        ],
        'multi_outlet' => [
            'label' => 'Outlet, Gudang & Transfer Stok',
            'description' => 'Tambah outlet atau gudang, bagikan produk antar outlet, dan pindahkan stok.',
            'group' => 'outlet',
            'menus' => ['manage_stores', 'manage_products', 'stock_transfers'],
            'advanced' => true,
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::FEATURES[$key]);
    }

    /** Nilai saklar modul, dengan default yang sama seperti di HandleInertiaRequests. */
    public static function settingOn(Store $store, string $settingKey): bool
    {
        $settings = $store->settings ?? [];
        if (array_key_exists($settingKey, $settings)) {
            return (bool) $settings[$settingKey];
        }

        return match ($settingKey) {
            'recipes_enabled', 'productions_enabled' => StoreSettingsController::featureDefaultsForType($store->business_type ?? 'general')[$settingKey],
            'receivables_enabled' => true,
            default => false,
        };
    }

    /**
     * Aktif = saklar modulnya menyala (bila ada) dan, di Mode Sederhana, menunya tampil.
     */
    public static function isActive(Store $store, string $key): bool
    {
        $feature = self::FEATURES[$key];

        if (isset($feature['setting']) && ! self::settingOn($store, $feature['setting'])) {
            return false;
        }

        if (! UiMode::isSimple($store)) {
            return true;
        }

        return array_diff($feature['menus'], UiMode::visibleMenus($store)) === [];
    }

    /** Fitur yang datanya masih berjalan tidak boleh dimatikan. */
    public static function isInUse(Store $store, string $key): bool
    {
        return array_intersect(self::FEATURES[$key]['menus'], UiMode::menusInUse($store)) !== [];
    }

    public static function needsUpgrade(Store $store, string $key): bool
    {
        return ! empty(self::FEATURES[$key]['advanced']) && ! StorePlanLimits::allowsAdvancedRetailFeatures($store);
    }

    /**
     * Di Mode Lengkap, fitur tanpa saklar modul selalu tampil sehingga tidak ada yang bisa diubah.
     */
    public static function isToggleable(Store $store, string $key): bool
    {
        return UiMode::isSimple($store) || isset(self::FEATURES[$key]['setting']);
    }

    public static function enable(Store $store, string $key): void
    {
        $feature = self::FEATURES[$key];

        if (isset($feature['setting'])) {
            if (isset(ModuleService::MODULES[$feature['setting']])) {
                // Samakan dulu nilai default yang belum tersimpan agar log sebelum/sesudah benar.
                self::persistSetting($store, $feature['setting'], self::settingOn($store, $feature['setting']));
                ModuleService::toggle($store, $feature['setting'], true);
            } else {
                self::persistSetting($store, $feature['setting'], true);
            }
        }

        $settings = $store->settings ?? [];
        $extras = array_values(array_unique([...(array) ($settings['simple_extras'] ?? []), ...$feature['menus']]));
        $settings['simple_extras'] = $extras;
        $store->update(['settings' => $settings]);
    }

    public static function disable(Store $store, string $key): void
    {
        $feature = self::FEATURES[$key];

        if (isset($feature['setting'])) {
            if (isset(ModuleService::MODULES[$feature['setting']])) {
                self::persistSetting($store, $feature['setting'], self::settingOn($store, $feature['setting']));
                ModuleService::toggle($store, $feature['setting'], false);
            } else {
                self::persistSetting($store, $feature['setting'], false);
            }
        }

        $settings = $store->settings ?? [];
        $settings['simple_extras'] = array_values(array_diff((array) ($settings['simple_extras'] ?? []), $feature['menus']));
        $store->update(['settings' => $settings]);
    }

    private static function persistSetting(Store $store, string $settingKey, bool $value): void
    {
        $settings = $store->settings ?? [];
        if (($settings[$settingKey] ?? null) === $value) {
            return;
        }
        $settings[$settingKey] = $value;
        $store->update(['settings' => $settings]);
    }

    /**
     * Data kartu untuk halaman Fitur Tambahan, dikelompokkan.
     *
     * @return list<array{key: string, label: string, features: list<array<string, mixed>>}>
     */
    public static function forFrontend(Store $store): array
    {
        $groups = [];
        foreach (self::GROUPS as $groupKey => $groupLabel) {
            $features = [];
            foreach (self::FEATURES as $key => $feature) {
                if ($feature['group'] !== $groupKey) {
                    continue;
                }
                $features[] = [
                    'key' => $key,
                    'label' => $feature['label'],
                    'description' => $feature['description'],
                    'active' => self::isActive($store, $key),
                    'in_use' => self::isInUse($store, $key),
                    'needs_upgrade' => self::needsUpgrade($store, $key),
                    'toggleable' => self::isToggleable($store, $key),
                ];
            }
            $groups[] = ['key' => $groupKey, 'label' => $groupLabel, 'features' => $features];
        }

        return $groups;
    }
}
