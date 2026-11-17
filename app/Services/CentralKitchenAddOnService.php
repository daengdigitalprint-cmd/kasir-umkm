<?php

namespace App\Services;

use App\Models\CentralKitchenPricing;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\CentralKitchenModule;

/**
 * R2 — Pembelian add-on Gudang Pusat Pro. Dibeli sekali per grup usaha;
 * semua lokasi dalam grup ikut aktif (organizations.central_kitchen_until).
 */
final class CentralKitchenAddOnService
{
    public const PAYMENT_TYPE = 'central_kitchen_addon';

    public static function priceFor(string $billingCycle = 'monthly'): int
    {
        $pricing = CentralKitchenPricing::current();

        return $billingCycle === 'yearly' ? (int) $pricing->yearly_price : (int) $pricing->monthly_price;
    }

    public static function hasPending(Store $store): bool
    {
        return self::pendingQuery($store)->exists();
    }

    /**
     * Pembayaran tertunda untuk grup usaha ini (dari lokasi mana pun dalam grup),
     * supaya dua outlet tidak membayar modul yang sama bersamaan.
     */
    private static function pendingQuery(Store $store)
    {
        return SubscriptionPayment::query()
            ->where('type', self::PAYMENT_TYPE)
            ->where('status', 'pending')
            ->whereIn('store_id', Store::query()->where('organization_id', $store->organization_id)->select('id'));
    }

    /** @return array<string, mixed> */
    public static function info(Store $store): array
    {
        return [
            'is_purchased' => CentralKitchenModule::enabled($store),
            'expires_at' => $store->organization?->central_kitchen_until,
            'pricing' => CentralKitchenPricing::current()->toPayload(),
            'has_pending_addon' => $store->organization_id !== null && self::hasPending($store),
            'has_organization' => $store->organization_id !== null,
        ];
    }

    /**
     * @throws \Exception
     */
    public static function createPayment(Store $store, string $paymentMethod, ?string $notes, ?User $user = null, string $billingCycle = 'monthly'): SubscriptionPayment
    {
        if (! $store->is_active) {
            throw new \Exception('Toko tidak aktif.');
        }
        if ($store->organization_id === null) {
            throw new \Exception('Gudang Pusat Pro butuh grup usaha dengan lebih dari satu lokasi. Buat grup usaha dan tambahkan outlet/Gudang Pusat dulu.');
        }
        if (! in_array($billingCycle, ['monthly', 'yearly'], true)) {
            $billingCycle = 'monthly';
        }
        if (self::hasPending($store)) {
            throw new \Exception('Grup usaha ini sudah memiliki pembayaran Gudang Pusat Pro yang menunggu konfirmasi.');
        }

        return SubscriptionPayment::create([
            'store_id' => $store->id,
            'user_id' => $user?->id ?? auth()->id(),
            'type' => self::PAYMENT_TYPE,
            'subscription_plan_id' => $store->subscription_plan_id,
            'amount' => self::priceFor($billingCycle),
            'duration_days' => $billingCycle === 'yearly' ? 365 : 30,
            'payment_method' => $paymentMethod,
            'notes' => $notes ?? "Pembelian Gudang Pusat Pro ({$billingCycle})",
            'status' => 'pending',
        ]);
    }

    /** Setelah Super Admin konfirmasi: perpanjang masa aktif modul untuk seluruh grup. */
    public static function applyConfirmedAddOn(SubscriptionPayment $payment): void
    {
        $organization = $payment->store?->organization;
        if (! $organization) {
            throw new \RuntimeException('Toko pembayar tidak lagi berada dalam grup usaha.');
        }

        $days = (int) $payment->duration_days;
        $current = $organization->central_kitchen_until;
        $until = $current && $current->isFuture() ? $current->copy()->addDays($days) : now()->addDays($days);

        $organization->update(['central_kitchen_until' => $until]);
        \Log::info("Gudang Pusat Pro dikonfirmasi: grup {$organization->name} aktif sampai {$until->toDateString()}.");
    }
}
