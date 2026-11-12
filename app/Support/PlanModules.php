<?php

namespace App\Support;

use App\Http\Controllers\StoreSettingsController;
use App\Models\JournalEntry;
use App\Models\ProductionBatch;
use App\Models\QueueEntry;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\Table;
use Illuminate\Support\Facades\Cache;

/**
 * Modul lanjutan yang hanya tersedia di paket berbayar (GrowUp ke atas).
 *
 * Paket Gratis (Starter) tidak bisa menyalakan atau membuka modul di bawah ini,
 * KECUALI modul yang datanya sudah berjalan di toko tersebut ("sedang dipakai"):
 * toko itu tetap bisa memakainya supaya tidak kehilangan akses ke datanya.
 *
 * Satu-satunya sumber kebenaran: route (EnsurePlanModule), saklar di Pengaturan,
 * halaman Fitur Tambahan, menu sidebar, dan props Inertia semuanya bertanya ke sini.
 */
final class PlanModules
{
    /**
     * key => definisi modul.
     *   setting : saklar di stores.settings (opsional)
     *   menus   : menu_key sidebar milik modul
     *   routes  : pola nama route (Str::is) yang dijaga
     *
     * @var array<string, array{label: string, setting?: string, menus: list<string>, routes: list<string>}>
     */
    public const MODULES = [
        'recipes' => [
            'label' => 'Resep',
            'setting' => 'recipes_enabled',
            'menus' => ['recipes'],
            'routes' => ['recipes.*'],
        ],
        'productions' => [
            'label' => 'Produksi',
            'setting' => 'productions_enabled',
            'menus' => ['productions'],
            'routes' => ['productions.*'],
        ],
        'receivables' => [
            'label' => 'Piutang Pelanggan',
            'setting' => 'receivables_enabled',
            'menus' => ['receivables'],
            'routes' => ['receivables.*', 'receivable-payments.*'],
        ],
        'scan_meja' => [
            'label' => 'Scan Meja (Menu Digital)',
            'setting' => 'scan_meja_enabled',
            'menus' => ['tables', 'table_orders'],
            'routes' => ['tables.*', 'table-orders.*'],
        ],
        'kitchen_queue' => [
            'label' => 'Antrian Dapur',
            'setting' => 'kitchen_queue_enabled',
            'menus' => ['kitchen_queue'],
            'routes' => ['kitchen.*'],
        ],
        'accounting' => [
            'label' => 'Akuntansi (Jurnal & Laporan Keuangan)',
            'setting' => 'accounting_enabled',
            'menus' => [],
            'routes' => ['financial.*'],
        ],
        'kpi' => [
            'label' => 'KPI',
            'menus' => ['kpi'],
            'routes' => ['kpi.*'],
        ],
        'ai_chat' => [
            'label' => 'Asisten AI',
            'setting' => 'ai_chat_enabled',
            'menus' => [],
            'routes' => ['ai.*'],
        ],
    ];

    /** Modul yang dikunci untuk paket toko ini (kosong untuk paket berbayar / trial). */
    public static function locked(?Store $store, string $module): bool
    {
        if (! $store || ! isset(self::MODULES[$module])) {
            return false;
        }

        if (! self::onFreePlan($store)) {
            return false;
        }

        // Izin khusus per toko (diberikan manual, mis. oleh super admin): settings.plan_unlocked_modules.
        if (in_array($module, (array) ($store->settings['plan_unlocked_modules'] ?? []), true)) {
            return false;
        }

        return ! in_array($module, self::modulesInUse($store), true);
    }

    /**
     * Toko di paket Gratis (Starter) dengan langganan aktif. Toko yang langganannya
     * tidak aktif tidak dihitung di sini: aksesnya sudah ditahan middleware store.active.
     */
    public static function onFreePlan(?Store $store): bool
    {
        if (! $store || ! $store->isSubscriptionActive()) {
            return false;
        }

        return ! StorePlanLimits::allowsAdvancedRetailFeatures($store);
    }

    /** Modul pemilik saklar tertentu (mis. 'recipes_enabled' => 'recipes'). */
    public static function moduleForSetting(string $settingKey): ?string
    {
        foreach (self::MODULES as $key => $module) {
            if (($module['setting'] ?? null) === $settingKey) {
                return $key;
            }
        }

        return null;
    }

    /** Apakah saklar ini dikunci paket (tidak boleh dinyalakan)? */
    public static function settingLocked(?Store $store, string $settingKey): bool
    {
        $module = self::moduleForSetting($settingKey);

        return $module !== null && self::locked($store, $module);
    }

    /**
     * Nilai saklar yang BERLAKU: nilai tersimpan (atau default-nya), dan selalu mati
     * bila modulnya dikunci paket. Pakai ini, bukan `$store->settings[...]` langsung.
     */
    public static function settingOn(?Store $store, string $settingKey): bool
    {
        if (! $store || self::settingLocked($store, $settingKey)) {
            return false;
        }

        $settings = $store->settings ?? [];
        if (array_key_exists($settingKey, $settings)) {
            return (bool) $settings[$settingKey];
        }

        return match ($settingKey) {
            'recipes_enabled', 'productions_enabled' => (bool) StoreSettingsController::featureDefaultsForType($store->business_type ?? 'general')[$settingKey],
            'receivables_enabled', 'ai_chat_enabled' => true,
            default => false,
        };
    }

    /** Modul pemilik route bernama $routeName, atau null bila route tidak dijaga. */
    public static function moduleForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        foreach (self::MODULES as $key => $module) {
            foreach ($module['routes'] as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * menu_key sidebar yang dikunci paket (ditampilkan nonaktif dengan ajakan upgrade).
     *
     * @return list<string>
     */
    public static function lockedMenus(?Store $store): array
    {
        if (! self::onFreePlan($store)) {
            return [];
        }

        $menus = [];
        foreach (self::MODULES as $key => $module) {
            if (self::locked($store, $key)) {
                array_push($menus, ...$module['menus']);
            }
        }

        return array_values(array_unique($menus));
    }

    public static function lockedMessage(string $module): string
    {
        $label = self::MODULES[$module]['label'] ?? 'Fitur ini';

        return "{$label} tersedia mulai paket GrowUp. Upgrade paket di halaman Langganan untuk memakainya.";
    }

    /**
     * Modul yang datanya sudah berjalan di toko ini (tetap bisa dipakai di paket Gratis).
     * Asisten AI & KPI tidak punya pengecualian. Di-cache 5 menit per toko.
     *
     * @return list<string>
     */
    public static function modulesInUse(Store $store): array
    {
        return Cache::memo()->remember(self::cacheKey($store), now()->addMinutes(5), function () use ($store) {
            $inUse = [];

            if (Recipe::where('store_id', $store->id)->exists()) {
                $inUse[] = 'recipes';
            }
            if (ProductionBatch::where('store_id', $store->id)->exists()) {
                $inUse[] = 'productions';
            }
            if ($store->transactions()->whereIn('payment_status', ['unpaid', 'partial'])->exists()) {
                $inUse[] = 'receivables';
            }
            if (Table::where('store_id', $store->id)->exists()) {
                $inUse[] = 'scan_meja';
            }
            $hasRecentKitchenOrder = QueueEntry::where('store_id', $store->id)
                ->whereNotNull('transaction_id')
                ->where('created_at', '>=', now()->subDays(30))
                ->exists();
            if ($hasRecentKitchenOrder) {
                $inUse[] = 'kitchen_queue';
            }
            if (! empty($store->settings['accounting_enabled']) && JournalEntry::where('store_id', $store->id)->exists()) {
                $inUse[] = 'accounting';
            }

            return $inUse;
        });
    }

    public static function forgetCache(Store $store): void
    {
        Cache::memo()->forget(self::cacheKey($store));
    }

    private static function cacheKey(Store $store): string
    {
        return 'plan_modules:in_use:'.$store->id;
    }
}
