<?php

namespace App\Modules\Attendance\Http\Middleware;

use App\Modules\Attendance\Support\AttendanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang aktivasi modul absensi. Menolak akses bila addon belum diaktifkan
 * pemilik usaha di pengaturan, sehingga modul benar-benar "off" saat tidak dipakai.
 */
class EnsureAttendanceEnabled
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

        if (! AttendanceSettings::purchased($store)) {
            return redirect()->route('subscription.index')
                ->with('error', 'Paket Absensi + Payroll belum aktif. Beli di halaman Langganan terlebih dahulu.');
        }

        if (! AttendanceSettings::toggleEnabled($store)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Modul absensi belum diaktifkan. Aktifkan di pengaturan toko terlebih dahulu.');
        }

        return $next($request);
    }
}
