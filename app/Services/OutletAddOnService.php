<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;

final class OutletAddOnService
{
    public const PRICE_PER_OUTLET_MONTHLY = 85000;

    public static function priceFor(int $outlets, string $billingCycle = 'monthly'): float
    {
        if ($billingCycle === 'yearly') {
            return self::PRICE_PER_OUTLET_MONTHLY * 12 * $outlets;
        }

        return self::PRICE_PER_OUTLET_MONTHLY * $outlets;
    }

    /**
     * Buat record pembayaran add-on outlet.
     *
     * @throws \Exception
     */
    public static function createPayment(Store $store, int $outlets, string $paymentMethod, ?string $notes, ?User $user = null, ?string $billingCycle = null): SubscriptionPayment
    {
        if ($outlets < 1) {
            throw new \Exception('Minimal 1 outlet tambahan.');
        }

        if (! $store->is_active) {
            throw new \Exception('Toko tidak aktif.');
        }

        // Cek apakah toko pakai paket GrowUp
        $planSlug = $store->subscriptionPlan?->slug;
        if (! $planSlug || in_array($planSlug, ['starter', 'starter-tahunan'], true)) {
            throw new \Exception('Beli outlet tambahan hanya tersedia untuk paket GrowUp. Upgrade paket terlebih dahulu.');
        }

        // Cegah double-submit: ada payment addon pending?
        $hasPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('type', 'outlet_addon')
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            throw new \Exception('Anda sudah memiliki pembayaran add-on outlet yang menunggu konfirmasi.');
        }

        // Billing cycle: dari param, atau fallback ke subscription saat ini
        if (! $billingCycle || ! in_array($billingCycle, ['monthly', 'yearly'], true)) {
            $billingCycle = str_ends_with($planSlug, '-tahunan') ? 'yearly' : 'monthly';
        }

        $amount = self::priceFor($outlets, $billingCycle);
        $durationDays = $billingCycle === 'yearly' ? 365 : 30;
        $userId = $user?->id ?? auth()->id();

        return SubscriptionPayment::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'type' => 'outlet_addon',
            'extra_outlets_count' => $outlets,
            'subscription_plan_id' => $store->subscription_plan_id,
            'amount' => $amount,
            'duration_days' => $durationDays,
            'payment_method' => $paymentMethod,
            'notes' => $notes ?? "Pembelian {$outlets} outlet tambahan",
            'status' => 'pending',
        ]);
    }

    /**
     * Proses add-on: increment extra_outlets + extend subscription (dipanggil dari controller setelah confirm).
     */
    public static function applyConfirmedAddOn(SubscriptionPayment $payment): void
    {
        $store = $payment->store;
        $store->increment('extra_outlets', (int) $payment->extra_outlets_count);
        $store->extendSubscription((int) $payment->duration_days);

        \Log::info("Outlet add-on dikonfirmasi: {$store->name} +{$payment->extra_outlets_count} outlet oleh admin.");
    }
}
