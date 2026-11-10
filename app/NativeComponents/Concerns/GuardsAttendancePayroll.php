<?php

namespace App\NativeComponents\Concerns;

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Services\AttendancePayrollAddOnService;
use Illuminate\Support\Facades\Auth;

/**
 * Gerbang akses layar mobile Absensi & Penggajian, setara dengan middleware web
 * (attendance.enabled / payroll.enabled + peran admin/manager/owner).
 */
trait GuardsAttendancePayroll
{
    private const ADMIN_ROLES = ['admin', 'manager', 'owner'];

    /**
     * @return array{0: User|null, 1: Store|null, 2: string} [user, toko, pesan penolakan ('' bila boleh)]
     */
    protected function guard(string $module): array
    {
        /** @var User|null $user */
        $user = Auth::user();
        $store = $user?->store;

        if (! $user || ! $store) {
            return [null, null, 'Silakan masuk dan pilih toko terlebih dahulu.'];
        }

        if (! in_array($user->getRoleForActiveStore(), self::ADMIN_ROLES, true)) {
            return [$user, $store, 'Menu ini hanya untuk pemilik, admin, atau manager toko.'];
        }

        if (! AttendancePayrollAddOnService::isPurchased($store)) {
            return [$user, $store, 'Paket Absensi + Payroll belum aktif atau sudah berakhir.'];
        }

        if ($module === 'attendance' && ! AttendanceSettings::toggleEnabled($store)) {
            return [$user, $store, 'Modul absensi belum diaktifkan di pengaturan toko.'];
        }

        return [$user, $store, ''];
    }

    protected function deniedView(string $title, string $message)
    {
        return view('native.akses-ditolak', compact('title', 'message'));
    }
}
