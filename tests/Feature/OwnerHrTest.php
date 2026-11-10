<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\PayrollApproval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Role owner di modul SDM & Gaji.
 */
function hrOutlet(string $name): Store
{
    $store = Store::query()->create([
        'name' => $name, 'slug' => 'outlet-'.uniqid(), 'is_active' => true, 'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta', 'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($store, ['enabled' => true, 'require_selfie' => false, 'require_geolocation' => false]);

    return $store->refresh();
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Jakarta'));

    $this->a = hrOutlet('Outlet A');
    $this->b = hrOutlet('Outlet B');
    $this->owner = User::factory()->create(['store_id' => $this->a->id, 'role' => 'owner', 'email_verified_at' => now()]);
    $this->a->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->b->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->admin = User::factory()->create(['store_id' => $this->a->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->a->members()->attach($this->admin->id, ['role' => 'admin']);
});

test('owner dan admin yang membuka halaman absen tidak otomatis jadi karyawan', function () {
    foreach ([$this->owner, $this->admin] as $user) {
        $this->actingAs($user)->get('/attendance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('profile', null)->where('attendanceToday', null));
        $this->actingAs($user)->post('/attendance/clock-in')->assertSessionHas('error');
    }

    expect(Employee::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0);
});

test('owner yang sengaja didaftarkan sebagai karyawan tetap bisa absen', function () {
    Employee::query()->create(['store_id' => $this->a->id, 'user_id' => $this->owner->id, 'name' => 'Pak Owner', 'is_active' => true]);

    $this->actingAs($this->owner)->get('/attendance')->assertInertia(fn ($page) => $page->where('profile.name', 'Pak Owner'));
    $this->actingAs($this->owner)->post('/attendance/clock-in')->assertSessionHas('success');
    expect(Attendance::query()->count())->toBe(1);
});

test('kasir tetap otomatis dibuatkan profil karyawan', function () {
    $kasir = User::factory()->create(['store_id' => $this->a->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $this->a->members()->attach($kasir->id, ['role' => 'cashier']);

    $this->actingAs($kasir)->get('/attendance')->assertInertia(fn ($page) => $page->where('profile.name', $kasir->name));
    expect(Employee::query()->where('user_id', $kasir->id)->exists())->toBeTrue();
});

test('owner bisa membuka Pengaturan SDM dari menu (ability manage_hr)', function () {
    $this->actingAs($this->owner)->get('/attendance/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('abilities.manage_hr', true));
});

test('badge pengajuan owner menjumlah semua outlet dan menyebut outlet lain', function () {
    $empA = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Ani', 'is_active' => true]);
    $empB = Employee::query()->create(['store_id' => $this->b->id, 'name' => 'Budi', 'is_active' => true]);
    LeaveRequest::create(['store_id' => $this->a->id, 'employee_id' => $empA->id, 'leave_type' => 'annual', 'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'status' => 'pending']);
    LeaveRequest::create(['store_id' => $this->b->id, 'employee_id' => $empB->id, 'leave_type' => 'sick', 'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'status' => 'pending']);
    OvertimeRequest::create(['store_id' => $this->b->id, 'employee_id' => $empB->id, 'work_date' => '2026-11-09', 'hours' => 2, 'status' => 'pending']);

    $this->actingAs($this->owner)->get('/attendance/leaves')
        ->assertInertia(fn ($page) => $page
            ->where('pending_hr_requests.leave', 1)
            ->where('pending_hr_requests.overtime', 0)
            ->where('pending_hr_requests.total', 3)
            ->where('pending_hr_requests.others.0.name', 'Outlet B')
            ->where('pending_hr_requests.others.0.leave', 1)
            ->where('pending_hr_requests.others.0.overtime', 1));

    // Admin hanya mengelola Outlet A: tidak melihat outlet B
    $this->actingAs($this->admin)->get('/attendance/leaves')
        ->assertInertia(fn ($page) => $page->where('pending_hr_requests.total', 1)->where('pending_hr_requests.others', []));
});

test('dashboard owner menampilkan ringkasan SDM per outlet', function () {
    $ani = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Ani', 'is_active' => true]);
    $budi = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Budi', 'is_active' => true]);
    $citra = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Citra', 'is_active' => true]);
    foreach ([$ani, $budi, $citra] as $e) {
        WorkSchedule::create(['store_id' => $this->a->id, 'employee_id' => $e->id, 'name' => 'Pagi', 'work_days' => [1, 2, 3, 4, 5, 6, 7], 'shift_start' => '08:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'is_active' => true]);
    }
    Attendance::create(['store_id' => $this->a->id, 'employee_id' => $ani->id, 'work_date' => '2026-11-10', 'clock_in' => '2026-11-10 08:30:00', 'status' => Attendance::STATUS_LATE, 'late_minutes' => 15, 'source' => Attendance::SOURCE_DEVICE]);
    LeaveRequest::create(['store_id' => $this->a->id, 'employee_id' => $budi->id, 'leave_type' => 'annual', 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'status' => 'approved']);
    $period = PayrollPeriod::create(['store_id' => $this->a->id, 'name' => 'Oktober 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved']);
    PayrollRun::create(['store_id' => $this->a->id, 'payroll_period_id' => $period->id, 'employee_id' => $ani->id, 'basic_salary' => 3000000, 'deductions' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'gross_salary' => 3000000, 'net_salary' => 2900000]);

    $this->actingAs($this->owner)->get('/owner/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('hr.outlets', 2)
            ->where('hr.outlets.0.name', 'Outlet A')
            ->where('hr.outlets.0.today.present', 1)
            ->where('hr.outlets.0.today.late', 1)
            ->where('hr.outlets.0.today.on_leave', 1)
            ->where('hr.outlets.0.today.not_yet', 1)
            ->where('hr.outlets.0.today.not_yet_names', ['Citra'])
            ->where('hr.outlets.0.payroll.status', 'approved')
            ->where('hr.outlets.0.payroll.total_net', 2900000)
            ->where('hr.totals.employees', 3));
});

test('persetujuan gaji oleh pemilik: admin tidak bisa menyetujui, owner bisa', function () {
    $emp = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Ani', 'is_active' => true]);
    $period = PayrollPeriod::create(['store_id' => $this->a->id, 'name' => 'November 2026', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'draft']);
    PayrollRun::create(['store_id' => $this->a->id, 'payroll_period_id' => $period->id, 'employee_id' => $emp->id, 'basic_salary' => 3000000, 'deductions' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'gross_salary' => 3000000, 'net_salary' => 3000000]);

    $this->actingAs($this->admin)->put('/payroll/settings', [
        'late_penalty_per_day' => 0, 'late_penalty_per_minute' => 0, 'late_penalty_method' => 'none',
        'overtime_rate_first_hour' => 0, 'overtime_rate_next_hour' => 0, 'overtime_rate_holiday' => 0,
        'bpjs_enabled' => false, 'bpjs_health_wage_cap' => 0, 'bpjs_jp_wage_cap' => 0,
        'absence_deduction_enabled' => false, 'pph21_enabled' => false, 'owner_approval' => true,
    ])->assertSessionHas('success');
    expect(PayrollApproval::ownerOnly($this->a->fresh()))->toBeTrue();

    $this->actingAs($this->admin)->get("/payroll/{$period->id}")
        ->assertInertia(fn ($page) => $page->where('approval.owner_only', true)->where('approval.can_approve', false));
    $this->actingAs($this->admin)->post("/payroll/{$period->id}/approve")->assertSessionHas('error');
    expect($period->fresh()->status)->toBe('draft');

    $this->actingAs($this->owner)->post("/payroll/{$period->id}/approve")->assertSessionHas('success');
    expect($period->fresh()->status)->toBe('approved');
});

test('tanpa akun owner, aturan persetujuan pemilik tidak mengunci admin', function () {
    $this->a->members()->detach($this->owner->id);
    PayrollApproval::setOwnerOnly($this->a, true);
    $emp = Employee::query()->create(['store_id' => $this->a->id, 'name' => 'Ani', 'is_active' => true]);
    $period = PayrollPeriod::create(['store_id' => $this->a->id, 'name' => 'November 2026', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'draft']);
    PayrollRun::create(['store_id' => $this->a->id, 'payroll_period_id' => $period->id, 'employee_id' => $emp->id, 'basic_salary' => 1, 'deductions' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'gross_salary' => 1, 'net_salary' => 1]);

    $this->actingAs($this->admin)->post("/payroll/{$period->id}/approve")->assertSessionHas('success');
});
