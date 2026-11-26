<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Area afiliator hanya untuk pengguna yang sudah bergabung di program afiliasi. */
class EnsureAffiliate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->affiliate()->exists()) {
            return redirect()->route('affiliate.landing');
        }

        return $next($request);
    }
}
