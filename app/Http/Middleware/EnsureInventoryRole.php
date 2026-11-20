<?php

namespace App\Http\Middleware;

use App\Support\InventoryMenuAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $routeName = $request->route()?->getName();

        if ($user->canAccessInventory()) {
            // Admin gudang tetap tunduk pada menu yang diizinkan di Tim & Akses.
            if (InventoryMenuAccess::deniedForWarehouse($user, $routeName)) {
                return self::denyMenu();
            }

            return $next($request);
        }

        // Kasir dengan hak akses spesifik dari admin lewat role_menu_permissions.
        if ($user->isCashier() && InventoryMenuAccess::grantedByMenu($user, $routeName)) {
            return $next($request);
        }

        return redirect()->route('dashboard')
            ->with('error', 'Akses ditolak. Hanya admin gudang, manajer, atau admin toko yang dapat membuka halaman ini.');
    }

    public static function denyMenu(): Response
    {
        return redirect()->route('dashboard')
            ->with('error', 'Menu ini tidak diizinkan untuk peran Anda. Hubungi pemilik atau admin toko.');
    }
}
