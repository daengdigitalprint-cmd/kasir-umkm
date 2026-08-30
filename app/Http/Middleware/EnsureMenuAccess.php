<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next, string $menuKey): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->canAccessMenu($menuKey)) {
            return redirect()->route('dashboard')
                ->with('error', 'Anda tidak memiliki akses ke menu ini.');
        }

        return $next($request);
    }
}
