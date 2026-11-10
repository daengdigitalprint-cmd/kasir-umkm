<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Attendance\Models\Employee;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Payroll\Support\PayrollApproval;
use App\Modules\Payroll\Support\PayslipPdf;
use App\Modules\Payroll\Support\SetupChecklist;
use App\Services\ActivityLogger;
use App\Support\Accounting\JournalPoster;
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
            ->withSum('runs', 'net_salary')
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'status' => $p->status,
                'runs_count' => $p->runs_count,
                'total_net' => (float) $p->runs_sum_net_salary,
            ]);

        return Inertia::render('Payroll/Index', [
            'periods' => $periods,
            'setup' => SetupChecklist::for($store),
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

        // Periode yang tumpang tindih akan membayar hari yang sama dua kali.
        $overlap = PayrollPeriod::query()
            ->where('store_id', $store->id)
            ->whereDate('period_start', '<=', $validated['period_end'])
            ->whereDate('period_end', '>=', $validated['period_start'])
            ->first();

        if ($overlap) {
            return back()->withErrors([
                'period_start' => "Tanggal bertabrakan dengan periode \"{$overlap->name}\" ({$overlap->period_start->format('d/m/Y')} – {$overlap->period_end->format('d/m/Y')}).",
            ])->withInput();
        }

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
                ...$this->workflow($period->loadMissing('approver:id,name', 'payer:id,name')),
            ],
            'accounting_enabled' => JournalPoster::moduleEnabled($store),
            'approval' => [
                'owner_only' => PayrollApproval::ownerOnly($store) && PayrollApproval::hasOwner($store),
                'can_approve' => PayrollApproval::canApprove(auth()->user(), $store),
            ],
            'skipped_employees' => Employee::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->whereDoesntHave('salaryComponents', fn ($q) => $q->where('type', 'earning'))
                ->orderBy('name')
                ->pluck('name'),
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

        if (! $period->isDraft()) {
            return back()->with('error', 'Hanya periode berstatus draft yang bisa dihitung ulang. Buka kembali periode yang sudah disetujui bila perlu koreksi.');
        }

        $count = $payroll->runPeriod($store, $period);

        ActivityLogger::log($store, 'payroll.calculated', "Menghitung gaji {$period->name} untuk {$count} karyawan", $period, [
            'employees' => $count,
            'total_net' => (float) $period->runs()->sum('net_salary'),
        ]);

        return back()->with('success', "Penggajian dihitung untuk {$count} karyawan.");
    }

    /**
     * Setujui periode: angka gaji dibekukan (tidak bisa dihitung ulang/dihapus) dan
     * slip mulai bisa dilihat karyawan.
     */
    public function approve(PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        if (! $period->isDraft()) {
            return back()->with('error', 'Periode ini sudah disetujui atau dibayar.');
        }

        if (! $period->runs()->exists()) {
            return back()->with('error', 'Hitung gaji periode ini terlebih dahulu sebelum disetujui.');
        }

        if (! PayrollApproval::canApprove(auth()->user(), $store)) {
            return back()->with('error', 'Gaji harus disetujui oleh pemilik toko (diatur di Pengaturan Gaji).');
        }

        $period->update([
            'status' => PayrollPeriod::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        ActivityLogger::log($store, 'payroll.approved', "Menyetujui gaji {$period->name}", $period, [
            'total_net' => (float) $period->runs()->sum('net_salary'),
        ]);

        return back()->with('success', 'Periode penggajian disetujui.');
    }

    /**
     * Buka kembali periode yang sudah disetujui (belum dibayar) untuk dikoreksi.
     */
    public function reopen(Request $request, PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        if ($period->status !== PayrollPeriod::STATUS_APPROVED) {
            return back()->with('error', 'Hanya periode yang disetujui dan belum dibayar yang bisa dibuka kembali.');
        }

        $period->update([
            'status' => PayrollPeriod::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        ActivityLogger::log($store, 'payroll.reopened', "Membuka kembali gaji {$period->name}: {$validated['reason']}", $period, [
            'reason' => $validated['reason'],
        ]);

        return back()->with('success', 'Periode dibuka kembali ke draft.');
    }

    /**
     * Tandai periode sudah dibayar: final, dan (bila modul akuntansi aktif) jurnal
     * beban gaji, utang PPh 21, utang BPJS, serta kas/bank diposting otomatis.
     */
    public function pay(Request $request, PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,bank',
            'payment_date' => 'required|date',
        ]);

        if ($period->status !== PayrollPeriod::STATUS_APPROVED) {
            return back()->with('error', 'Periode harus disetujui terlebih dahulu sebelum ditandai dibayar.');
        }

        DB::transaction(function () use ($store, $period, $validated) {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            abort_if($locked->status !== PayrollPeriod::STATUS_APPROVED, 409, 'Periode sudah diproses.');

            $entry = JournalPoster::postPayroll(
                $store,
                $locked,
                $validated['payment_method'],
                $validated['payment_date'],
                auth()->id(),
            );

            $locked->update([
                'status' => PayrollPeriod::STATUS_PAID,
                'paid_by' => auth()->id(),
                'paid_at' => now(),
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'journal_entry_id' => $entry?->id,
            ]);

            ActivityLogger::log($store, 'payroll.paid', "Membayar gaji {$locked->name}", $locked, [
                'payment_method' => $validated['payment_method'],
                'payment_date' => $validated['payment_date'],
                'total_net' => (float) $locked->runs()->sum('net_salary'),
                'journal_entry_id' => $entry?->id,
            ]);
        });

        return back()->with('success', JournalPoster::moduleEnabled($store)
            ? 'Gaji ditandai dibayar dan jurnal akuntansi sudah dicatat.'
            : 'Gaji ditandai dibayar.');
    }

    /**
     * Slip gaji PDF satu karyawan.
     */
    public function payslip(PayrollPeriod $period, PayrollRun $run)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);
        abort_if((int) $run->payroll_period_id !== (int) $period->id, 404);

        return PayslipPdf::download($store, $period, $run);
    }

    public function destroy(PayrollPeriod $period)
    {
        $store = $this->currentStore();
        $this->authorizeAdmin();
        $this->ensureOwned($period, $store);

        if (! $period->isDraft()) {
            return back()->with('error', 'Periode yang sudah disetujui atau dibayar tidak bisa dihapus.');
        }

        DB::transaction(function () use ($period) {
            $userId = auth()->id();

            $period->runs()->get()->each(function (PayrollRun $run) use ($userId) {
                $run->forceFill(['deleted_by' => $userId])->save();
                $run->delete();
            });

            $period->forceFill(['deleted_by' => $userId])->save();
            $period->delete();
        });

        ActivityLogger::log($store, 'payroll.deleted', "Menghapus periode gaji {$period->name}", $period);

        return back()->with('success', 'Periode penggajian dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(PayrollPeriod $period): array
    {
        return [
            'status' => $period->status,
            'approved_by' => $period->approver?->name,
            'approved_at' => $period->approved_at?->toDateTimeString(),
            'paid_by' => $period->payer?->name,
            'paid_at' => $period->paid_at?->toDateTimeString(),
            'payment_date' => $period->payment_date?->toDateString(),
            'payment_method' => $period->payment_method,
            'journal_entry_id' => $period->journal_entry_id,
        ];
    }

    private function ensureOwned(PayrollPeriod $period, \App\Models\Store $store): void
    {
        abort_if($period->store_id !== $store->id, 404);
    }
}
