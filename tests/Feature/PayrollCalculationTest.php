<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\PayrollService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Kebenaran perhitungan gaji (Fase 2 kesiapan production).
 * Periode uji: November 2026 (30 hari) dan Desember 2026.
 */
beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Gaji',
        'slug' => 'toko-gaji-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'settings' => [],
        'attendance_payroll_until' => now()->addYear(),
        'bpjs_enabled' => false,
        'pph21_enabled' => false,
        'late_penalty_method' => 'none',
    ]);
    AttendanceSettings::update($this->store, ['enabled' => true, 'overtime_requires_approval' => true]);
    $this->store->refresh();
});

function employeeWithSalary(Store $store, float $fixed, array $extra = [], float $variable = 0, float $deduction = 0): Employee
{
    $employee = Employee::query()->create([
        'store_id' => $store->id,
        'name' => 'Karyawan '.uniqid(),
        'is_active' => true,
        'tax_status' => 'TK/0',
        ...$extra,
    ]);

    SalaryComponent::create(['store_id' => $store->id, 'employee_id' => $employee->id, 'name' => 'Gaji Pokok', 'type' => 'earning', 'is_fixed' => true, 'amount' => $fixed]);
    if ($variable > 0) {
        SalaryComponent::create(['store_id' => $store->id, 'employee_id' => $employee->id, 'name' => 'Uang Makan', 'type' => 'earning', 'is_fixed' => false, 'amount' => $variable]);
    }
    if ($deduction > 0) {
        SalaryComponent::create(['store_id' => $store->id, 'employee_id' => $employee->id, 'name' => 'Kasbon', 'type' => 'deduction', 'amount' => $deduction]);
    }

    return $employee;
}

function runNovember(Store $store): PayrollPeriod
{
    $period = PayrollPeriod::create([
        'store_id' => $store->id,
        'name' => 'November 2026',
        'period_start' => '2026-11-01',
        'period_end' => '2026-11-30',
        'status' => 'draft',
    ]);

    app(PayrollService::class)->runPeriod($store, $period);

    return $period;
}

function runFor(PayrollPeriod $period, Employee $employee): PayrollRun
{
    return PayrollRun::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)->firstOrFail();
}

test('BPJS Kesehatan dan JP memakai batas upah, JHT tanpa batas', function () {
    $this->store->update(['bpjs_enabled' => true]);
    $employee = employeeWithSalary($this->store, 20_000_000);

    $bpjs = runFor(runNovember($this->store->fresh()), $employee)->component_breakdown['bpjs'];

    expect($bpjs['health']['employee'])->toEqual(120_000)   // 1% × 12 jt
        ->and($bpjs['health']['employer'])->toEqual(480_000)  // 4% × 12 jt
        ->and($bpjs['jp']['employee'])->toEqual(110_863)      // 1% × 11.086.300
        ->and($bpjs['jht']['employee'])->toEqual(400_000);    // 2% × 20 jt
});

test('lembur dihitung per hari: jam pertama, jam berikutnya, dan hari libur', function () {
    // Upah tetap 1.730.000 -> upah per jam 10.000.
    $employee = employeeWithSalary($this->store, 1_730_000);
    Holiday::create(['store_id' => $this->store->id, 'date' => '2026-11-15', 'name' => 'Libur toko']);

    foreach ([['2026-11-10', 3], ['2026-11-15', 2]] as [$date, $hours]) {
        OvertimeRequest::create([
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'work_date' => $date,
            'hours' => $hours,
            'status' => 'approved',
        ]);
    }

    $run = runFor(runNovember($this->store), $employee);

    // Hari biasa 3 jam: 1×1,5 + 2×2 = 5,5 × 10.000 = 55.000. Hari libur 2 jam × 2 = 40.000.
    expect((float) $run->overtime_pay)->toEqual(95_000.0)
        ->and($run->component_breakdown['overtime']['holiday_hours'])->toEqual(2);
});

test('karyawan yang masuk di tengah periode digaji prorata', function () {
    $employee = employeeWithSalary($this->store, 3_000_000, ['joined_at' => '2026-11-21']);

    $run = runFor(runNovember($this->store), $employee);

    // 21–30 November = 10 dari 30 hari.
    expect((float) $run->basic_salary)->toEqual(1_000_000.0)
        ->and($run->component_breakdown['proration']['days_employed'])->toBe(10);
});

test('karyawan yang keluar di tengah periode tetap mendapat slip prorata', function () {
    $employee = employeeWithSalary($this->store, 3_000_000, ['is_active' => false, 'resigned_at' => '2026-11-15']);
    $lama = employeeWithSalary($this->store, 3_000_000, ['is_active' => false, 'resigned_at' => '2026-10-31']);

    $period = runNovember($this->store);

    expect((float) runFor($period, $employee)->basic_salary)->toEqual(1_500_000.0)
        ->and(PayrollRun::where('payroll_period_id', $period->id)->where('employee_id', $lama->id)->exists())->toBeFalse();
});

test('potongan alpa dan cuti tanpa gaji memakai upah harian bila diaktifkan', function () {
    $this->store->update(['absence_deduction_enabled' => true]);
    $employee = employeeWithSalary($this->store, 2_500_000);

    foreach (['2026-11-03', '2026-11-04'] as $date) {
        Attendance::create([
            'store_id' => $this->store->id,
            'employee_id' => $employee->id,
            'work_date' => $date,
            'status' => Attendance::STATUS_ABSENT,
            'source' => Attendance::SOURCE_IMPORTED,
        ]);
    }

    $run = runFor(runNovember($this->store->fresh()), $employee);

    // Tanpa jadwal: upah harian = 2.500.000 / 25 = 100.000; 2 hari alpa.
    expect($run->component_breakdown['absence']['total'])->toEqual(200_000)
        ->and((float) $run->gross_salary)->toEqual(2_300_000.0);
});

test('alpa tidak memotong gaji bila opsi potongan dimatikan', function () {
    $employee = employeeWithSalary($this->store, 2_500_000);
    Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-11-03',
        'status' => Attendance::STATUS_ABSENT,
        'source' => Attendance::SOURCE_IMPORTED,
    ]);

    expect((float) runFor(runNovember($this->store), $employee)->gross_salary)->toEqual(2_500_000.0);
});

test('gaji bersih tidak pernah negatif, kekurangannya dicatat', function () {
    $employee = employeeWithSalary($this->store, 2_000_000, deduction: 2_500_000);

    $run = runFor(runNovember($this->store), $employee);

    expect((float) $run->net_salary)->toEqual(0.0)
        ->and($run->component_breakdown['shortfall'])->toEqual(500_000);
});

test('tunjangan tidak tetap tidak menjadi dasar BPJS dan upah lembur', function () {
    $this->store->update(['bpjs_enabled' => true]);
    $employee = employeeWithSalary($this->store, 5_000_000, variable: 1_000_000);

    $run = runFor(runNovember($this->store->fresh()), $employee);

    expect((float) $run->gross_salary)->toEqual(6_000_000.0)
        ->and($run->component_breakdown['bpjs']['jht']['employee'])->toEqual(100_000) // 2% × 5 jt
        ->and($run->component_breakdown['overtime']['hourly_rate'])->toEqual(28_902.0); // 5 jt / 173
});

test('TER bulanan dihitung dari bruto ditambah premi BPJS yang ditanggung perusahaan', function () {
    $this->store->update(['bpjs_enabled' => true, 'pph21_enabled' => true]);
    $employee = employeeWithSalary($this->store, 10_000_000);

    $tax = runFor(runNovember($this->store->fresh()), $employee)->component_breakdown['tax'];

    // 10 jt + Kesehatan 400.000 + JKK 24.000 + JKM 30.000 = 10.454.000 -> TER A 2,5% = 261.350.
    expect($tax['monthly_gross'])->toEqual(10_454_000)
        ->and($tax['rate_percent'])->toBe(2.5)
        ->and($tax['monthly_tax'])->toEqual(261_350);
});

test('rekonsiliasi Desember memakai riwayat setahun dan hanya di periode Desember terakhir', function () {
    $this->store->update(['pph21_enabled' => true]);
    $employee = employeeWithSalary($this->store, 10_000_000);

    // Riwayat Januari–November: bruto kena pajak 10 jt, TER 200.000, iuran JHT+JP 300.000 per bulan.
    foreach (range(1, 11) as $month) {
        $start = sprintf('2026-%02d-01', $month);
        $period = PayrollPeriod::create([
            'store_id' => $this->store->id,
            'name' => "Bulan {$month}",
            'period_start' => $start,
            'period_end' => date('Y-m-t', strtotime($start)),
            'status' => 'locked',
        ]);
        PayrollRun::create([
            'store_id' => $this->store->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'basic_salary' => 10_000_000,
            'deductions' => 0,
            'overtime_hours' => 0,
            'overtime_pay' => 0,
            'gross_salary' => 10_000_000,
            'net_salary' => 9_500_000,
            'component_breakdown' => [
                'taxable_gross' => 10_000_000,
                'tax' => ['method' => 'ter', 'monthly_tax' => 200_000],
                'bpjs' => ['jht' => ['employee' => 200_000], 'jp' => ['employee' => 100_000]],
            ],
        ]);
    }

    $december = PayrollPeriod::create([
        'store_id' => $this->store->id,
        'name' => 'Desember 2026',
        'period_start' => '2026-12-01',
        'period_end' => '2026-12-31',
        'status' => 'draft',
    ]);
    app(PayrollService::class)->runPeriod($this->store->fresh(), $december);

    $tax = runFor($december, $employee)->component_breakdown['tax'];

    // Bruto 120 jt − biaya jabatan 6 jt − iuran 3,3 jt = 110,7 jt; PKP 56,7 jt; PPh setahun 2.835.000.
    // Sudah dipotong 2.200.000 -> Desember 635.000.
    expect($tax['method'])->toBe('ter_december')
        ->and($tax['pkp'])->toEqual(56_700_000)
        ->and($tax['annual_tax'])->toEqual(2_835_000)
        ->and($tax['monthly_tax'])->toEqual(635_000);
});

test('periode Desember yang bukan terakhir memakai TER biasa', function () {
    $this->store->update(['pph21_enabled' => true]);
    $employee = employeeWithSalary($this->store, 10_000_000);

    $first = PayrollPeriod::create(['store_id' => $this->store->id, 'name' => 'Des A', 'period_start' => '2026-12-01', 'period_end' => '2026-12-15', 'status' => 'draft']);
    PayrollPeriod::create(['store_id' => $this->store->id, 'name' => 'Des B', 'period_start' => '2026-12-16', 'period_end' => '2026-12-31', 'status' => 'draft']);

    app(PayrollService::class)->runPeriod($this->store->fresh(), $first);

    expect(runFor($first, $employee)->component_breakdown['tax']['method'])->toBe('ter');
});

test('periode gaji yang tumpang tindih ditolak', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    PayrollPeriod::create(['store_id' => $this->store->id, 'name' => 'November', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'draft']);

    $this->actingAs($admin)
        ->post('/payroll', ['name' => 'Bentrok', 'period_start' => '2026-11-25', 'period_end' => '2026-12-24'])
        ->assertSessionHasErrors('period_start');

    $this->actingAs($admin)
        ->post('/payroll', ['name' => 'Desember', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31'])
        ->assertSessionHasNoErrors();

    expect(PayrollPeriod::count())->toBe(2);
});

test('halaman admin payroll dan absensi yang diubah tetap terbuka dengan data baru', function () {
    $admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $employee = employeeWithSalary($this->store, 5_000_000, ['joined_at' => '2026-11-21'], variable: 500_000);
    Holiday::create(['store_id' => $this->store->id, 'date' => now()->toDateString(), 'name' => 'Libur']);
    $period = runNovember($this->store);

    $this->actingAs($admin)->get('/payroll/settings')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Payroll/Settings')
            ->where('settings.bpjs_health_wage_cap', 12000000)
            ->where('settings.absence_deduction_enabled', false));

    $this->actingAs($admin)->get('/payroll/salaries')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Payroll/Salaries')
            ->where('employees.0.components.1.is_fixed', false));

    $this->actingAs($admin)->get("/payroll/{$period->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Payroll/Show')
            ->where('runs.0.component_breakdown.proration.days_employed', 10));

    $this->actingAs($admin)->get('/attendance/settings')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Attendance/Settings')->has('holidays', 1));

    $this->actingAs($admin)->get('/attendance/employees')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Attendance/Employees')
            ->where('employees.0.joined_at', '2026-11-21'));

    expect($employee->fresh()->joined_at->toDateString())->toBe('2026-11-21');
});
