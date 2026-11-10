<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Isolasi data antar toko dan integritas periode gaji (Fase 1 kesiapan production).
 */
function makeAddOnStore(string $name): Store
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => str($name)->slug().'-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'settings' => [],
        'attendance_payroll_until' => now()->addDays(30),
    ]);
    AttendanceSettings::update($store, ['enabled' => true, 'require_geolocation' => false, 'require_selfie' => false]);

    return $store->fresh();
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->storeA = makeAddOnStore('Toko A');
    $this->storeB = makeAddOnStore('Toko B');

    $this->adminA = User::factory()->create([
        'store_id' => $this->storeA->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->employeeB = Employee::query()->create([
        'store_id' => $this->storeB->id,
        'name' => 'Karyawan Toko B',
        'is_active' => true,
    ]);
});

test('admin toko A tidak bisa membuat jadwal untuk karyawan toko B', function () {
    $this->actingAs($this->adminA)
        ->post('/attendance/schedules', [
            'employee_id' => $this->employeeB->id,
            'name' => 'Pagi',
            'work_days' => [1, 2, 3],
            'shift_start' => '08:00',
            'shift_end' => '16:00',
            'break_minutes' => 60,
        ])
        ->assertSessionHasErrors('employee_id');

    expect(WorkSchedule::count())->toBe(0);
});

test('admin toko A tidak bisa membuat lembur untuk karyawan toko B', function () {
    $this->actingAs($this->adminA)
        ->post('/attendance/overtimes', [
            'employee_id' => $this->employeeB->id,
            'work_date' => now()->toDateString(),
            'hours' => 24,
        ])
        ->assertSessionHasErrors('employee_id');

    expect(OvertimeRequest::count())->toBe(0);
});

test('admin toko A tidak bisa membuat cuti untuk karyawan toko B', function () {
    $this->actingAs($this->adminA)
        ->post('/attendance/leaves', [
            'employee_id' => $this->employeeB->id,
            'leave_type' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('employee_id');

    expect(LeaveRequest::count())->toBe(0);
});

test('admin tidak bisa menautkan akun user yang bukan anggota toko', function () {
    $orangLuar = User::factory()->create(['store_id' => $this->storeB->id]);

    $this->actingAs($this->adminA)
        ->post('/attendance/employees', [
            'name' => 'Penyusup',
            'user_id' => $orangLuar->id,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('user_id');

    expect(Employee::where('user_id', $orangLuar->id)->exists())->toBeFalse();
});

test('karyawan bisa mengajukan cuti sendiri dan melihatnya di daftar', function () {
    $kasir = User::factory()->create([
        'store_id' => $this->storeA->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $employee = Employee::query()->create([
        'store_id' => $this->storeA->id,
        'user_id' => $kasir->id,
        'name' => $kasir->name,
        'is_active' => true,
    ]);

    $this->actingAs($kasir)
        ->post('/attendance/my-leaves', [
            'type' => 'sick',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'reason' => 'Demam',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $leave = LeaveRequest::firstOrFail();
    expect($leave->employee_id)->toBe($employee->id)
        ->and($leave->leave_type)->toBe('sick')
        ->and($leave->store_id)->toBe($this->storeA->id);

    $this->actingAs($kasir)
        ->get('/attendance/my-leaves')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaves.0.type', 'sick'));
});

function makePeriod(Store $store, string $status): PayrollPeriod
{
    $period = PayrollPeriod::create([
        'store_id' => $store->id,
        'name' => 'Gaji Bulan Ini',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => $status,
    ]);

    $employee = Employee::query()->create(['store_id' => $store->id, 'name' => 'Rina', 'is_active' => true]);
    \App\Modules\Payroll\Models\SalaryComponent::create([
        'store_id' => $store->id, 'employee_id' => $employee->id, 'name' => 'Gaji Pokok', 'type' => 'earning', 'amount' => 3000000,
    ]);

    PayrollRun::create([
        'store_id' => $store->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'basic_salary' => 3000000,
        'deductions' => 0,
        'overtime_hours' => 0,
        'overtime_pay' => 0,
        'gross_salary' => 3000000,
        'net_salary' => 3000000,
    ]);

    return $period;
}

test('alur gaji: hitung tetap draft, lalu disetujui, dibuka kembali dengan alasan, lalu dibayar', function () {
    $period = makePeriod($this->storeA, 'draft');

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/run")->assertSessionHas('success');
    expect($period->fresh()->status)->toBe('draft');

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/approve")->assertSessionHas('success');
    expect($period->fresh())
        ->status->toBe('approved')
        ->approved_by->toBe($this->adminA->id);

    // Disetujui: tidak bisa dihitung ulang atau dihapus.
    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/run")->assertSessionHas('error');
    $this->actingAs($this->adminA)->delete("/payroll/{$period->id}")->assertSessionHas('error');

    // Buka kembali wajib alasan.
    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/reopen", [])->assertSessionHasErrors('reason');
    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/reopen", ['reason' => 'Koreksi lembur'])->assertSessionHas('success');
    expect($period->fresh()->status)->toBe('draft');

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/approve");
    $this->actingAs($this->adminA)
        ->post("/payroll/{$period->id}/pay", ['payment_method' => 'bank', 'payment_date' => now()->toDateString()])
        ->assertSessionHas('success');

    expect($period->fresh())
        ->status->toBe('paid')
        ->payment_method->toBe('bank')
        ->paid_by->toBe($this->adminA->id);

    // Dibayar = final.
    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/reopen", ['reason' => 'x'])->assertSessionHas('error');
    $this->actingAs($this->adminA)->delete("/payroll/{$period->id}")->assertSessionHas('error');

    expect(\App\Models\ActivityLog::where('store_id', $this->storeA->id)->where('action', 'like', 'payroll.%')->pluck('action')->all())
        ->toContain('payroll.calculated', 'payroll.approved', 'payroll.reopened', 'payroll.paid');
});

test('periode tanpa hasil hitung tidak bisa disetujui, dan draft tidak bisa langsung dibayar', function () {
    $period = PayrollPeriod::create([
        'store_id' => $this->storeA->id,
        'name' => 'Kosong',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'draft',
    ]);

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/approve")->assertSessionHas('error');
    $this->actingAs($this->adminA)
        ->post("/payroll/{$period->id}/pay", ['payment_method' => 'cash', 'payment_date' => now()->toDateString()])
        ->assertSessionHas('error');

    expect($period->fresh()->status)->toBe('draft');
});

test('pembayaran gaji memposting jurnal seimbang bila modul akuntansi aktif', function () {
    $this->storeA->update(['settings' => [...($this->storeA->settings ?? []), 'accounting_enabled' => true]]);
    $period = PayrollPeriod::create([
        'store_id' => $this->storeA->id,
        'name' => 'Gaji Bulan Ini',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'approved',
    ]);
    $employee = Employee::query()->create(['store_id' => $this->storeA->id, 'name' => 'Rina', 'is_active' => true]);

    // Bruto 10 jt; PPh 200.000; BPJS karyawan 400.000, perusahaan 600.000; kasbon 100.000 -> bersih 9.300.000.
    PayrollRun::create([
        'store_id' => $this->storeA->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'basic_salary' => 10_000_000,
        'deductions' => 700_000,
        'overtime_hours' => 0,
        'overtime_pay' => 0,
        'gross_salary' => 10_000_000,
        'net_salary' => 9_300_000,
        'component_breakdown' => [
            'tax' => ['method' => 'ter', 'monthly_tax' => 200_000],
            'bpjs' => [
                'health' => ['employee' => 100_000, 'employer' => 400_000],
                'jht' => ['employee' => 200_000, 'employer' => 100_000],
                'jp' => ['employee' => 100_000, 'employer' => 50_000],
                'jkk' => 20_000,
                'jkm' => 30_000,
                'total_employee' => 400_000,
            ],
        ],
    ]);

    $this->actingAs($this->adminA)
        ->post("/payroll/{$period->id}/pay", ['payment_method' => 'cash', 'payment_date' => now()->toDateString()])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $entry = \App\Models\JournalEntry::where('reference_type', 'payroll_period')->where('reference_id', $period->id)->firstOrFail();
    $lines = \App\Models\JournalLine::where('journal_entry_id', $entry->id)->get()
        ->mapWithKeys(fn ($l) => [\App\Models\ChartOfAccount::find($l->chart_of_account_id)->code => [(float) $l->debit, (float) $l->credit]]);

    expect($period->fresh()->journal_entry_id)->toBe($entry->id)
        ->and($lines['6.2.01'])->toBe([9_900_000.0, 0.0])   // beban gaji = bruto − kasbon
        ->and($lines['6.2.02'])->toBe([600_000.0, 0.0])     // BPJS perusahaan
        ->and($lines['2.1.04'])->toBe([0.0, 200_000.0])     // utang PPh 21
        ->and($lines['2.1.05'])->toBe([0.0, 1_000_000.0])   // utang BPJS karyawan + perusahaan
        ->and($lines['1.1.01'])->toBe([0.0, 9_300_000.0])   // kas keluar
        ->and($lines->sum(fn ($l) => $l[0]))->toBe($lines->sum(fn ($l) => $l[1]));
});

test('admin toko A tidak bisa menyetujui atau membayar periode toko B', function () {
    $period = makePeriod($this->storeB, 'draft');

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/approve")->assertNotFound();
    $this->actingAs($this->adminA)
        ->post("/payroll/{$period->id}/pay", ['payment_method' => 'cash', 'payment_date' => now()->toDateString()])
        ->assertNotFound();

    expect($period->fresh()->status)->toBe('draft');
});

test('kasir tidak bisa menyetujui periode gaji', function () {
    $period = makePeriod($this->storeA, 'draft');
    $kasir = User::factory()->create(['store_id' => $this->storeA->id, 'role' => 'cashier', 'email_verified_at' => now()]);

    $this->actingAs($kasir)->post("/payroll/{$period->id}/approve")->assertForbidden();
});

test('slip gaji PDF: admin bisa unduh, karyawan hanya slip sendiri yang sudah disetujui', function () {
    $period = makePeriod($this->storeA, 'draft');
    $run = PayrollRun::where('payroll_period_id', $period->id)->firstOrFail();

    $this->actingAs($this->adminA)->get("/payroll/{$period->id}/runs/{$run->id}/slip")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $kasir = User::factory()->create(['store_id' => $this->storeA->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $run->employee->update(['user_id' => $kasir->id]);

    // Draft belum terlihat oleh karyawan.
    $this->actingAs($kasir)->get('/attendance/my-payslips')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Attendance/MyPayslips')->has('slips', 0));
    $this->actingAs($kasir)->get("/attendance/my-payslips/{$run->id}")->assertNotFound();

    $period->update(['status' => 'approved']);

    $this->actingAs($kasir)->get('/attendance/my-payslips')->assertOk()
        ->assertInertia(fn ($page) => $page->has('slips', 1)->where('slips.0.net_salary', 3000000));
    $this->actingAs($kasir)->get("/attendance/my-payslips/{$run->id}")->assertOk()->assertHeader('content-type', 'application/pdf');

    // Slip karyawan lain tidak bisa dibuka.
    $lain = makePeriod($this->storeA, 'approved');
    $runLain = PayrollRun::where('payroll_period_id', $lain->id)->firstOrFail();
    $this->actingAs($kasir)->get("/attendance/my-payslips/{$runLain->id}")->assertNotFound();

    // Kasir tidak bisa memakai jalur admin.
    $this->actingAs($kasir)->get("/payroll/{$period->id}/runs/{$run->id}/slip")->assertForbidden();
});

test('karyawan tanpa komponen gaji dilewati saat hitung dan ditampilkan di detail periode', function () {
    $period = makePeriod($this->storeA, 'draft');
    Employee::query()->create(['store_id' => $this->storeA->id, 'name' => 'Pemilik Tanpa Gaji', 'is_active' => true]);

    $this->actingAs($this->adminA)->post("/payroll/{$period->id}/run")->assertSessionHas('success');

    expect(PayrollRun::where('payroll_period_id', $period->id)->count())->toBe(1);

    $this->actingAs($this->adminA)->get("/payroll/{$period->id}")
        ->assertInertia(fn ($page) => $page->where('skipped_employees', ['Pemilik Tanpa Gaji']));

    $this->actingAs($this->adminA)->get('/payroll')
        ->assertInertia(fn ($page) => $page->where('setup.total', 6)->has('setup.steps', 6));
});
