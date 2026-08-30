<?php

namespace App\Services;

use App\Models\AttendancePayrollPricing;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;

final class AttendancePayrollAddOnService
{
    public static function priceFor(string $billingCycle = 'monthly'): int
    {
        $pricing = AttendancePayrollPricing::current();

        return $billingCycle === 'yearly'
            ? (int) $pricing->yearly_price
            : (int) $pricing->monthly_price;
    }

    public static function isPurchased(?Store $store): bool
    {
        return $store !== null
            && self::attendancePayrollUntil($store) !== null
            && self::attendancePayrollUntil($store)->gte(now());
    }

    /**
     * Masa aktif efektif add-on Absensi + Payroll.
     * Add-on dibeli sekali per grup usaha (organization); semua outlet
     * dalam satu grup mewarisi masa aktif dari organization.
     */
    public static function attendancePayrollUntil(Store $store): ?\Illuminate\Support\Carbon
    {
        return $store->organization?->attendance_payroll_until
            ?? $store->attendance_payroll_until;
    }

    /**
     * Buat record pembayaran add-on Absensi + Payroll.
     *
     * @throws \Exception
     */
    public static function createPayment(Store $store, string $paymentMethod, ?string $notes, ?User $user = null, string $billingCycle = 'monthly'): SubscriptionPayment
    {
        if (! $store->is_active) {
            throw new \Exception('Toko tidak aktif.');
        }

        if (! in_array($billingCycle, ['monthly', 'yearly'], true)) {
            $billingCycle = 'monthly';
        }

        // Cegah double-submit: ada payment addon pending?
        $hasPending = SubscriptionPayment::where('store_id', $store->id)
            ->where('type', 'attendance_payroll_addon')
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            throw new \Exception('Anda sudah memiliki pembayaran paket Absensi + Payroll yang menunggu konfirmasi.');
        }

        $amount = self::priceFor($billingCycle);
        $durationDays = $billingCycle === 'yearly' ? 365 : 30;
        $userId = $user?->id ?? auth()->id();

        return SubscriptionPayment::create([
            'store_id' => $store->id,
            'user_id' => $userId,
            'type' => 'attendance_payroll_addon',
            'subscription_plan_id' => $store->subscription_plan_id,
            'amount' => $amount,
            'duration_days' => $durationDays,
            'payment_method' => $paymentMethod,
            'notes' => $notes ?? "Pembelian paket Absensi + Payroll ({$billingCycle})",
            'status' => 'pending',
        ]);
    }

    /**
     * Proses add-on setelah admin konfirmasi: set masa aktif paket.
     */
    public static function applyConfirmedAddOn(SubscriptionPayment $payment): void
    {
        $store = $payment->store;
        $days = (int) $payment->duration_days;
        $now = now();

        $current = self::attendancePayrollUntil($store);
        $until = $current && $current->gt($now)
            ? $current->addDays($days)
            : $now->addDays($days);

        // Beli sekali untuk semua outlet dalam satu grup usaha (organization).
        if ($store->organization_id) {
            $store->organization->update(['attendance_payroll_until' => $until]);
            \Log::info("Add-on Absensi + Payroll dikonfirmasi: grup {$store->organization->name} aktif sampai {$until->toDateString()} (semua outlet).");
        } else {
            $store->attendance_payroll_until = $until;
            $store->save();
            \Log::info("Add-on Absensi + Payroll dikonfirmasi: {$store->name} aktif sampai {$until->toDateString()}.");
        }
    }
}
