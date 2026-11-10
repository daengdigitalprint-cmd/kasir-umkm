<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lembur otomatis dari jam absen pulang (shift 08:00–16:00).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->store = Store::query()->create([
        'name' => 'Toko Lembur', 'slug' => 'toko-lembur-'.uniqid(), 'is_active' => true, 'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta', 'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, ['enabled' => true, 'require_selfie' => false, 'require_geolocation' => false]);
    $this->store->refresh();

    $this->admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->kasir = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $this->employee = Employee::query()->create(['store_id' => $this->store->id, 'user_id' => $this->kasir->id, 'name' => 'Sari', 'is_active' => true]);
    WorkSchedule::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'name' => 'Pagi',
        'work_days' => [1, 2, 3, 4, 5, 6, 7], 'shift_start' => '08:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'is_active' => true,
    ]);

    $this->workDay = function (string $out, string $date = '2026-11-10') {
        $this->travelTo(CarbonImmutable::parse("$date 07:58:00", 'Asia/Jakarta'));
        $this->actingAs($this->kasir)->post('/attendance/clock-in')->assertSessionHas('success');
        $this->travelTo(CarbonImmutable::parse("$date $out:00", 'Asia/Jakarta'));
        $this->actingAs($this->kasir)->post('/attendance/clock-out')->assertSessionHas('success');

        return Attendance::query()->whereDate('work_date', $date)->sole();
    };
    $this->overtimeTotal = fn () => app(AttendanceService::class)->aggregate(
        $this->employee->fresh(),
        CarbonImmutable::parse('2026-11-01'),
        CarbonImmutable::parse('2026-11-30 23:59:59'),
    )['total_overtime'];
});

test('bawaan: lembur terdeteksi otomatis, menunggu persetujuan admin, jam bisa diubah saat disetujui', function () {
    expect(AttendanceSettings::overtimeMode($this->store))->toBe('auto_approval');

    $attendance = ($this->workDay)('18:10'); // lewat 130 menit → dibulatkan 120 menit
    expect((float) $attendance->overtime_hours)->toBe(2.0);

    $overtime = OvertimeRequest::query()->sole();
    expect($overtime->source)->toBe('auto')
        ->and($overtime->status)->toBe('pending')
        ->and($overtime->hours)->toBe(2.0)
        ->and($overtime->attendance_id)->toBe($attendance->id)
        ->and($overtime->reason)->toContain('pulang 18:10');

    // Belum disetujui: belum dibayar
    expect(($this->overtimeTotal)())->toBe(0.0);

    // Badge admin ikut menghitung lembur otomatis yang menunggu
    $this->actingAs($this->admin)->get('/attendance/overtimes')
        ->assertInertia(fn ($page) => $page
            ->where('pending_hr_requests.overtime', 1)
            ->where('policy.mode', 'auto_approval')
            ->where('overtimes.0.source', 'auto'));

    $this->actingAs($this->admin)->patch("/attendance/overtimes/{$overtime->id}", ['status' => 'approved', 'hours' => 1.5])
        ->assertSessionHas('success');
    expect($overtime->fresh()->hours)->toBe(1.5)
        ->and(($this->overtimeTotal)())->toBe(1.5);
});

test('pulang telat di bawah batas minimum tidak dihitung lembur', function () {
    $attendance = ($this->workDay)('16:20');

    expect((float) $attendance->overtime_hours)->toBe(0.0)
        ->and(OvertimeRequest::query()->count())->toBe(0);
});

test('karyawan tidak bisa mengajukan lembur ganda untuk hari yang sudah tercatat otomatis', function () {
    ($this->workDay)('17:45');

    $this->actingAs($this->kasir)->post('/attendance/my-overtimes', ['work_date' => '2026-11-10', 'hours' => 2])
        ->assertSessionHasErrors('work_date');
    expect(OvertimeRequest::query()->count())->toBe(1);

    // Hari lain tanpa lembur otomatis tetap bisa diajukan
    $this->actingAs($this->kasir)->post('/attendance/my-overtimes', ['work_date' => '2026-11-09', 'hours' => 2])
        ->assertSessionHasNoErrors();
});

test('pengajuan manual yang sudah ada tidak diduplikasi oleh deteksi otomatis', function () {
    OvertimeRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-11-10', 'hours' => 3, 'status' => 'pending',
    ]);

    ($this->workDay)('19:00');

    expect(OvertimeRequest::query()->count())->toBe(1)
        ->and(OvertimeRequest::query()->sole()->source)->toBe('request');
});

test('mode otomatis langsung: lembur dihitung tanpa persetujuan dan tidak ganda', function () {
    AttendanceSettings::update($this->store, ['overtime_mode' => 'auto']);
    $this->store->refresh();

    ($this->workDay)('17:30');

    $overtime = OvertimeRequest::query()->sole();
    expect($overtime->status)->toBe('approved')
        ->and($overtime->source)->toBe('auto')
        ->and(($this->overtimeTotal)())->toBe(1.5);
});

test('mode hanya pengajuan: tidak ada lembur otomatis', function () {
    AttendanceSettings::update($this->store, ['overtime_mode' => 'request']);
    $this->store->refresh();

    ($this->workDay)('18:00');

    expect(OvertimeRequest::query()->count())->toBe(0)
        ->and(($this->overtimeTotal)())->toBe(0.0);
});

test('toko lama yang mematikan lembur wajib approval otomatis memakai mode otomatis langsung', function () {
    AttendanceSettings::update($this->store, ['overtime_requires_approval' => false]);

    expect(AttendanceSettings::overtimeMode($this->store->fresh()))->toBe('auto');
});

test('koreksi jam pulang memperbarui lembur otomatis yang masih menunggu', function () {
    $attendance = ($this->workDay)('18:00');
    expect(OvertimeRequest::query()->sole()->hours)->toBe(2.0);

    $attendance->forceFill(['clock_out' => CarbonImmutable::parse('2026-11-10 17:00:00')])->save();
    app(AttendanceService::class)->applySchedule($attendance->fresh());
    expect(OvertimeRequest::query()->sole()->hours)->toBe(1.0);

    $attendance->forceFill(['clock_out' => CarbonImmutable::parse('2026-11-10 16:10:00')])->save();
    app(AttendanceService::class)->applySchedule($attendance->fresh());
    expect(OvertimeRequest::query()->count())->toBe(0);
});

test('pengaturan mode lembur bisa disimpan dari halaman Pengaturan SDM', function () {
    $this->actingAs($this->admin)->post('/attendance/settings', [
        'radius_meters' => 100, 'grace_minutes' => 15,
        'overtime_mode' => 'auto', 'overtime_min_minutes' => 45, 'overtime_rounding_minutes' => 15,
    ])->assertSessionHas('success');

    $store = $this->store->fresh();
    expect(AttendanceSettings::overtimeMode($store))->toBe('auto')
        ->and(AttendanceSettings::overtimeMinMinutes($store))->toBe(45)
        ->and(AttendanceSettings::overtimeHoursFromMinutes($store, 50))->toBe(0.75)
        ->and(AttendanceSettings::overtimeHoursFromMinutes($store, 40))->toBe(0.0);
});
