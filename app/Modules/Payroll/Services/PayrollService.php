<?php

namespace App\Modules\Payroll\Services;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\SalaryComponent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hitung gaji per periode dari komponen gaji + data absensi.
 * Sumber kehadiran tunggal: AttendanceService::aggregate().
 *
 * Semua nominal dibulatkan ke rupiah penuh; PPh 21 dibulatkan ke bawah.
 *
 * Urutan perhitungan per karyawan:
 *   1. Upah tetap (komponen is_fixed) diprorata bila masuk/keluar di tengah periode.
 *   2. Potongan hari alpa & cuti tanpa gaji (opsional, per toko) dari upah tetap.
 *   3. Lembur per hari: jam pertama × tarif 1, jam berikutnya × tarif 2, hari libur × tarif libur.
 *   4. Bruto tunai = upah tetap + tunjangan tidak tetap + lembur − potongan alpa.
 *   5. BPJS: Kesehatan & JP memakai batas upah; JHT/JKK/JKM tanpa batas.
 *   6. PPh 21: TER bulanan atas bruto + premi BPJS yang ditanggung pemberi kerja
 *      (Kesehatan, JKK, JKM); periode terakhir di bulan Desember memakai rekonsiliasi tahunan.
 *   7. Gaji bersih tidak pernah negatif; kekurangannya dicatat sebagai 'shortfall'.
 */
class PayrollService
{
    /** Jam kerja standar sebulan (dasar upah lembur per jam, Kepmenaker 102/2004 & PP 35/2021). */
    public const STANDARD_MONTHLY_HOURS = 173;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly TaxService $tax,
    ) {}

    public function hourlyRate(float $monthlyWage): float
    {
        return $monthlyWage > 0
            ? (float) round($monthlyWage / self::STANDARD_MONTHLY_HOURS)
            : 0.0;
    }

    /**
     * Potongan keterlambatan sesuai metode denda toko (hanya satu metode aktif).
     */
    public function latePenalty(Store $store, int $lateDays, int $lateMinutes): float
    {
        return match ($store->late_penalty_method) {
            'per_minute' => (float) round($lateMinutes * (float) $store->late_penalty_per_minute),
            'none' => 0.0,
            default => (float) round($lateDays * (float) $store->late_penalty_per_day),
        };
    }

    /**
     * Upah lembur per hari sesuai tarif toko.
     *
     * @param  array<string, float>  $overtimeByDay  [Y-m-d => jam]
     * @param  array<string, true>  $holidays
     * @return array{total: float, hours: float, regular_hours: float, holiday_hours: float}
     */
    public function overtimePay(Store $store, float $hourlyRate, array $overtimeByDay, array $holidays): array
    {
        $first = (float) $store->overtime_rate_first_hour;
        $next = (float) $store->overtime_rate_next_hour;
        $holiday = (float) $store->overtime_rate_holiday;

        $multiplierHours = 0.0;
        $regular = 0.0;
        $onHoliday = 0.0;

        foreach ($overtimeByDay as $date => $hours) {
            $hours = max(0.0, (float) $hours);

            if (isset($holidays[$date])) {
                $multiplierHours += $hours * $holiday;
                $onHoliday += $hours;

                continue;
            }

            $multiplierHours += min(1.0, $hours) * $first + max(0.0, $hours - 1.0) * $next;
            $regular += $hours;
        }

        return [
            'total' => (float) round($hourlyRate * $multiplierHours),
            'hours' => round($regular + $onHoliday, 2),
            'regular_hours' => round($regular, 2),
            'holiday_hours' => round($onHoliday, 2),
        ];
    }

    /**
     * Generate (atau regenerate) slip gaji untuk periode.
     * Idempoten: updateOrCreate per karyawan. Periode tetap draft; penguncian lewat PayrollController::lock.
     */
    public function runPeriod(Store $store, PayrollPeriod $period): int
    {
        $from = CarbonImmutable::parse($period->period_start->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($period->period_end->toDateString())->endOfDay();

        // Karyawan aktif, plus yang keluar di dalam periode ini (tetap menerima gaji prorata).
        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where(fn ($q) => $q
                ->where('is_active', true)
                ->orWhereDate('resigned_at', '>=', $from->toDateString()))
            ->where(fn ($q) => $q
                ->whereNull('joined_at')
                ->orWhereDate('joined_at', '<=', $to->toDateString()))
            ->where(fn ($q) => $q
                ->whereNull('resigned_at')
                ->orWhereDate('resigned_at', '>=', $from->toDateString()))
            ->orderBy('name')
            ->get();

        $components = SalaryComponent::query()
            ->where('store_id', $store->id)
            ->get()
            ->groupBy('employee_id');

        // Karyawan tanpa komponen penghasilan (mis. profil pemilik yang hanya dipakai absen)
        // tidak diberi slip Rp0; mereka ditampilkan sebagai "dilewati" di detail periode.
        $employees = $employees
            ->filter(fn (Employee $e) => ($components->get($e->id) ?? collect())->where('type', 'earning')->isNotEmpty())
            ->values();

        $holidays = Holiday::datesBetween($store->id, $from, $to);
        $isYearEnd = $this->isYearEndPeriod($store, $period);

        DB::transaction(function () use ($store, $period, $employees, $components, $from, $to, $holidays, $isYearEnd) {
            foreach ($employees as $employee) {
                $result = $this->calculate(
                    $store,
                    $employee,
                    $components->get($employee->id) ?? collect(),
                    $this->attendance->aggregate($employee, $from, $to),
                    $from,
                    $to,
                    $holidays,
                    $isYearEnd,
                );

                PayrollRun::updateOrCreate(
                    ['payroll_period_id' => $period->id, 'employee_id' => $employee->id],
                    ['store_id' => $store->id, ...$result],
                );
            }

            // Slip lama untuk karyawan yang tidak lagi termasuk periode ini (mis. data masuk/keluar dikoreksi).
            PayrollRun::query()
                ->where('payroll_period_id', $period->id)
                ->whereNotIn('employee_id', $employees->pluck('id'))
                ->get()
                ->each(function (PayrollRun $run) {
                    $run->forceFill(['deleted_by' => auth()->id()])->save();
                    $run->delete();
                });
        });

        return $employees->count();
    }

    /**
     * Rekonsiliasi PPh 21 tahunan hanya sekali: pada periode dengan tanggal akhir
     * paling akhir di bulan Desember (periode lain di Desember memakai TER biasa).
     */
    public function isYearEndPeriod(Store $store, PayrollPeriod $period): bool
    {
        if ($period->period_end->month !== 12) {
            return false;
        }

        return ! PayrollPeriod::query()
            ->where('store_id', $store->id)
            ->whereKeyNot($period->id)
            ->whereYear('period_end', $period->period_end->year)
            ->where('period_end', '>', $period->period_end->toDateString())
            ->exists();
    }

    /**
     * @param  Collection<int, SalaryComponent>  $items
     * @param  array<string, mixed>  $summary
     * @param  array<string, true>  $holidays
     * @return array<string, mixed>
     */
    private function calculate(
        Store $store,
        Employee $employee,
        Collection $items,
        array $summary,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $holidays,
        bool $isYearEnd,
    ): array {
        $earnings = $items->where('type', 'earning')->sortBy('sort_order')->values();
        $deductionItems = $items->where('type', 'deduction')->sortBy('sort_order')->values();

        // 1. Upah tetap + prorata masuk/keluar di tengah periode (hari kalender).
        $proration = $this->prorationFactor($employee, $from, $to);
        // Pakai pecahan hari yang persis (bukan faktor yang sudah dibulatkan) agar tidak selisih rupiah.
        $ratio = $proration['days_in_period'] > 0
            ? min(1, $proration['days_employed'] / $proration['days_in_period'])
            : 1;
        $monthlyFixed = (float) $earnings->where('is_fixed', true)->sum('amount');
        $fixed = (float) round($monthlyFixed * $ratio);
        $variable = (float) round($earnings->where('is_fixed', false)->sum('amount'));

        // 2. Potongan alpa & cuti tanpa gaji (upah sehari = upah tetap ÷ 25 atau 21 hari).
        $absentDays = (int) ($summary['absent_days'] ?? 0) + (int) ($summary['unpaid_leave_days'] ?? 0);
        $dailyWage = $monthlyFixed > 0 ? round($monthlyFixed / $this->workDaysPerMonth($employee)) : 0;
        $absenceDeduction = $store->absence_deduction_enabled
            ? (float) min($fixed, $absentDays * $dailyWage)
            : 0.0;

        // 3. Lembur per hari.
        $hourly = $this->hourlyRate($monthlyFixed);
        $overtime = $this->overtimePay($store, $hourly, $summary['overtime_by_day'] ?? [], $holidays);

        // 4. Bruto tunai.
        $gross = (float) round($fixed + $variable + $overtime['total'] - $absenceDeduction);

        // 5. BPJS dari upah tetap (setelah prorata) dengan batas upah.
        $bpjs = $this->bpjs($store, $fixed);

        // 6. PPh 21.
        $taxableGross = (float) round($gross + $bpjs['taxable_employer_premium']);
        $tax = $store->pph21_enabled
            ? $this->pph21($store, $employee, $from, $taxableGross, $bpjs['pension_employee'], $isYearEnd)
            : null;
        $taxAmount = (float) ($tax['monthly_tax'] ?? 0);
        $refund = (float) ($tax['refund'] ?? 0);

        $latePenalty = $this->latePenalty($store, (int) ($summary['late_days'] ?? 0), (int) ($summary['total_late_min'] ?? 0));
        $componentDeductions = (float) round($deductionItems->sum('amount'));

        // 7. Gaji bersih tidak negatif.
        $totalDeductions = (float) round($componentDeductions + $latePenalty + $bpjs['total_employee'] + $taxAmount);
        $netRaw = $gross - $totalDeductions + $refund;
        $net = max(0.0, $netRaw);

        return [
            'basic_salary' => $fixed,
            'deductions' => $totalDeductions,
            'overtime_hours' => $overtime['hours'],
            'overtime_pay' => $overtime['total'],
            'gross_salary' => $gross,
            'net_salary' => (float) round($net),
            'attendance_summary' => $summary,
            'component_breakdown' => [
                'earnings' => $earnings->map(fn (SalaryComponent $c) => [
                    'name' => $c->name,
                    'amount' => $c->is_fixed
                        ? (float) round((float) $c->amount * $ratio)
                        : (float) round((float) $c->amount),
                    'is_fixed' => (bool) $c->is_fixed,
                ])->all(),
                'deductions' => $deductionItems->map(fn (SalaryComponent $c) => [
                    'name' => $c->name,
                    'amount' => (float) round((float) $c->amount),
                ])->all(),
                'proration' => $proration,
                'absence' => [
                    'enabled' => (bool) $store->absence_deduction_enabled,
                    'days' => $absentDays,
                    'daily_wage' => (float) $dailyWage,
                    'total' => $absenceDeduction,
                ],
                'late_penalty' => [
                    'method' => $store->late_penalty_method,
                    'late_days' => (int) ($summary['late_days'] ?? 0),
                    'late_minutes' => (int) ($summary['total_late_min'] ?? 0),
                    'penalty_per_day' => (float) $store->late_penalty_per_day,
                    'penalty_per_minute' => (float) $store->late_penalty_per_minute,
                    'total' => $latePenalty,
                ],
                'overtime' => [
                    ...$overtime,
                    'rate_multiplier' => (float) $store->overtime_rate_first_hour,
                    'rate_next_hour' => (float) $store->overtime_rate_next_hour,
                    'rate_holiday' => (float) $store->overtime_rate_holiday,
                    'hourly_rate' => $hourly,
                ],
                'bpjs' => $store->bpjs_enabled ? $bpjs['breakdown'] : null,
                'taxable_gross' => $taxableGross,
                'tax' => $tax,
                'shortfall' => (float) round(max(0.0, -$netRaw)),
            ],
        ];
    }

    /**
     * @return array{factor: float, days_employed: int, days_in_period: int}
     */
    private function prorationFactor(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periodDays = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        $start = $employee->joined_at && $employee->joined_at->gt($from)
            ? CarbonImmutable::parse($employee->joined_at->toDateString())
            : $from->startOfDay();
        $end = $employee->resigned_at && $employee->resigned_at->lt($to->startOfDay())
            ? CarbonImmutable::parse($employee->resigned_at->toDateString())
            : $to->startOfDay();

        $employed = $end->lt($start) ? 0 : (int) $start->diffInDays($end) + 1;

        return [
            'factor' => $periodDays > 0 ? round(min(1, $employed / $periodDays), 6) : 1.0,
            'days_employed' => $employed,
            'days_in_period' => $periodDays,
        ];
    }

    /**
     * Pembagi upah harian: 21 hari untuk 5 hari kerja/minggu, 25 hari untuk 6 hari kerja/minggu.
     */
    private function workDaysPerMonth(Employee $employee): int
    {
        $schedule = WorkSchedule::query()
            ->where('store_id', $employee->store_id)
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $schedule && count($schedule->work_days ?? []) <= 5 ? 21 : 25;
    }

    /**
     * @return array{breakdown: array<string, mixed>, total_employee: float, pension_employee: float, taxable_employer_premium: float}
     */
    private function bpjs(Store $store, float $wage): array
    {
        if (! $store->bpjs_enabled || $wage <= 0) {
            return ['breakdown' => [], 'total_employee' => 0.0, 'pension_employee' => 0.0, 'taxable_employer_premium' => 0.0];
        }

        $pct = fn (float $base, $rate) => (float) round($base * (float) $rate / 100);

        $healthCap = (float) $store->bpjs_health_wage_cap;
        $jpCap = (float) $store->bpjs_jp_wage_cap;
        $healthBase = $healthCap > 0 ? min($wage, $healthCap) : $wage;
        $jpBase = $jpCap > 0 ? min($wage, $jpCap) : $wage;

        $health = ['employee' => $pct($healthBase, $store->bpjs_health_employee), 'employer' => $pct($healthBase, $store->bpjs_health_employer)];
        $jht = ['employee' => $pct($wage, $store->bpjs_jht_employee), 'employer' => $pct($wage, $store->bpjs_jht_employer)];
        $jp = ['employee' => $pct($jpBase, $store->bpjs_jp_employee), 'employer' => $pct($jpBase, $store->bpjs_jp_employer)];
        $jkk = $pct($wage, $store->bpjs_jkk);
        $jkm = $pct($wage, $store->bpjs_jkm);

        $totalEmployee = $health['employee'] + $jht['employee'] + $jp['employee'];

        return [
            'breakdown' => [
                'wage_base' => $wage,
                'health_base' => $healthBase,
                'jp_base' => $jpBase,
                'health' => $health,
                'jht' => $jht,
                'jp' => $jp,
                'jkk' => $jkk,
                'jkm' => $jkm,
                'total_employee' => $totalEmployee,
            ],
            'total_employee' => $totalEmployee,
            'pension_employee' => $jht['employee'] + $jp['employee'],
            // Premi yang dibayar pemberi kerja dan menjadi objek PPh 21 (bukan JHT/JP).
            'taxable_employer_premium' => $health['employer'] + $jkk + $jkm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pph21(
        Store $store,
        Employee $employee,
        CarbonImmutable $from,
        float $taxableGross,
        float $pensionEmployee,
        bool $isYearEnd,
    ): array {
        $taxStatus = $employee->tax_status ?: 'TK/0';

        if (! $isYearEnd) {
            return [...$this->tax->calculateTerMonthly($taxableGross, $taxStatus), 'refund' => 0];
        }

        $prior = PayrollRun::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q
                ->whereYear('period_end', $from->year)
                ->where('period_end', '<', $from->toDateString()))
            ->get();

        $priorTaxable = $prior->sum(fn (PayrollRun $run) => (float) (
            $run->component_breakdown['taxable_gross']
            ?? $run->component_breakdown['tax']['monthly_gross']
            ?? $run->gross_salary
        ));
        $priorTax = $prior->sum(fn (PayrollRun $run) => (float) ($run->component_breakdown['tax']['monthly_tax'] ?? 0)
            - (float) ($run->component_breakdown['tax']['refund'] ?? 0));
        $priorPension = $prior->sum(fn (PayrollRun $run) => (float) ($run->component_breakdown['bpjs']['jht']['employee'] ?? 0)
            + (float) ($run->component_breakdown['bpjs']['jp']['employee'] ?? 0));

        return $this->tax->reconcileDecember(
            $priorTaxable + $taxableGross,
            $taxStatus,
            $priorTax,
            $priorPension + $pensionEmployee,
        );
    }
}
