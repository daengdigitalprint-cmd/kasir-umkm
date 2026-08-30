<?php

namespace App\Modules\CashierPin\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class CashierPinService
{
    private const EASY_PINS = [
        '0000', '1111', '2222', '3333', '4444',
        '5555', '6666', '7777', '8888', '9999',
        '1234', '4321', '1212', '2121', '1313',
    ];

    public function setPin(User $user, string $pin): bool
    {
        if (! $this->validatePinFormat($pin)) {
            return false;
        }

        if (in_array($pin, self::EASY_PINS, true)) {
            return false;
        }

        $user->update([
            'cashier_pin_hash' => Hash::make($pin),
            'cashier_pin_set_at' => now(),
            'can_login_pos' => true,
        ]);

        return true;
    }

    public function verifyPin(User $user, string $pin): bool
    {
        return $user->verifyCashierPin($pin);
    }

    public function clearPin(User $user): void
    {
        $user->update([
            'cashier_pin_hash' => null,
            'cashier_pin_set_at' => null,
            'can_login_pos' => false,
        ]);
    }

    public function validatePinFormat(string $pin): bool
    {
        return strlen($pin) >= 4
            && strlen($pin) <= 6
            && ctype_digit($pin);
    }

    /**
     * Kasir yang sudah punya PIN dan boleh login POS (untuk layar login PIN).
     *
     * @return Collection<int, User>
     */
    public function getCashiersForStore(int $storeId): Collection
    {
        $store = Store::query()->findOrFail($storeId);

        return $store->members()
            ->where('users.is_active', true)
            ->where('users.can_login_pos', true)
            ->whereNotNull('users.cashier_pin_hash')
            ->whereNotIn('store_user.role', ['admin', 'owner'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.cashier_pin_hash', 'users.cashier_pin_set_at', 'users.can_login_pos']);
    }

    public function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'manager' => 'Manajer',
            'cashier' => 'Kasir',
            'warehouse' => 'Admin gudang',
            'kitchen' => 'Dapur',
            'owner' => 'Pemilik (Owner)',
            default => $role ?? 'Anggota tim',
        };
    }
}
