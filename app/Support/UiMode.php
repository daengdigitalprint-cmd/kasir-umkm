<?php

namespace App\Support;

use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Mode tampilan toko: "simple" (menu inti saja) atau "full" (semua menu).
 *
 * Mode HANYA menyaring tampilan menu. Hak akses rute tetap ditentukan role
 * (role_menu_permissions) dan paket langganan; pencatatan stok/akuntansi tidak berubah.
 * Lihat RENCANA_IMPLEMENTASI_MODE_SEDERHANA.md.
 */
class UiMode
{
    public const SIMPLE = 'simple';

    public const FULL = 'full';

    /** Menu inti yang selalu tampil di Mode Sederhana (menu_key di MainMenu.vue). */
    public const CORE_MENUS = [
        'dashboard',
        'pos',
        'pos_transactions',
        'cash_shifts',
        'products',
        'product_categories',
        'stock_overview',
        'purchases',
        'customers',
        'reports',
        'expenses',
        'settings',
        'manage_staff',
        'cashier_pin_settings',
    ];

    /** Menu inti tambahan menurut jenis usaha. */
    private const CORE_BY_BUSINESS_TYPE = [
        'fnb' => ['tables', 'table_orders'],
        'bakery' => ['tables', 'table_orders'],
        'laundry' => ['laundry_orders'],
        'barbershop' => ['queues'],
    ];

    /** Menu yang ikut tampil selama saklar modulnya (stores.settings) menyala. */
    private const MENUS_BY_SETTING = [
        'kitchen_queue_enabled' => ['kitchen_queue'],
        'queue_module_enabled' => ['queues'],
        'scan_meja_enabled' => ['tables', 'table_orders'],
        'attendance_enabled' => ['attendance', 'payroll'],
    ];

    /** Toko tanpa nilai ui_mode dianggap Mode Lengkap (toko lama tidak berubah). */
    public static function of(?Store $store): string
    {
        $mode = $store?->settings['ui_mode'] ?? null;

        return $mode === self::SIMPLE ? self::SIMPLE : self::FULL;
    }

    public static function isSimple(?Store $store): bool
    {
        return self::of($store) === self::SIMPLE;
    }

    public static function isValid(mixed $mode): bool
    {
        return in_array($mode, [self::SIMPLE, self::FULL], true);
    }

    /**
     * Mode untuk toko yang baru dibuat: mengikuti outlet acuan (outlet asal / satu grup),
     * dan Mode Sederhana bila belum ada outlet acuan.
     */
    public static function defaultForNewStore(?Store $reference): string
    {
        return $reference ? self::of($reference) : self::SIMPLE;
    }

    /**
     * @return list<string>
     */
    public static function coreMenus(Store $store): array
    {
        $extra = self::CORE_BY_BUSINESS_TYPE[$store->business_type ?? 'general'] ?? [];

        return array_values(array_unique([...self::CORE_MENUS, ...$extra]));
    }

    /**
     * Menu yang tampil di Mode Sederhana: inti + yang dinyalakan satu per satu
     * (settings.simple_extras) + modul yang saklarnya menyala + menu yang datanya sedang berjalan.
     *
     * @return list<string>
     */
    public static function visibleMenus(Store $store): array
    {
        $settings = $store->settings ?? [];
        $menus = self::coreMenus($store);

        foreach ((array) ($settings['simple_extras'] ?? []) as $menuKey) {
            if (is_string($menuKey)) {
                $menus[] = $menuKey;
            }
        }

        foreach (self::MENUS_BY_SETTING as $settingKey => $menuKeys) {
            if (! empty($settings[$settingKey])) {
                array_push($menus, ...$menuKeys);
            }
        }

        array_push($menus, ...self::menusInUse($store));

        return array_values(array_unique($menus));
    }

    /**
     * Menu lanjutan yang datanya masih berjalan, supaya tidak "hilang" dari pengguna.
     * Di-cache 5 menit per toko agar tidak menambah query di setiap halaman.
     *
     * @return list<string>
     */
    public static function menusInUse(Store $store): array
    {
        return Cache::remember(self::inUseCacheKey($store), now()->addMinutes(5), function () use ($store) {
            $menus = [];

            if ($store->transactions()->whereIn('payment_status', ['unpaid', 'partial'])->exists()) {
                $menus[] = 'receivables';
            }

            if ($store->preOrders()->where('status', 'open')->exists()) {
                $menus[] = 'pre_orders';
            }

            $hasTransferInProgress = StockTransfer::query()
                ->whereIn('status', [StockTransfer::STATUS_DRAFT, StockTransfer::STATUS_SENT])
                ->where(fn ($q) => $q->where('from_store_id', $store->id)->orWhere('to_store_id', $store->id))
                ->exists();
            if ($hasTransferInProgress) {
                $menus[] = 'stock_transfers';
            }

            return $menus;
        });
    }

    public static function forgetCache(Store $store): void
    {
        Cache::forget(self::inUseCacheKey($store));
    }

    private static function inUseCacheKey(Store $store): string
    {
        return 'ui_mode:menus_in_use:'.$store->id;
    }
}
