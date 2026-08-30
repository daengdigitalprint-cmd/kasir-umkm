<?php

namespace App\Modules\Payroll\Http\Middleware;

use App\Services\AttendancePayrollAddOnService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang aktivasi modul payroll. Add-on "Absensi + Payroll" dibeli sekali
 * per grup usaha, jadi cukup cek masa aktif add-on pada toko aktif.
 */
class EnsurePayrollEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! AttendancePayrollAddOnService::isPurchased($store)) {
            return redirect()->route('subscription.index')
                ->with('error', 'Paket Absensi + Payroll belum aktif. Beli di halaman Langganan terlebih dahulu.');
        }

        return $next($request);
    }
}
