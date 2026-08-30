<?php

namespace App\Http\Middleware;

use App\Support\ScanMejaFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScanMejaEnabled
{
    /**
     * Cek apakah modul Scan Meja aktif untuk store saat ini.
     * Jika tidak aktif, redirect ke dashboard dengan pesan error.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return $next($request);
        }

        if (! ScanMejaFeatures::enabled($store)) {
            return redirect()->route('dashboard')
                ->with('error', 'Modul Scan Meja belum diaktifkan. Silakan aktifkan di Pengaturan Toko.');
        }

        return $next($request);
    }
}
