<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQueueAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $hasAccess = $user->canUsePointOfSale()
            || $user->canAccessMenu('queues')
            || $user->canAccessMenu('kitchen_queue');

        if (! $hasAccess) {
            return redirect()->route('dashboard')
                ->with('error', 'Anda tidak memiliki akses ke modul antrian.');
        }

        return $next($request);
    }
}
