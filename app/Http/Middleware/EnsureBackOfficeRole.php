<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackOfficeRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canAccessBackOffice()) {
            return redirect()->route('dashboard')
                ->with('error', 'Akses ditolak. Hanya admin atau manajer toko yang dapat membuka halaman ini.');
        }

        return $next($request);
    }
}
