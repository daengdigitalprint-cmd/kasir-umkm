<?php

namespace App\Http\Middleware;

use App\Support\PlanModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jaga route modul berbayar (lihat App\Support\PlanModules::MODULES) untuk toko paket Gratis.
 *
 * Dipasang di grup `web`: route dicocokkan lewat namanya, sehingga route baru di modul
 * yang sama otomatis ikut terjaga tanpa perlu menambah middleware satu per satu.
 */
class EnsurePlanModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $module = PlanModules::moduleForRoute($request->route()?->getName());
        if ($module === null) {
            return $next($request);
        }

        $user = $request->user();
        $store = $user?->store;

        // Tanpa user/toko: biarkan middleware auth / store.active yang menangani.
        if (! $user || ! $store || ! PlanModules::locked($store, $module)) {
            return $next($request);
        }

        $message = PlanModules::lockedMessage($module);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message, 'upgrade_required' => true], 403);
        }

        return redirect()->route('dashboard')->with('warning', $message);
    }
}
