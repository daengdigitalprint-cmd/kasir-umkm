<?php

namespace App\Http\Middleware;

use App\Support\InventoryMenuAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akses "Lihat Stok" (read-only): kasir/manajer/admin (POS) ATAU admin gudang/manajer/admin (inventory).
 * Gudang pusat & cabang juga harus bisa melihat stok, termasuk stok per outlet.
 */
class EnsureStockViewRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canUsePointOfSale() && ! $user->canAccessInventory()) {
            return redirect()->route('dashboard')
                ->with('error', 'Akses ditolak. Halaman ini hanya untuk kasir, admin, manajer, atau admin gudang.');
        }

        if (InventoryMenuAccess::deniedForWarehouse($user, $request->route()?->getName())) {
            return EnsureInventoryRole::denyMenu();
        }

        return $next($request);
    }
}
