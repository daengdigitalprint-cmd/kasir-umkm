<?php

namespace App\Http\Middleware;

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

        return $next($request);
    }
}
