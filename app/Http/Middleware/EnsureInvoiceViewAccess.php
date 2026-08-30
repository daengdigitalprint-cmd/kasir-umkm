<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Akses halaman struk / invoice: kasir (POS) atau back office (admin/manajer). */
class EnsureInvoiceViewAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canUsePointOfSale() && ! $user->canAccessBackOffice()) {
            return redirect()->route('dashboard')
                ->with('error', 'Anda tidak memiliki akses untuk melihat struk transaksi.');
        }

        return $next($request);
    }
}
