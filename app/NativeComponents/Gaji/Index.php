<?php

namespace App\NativeComponents\Gaji;

use App\Modules\Attendance\Models\Employee;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\NativeComponents\Concerns\GuardsAttendancePayroll;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class Index extends NativeComponent
{
    use GuardsAttendancePayroll;

    public int $totalKaryawan = 0;

    public string $totalGajiBulanIni = 'Rp 0';

    public int $sudahDibayar = 0;

    public int $belumDibayar = 0;

    public bool $showDetailModal = false;

    public string $detailNama = '';

    public string $detailPosisi = '';

    public string $detailGajiPokok = 'Rp 0';

    public string $detailPotongan = 'Rp 0';

    public string $detailLembur = 'Rp 0';

    public string $detailGajiBersih = 'Rp 0';

    public int $detailHadir = 0;

    public int $detailAlpha = 0;

    public function navTitle(): string
    {
        return 'Penggajian';
    }

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        [, $store, $denied] = $this->guard('payroll');
        if ($denied !== '') {
            return;
        }

        $storeId = $store->id;

        $this->totalKaryawan = Employee::where('store_id', $storeId)
            ->where('is_active', true)
            ->count();

        $period = $this->currentPeriod($storeId);

        if ($period) {
            $runs = $period->runs()->whereNull('deleted_at')->get();
            $total = $runs->sum(fn ($r) => (float) $r->net_salary);
            $this->totalGajiBulanIni = 'Rp '.number_format($total, 0, ',', '.');
            $this->sudahDibayar = $period->status === PayrollPeriod::STATUS_PAID ? $runs->count() : 0;
            $this->belumDibayar = max(0, $this->totalKaryawan - $this->sudahDibayar);
        } else {
            $this->totalGajiBulanIni = 'Rp 0';
            $this->sudahDibayar = 0;
            $this->belumDibayar = $this->totalKaryawan;
        }
    }

    public function openDetail(int $runId): void
    {
        [, $store, $denied] = $this->guard('payroll');
        if ($denied !== '') {
            return;
        }

        // Wajib milik toko aktif: ID slip dari toko lain tidak boleh terbuka.
        $run = PayrollRun::with(['employee'])
            ->where('store_id', $store->id)
            ->find($runId);
        if (! $run) {
            return;
        }

        $this->detailNama = $run->employee->name ?? '-';
        $this->detailPosisi = $run->employee->position ?? 'Karyawan';
        $this->detailGajiPokok = 'Rp '.number_format((float) $run->basic_salary, 0, ',', '.');
        $this->detailPotongan = 'Rp '.number_format((float) $run->deductions, 0, ',', '.');
        $this->detailLembur = 'Rp '.number_format((float) $run->overtime_pay, 0, ',', '.');
        $this->detailGajiBersih = 'Rp '.number_format((float) $run->net_salary, 0, ',', '.');

        // Kunci ringkasan mengikuti AttendanceService::aggregate().
        $summary = $run->attendance_summary ?? [];
        $this->detailHadir = (int) ($summary['present_days'] ?? 0);
        $this->detailAlpha = (int) ($summary['absent_days'] ?? 0);

        $this->showDetailModal = true;
    }

    public function closeDetail(): void
    {
        $this->showDetailModal = false;
    }

    public function render(): Element|View
    {
        [, $store, $denied] = $this->guard('payroll');
        if ($denied !== '') {
            return $this->deniedView('Akses Penggajian Ditolak', $denied);
        }

        $period = $this->currentPeriod($store->id);

        $runs = collect();
        if ($period) {
            $runs = $period->runs()
                ->with('employee')
                ->whereNull('deleted_at')
                ->get()
                ->sortBy('employee.name');
        }

        $periodLabel = $period
            ? $period->period_start->format('d M').' – '.$period->period_end->format('d M Y')
            : now()->translatedFormat('F Y');

        $periodStatus = $period?->status ?? 'draft';

        return view('native.gaji.index', compact('runs', 'periodLabel', 'periodStatus'));
    }

    private function currentPeriod(int $storeId): ?PayrollPeriod
    {
        return PayrollPeriod::where('store_id', $storeId)
            ->where('period_start', '>=', now()->startOfMonth())
            ->where('period_end', '<=', now()->endOfMonth())
            ->latest()
            ->first();
    }
}
