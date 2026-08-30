<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;

/**
 * Basis controller admin untuk modul payroll.
 */
abstract class AdminPayrollController extends Controller
{
    protected function currentStore(): Store
    {
        $store = auth()->user()->store ?? auth()->user()->stores()->first();

        if (! $store) {
            abort(404, 'Anda perlu membuat toko terlebih dahulu.');
        }

        return $store;
    }

    protected function authorizeAdmin(): void
    {
        $role = auth()->user()->getRoleForActiveStore();

        if (! in_array($role, ['admin', 'manager', 'owner'], true)) {
            abort(403, 'Anda tidak memiliki akses ke menu ini.');
        }
    }
}
