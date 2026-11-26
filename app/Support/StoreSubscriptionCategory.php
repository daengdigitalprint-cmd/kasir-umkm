<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
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

    /** Peringkat kategori; grup memakai peringkat tertinggi di antara tokonya. */
    private const RANK = [self::FREE => 1, self::TRIAL => 2, self::PREMIUM => 3];

    /** Sama dengan daftar slug Starter di StorePlanLimits::allowsAdvancedRetailFeatures(). */
    private const STARTER_SLUGS = ['starter', 'starter-tahunan'];

    /** Satu nilai per grup usaha; toko tanpa grup memakai id-nya sendiri (negatif agar tidak bentrok). */
    private const GROUP_KEY = 'coalesce(stores.organization_id, -stores.id)';

    /**
     * Ringkasan per grup usaha.
     *
     * @return array{premium: int, trial: int, free: int, free_lapsed: int, total: int, active: int}
     *                                                                                               free_lapsed: grup Free yang punya toko berpaket selain Starter (langganan/trial habis).
     *                                                                                               active: grup dengan minimal satu toko yang tidak dinonaktifkan.
     */
    public static function summary(): array
    {
        [$rankSql, $rankBindings] = self::rankExpression();
        $starter = self::placeholders(self::STARTER_SLUGS);

        $perGroup = Store::query()
            ->toBase()
            ->selectRaw("max({$rankSql}) as category_rank", $rankBindings)
            ->selectRaw(
                "max(case when exists (
                    select 1 from subscription_plans
                    where subscription_plans.id = stores.subscription_plan_id
                        and subscription_plans.slug not in ({$starter})
                ) then 1 else 0 end) as has_paid_plan",
                self::STARTER_SLUGS,
            )
            ->selectRaw('max(case when stores.is_active = 1 then 1 else 0 end) as has_active')
            ->groupByRaw(self::GROUP_KEY);

        $rows = DB::query()
            ->fromSub($perGroup, 'store_groups')
            ->selectRaw('category_rank, count(*) as total, sum(has_paid_plan) as paid_plan, sum(has_active) as active')
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

    /** Saring query toko ke toko-toko milik grup usaha berkategori tertentu. */
    public static function apply(Builder $query, string $category): Builder
    {
        [$rankSql, $rankBindings] = self::rankExpression();

        // Lewat tabel turunan, bukan HAVING: MySQL (only_full_group_by) menolak HAVING berisi subquery berkorelasi.
        $perGroup = Store::query()
            ->toBase()
            ->selectRaw(self::GROUP_KEY.' as group_key')
            ->selectRaw("max({$rankSql}) as category_rank", $rankBindings)
            ->groupByRaw(self::GROUP_KEY);

        $groups = DB::query()
            ->fromSub($perGroup, 'store_groups')
            ->select('group_key')
            ->where('category_rank', self::RANK[$category]);

        return $query->whereIn(DB::raw(self::GROUP_KEY), $groups);
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
