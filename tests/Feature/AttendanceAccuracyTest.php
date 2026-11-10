<?php

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Kebenaran data absensi (Fase 2 kesiapan production).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->store = Store::query()->create([
        'name' => 'Toko Absen',
        'slug' => 'toko-absen-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta',
        'settings' => [],
        'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, [
        'enabled' => true,
        'require_selfie' => false,
        'require_geolocation' => false,
        'grace_minutes' => 0,
    ]);
    $this->store->refresh();

    $this->admin = User::factory()->create([
        'store_id' => $this->store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->employee = Employee::query()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->admin->id,
        'name' => 'Penjaga Malam',
        'is_active' => true,
    ]);

    $this->service = app(AttendanceService::class);
});

test('shift malam bisa check-out setelah tengah malam dengan jam kerja yang benar', function () {
    WorkSchedule::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'name' => 'Malam',
        'work_days' => [1, 2, 3, 4, 5, 6, 7],
        'shift_start' => '22:00',
        'shift_end' => '06:00',
        'break_minutes' => 0,
        'is_active' => true,
    ]);

    // 22:00 WIB = 15:00 UTC
    $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00:00', 'UTC'));
    $this->actingAs($this->admin)->post('/attendance/clock-in')->assertSessionHasNoErrors();

    // 06:30 WIB keesokan harinya
    $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30:00', 'UTC'));
    $this->actingAs($this->admin)->post('/attendance/clock-out')->assertSessionHasNoErrors();

    $attendance = Attendance::firstOrFail();

    expect($attendance->work_date->toDateString())->toBe('2026-11-09')
        ->and($attendance->clock_out)->not->toBeNull()
        ->and($attendance->status)->toBe(Attendance::STATUS_PRESENT)
        ->and($attendance->hours_worked)->toBe(8.5)
        ->and($attendance->overtime_hours)->toBe(0.5)
        ->and($attendance->early_leave_minutes)->toBe(0);
});

test('cuti yang disetujui tercatat di absensi dan dihapus saat pengajuan dihapus', function () {
    $leave = LeaveRequest::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'leave_type' => 'unpaid',
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-04',
        'status' => 'pending',
    ]);
    Holiday::create(['store_id' => $this->store->id, 'date' => '2026-11-03', 'name' => 'Libur']);

    // Cuti milik admin sendiri harus disetujui orang lain.
    $owner = User::factory()->create(['store_id' => $this->store->id, 'role' => 'owner', 'email_verified_at' => now()]);

    $this->actingAs($owner)
        ->put("/attendance/leaves/{$leave->id}", ['status' => 'approved'])
        ->assertSessionHasNoErrors();

    // 2 dan 4 November tercatat cuti; 3 November hari libur dilewati.
    expect(Attendance::where('leave_request_id', $leave->id)->orderBy('work_date')->pluck('work_date')->map->toDateString()->all())
        ->toBe(['2026-11-02', '2026-11-04'])
        ->and(Attendance::where('leave_request_id', $leave->id)->first()->status)->toBe(Attendance::STATUS_LEAVE);

    $summary = $this->service->aggregate($this->employee->fresh(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($summary['leave_days'])->toBe(2)
        ->and($summary['unpaid_leave_days'])->toBe(2);

    $this->actingAs($this->admin)->delete("/attendance/leaves/{$leave->id}")->assertSessionHasNoErrors();

    expect(Attendance::count())->toBe(0);
});

test('karyawan tidak ditandai alpa pada hari libur', function () {
    WorkSchedule::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'name' => 'Pagi',
        'work_days' => [1, 2, 3, 4, 5, 6, 7],
        'shift_start' => '08:00',
        'shift_end' => '16:00',
        'break_minutes' => 60,
        'is_active' => true,
    ]);
    Holiday::create(['store_id' => $this->store->id, 'date' => '2026-11-10', 'name' => 'Hari Pahlawan']);

    expect($this->service->markAbsent($this->store, CarbonImmutable::parse('2026-11-10')))->toBe(0)
        ->and($this->service->markAbsent($this->store, CarbonImmutable::parse('2026-11-11')))->toBe(1);
});

test('absen dari shift kasir memakai jam toko, bukan UTC', function () {
    // Shift dibuka 08:00 WIB (01:00 UTC) dan ditutup 16:00 WIB (09:00 UTC).
    CashShift::create([
        'store_id' => $this->store->id,
        'user_id' => $this->admin->id,
        'employee_id' => $this->employee->id,
        'shift_number' => 'SH-TEST-1',
        'opened_at' => CarbonImmutable::parse('2026-11-09 01:00:00', 'UTC'),
        'closed_at' => CarbonImmutable::parse('2026-11-09 09:00:00', 'UTC'),
        'opening_cash' => 0,
    ]);

    $processed = $this->service->generateFromCashShifts($this->store, CarbonImmutable::parse('2026-11-09'));
    $attendance = Attendance::firstOrFail();

    expect($processed)->toBe(1)
        ->and($attendance->work_date->toDateString())->toBe('2026-11-09')
        ->and($attendance->clock_in->format('H:i'))->toBe('08:00')
        ->and($attendance->clock_out->format('H:i'))->toBe('16:00')
        ->and($attendance->source)->toBe(Attendance::SOURCE_CASH_SHIFT);
});

test('shift kasir yang dibuka tengah malam WIB masuk ke tanggal toko yang benar', function () {
    // 00:30 WIB tanggal 10 = 17:30 UTC tanggal 9.
    CashShift::create([
        'store_id' => $this->store->id,
        'user_id' => $this->admin->id,
        'employee_id' => $this->employee->id,
        'shift_number' => 'SH-TEST-2',
        'opened_at' => CarbonImmutable::parse('2026-11-09 17:30:00', 'UTC'),
        'opening_cash' => 0,
    ]);

    expect($this->service->generateFromCashShifts($this->store, CarbonImmutable::parse('2026-11-09')))->toBe(0)
        ->and($this->service->generateFromCashShifts($this->store, CarbonImmutable::parse('2026-11-10')))->toBe(1)
        ->and(Attendance::firstOrFail()->work_date->toDateString())->toBe('2026-11-10');
});

test('admin bisa menambah dan menghapus hari libur, tanpa duplikat tanggal', function () {
    $this->actingAs($this->admin)
        ->post('/attendance/holidays', ['date' => '2026-12-25', 'name' => 'Natal'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post('/attendance/holidays', ['date' => '2026-12-25', 'name' => 'Duplikat'])
        ->assertSessionHasErrors('date');

    $holiday = Holiday::firstOrFail();

    $this->actingAs($this->admin)->delete("/attendance/holidays/{$holiday->id}")->assertSessionHasNoErrors();

    expect(Holiday::count())->toBe(0);
});

test('absen dari shift kasir tetap benar saat zona waktu aplikasi bukan UTC (server WITA)', function () {
    $originalConfig = config('app.timezone');
    $originalPhp = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Makassar']);
    date_default_timezone_set('Asia/Makassar');

    try {
        // Seperti di production: kolom timestamp berisi jam WITA. 09:00 WITA = 08:00 WIB.
        CashShift::create([
            'store_id' => $this->store->id,
            'user_id' => $this->admin->id,
            'employee_id' => $this->employee->id,
            'shift_number' => 'SH-WITA-1',
            'opened_at' => '2026-11-09 09:00:00',
            'closed_at' => '2026-11-09 17:00:00',
            'opening_cash' => 0,
        ]);
        // 00:30 WITA tanggal 10 = 23:30 WIB tanggal 9 → tetap tanggal 9 di toko WIB.
        CashShift::create([
            'store_id' => $this->store->id,
            'user_id' => $this->admin->id,
            'employee_id' => Employee::query()->create(['store_id' => $this->store->id, 'name' => 'Shift Malam', 'is_active' => true])->id,
            'shift_number' => 'SH-WITA-2',
            'opened_at' => '2026-11-10 00:30:00',
            'opening_cash' => 0,
        ]);

        expect($this->service->generateFromCashShifts($this->store, CarbonImmutable::parse('2026-11-09')))->toBe(2);

        $pagi = Attendance::where('employee_id', $this->employee->id)->firstOrFail();
        $malam = Attendance::where('employee_id', '!=', $this->employee->id)->firstOrFail();

        expect($pagi->work_date->toDateString())->toBe('2026-11-09')
            ->and($pagi->clock_in->format('H:i'))->toBe('08:00')
            ->and($pagi->clock_out->format('H:i'))->toBe('16:00')
            ->and($malam->work_date->toDateString())->toBe('2026-11-09')
            ->and($malam->clock_in->format('H:i'))->toBe('23:30');
    } finally {
        config(['app.timezone' => $originalConfig]);
        date_default_timezone_set($originalPhp);
    }
});
