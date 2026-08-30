<?php

namespace App\Modules\Payroll\Services;

use App\Models\Store;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\SalaryComponent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hitung gaji per periode dari komponen gaji + data absensi.
 * Sumber kehadiran tunggal: AttendanceService::aggregate().
 */
class PayrollService
{
    /** Jam kerja standar sebulan (dasar perhitungan tarif lembur per jam). */
    public const STANDARD_MONTHLY_HOURS = 173;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly TaxService $tax,
    ) {}

    public function hourlyRate(float $basicSalary): float
    {
        return $basicSalary > 0
            ? round($basicSalary / self::STANDARD_MONTHLY_HOURS, 2)
            : 0.0;
    }

    /**
     * Potongan keterlambatan sesuai metode denda toko (hanya satu metode aktif).
     */
    public function latePenalty(Store $store, int $lateDays, int $lateMinutes): float
    {
        return match ($store->late_penalty_method) {
            'per_minute' => round($lateMinutes * (float) $store->late_penalty_per_minute, 2),
            'none' => 0.0,
            default => round($lateDays * (float) $store->late_penalty_per_day, 2),
        };
    }

    /**
     * Generate (atau regenerate) slip gaji semua karyawan aktif untuk periode.
     * Idempoten: memakai updateOrCreate per karyawan, lalu mengunci periode.
     */
    public function runPeriod(Store $store, PayrollPeriod $period): int
    {
        $employees = Employee::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $components = SalaryComponent::query()
            ->where('store_id', $store->id)
            ->get()
            ->groupBy('employee_id');

        $from = CarbonImmutable::parse($period->period_start->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($period->period_end->toDateString())->endOfDay();

        DB::transaction(function () use ($store, $period, $employees, $components, $from, $to) {
            foreach ($employees as $employee) {
                $items = $components->get($employee->id) ?? collect();

                $earnings = $items->where('type', 'earning')->sortBy('sort_order');
                $deductions = $items->where('type', 'deduction')->sortBy('sort_order');

                $basic = round($earnings->sum('amount'), 2);
                $deduction = round($deductions->sum('amount'), 2);

                $summary = $this->attendance->aggregate($employee, $from, $to);

                // Potongan keterlambatan dari data absensi (satu metode saja)
                $lateDays = (int) ($summary['late_days'] ?? 0);
                $lateMinutes = (int) ($summary['total_late_min'] ?? 0);
                $latePenalty = $this->latePenalty($store, $lateDays, $lateMinutes);

                // Upah lembur dengan tarif dinamis dari konfigurasi toko
                $overtimeHours = (float) ($summary['total_overtime'] ?? 0);
                $overtimeRate = (float) $store->overtime_rate_first_hour;
                $overtimePay = round($this->hourlyRate($basic) * $overtimeRate * $overtimeHours, 2);

                $gross = round($basic + $overtimePay, 2);

                // BPJS Kesehatan & Ketenagakerjaan (potongan karyawan)
                $bpjsBreakdown = null;
                $bpjsEmployee = 0;
                if ($store->bpjs_enabled) {
                    $bpjsHealthEmp = round($basic * (float) $store->bpjs_health_employee / 100, 2);
                    $bpjsJhtEmp = round($basic * (float) $store->bpjs_jht_employee / 100, 2);
                    $bpjsJpEmp = round($basic * (float) $store->bpjs_jp_employee / 100, 2);
                    $bpjsEmployee = round($bpjsHealthEmp + $bpjsJhtEmp + $bpjsJpEmp, 2);

                    $bpjsBreakdown = [
                        'health' => ['employee' => $bpjsHealthEmp, 'employer' => round($basic * (float) $store->bpjs_health_employer / 100, 2)],
                        'jht' => ['employee' => $bpjsJhtEmp, 'employer' => round($basic * (float) $store->bpjs_jht_employer / 100, 2)],
                        'jp' => ['employee' => $bpjsJpEmp, 'employer' => round($basic * (float) $store->bpjs_jp_employer / 100, 2)],
                        'jkk' => round($basic * (float) $store->bpjs_jkk / 100, 2),
                        'jkm' => round($basic * (float) $store->bpjs_jkm / 100, 2),
                        'total_employee' => $bpjsEmployee,
                    ];
                }

                // PPh 21 metode TER 2024 (bruto bulanan; Desember pakai rekonsiliasi tahunan)
                $taxBreakdown = null;
                $pph21Monthly = 0;
                if ($store->pph21_enabled) {
                    $monthlyGross = $gross;
                    $taxStatus = $employee->tax_status ?? 'TK/0';
                    $taxYear = $period->period_end->year;

                    if ($period->period_end->month === 12) {
                        $prior = PayrollRun::query()
                            ->where('employee_id', $employee->id)
                            ->where('store_id', $store->id)
                            ->whereHas('period', fn ($q) => $q
                                ->whereYear('period_end', $taxYear)
                                ->where('period_end', '<', $from));

                        $priorGross = (float) (clone $prior)->sum('gross_salary');
                        $annualGross = round($priorGross + $monthlyGross, 2);

                        $accumulatedTer = (clone $prior)->get()
                            ->sum(fn (PayrollRun $run) => (float) ($run->component_breakdown['tax']['monthly_tax'] ?? 0));

                        $taxBreakdown = $this->tax->reconcileDecember($annualGross, $taxStatus, $accumulatedTer);
                        $pph21Monthly = $taxBreakdown['monthly_tax'];
                    } else {
                        $taxBreakdown = $this->tax->calculateTerMonthly($monthlyGross, $taxStatus);
                        $pph21Monthly = $taxBreakdown['monthly_tax'];
                    }
                }

                $net = round($gross - $deduction - $latePenalty - $bpjsEmployee - $pph21Monthly, 2);

                PayrollRun::updateOrCreate(
                    [
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'store_id' => $store->id,
                        'basic_salary' => $basic,
                        'deductions' => $deduction,
                        'overtime_hours' => $overtimeHours,
                        'overtime_pay' => $overtimePay,
                        'gross_salary' => $gross,
                        'net_salary' => $net,
                        'attendance_summary' => $summary,
                        'component_breakdown' => [
                            'earnings' => $earnings->values()->map(fn ($c) => [
                                'name' => $c->name,
                                'amount' => (float) $c->amount,
                            ])->all(),
                            'deductions' => $deductions->values()->map(fn ($c) => [
                                'name' => $c->name,
                                'amount' => (float) $c->amount,
                            ])->all(),
                            'late_penalty' => [
                                'method' => $store->late_penalty_method,
                                'late_days' => $lateDays,
                                'late_minutes' => $lateMinutes,
                                'penalty_per_day' => (float) $store->late_penalty_per_day,
                                'penalty_per_minute' => (float) $store->late_penalty_per_minute,
                                'total' => $latePenalty,
                            ],
                            'overtime' => [
                                'hours' => $overtimeHours,
                                'rate_multiplier' => $overtimeRate,
                                'hourly_rate' => $this->hourlyRate($basic),
                                'total' => $overtimePay,
                            ],
                            'bpjs' => $bpjsBreakdown,
                            'tax' => $taxBreakdown,
                        ],
                    ],
                );
            }

            $period->update(['status' => 'locked']);
        });

        return $employees->count();
    }
}
