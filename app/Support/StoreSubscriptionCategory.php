<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kategori langganan untuk ringkasan super admin: Premium, Trial Premium, atau Free,
 * dihitung PER GRUP USAHA. Semua toko dalam satu grup usaha (organization_id sama)
 * dihitung satu; toko tanpa grup usaha dihitung sendiri.
 *
 * Kategori tiap toko (aturan dicek berurutan, yang pertama cocok dipakai):
 *   1. trial   : trial Pro sedang berjalan (pro_trial_active dan pro_trial_end belum lewat),
 *                sama dengan "active" di TrialService::payload().
 *   2. premium : bukan trial, langganan aktif (Store::isSubscriptionActive()) di paket selain
 *                Starter, sama dengan StorePlanLimits::allowsAdvancedRetailFeatures().
 *   3. free    : sisanya. Paket Starter gratis, plus toko yang paket berbayar atau trialnya
 *                sudah habis dan belum diperpanjang (tidak punya akses fitur berbayar).
 *
 * Kategori grup = kategori tertinggi di antara tokonya: premium > trial > free. Jadi grup
 * dengan satu outlet berbayar tetap Premium walau outlet lainnya gratis, sejalan dengan
 * StorePlanLimits::maxOutletsAllowedForOrganization() yang memakai paket terbaik di grup.
 *
 * Tiap grup mendapat tepat satu peringkat, jadi ketiga kategori saling lepas dan jumlahnya
 * selalu sama dengan jumlah grup usaha.
 */
final class StoreSubscriptionCategory
{
    public const PREMIUM = 'premium';

    public const TRIAL = 'trial';

    public const FREE = 'free';

    public const ALL = [self::PREMIUM, self::TRIAL, self::FREE];

    /** Saringan daftar: grup yang punya minimal satu toko yang tidak dinonaktifkan. */
    public const FILTER_ACTIVE = 'aktif';

    /** Peringkat kategori; grup memakai peringkat tertinggi di antara tokonya. */
    private const RANK = [self::FREE => 1, self::TRIAL => 2, self::PREMIUM => 3];

    /** Sama dengan daftar slug Starter di StorePlanLimits::allowsAdvancedRetailFeatures(). */
    private const STARTER_SLUGS = ['starter', 'starter-tahunan'];

    /** Satu nilai per grup usaha; toko tanpa grup memakai id-nya sendiri (negatif agar tidak bentrok). */
    private const GROUP_KEY = 'coalesce(stores.organization_id, -stores.id)';

    /**
     * Ringkasan per grup usaha.
     *
     * free_lapsed: grup Free yang punya toko berpaket selain Starter (langganan/trial habis).
     * active: grup dengan minimal satu toko yang tidak dinonaktifkan.
     *
     * @return array{premium: int, trial: int, free: int, free_lapsed: int, total: int, active: int}
     */
    public static function summary(): array
    {
        $rows = self::groups()
            ->selectRaw('category_rank, count(*) as total, sum(has_paid_plan) as paid_plan')
            ->selectRaw('sum(case when active_count > 0 then 1 else 0 end) as active')
            ->groupBy('category_rank')
            ->get()
            ->keyBy(fn ($row) => (int) $row->category_rank);

        $count = fn (string $category, string $column = 'total') => (int) ($rows[self::RANK[$category]]->{$column} ?? 0);

        return [
            self::PREMIUM => $count(self::PREMIUM),
            self::TRIAL => $count(self::TRIAL),
            self::FREE => $count(self::FREE),
            'free_lapsed' => $count(self::FREE, 'paid_plan'),
            'total' => (int) $rows->sum('total'),
            'active' => (int) $rows->sum('active'),
        ];
    }

    /**
     * Satu baris per grup usaha: group_key, category_rank, has_paid_plan, stores_count,
     * active_count, latest_created_at. Saringan opsional: salah satu kategori, atau FILTER_ACTIVE.
     *
     * Disajikan sebagai tabel turunan, bukan HAVING: MySQL (only_full_group_by) menolak
     * HAVING berisi subquery berkorelasi.
     */
    public static function groups(?string $filter = null): Builder
    {
        [$rankSql, $rankBindings] = self::rankExpression();
        $starter = self::placeholders(self::STARTER_SLUGS);

        $perGroup = Store::query()
            ->toBase()
            ->selectRaw(self::GROUP_KEY.' as group_key')
            ->selectRaw("max({$rankSql}) as category_rank", $rankBindings)
            ->selectRaw(
                "max(case when exists (
                    select 1 from subscription_plans
                    where subscription_plans.id = stores.subscription_plan_id
                        and subscription_plans.slug not in ({$starter})
                ) then 1 else 0 end) as has_paid_plan",
                self::STARTER_SLUGS,
            )
            ->selectRaw('count(*) as stores_count')
            ->selectRaw('sum(case when stores.is_active = 1 then 1 else 0 end) as active_count')
            ->selectRaw('max(stores.created_at) as latest_created_at')
            ->groupByRaw(self::GROUP_KEY);

        return DB::query()
            ->fromSub($perGroup, 'store_groups')
            ->when(isset(self::RANK[$filter ?? '']), fn (Builder $q) => $q->where('category_rank', self::RANK[$filter]))
            ->when($filter === self::FILTER_ACTIVE, fn (Builder $q) => $q->where('active_count', '>', 0));
    }

    /**
     * Saring query toko ke anggota grup-grup tertentu.
     *
     * @param  iterable<int>  $groupKeys  nilai group_key dari groups()
     */
    public static function whereInGroups(EloquentBuilder $query, iterable $groupKeys): EloquentBuilder
    {
        return $query->whereIn(DB::raw(self::GROUP_KEY), collect($groupKeys)->all());
    }

    /** group_key milik sebuah toko (padanan GROUP_KEY di sisi PHP). */
    public static function groupKey(Store $store): int
    {
        return (int) ($store->organization_id ?? -$store->id);
    }

    /** Nama kategori dari kolom category_rank. */
    public static function fromRank(int $rank): string
    {
        return array_search($rank, self::RANK, true) ?: self::FREE;
    }

    /**
     * Peringkat kategori satu toko sebagai ekspresi SQL. Ditulis sebagai CASE supaya baris
     * dengan kolom NULL tetap jatuh ke tepat satu cabang.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function rankExpression(): array
    {
        $now = now();
        $starter = self::placeholders(self::STARTER_SLUGS);
        ['trial' => $trial, 'premium' => $premium, 'free' => $free] = self::RANK;

        $sql = <<<SQL
            (case
                when stores.pro_trial_active = 1 and stores.pro_trial_end > ? then {$trial}
                when stores.is_subscription_active = 1
                    and (stores.subscription_end is null or stores.subscription_end >= ?)
                    and (
                        exists (
                            select 1 from subscription_plans
                            where subscription_plans.id = stores.subscription_plan_id
                                and subscription_plans.slug not in ({$starter})
                        )
                        or (
                            stores.subscription_end is null
                            and not exists (
                                select 1 from subscription_plans
                                where subscription_plans.id = stores.subscription_plan_id
                            )
                        )
                    ) then {$premium}
                else {$free}
            end)
            SQL;

        return [$sql, [$now, $now, ...self::STARTER_SLUGS]];
    }

    /** @param  list<string>  $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
