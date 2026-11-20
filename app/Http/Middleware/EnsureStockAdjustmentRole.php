<?php

namespace App\Http\Middleware;

use App\Support\InventoryMenuAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStockAdjustmentRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canAccessStockAdjustment()) {
            return redirect()->route('dashboard')
                ->with('error', 'Akses ditolak. Anda tidak memiliki izin mengelola penyesuaian stok.');
        }

        if (InventoryMenuAccess::deniedForWarehouse($user, $request->route()?->getName())) {
            return EnsureInventoryRole::denyMenu();
        }

        return $next($request);
    }
}
