<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PayrollController extends AdminPayrollController
{
    public function index()
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $periods = PayrollPeriod::query()
            ->where('store_id', $store->id)
            ->withCount('runs')
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'status' => $p->status,
                'runs_count' => $p->runs_count,
                'total_net' => $p->runs->sum('net_salary'),
            ]);

        return Inertia::render('Payroll/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'period_start' => 'required|date|before_or_equal:period_end',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        PayrollPeriod::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'status' => 'draft',
        ]);

        return back()->with('success', 'Periode penggajian berhasil dibuat.');
    }

    public function show(PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        $runs = $period->runs()
            ->with('employee:id,name,position')
            ->orderByDesc('net_salary')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'employee' => $r->employee->name,
                'position' => $r->employee->position,
                'basic_salary' => (float) $r->basic_salary,
                'deductions' => (float) $r->deductions,
                'overtime_hours' => (float) $r->overtime_hours,
                'overtime_pay' => (float) $r->overtime_pay,
                'gross_salary' => (float) $r->gross_salary,
                'net_salary' => (float) $r->net_salary,
                'attendance_summary' => $r->attendance_summary,
                'component_breakdown' => $r->component_breakdown,
            ]);

        return Inertia::render('Payroll/Show', [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
                'status' => $period->status,
            ],
            'runs' => $runs,
            'totals' => [
                'basic_salary' => round($runs->sum('basic_salary'), 2),
                'deductions' => round($runs->sum('deductions'), 2),
                'overtime_pay' => round($runs->sum('overtime_pay'), 2),
                'gross_salary' => round($runs->sum('gross_salary'), 2),
                'net_salary' => round($runs->sum('net_salary'), 2),
            ],
        ]);
    }

    public function run(PayrollPeriod $period, PayrollService $payroll)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        $count = $payroll->runPeriod($store, $period);

        return back()->with('success', "Penggajian dihitung untuk {$count} karyawan.");
    }

    public function destroy(PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        DB::transaction(function () use ($period) {
            $userId = auth()->id();

            $period->runs()->get()->each(function (PayrollRun $run) use ($userId) {
                $run->forceFill(['deleted_by' => $userId])->save();
                $run->delete();
            });

            $period->forceFill(['deleted_by' => $userId])->save();
            $period->delete();
        });

        return back()->with('success', 'Periode penggajian dihapus.');
    }

    private function ensureOwned(PayrollPeriod $period, \App\Models\Store $store): void
    {
        abort_if($period->store_id !== $store->id, 404);
    }
}
