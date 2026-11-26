<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kategori langganan toko untuk ringkasan super admin: Premium, Trial Premium, atau Free.
 *
 * Tiap toko masuk tepat satu kategori; aturan dicek berurutan dan yang pertama cocok dipakai:
 *   1. trial   : trial Pro sedang berjalan (pro_trial_active dan pro_trial_end belum lewat),
 *                sama dengan "active" di TrialService::payload().
 *   2. premium : bukan trial, langganan aktif (Store::isSubscriptionActive()) di paket selain
 *                Starter, sama dengan StorePlanLimits::allowsAdvancedRetailFeatures().
 *   3. free    : sisanya. Paket Starter gratis, plus toko yang paket berbayar atau trialnya
 *                sudah habis dan belum diperpanjang (tidak punya akses fitur berbayar).
 *
 * Dihitung lewat satu ekspresi CASE supaya kategori pasti saling lepas dan jumlahnya
 * selalu sama dengan total toko, termasuk untuk baris dengan kolom NULL.
 */
final class StoreSubscriptionCategory
{
    public const PREMIUM = 'premium';

    public const TRIAL = 'trial';

    public const FREE = 'free';

    public const ALL = [self::PREMIUM, self::TRIAL, self::FREE];

    /** Sama dengan daftar slug Starter di StorePlanLimits::allowsAdvancedRetailFeatures(). */
    private const STARTER_SLUGS = ['starter', 'starter-tahunan'];

    /**
     * Jumlah toko per kategori.
     *
     * @return array{premium: int, trial: int, free: int}
     */
    public static function counts(): array
    {
        [$sql, $bindings] = self::caseExpression();

        $rows = Store::query()
            ->toBase()
            ->selectRaw("{$sql} as category, count(*) as total", $bindings)
            ->groupBy('category')
            ->pluck('total', 'category');

        return [
            self::PREMIUM => (int) ($rows[self::PREMIUM] ?? 0),
            self::TRIAL => (int) ($rows[self::TRIAL] ?? 0),
            self::FREE => (int) ($rows[self::FREE] ?? 0),
        ];
    }

    /** Saring query toko ke satu kategori. */
    public static function apply(Builder $query, string $category): Builder
    {
        [$sql, $bindings] = self::caseExpression();

        return $query->whereRaw("{$sql} = ?", [...$bindings, $category]);
    }

    /**
     * Toko kategori Free yang paketnya bukan Starter: paket berbayar atau trialnya sudah
     * habis dan belum diperpanjang. Dipakai untuk keterangan di kartu Free.
     */
    public static function applyFreeLapsed(Builder $query): Builder
    {
        return self::apply($query, self::FREE)
            ->whereHas('subscriptionPlan', fn ($plan) => $plan->whereNotIn('slug', self::STARTER_SLUGS));
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function caseExpression(): array
    {
        $now = now();
        $starter = implode(', ', array_fill(0, count(self::STARTER_SLUGS), '?'));

        $sql = <<<SQL
            (case
                when stores.pro_trial_active = 1 and stores.pro_trial_end > ? then 'trial'
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
                    ) then 'premium'
                else 'free'
            end)
            SQL;

        return [$sql, [$now, $now, ...self::STARTER_SLUGS]];
    }
}
