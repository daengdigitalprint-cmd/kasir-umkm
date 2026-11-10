<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Basis controller admin untuk modul absensi (jadwal, cuti, lembur).
 */
abstract class AdminAttendanceController extends Controller
{
    protected function currentStore(): Store
    {
        $store = auth()->user()->store ?? auth()->user()->stores()->first();

        if (! $store) {
            abort(404, 'Anda perlu membuat toko terlebih dahulu.');
        }

        return $store;
    }

    /**
     * Aturan validasi employee_id: karyawan wajib milik toko aktif (cegah IDOR lintas toko).
     */
    protected function storeEmployeeRule(): Exists
    {
        return Rule::exists('employees', 'id')->where('store_id', $this->currentStore()->id);
    }

    protected function authorizeAdmin(): void
    {
        $role = auth()->user()->getRoleForActiveStore();

        if (! in_array($role, ['admin', 'manager', 'owner'], true)) {
            abort(403, 'Anda tidak memiliki akses ke menu ini.');
        }
    }
}
