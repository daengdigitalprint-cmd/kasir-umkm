<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langganan, add-on, dan pembayaran hanya untuk pemilik/admin toko yang sedang aktif.
 *
 * Beberapa middleware modul (Absensi, Payroll, Gudang Pusat) mengarahkan ke halaman
 * Langganan saat add-on belum aktif. Untuk staf, pesan dari pengalihan itu dipertahankan
 * lalu diarahkan ke dashboard, supaya mereka tahu alasannya tanpa melihat halaman pembelian.
 */
class EnsureBillingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->canManageStoreSettings()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Langganan hanya bisa dikelola pemilik atau admin toko.');
        }

        $session = $request->session();
        foreach (['warning', 'error'] as $key) {
            if ($session->has($key)) {
                $message = rtrim((string) $session->get($key)).' Hubungi pemilik atau admin toko.';

                return redirect()->route('dashboard')->with($key, $message);
            }
        }

        return redirect()->route('dashboard')
            ->with('error', 'Langganan hanya bisa dikelola pemilik atau admin toko.');
    }
}
