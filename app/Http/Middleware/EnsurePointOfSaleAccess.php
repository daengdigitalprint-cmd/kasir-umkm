<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePointOfSaleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canUsePointOfSale()) {
            return redirect()->route('dashboard')
                ->with('error', 'Akses POS dan shift kasir hanya untuk kasir, manajer, atau admin toko.');
        }

        return $next($request);
    }
}
