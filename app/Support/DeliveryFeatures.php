<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/** F10 — Modul Pesanan Antar mandiri (kurir sendiri). */
final class DeliveryFeatures
{
    public static function enabled(?Store $store): bool
    {
        return PlanModules::settingOn($store, 'delivery_enabled');
    }

    public static function defaultFee(Store $store): float
    {
        return max(0.0, (float) ($store->settings['delivery_default_fee'] ?? 0));
    }

    /**
     * Karyawan toko yang bisa dipilih sebagai kurir (anggota toko aktif).
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public static function couriers(Store $store): Collection
    {
        return User::query()
            ->where(fn ($q) => $q->where('store_id', $store->id)
                ->orWhereIn('id', fn ($sub) => $sub->select('user_id')->from('store_user')->where('store_id', $store->id)))
            ->where(fn ($q) => $q->whereNull('is_super_admin')->orWhere('is_super_admin', false))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }

    public static function isCourierOf(Store $store, int $userId): bool
    {
        return self::couriers($store)->contains('id', $userId);
    }
}
