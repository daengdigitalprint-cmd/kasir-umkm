<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        if (! $user->canAccessOwnerPanel()) {
            abort(403, 'Akses ditolak. Hanya pemilik usaha yang dapat mengakses halaman ini.');
        }

        return $next($request);
    }
}
