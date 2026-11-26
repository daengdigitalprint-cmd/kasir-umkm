<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Discount;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\StockTransfer;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TrialActivationLog;
use App\Models\TrialNotification;
use App\Models\User;
use App\Models\VoucherUsage;
use App\Support\PlanModules;
use App\Support\StorePlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trial Pro (paket GrowUp) dengan pola "reverse trial": toko pertama mulai di GrowUp,
 * lalu turun ke paket Gratis (Starter) tanpa kehilangan data.
 *
 * Urutan pesan ke pemilik toko (TrialNotification, tampil di dashboard):
 *   hari 0  trial_activated      sambutan + fitur yang bisa dicoba
 *   H-3     trial_recap          rekap pemakaian pribadi (transaksi, produk, fitur Pro yang dipakai)
 *   H-1     trial_expiring_soon  apa yang berubah besok + jaminan data aman
 *   hari 0  trial_expired        sudah di paket Gratis + penawaran tahunan (berlaku PROMO_GRACE_DAYS)
 *   H+3     trial_followup       pengingat terakhir, sekali saja
 */
final class TrialService
{
    public const TRIAL_DAYS = 14;

    /** Promo tahunan (paket promo-onboarding) masih bisa dibeli sekian hari setelah trial berakhir. */
    public const PROMO_GRACE_DAYS = 7;

    /** Pengingat terakhir dikirim sekian hari setelah trial berakhir. */
    public const FOLLOWUP_AFTER_DAYS = 3;

    public const RECAP_DAYS_BEFORE = 3;

    /** Jenis notifikasi yang ditampilkan ke pemilik toko di dashboard. */
    public const NOTICE_TYPES = ['trial_activated', 'trial_recap', 'trial_expiring_soon', 'trial_expired', 'trial_followup'];

    /**
     * Trial otomatis untuk toko baru. Hanya toko pertama pemilik yang mendapat trial;
     * toko berikutnya (atau pemilik yang pernah trial) langsung di paket Gratis.
     *
     * $days: lama trial khusus (mis. toko yang datang lewat link afiliasi); null = TRIAL_DAYS.
     *
     * @return bool true bila trial dimulai
     */
    public static function startForNewStore(Store $store, User $owner, ?int $days = null): bool
    {
        $days = max(1, $days ?? self::TRIAL_DAYS);

        $usedBefore = $owner->stores()
            ->where('stores.id', '!=', $store->id)
            ->where('pro_trial_used', true)
            ->exists();

        $growupPlan = self::growupPlan();

        if ($usedBefore || ! $growupPlan) {
            self::applyStarter($store, $usedBefore
                ? 'Registrasi: paket Gratis (trial Pro sudah dipakai di toko lain).'
                : 'Registrasi: paket Gratis (paket Pro belum tersedia).');

            return false;
        }

        $now = now();
        $trialEnd = $now->copy()->addDays($days);

        DB::transaction(function () use ($store, $growupPlan, $now, $trialEnd, $days) {
            $store->forceFill([
                'pro_trial_used' => true,
                'pro_trial_active' => true,
                'pro_trial_start' => $now,
                'pro_trial_end' => $trialEnd,
                'subscription_plan_id' => $growupPlan->id,
                'subscription_start' => $store->subscription_start ?? $now,
                'subscription_end' => $trialEnd,
                'subscription_days' => $days,
                'is_subscription_active' => true,
                'subscription_notes' => 'Registrasi: Trial Pro '.$days.' hari otomatis.',
            ])->save();

            TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => null,
                'action' => 'activated',
                'old_plan_slug' => null,
                'new_plan_slug' => $growupPlan->slug,
                'trial_start' => $now,
                'trial_end' => $trialEnd,
                'notes' => 'Trial Pro '.$days.' hari otomatis saat registrasi.',
            ]);

            self::welcome($store, $trialEnd, $days);
        });

        return true;
    }

    /**
     * Aktifkan trial paket Pro (GrowUp) secara manual oleh super admin untuk toko paket gratis.
     *
     * @throws \Exception jika validasi gagal
     */
    public static function activate(Store $store, User $admin): TrialActivationLog
    {
        self::assertCanActivate($store);

        $growupPlan = self::growupPlan();

        if (! $growupPlan) {
            throw new \Exception('Paket GrowUp (Pro) tidak tersedia di sistem.');
        }

        $oldPlanSlug = $store->subscriptionPlan?->slug;
        $now = now();
        $days = self::TRIAL_DAYS;

        return DB::transaction(function () use ($store, $admin, $growupPlan, $oldPlanSlug, $now, $days) {
            $trialEnd = $now->copy()->addDays($days);

            $store->update([
                'pro_trial_used' => true,
                'pro_trial_active' => true,
                'pro_trial_start' => $now,
                'pro_trial_end' => $trialEnd,
                'subscription_plan_id' => $growupPlan->id,
                'subscription_start' => $store->subscription_start ?? $now,
                'subscription_end' => $trialEnd,
                'subscription_days' => $days,
                'is_subscription_active' => true,
                'subscription_notes' => "Trial Pro {$days} hari diaktifkan oleh admin.",
            ]);

            $log = TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => $admin->id,
                'action' => 'activated',
                'old_plan_slug' => $oldPlanSlug,
                'new_plan_slug' => $growupPlan->slug,
                'trial_start' => $now,
                'trial_end' => $trialEnd,
                'notes' => "Trial Pro {$days} hari diaktifkan oleh {$admin->name}.",
            ]);

            self::welcome($store, $trialEnd, $days);

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
        $starterPlan = self::starterPlan();

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
                'body' => 'Masa trial Pro telah dihentikan oleh admin. Akun Anda kembali ke paket Gratis. '.self::DATA_SAFE,
            ]);

            return $log;
        });
    }

    /**
     * Trial selesai karena toko sudah berlangganan: status trial dimatikan TANPA menurunkan paket
     * dan tanpa mengubah masa aktif. Dipanggil saat masa aktif ditambah selagi trial (pembayaran
     * langganan dikonfirmasi, perpanjangan super admin, voucher), dan oleh expire() sebagai pengaman.
     *
     * @return TrialActivationLog|null null bila toko tidak sedang trial
     */
    public static function convertToPaid(Store $store, ?User $admin = null, ?string $note = null): ?TrialActivationLog
    {
        if (! $store->pro_trial_active) {
            return null;
        }

        $log = DB::transaction(function () use ($store, $admin, $note) {
            $store->forceFill(['pro_trial_active' => false])->save();

            $trialEnd = $store->pro_trial_end && $store->pro_trial_end->lt(now()) ? $store->pro_trial_end : now();

            $log = TrialActivationLog::create([
                'store_id' => $store->id,
                'admin_id' => $admin?->id,
                'action' => 'converted',
                'old_plan_slug' => 'bisnis',
                'new_plan_slug' => $store->subscriptionPlan()->value('slug'),
                'trial_start' => $store->pro_trial_start,
                'trial_end' => $trialEnd,
                'notes' => $note ?? 'Trial Pro selesai: toko sudah berlangganan.',
            ]);

            // Pesan trial yang belum dibaca (sambutan, rekap, "berakhir besok") tidak relevan lagi.
            TrialNotification::where('store_id', $store->id)
                ->whereIn('type', ['trial_activated', 'trial_recap', 'trial_expiring_soon'])
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return $log;
        });

        PlanModules::forgetCache($store);

        return $log;
    }

    /**
     * Toko sudah membayar sejak trialnya dimulai: ada pembayaran langganan yang dikonfirmasi
     * atau voucher yang dipakai. Toko seperti ini tidak boleh diturunkan ke paket Gratis.
     */
    public static function paidSinceTrialStart(Store $store): bool
    {
        $since = $store->pro_trial_start;

        return SubscriptionPayment::query()
            ->where('store_id', $store->id)
            ->where('type', 'subscription')
            ->where('status', 'confirmed')
            ->when($since, fn ($q) => $q->where('confirmed_at', '>=', $since))
            ->exists()
            || VoucherUsage::query()
                ->where('store_id', $store->id)
                ->when($since, fn ($q) => $q->where('used_at', '>=', $since))
                ->exists();
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

        $starterPlan = self::starterPlan();

        foreach ($expiredStores as $store) {
            self::expire($store, $starterPlan);
        }

        return $expiredStores->count();
    }

    /**
     * Pengingat bertahap: rekap H-3, peringatan H-1, dan pengingat terakhir H+3.
     * Tiap jenis hanya dikirim sekali per toko.
     *
     * @return int jumlah notifikasi yang dibuat
     */
    public static function sendReminders(): int
    {
        $created = 0;

        $active = Store::where('pro_trial_active', true)
            ->where('pro_trial_end', '>', now())
            ->where('pro_trial_end', '<=', now()->addDays(self::RECAP_DAYS_BEFORE))
            ->get();

        foreach ($active as $store) {
            if (self::paidSinceTrialStart($store)) {
                continue; // sudah berbayar: toko tidak akan turun ke paket Gratis
            }

            $hoursLeft = now()->diffInHours($store->pro_trial_end, false);

            if ($hoursLeft <= 24) {
                $created += self::notifyOnce($store, 'trial_expiring_soon', 'Trial Pro berakhir besok', self::expiringSoonBody($store));
            } else {
                $created += self::notifyOnce($store, 'trial_recap', 'Rekap trial Pro Anda', self::recapBody($store));
            }
        }

        $followups = Store::where('pro_trial_used', true)
            ->where('pro_trial_active', false)
            ->whereNotNull('pro_trial_end')
            ->where('pro_trial_end', '<=', now()->subDays(self::FOLLOWUP_AFTER_DAYS))
            ->where('pro_trial_end', '>=', now()->subDays(self::FOLLOWUP_AFTER_DAYS + 7))
            ->get();

        foreach ($followups as $store) {
            if (! PlanModules::onFreePlan($store)) {
                continue; // sudah berlangganan
            }
            $created += self::notifyOnce($store, 'trial_followup', 'Masih ingin memakai fitur Pro?', self::followupBody($store));
        }

        return $created;
    }

    /** Nama lama, dipertahankan untuk pemanggil yang sudah ada. */
    public static function sendExpiryReminders(): int
    {
        return self::sendReminders();
    }

    /**
     * Ringkasan pemakaian selama trial, untuk pesan pengingat yang personal.
     *
     * @return array{transactions: int, products: int, customers: int, max_products: int, max_customers: int, pro_features: list<string>}
     */
    public static function usage(Store $store): array
    {
        $since = $store->pro_trial_start ?? now()->subDays(self::TRIAL_DAYS);
        $starter = self::starterPlan();

        $proFeatures = [];
        $checks = [
            'shift kasir' => fn () => CashShift::where('store_id', $store->id)->exists(),
            'pembelian dari supplier' => fn () => Purchase::where('store_id', $store->id)->exists(),
            'varian produk' => fn () => ProductVariant::where('store_id', $store->id)->exists(),
            'diskon' => fn () => Discount::where('store_id', $store->id)->exists(),
            'transfer stok' => fn () => StockTransfer::where('from_store_id', $store->id)->orWhere('to_store_id', $store->id)->exists(),
        ];
        foreach ($checks as $label => $check) {
            if ($check()) {
                $proFeatures[] = $label;
            }
        }

        return [
            'transactions' => (int) $store->transactions()->where('created_at', '>=', $since)->count(),
            'products' => StorePlanLimits::currentProductCount($store),
            'customers' => StorePlanLimits::currentCustomerCount($store),
            'max_products' => (int) ($starter?->max_products ?? StorePlanLimits::STARTER_MAX_PRODUCTS),
            'max_customers' => (int) ($starter?->max_customers ?? 50),
            'pro_features' => $proFeatures,
        ];
    }

    /** Promo tahunan khusus pengguna baru: selama trial dan PROMO_GRACE_DAYS hari sesudahnya. */
    public static function promoEligible(Store $store): bool
    {
        if (! $store->pro_trial_used || ! $store->pro_trial_end) {
            return false;
        }

        if (! $store->pro_trial_active && $store->pro_trial_end->lt(now()->subDays(self::PROMO_GRACE_DAYS))) {
            return false;
        }

        return self::promoPlan() !== null;
    }

    public static function promoDeadline(Store $store): ?Carbon
    {
        return $store->pro_trial_end?->copy()->addDays(self::PROMO_GRACE_DAYS);
    }

    /**
     * Data trial untuk frontend (props bersama). null bila toko tidak pernah trial.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(Store $store): ?array
    {
        if (! $store->pro_trial_used || ! $store->pro_trial_end) {
            return null;
        }

        $notice = TrialNotification::query()
            ->where('store_id', $store->id)
            ->whereIn('type', self::NOTICE_TYPES)
            ->whereNull('read_at')
            ->latest('id')
            ->first();

        $active = (bool) $store->pro_trial_active && $store->pro_trial_end->isFuture();
        $promo = self::promoEligible($store);

        return [
            'active' => $active,
            // Lama trial toko ini (toko rujukan afiliasi bisa lebih panjang dari TRIAL_DAYS).
            'days_total' => $store->pro_trial_start
                ? max(1, (int) round($store->pro_trial_start->diffInDays($store->pro_trial_end)))
                : self::TRIAL_DAYS,
            'days_left' => $active ? max(1, (int) ceil(now()->diffInHours($store->pro_trial_end) / 24)) : 0,
            'ends_at' => $store->pro_trial_end->toISOString(),
            'ended' => ! $active,
            'on_free_plan' => PlanModules::onFreePlan($store),
            'promo_eligible' => $promo,
            'promo_deadline' => $promo ? self::promoDeadline($store)?->toISOString() : null,
            'notice' => $notice ? [
                'id' => $notice->id,
                'type' => $notice->type,
                'title' => $notice->title,
                'body' => $notice->body,
            ] : null,
        ];
    }

    // ───────────────────────── Teks pesan ─────────────────────────

    private const DATA_SAFE = 'Data Anda aman: semua produk, pelanggan, dan riwayat transaksi tetap ada dan tetap bisa dipakai berjualan.';

    private static function welcome(Store $store, Carbon $trialEnd, int $days = self::TRIAL_DAYS): void
    {
        TrialNotification::create([
            'store_id' => $store->id,
            'type' => 'trial_activated',
            'title' => 'Semua fitur Pro aktif '.$days.' hari',
            'body' => 'Selamat datang! Sampai '.self::tanggal($trialEnd).' Anda bisa mencoba semua fitur GrowUp tanpa biaya: '
                .'shift kasir, pembelian & stok opname, diskon & loyalitas, varian produk, scan QR meja & antrean dapur, dan produk tanpa batas. '
                .'Fitur bertanda Pro di menu adalah fitur GrowUp. Tidak perlu kartu atau pembayaran di awal.',
        ]);
    }

    private static function recapBody(Store $store): string
    {
        $u = self::usage($store);
        $days = max(1, (int) ceil(now()->diffInHours($store->pro_trial_end) / 24));

        $parts = ["Trial Pro tersisa {$days} hari. Selama trial, toko Anda mencatat {$u['transactions']} transaksi."];
        if ($u['pro_features']) {
            $parts[] = 'Fitur GrowUp yang sudah Anda pakai: '.self::joinList($u['pro_features']).'.';
        }
        if ($u['products'] > $u['max_products']) {
            $parts[] = "Anda punya {$u['products']} produk, sedangkan paket Gratis menampung {$u['max_products']}. Produk yang sudah ada tetap bisa dijual; hanya menambah produk baru yang dikunci.";
        }
        $parts[] = 'Lanjutkan Pro agar semua fitur tetap aktif.';

        return implode(' ', $parts);
    }

    private static function expiringSoonBody(Store $store): string
    {
        $u = self::usage($store);
        $parts = ['Besok toko Anda pindah ke paket Gratis.'];
        $lost = ['shift kasir', 'pembelian & stok opname', 'diskon & loyalitas', 'varian produk'];
        $parts[] = 'Yang berubah: fitur GrowUp seperti '.self::joinList($lost).' dikunci'
            .($u['products'] > $u['max_products'] ? ", dan produk baru tidak bisa ditambah di atas {$u['max_products']} produk" : '').'.';
        $parts[] = self::DATA_SAFE;

        return implode(' ', $parts);
    }

    private static function expiredBody(Store $store): string
    {
        $body = 'Trial Pro sudah selesai dan toko Anda sekarang di paket Gratis. '.self::DATA_SAFE;
        if (self::promoPlan()) {
            $deadline = self::tanggal(self::promoDeadline($store));
            $body .= " Khusus pengguna baru, paket tahunan GrowUp bisa diambil dengan bayar 8 bulan untuk 12 bulan sampai {$deadline}.";
        }

        return $body;
    }

    private static function followupBody(Store $store): string
    {
        $u = self::usage($store);
        $body = "Selama trial Anda mencatat {$u['transactions']} transaksi dengan fitur Pro.";
        if (self::promoEligible($store)) {
            $deadline = self::tanggal(self::promoDeadline($store));
            $body .= " Promo tahunan pengguna baru (bayar 8 bulan untuk 12 bulan) berakhir {$deadline}.";
        }

        return $body.' Ini pengingat terakhir dari kami; paket Gratis tetap bisa Anda pakai selamanya.';
    }

    // ───────────────────────── Internal ─────────────────────────

    private static function notifyOnce(Store $store, string $type, string $title, string $body): int
    {
        if (TrialNotification::where('store_id', $store->id)->where('type', $type)->exists()) {
            return 0;
        }

        TrialNotification::create(['store_id' => $store->id, 'type' => $type, 'title' => $title, 'body' => $body]);

        return 1;
    }

    private static function expire(Store $store, ?SubscriptionPlan $starterPlan = null): void
    {
        // Pengaman: toko yang sudah membayar selagi trial tetapi status trialnya belum dimatikan
        // (mis. pembayaran dikonfirmasi sebelum convertToPaid ada) tidak diturunkan. Cukup akhiri
        // status trialnya; paket dan masa aktif hasil pembayaran dibiarkan.
        if (self::paidSinceTrialStart($store)) {
            self::convertToPaid($store, null, 'Trial Pro selesai: toko sudah membayar selagi trial, paket tidak diturunkan.');

            return;
        }

        $starterPlan ??= self::starterPlan();

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
                'admin_id' => null,
                'action' => 'expired',
                'old_plan_slug' => 'bisnis',
                'new_plan_slug' => $starterPlan?->slug ?? 'starter',
                'trial_start' => $store->pro_trial_start,
                'trial_end' => $store->pro_trial_end,
                'notes' => 'Trial Pro berakhir otomatis.',
            ]);

            // Pesan lama yang belum dibaca tidak relevan lagi.
            TrialNotification::where('store_id', $store->id)
                ->whereIn('type', ['trial_activated', 'trial_recap', 'trial_expiring_soon'])
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            TrialNotification::create([
                'store_id' => $store->id,
                'type' => 'trial_expired',
                'title' => 'Trial Pro selesai',
                'body' => self::expiredBody($store),
            ]);
        });

        PlanModules::forgetCache($store);
    }

    private static function applyStarter(Store $store, string $note): void
    {
        $starter = self::starterPlan();

        $store->forceFill([
            'subscription_plan_id' => $starter?->id,
            'subscription_start' => $store->subscription_start ?? now(),
            'subscription_end' => null,
            'subscription_days' => $starter?->duration_days ?? 30,
            'is_subscription_active' => true,
            'subscription_notes' => $note,
        ])->save();
    }

    private static function growupPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'bisnis')->where('is_active', true)->first();
    }

    private static function starterPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'starter')
            ->where('is_active', true)
            ->orderByRaw("case when coalesce(billing_cycle, 'monthly') = 'monthly' then 0 else 1 end")
            ->orderBy('sort_order')
            ->first();
    }

    public static function promoPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'like', 'promo-onboarding%')
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();
    }

    /** Tanggal bahasa Indonesia, mis. "5 Desember 2026". */
    private static function tanggal(?Carbon $date): string
    {
        return $date ? $date->copy()->locale('id')->translatedFormat('j F Y') : '';
    }

    /** @param  list<string>  $items */
    private static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' dan '.$last;
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
