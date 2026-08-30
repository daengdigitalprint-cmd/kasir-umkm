<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canManageStoreSettings()) {
            return redirect()->route('dashboard')
                ->with('error', 'Akses ditolak. Hanya pemilik/admin toko yang dapat mengubah pengaturan ini.');
        }

        return $next($request);
    }
}
