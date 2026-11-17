<?php

namespace App\Http\Middleware;

use App\Support\CentralKitchenModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman Gudang Pusat Pro (Permintaan Stok, Pantau Stok) hanya untuk grup yang berlangganan. */
class EnsureCentralKitchenModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin() || CentralKitchenModule::enabled($user->store)) {
            return $next($request);
        }

        $message = 'Gudang Pusat Pro belum aktif untuk grup usaha ini. Aktifkan di menu Langganan, tab Gudang Pusat Pro.';

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('subscription.index', ['tab' => 'central_kitchen'])->with('warning', $message);
    }
}
