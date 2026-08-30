<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasStore
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        if (! $user->store_id) {
            $first = $user->stores()->orderBy('stores.name')->first();
            if ($first) {
                $user->update([
                    'store_id' => $first->id,
                    'role' => $first->pivot->role ?? $user->role ?? 'cashier',
                ]);

                return $next($request);
            }

            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat atau bergabung dengan toko terlebih dahulu.');
        }

        return $next($request);
    }
}
