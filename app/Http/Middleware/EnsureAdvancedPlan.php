<?php

namespace App\Http\Middleware;

use App\Support\StorePlanLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang fitur level GrowUp ke atas (slug selain `starter` / `starter-tahunan`).
 * Dipakai modul yang tidak termasuk paket Starter, mis. koreksi stok & stock opname.
 */
class EnsureAdvancedPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('error', 'Fitur ini tersedia mulai paket GrowUp. Naikkan paket di halaman langganan untuk mengaktifkannya.');
        }

        return $next($request);
    }
}
