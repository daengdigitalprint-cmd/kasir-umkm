<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Store;
use App\Models\UpsellRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F8 — Saran tambahan menu (upsell).
 *
 * Peta "produk pemicu → produk yang ditawarkan" untuk kasir dan Scan Meja:
 * aturan manual lebih dulu, lalu (bila dinyalakan) pasangan yang sering dibeli
 * bersama dalam 30 hari terakhir. Maksimal tiga saran per produk.
 */
final class UpsellSuggestions
{
    public const MAX_PER_PRODUCT = 3;

    /** Minimal berapa transaksi berisi pasangan yang sama sebelum dianggap "sering dibeli bersama". */
    public const MIN_TOGETHER = 3;

    public const LOOKBACK_DAYS = 30;

    public static function enabled(?Store $store): bool
    {
        return PlanModules::settingOn($store, 'upsell_enabled');
    }

    public static function autoEnabled(Store $store): bool
    {
        return (bool) ($store->settings['upsell_auto_enabled'] ?? true);
    }

    /**
     * Peta untuk frontend: [trigger_product_id => [suggested_product_id, ...]].
     * Kosong bila modul mati.
     *
     * @return array<int, list<int>>
     */
    public static function map(?Store $store): array
    {
        if (! $store || ! self::enabled($store)) {
            return [];
        }

        return Cache::remember(self::cacheKey($store->id), now()->addHours(6), fn () => self::build($store));
    }

    public static function forget(int $storeId): void
    {
        Cache::forget(self::cacheKey($storeId));
    }

    /** @return array<int, list<int>> */
    public static function build(Store $store): array
    {
        $sellable = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('inventory_type', InventoryType::SELLABLE)
            ->pluck('id')
            ->flip();

        $map = [];
        $push = function (int $trigger, int $suggested) use (&$map, $sellable) {
            if ($trigger === $suggested || ! $sellable->has($suggested)) {
                return;
            }
            $map[$trigger] ??= [];
            if (count($map[$trigger]) < self::MAX_PER_PRODUCT && ! in_array($suggested, $map[$trigger], true)) {
                $map[$trigger][] = $suggested;
            }
        };

        UpsellRule::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('trigger_product_id')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['trigger_product_id', 'suggested_product_id'])
            ->each(fn ($rule) => $push((int) $rule->trigger_product_id, (int) $rule->suggested_product_id));

        if (self::autoEnabled($store)) {
            foreach (self::frequentPairs($store) as $pair) {
                $push((int) $pair->trigger_id, (int) $pair->suggested_id);
            }
        }

        return $map;
    }

    /**
     * Pasangan produk yang muncul di transaksi yang sama (30 hari terakhir), terbanyak dulu.
     *
     * @return \Illuminate\Support\Collection<int, object{trigger_id: int, suggested_id: int, together: int}>
     */
    public static function frequentPairs(Store $store, int $limit = 2000)
    {
        return DB::table('transaction_items as a')
            ->join('transaction_items as b', function ($join) {
                $join->on('a.transaction_id', '=', 'b.transaction_id')
                    ->whereColumn('a.product_id', '<>', 'b.product_id');
            })
            ->join('transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('t.store_id', $store->id)
            ->where('t.status', 'completed')
            ->where('t.is_void', false)
            ->where('t.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->groupBy('a.product_id', 'b.product_id')
            ->havingRaw('COUNT(DISTINCT a.transaction_id) >= ?', [self::MIN_TOGETHER])
            ->orderByDesc('together')
            ->orderBy('a.product_id')
            ->orderBy('b.product_id')
            ->limit($limit)
            ->get([
                'a.product_id as trigger_id',
                'b.product_id as suggested_id',
                DB::raw('COUNT(DISTINCT a.transaction_id) as together'),
            ]);
    }

    private static function cacheKey(int $storeId): string
    {
        return "upsell_map:{$storeId}";
    }
}
