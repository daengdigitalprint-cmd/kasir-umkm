<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryRole
{
    /** Menu_key inventori yang bisa diakses kasir via role_menu_permissions. */
    private const CASHIER_MENU_EXCEPTIONS = [
        'recipes',
        'productions',
        'purchases',
        'purchase-returns',
        'pre-orders',
        'stock-transfers',
        'products',
        'product-categories',
        'stock-opnames',
        'stock-movements',
        'stock-adjustments',
        'suppliers',
        'stock-overview',
    ];

    /**
     * Mapping route name prefix → menu_key di role_menu_permissions.
     * Route name pakai dot/hyphen, menu_key pakai underscore.
     */
    private const ROUTE_TO_MENU_KEY = [
        'purchases' => 'purchases',
        'purchase-returns' => 'purchase_returns',
        'pre-orders' => 'pre_orders',
        'stock-transfers' => 'stock_transfers',
        'products' => 'products',
        'product-categories' => 'product_categories',
        'stock-opnames' => 'stock_opnames',
        'stock-movements' => 'stock_movements',
        'stock-adjustments' => 'stock_adjustment',
        'suppliers' => 'purchases',
        'stock-overview' => 'stock_overview',
        'recipes' => 'recipes',
        'productions' => 'productions',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->canAccessInventory()) {
            return $next($request);
        }

        // Kasir dengan hak akses spesifik dari admin lewat role_menu_permissions.
        if ($user->isCashier()) {
            $routeName = $request->route()?->getName() ?? '';

            foreach (self::ROUTE_TO_MENU_KEY as $routePrefix => $menuKey) {
                if (str_starts_with($routeName, $routePrefix) && $user->canAccessMenu($menuKey)) {
                    return $next($request);
                }
            }
        }

        return redirect()->route('dashboard')
            ->with('error', 'Akses ditolak. Hanya admin gudang, manajer, atau admin toko yang dapat membuka halaman ini.');
    }
}
