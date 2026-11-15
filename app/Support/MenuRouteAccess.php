<?php

namespace App\Support;

use App\Models\User;

/**
 * Mencocokkan nama rute ke menu_key "Peran & Izin", supaya peran yang diberi
 * akses sebuah menu oleh admin toko juga bisa membuka halamannya (bukan hanya
 * melihat menunya lalu ditolak).
 */
final class MenuRouteAccess
{
    /**
     * Rute yang dijaga role.backoffice. Ubah/void/hapus transaksi dan akuntansi
     * (transactions.*, financial.*) sengaja tidak ada: tetap khusus admin/manajer.
     */
    public const BACK_OFFICE = [
        'customers.' => 'customers',
        'discounts.' => 'discounts',
        'expenses.' => 'expenses',
        'loyalty.' => 'loyalty',
        'receivables.' => 'receivables',
        'sales-returns.' => 'sales_returns',
        'reports.' => 'reports',
    ];

    /** Rute yang dijaga role.pos. Urutan penting: awalan yang lebih spesifik dulu. */
    public const POINT_OF_SALE = [
        'cash-shifts.transactions.' => 'cash_transactions',
        'cash-shifts.' => 'cash_shifts',
        'pos.transactions' => 'pos_transactions',
        'pos.' => 'pos',
        'laundry-orders.' => 'laundry_orders',
        'table-orders.' => 'table_orders',
        'tables.' => 'tables',
    ];

    /** @param  array<string, string>  $map */
    public static function allows(User $user, ?string $routeName, array $map): bool
    {
        if (! $routeName) {
            return false;
        }

        foreach ($map as $prefix => $menuKey) {
            if (str_starts_with($routeName, $prefix)) {
                return $user->canAccessMenu($menuKey);
            }
        }

        return false;
    }
}
