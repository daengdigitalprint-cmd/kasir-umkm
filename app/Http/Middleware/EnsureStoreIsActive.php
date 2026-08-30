<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();
        $store = $user->store;

        // If user has no store, let other middleware handle it
        if (! $store) {
            return $next($request);
        }

        // If store is inactive, redirect to dashboard with message
        if (! $store->is_active) {
            return redirect()->route('dashboard')
                ->with('error', 'Toko Anda telah dinonaktifkan oleh administrator.');
        }

        return $next($request);
    }
}
