<?php

namespace App\Modules\Payroll\Support;

use App\Models\Store;
use App\Models\User;

/**
 * Aturan siapa yang boleh menyetujui gaji.
 *
 * Bila "Gaji disetujui pemilik" aktif, admin/manajer hanya menghitung dan memeriksa;
 * persetujuan (yang membekukan angka & membuka slip) hanya oleh owner toko.
 * Disimpan di stores.settings['payroll']['owner_approval'].
 */
final class PayrollApproval
{
    public static function ownerOnly(Store $store): bool
    {
        return (bool) data_get($store->settings, 'payroll.owner_approval', false);
    }

    public static function setOwnerOnly(Store $store, bool $value): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $settings['payroll'] = array_merge(is_array($settings['payroll'] ?? null) ? $settings['payroll'] : [], ['owner_approval' => $value]);
        $store->settings = $settings;
        $store->save();
    }

    /** Toko punya akun owner (aturan hanya berlaku bila ada owner yang bisa menyetujui). */
    public static function hasOwner(Store $store): bool
    {
        return $store->members()->wherePivot('role', 'owner')->exists();
    }

    public static function canApprove(User $user, Store $store): bool
    {
        if (! self::ownerOnly($store) || $user->isSuperAdmin() || ! self::hasOwner($store)) {
            return true;
        }

        return $user->getRoleForActiveStore() === 'owner';
    }
}
