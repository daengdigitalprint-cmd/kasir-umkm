<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\TrialActivationLog;
use App\Models\TrialNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TrialService
{
    public const TRIAL_DAYS = 7;

    /**
     * Aktifkan trial 7 hari paket Pro (GrowUp) untuk toko yang pakai paket gratis.
     *
     * @throws \Exception jika validasi gagal
     */
    public static function activate(Store $store, User $admin): TrialActivationLog
    {
        self::assertCanActivate($store);

        $growupPlan = SubscriptionPlan::where('slug', 'bisnis')
            ->where('is_active', true)
            ->first();

        if (! $growupPlan) {
            throw new \Exception('Paket GrowUp (Pro) tidak tersedia di sistem.');
        }

        $oldPlanSlug = $store->subscriptionPlan?->slug;
        $now = now();

        return DB::transaction(function () use ($store, $admin, $growupPlan, $oldPlanSlug, $now) {
            $trialEnd = $now->copy()->addDays(self::TRIAL_DAYS);

            $store->update([
                'pro_trial_used' => true,
                'pro_trial_active' => true,
                'pro_trial_start' => $now,
                'pro_trial_end' => $trialEnd,
                'subscription_plan_id' => $growupPlan->id,
                'subscription_start' => $store->subscription_start ?? $now,
                'subscription_end' => $trialEnd,
                'subscription_days' => self::TRIAL_DAYS,
                'is_subscription_active' => true,
                'subscription_notes' => 'Trial Pro 7 hari diaktifkan oleh admin.',
            ]);

            $log = TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => $admin->id,
                'action' => 'activated',
                'old_plan_slug' => $oldPlanSlug,
                'new_plan_slug' => $growupPlan->slug,
                'trial_start' => $now,
                'trial_end' => $trialEnd,
                'notes' => "Trial Pro 7 hari diaktifkan oleh {$admin->name}.",
            ]);

            // Database notification untuk store
            TrialNotification::create([
                'store_id' => $store->id,
                'type' => 'trial_activated',
                'title' => 'Trial Pro Diaktifkan',
                'body' => "Akun Anda telah di-upgrade ke paket GrowUp (Pro) selama 7 hari oleh admin. Masa trial berakhir pada {$trialEnd->translatedFormat('d M Y H:i')}.",
            ]);

            return $log;
        });
    }

    /**
     * Hentikan trial secara manual sebelum masa berakhir.
     */
    public static function stop(Store $store, User $admin): TrialActivationLog
    {
        if (! $store->pro_trial_active) {
            throw new \Exception('Toko ini tidak memiliki trial Pro yang aktif.');
        }

        $oldPlanSlug = $store->subscriptionPlan?->slug;
        $starterPlan = SubscriptionPlan::where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();

        return DB::transaction(function () use ($store, $admin, $starterPlan, $oldPlanSlug) {
            $store->update([
                'pro_trial_active' => false,
                'subscription_plan_id' => $starterPlan?->id,
                'subscription_end' => null,
                'subscription_days' => $starterPlan?->duration_days ?? 30,
                'is_subscription_active' => true,
                'subscription_notes' => "Trial Pro dihentikan manual oleh {$admin->name}. Kembali ke paket Starter.",
            ]);

            $log = TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => $admin->id,
                'action' => 'stopped',
                'old_plan_slug' => $oldPlanSlug,
                'new_plan_slug' => $starterPlan?->slug ?? 'starter',
                'trial_start' => $store->pro_trial_start,
                'trial_end' => now(),
                'notes' => "Trial Pro dihentikan manual oleh {$admin->name}.",
            ]);

            TrialNotification::create([
                'store_id' => $store->id,
                'type' => 'trial_stopped',
                'title' => 'Trial Pro Dihentikan',
                'body' => 'Masa trial Pro telah dihentikan oleh admin. Akun Anda kembali ke paket Starter.',
            ]);

            return $log;
        });
    }

    /**
     * Proses auto-expire untuk semua trial yang sudah lewat waktu.
     */
    public static function expireExpiredTrials(): int
    {
        $expiredStores = Store::where('pro_trial_active', true)
            ->where('pro_trial_end', '<', now())
            ->get();

        if ($expiredStores->isEmpty()) {
            return 0;
        }

        // Load plan sekali saja untuk semua toko
        $starterPlan = SubscriptionPlan::where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();

        $count = 0;

        foreach ($expiredStores as $store) {
            self::expire($store, $starterPlan);
            $count++;
        }

        return $count;
    }

    /**
     * Kirim notifikasi reminder 24 jam sebelum trial berakhir.
     */
    public static function sendExpiryReminders(): int
    {
        $expiringStores = Store::where('pro_trial_active', true)
            ->where('pro_trial_end', '>', now())
            ->where('pro_trial_end', '<=', now()->addDay())
            ->with('users')
            ->get();

        if ($expiringStores->isEmpty()) {
            return 0;
        }

        // Batch-load recent reminders to avoid N+1 queries
        $storeIds = $expiringStores->pluck('id')->values();
        $recentReminderStoreIds = TrialNotification::query()
            ->whereIn('store_id', $storeIds)
            ->where('type', 'trial_expiring_soon')
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subHours(12))
            ->pluck('store_id')
            ->flip();

        foreach ($expiringStores as $store) {
            if ($recentReminderStoreIds->has($store->id)) {
                continue;
            }

            $hoursLeft = now()->diffInHours($store->pro_trial_end, false);
            $hoursText = max(1, (int) $hoursLeft);

            TrialNotification::create([
                'store_id' => $store->id,
                'type' => 'trial_expiring_soon',
                'title' => 'Trial Pro Akan Berakhir',
                'body' => "Masa trial Pro Anda akan berakhir dalam {$hoursText} jam. Silakan perpanjang langganan agar fitur premium tetap aktif.",
            ]);

            // Log untuk audit trail
            \Log::info("Trial reminder: Toko {$store->name} (ID: {$store->id}) trial akan berakhir pada {$store->pro_trial_end}.");
        }

        return $expiringStores->count();
    }

    private static function expire(Store $store, ?\App\Models\SubscriptionPlan $starterPlan = null): void
    {
        if (! $starterPlan) {
            $starterPlan = SubscriptionPlan::where('slug', 'starter')
                ->where('is_active', true)
                ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
                ->orderBy('sort_order')
                ->first();
        }

        DB::transaction(function () use ($store, $starterPlan) {
            $store->update([
                'pro_trial_active' => false,
                'subscription_plan_id' => $starterPlan?->id,
                'subscription_end' => null,
                'subscription_days' => $starterPlan?->duration_days ?? 30,
                'is_subscription_active' => true,
                'subscription_notes' => 'Trial Pro telah berakhir. Kembali ke paket Starter.',
            ]);

            TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => null, // system / automated
                'action' => 'expired',
                'old_plan_slug' => 'bisnis',
                'new_plan_slug' => $starterPlan?->slug ?? 'starter',
                'trial_start' => $store->pro_trial_start,
                'trial_end' => $store->pro_trial_end,
                'notes' => 'Trial Pro berakhir otomatis.',
            ]);

            TrialNotification::create([
                'store_id' => $store->id,
                'type' => 'trial_expired',
                'title' => 'Trial Pro Berakhir',
                'body' => 'Masa trial Pro telah berakhir. Akun Anda telah kembali ke paket Starter.',
            ]);
        });
    }

    private static function assertCanActivate(Store $store): void
    {
        if (! $store->is_active) {
            throw new \Exception('Toko tidak aktif. Aktifkan toko terlebih dahulu sebelum mengaktifkan trial.');
        }

        if ($store->pro_trial_used) {
            throw new \Exception('Toko ini sudah pernah menggunakan trial Pro. Trial hanya dapat digunakan satu kali.');
        }

        if ($store->pro_trial_active) {
            throw new \Exception('Toko ini sedang dalam masa trial Pro aktif.');
        }
    }
}
