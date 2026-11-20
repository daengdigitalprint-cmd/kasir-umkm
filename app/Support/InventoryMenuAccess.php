<?php

namespace App\Support;

use App\Models\User;

/**
 * Mencocokkan rute stok/inventori ke menu_key "Tim & Akses".
 *
 * Dipakai middleware role.inventory, role.stock_adjustment, dan role.stock_view:
 * - kasir: boleh membuka rute stok bila admin memberinya menu tersebut;
 * - admin gudang: hanya rute yang menunya diizinkan (mencabut menu di Tim & Akses
 *   benar-benar menutup halamannya, bukan sekadar menyembunyikan tautan).
 * Admin, manajer, dan pemilik tidak dibatasi menu di sini (sama dengan role.backoffice).
 */
final class InventoryMenuAccess
{
    /**
     * Awalan nama rute → menu_key. Rute tambahan (batch, pembayaran supplier,
     * pecah kemasan) ikut menu induknya.
     */
    public const ROUTE_TO_MENU_KEY = [
        'purchase-payments' => 'purchases',
        'purchase-returns' => 'purchase_returns',
        'purchases' => 'purchases',
        'suppliers' => 'purchases',
        'pre-orders' => 'pre_orders',
        'stock-transfers' => 'stock_transfers',
        // Tambah kategori cepat dipanggil dari form produk.
        'product-categories.quick-store' => 'products',
        'product-categories' => 'product_categories',
        'product-batches' => 'products',
        'products' => 'products',
        'stock-opnames' => 'stock_opnames',
        'stock-movements' => 'stock_movements',
        'stock-adjustments' => 'stock_adjustment',
        'stock-repacks' => 'stock_adjustment',
        'stock-overview' => 'stock_overview',
        'recipes' => 'recipes',
        'productions' => 'productions',
        'shopping-list' => 'shopping_list',
        'stock-requests' => 'stock_requests',
    ];

    public static function menuKeyFor(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        foreach (self::ROUTE_TO_MENU_KEY as $prefix => $menuKey) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                return $menuKey;
            }
        }

        return null;
    }

    /** Kasir/staf lain yang diberi menu rute ini secara eksplisit. */
    public static function grantedByMenu(User $user, ?string $routeName): bool
    {
        $menuKey = self::menuKeyFor($routeName);

        return $menuKey !== null && $user->canAccessMenu($menuKey);
    }

    /**
     * Admin gudang yang menunya dicabut untuk rute ini. Toko tanpa daftar menu
     * (mode lama, allowed_menus kosong) tidak dibatasi — sama seperti sidebar.
     */
    public static function deniedForWarehouse(User $user, ?string $routeName): bool
    {
        if (! $user->isWarehouseStaff() || $user->isSuperAdmin()) {
            return false;
        }

        $menuKey = self::menuKeyFor($routeName);
        if ($menuKey === null || $user->allowedMenus() === []) {
            return false;
        }

        return ! $user->canAccessMenu($menuKey);
    }
}
