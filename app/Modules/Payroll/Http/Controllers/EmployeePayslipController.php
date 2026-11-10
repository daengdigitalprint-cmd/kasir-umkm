<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayslipPdf;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Slip gaji milik karyawan sendiri. Hanya periode yang sudah disetujui/dibayar
 * yang terlihat, dan hanya slip atas profil karyawan yang tertaut ke akun login.
 */
class EmployeePayslipController extends Controller
{
    public function index()
    {
        [$store, $employee] = $this->context();

        $slips = PayrollRun::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID]))
            ->with('period')
            ->get()
            ->sortByDesc(fn (PayrollRun $run) => $run->period->period_end)
            ->values()
            ->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'period' => $run->period->name,
                'period_start' => $run->period->period_start->toDateString(),
                'period_end' => $run->period->period_end->toDateString(),
                'status' => $run->period->status,
                'payment_date' => $run->period->payment_date?->toDateString(),
                'gross_salary' => (float) $run->gross_salary,
                'deductions' => (float) $run->deductions,
                'net_salary' => (float) $run->net_salary,
            ]);

        return Inertia::render('Attendance/MyPayslips', [
            'slips' => $slips,
        ]);
    }

    public function download(PayrollRun $run)
    {
        [$store, $employee] = $this->context();

        abort_if((int) $run->store_id !== (int) $store->id || (int) $run->employee_id !== (int) $employee->id, 404);

        $period = $run->period;
        abort_if(! $period || $period->isDraft(), 404);

        return PayslipPdf::download($store, $period, $run);
    }

    /**
     * @return array{0: Store, 1: Employee}
     */
    private function context(): array
    {
        $user = Auth::user();
        $store = $user->store ?? $user->stores()->first();
        abort_if(! $store, 404, 'Anda perlu membuat toko terlebih dahulu.');

        $employee = Employee::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->first();
        abort_if(! $employee, 404, 'Profil karyawan belum dibuat untuk akun ini.');

        return [$store, $employee];
    }
}
