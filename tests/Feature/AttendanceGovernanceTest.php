<?php

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePhoto;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\SalaryComponent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Tata kelola data absensi (Fase 3): audit, riwayat tidak terhapus, dan anti-kecurangan.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->store = Store::query()->create([
        'name' => 'Toko Tata Kelola',
        'slug' => 'toko-tk-'.uniqid(),
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
    ]);
    $this->store->refresh();

    $this->admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->employee = Employee::query()->create(['store_id' => $this->store->id, 'name' => 'Budi', 'is_active' => true]);
});

test('karyawan yang punya riwayat tidak bisa dihapus, yang belum punya bisa', function () {
    Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'status' => Attendance::STATUS_ABSENT,
        'source' => Attendance::SOURCE_IMPORTED,
    ]);
    $baru = Employee::query()->create(['store_id' => $this->store->id, 'name' => 'Baru', 'is_active' => true]);

    $this->actingAs($this->admin)->delete("/attendance/employees/{$this->employee->id}")->assertSessionHas('error');
    $this->actingAs($this->admin)->delete("/attendance/employees/{$baru->id}")->assertSessionHas('success');

    expect(Employee::find($this->employee->id))->not->toBeNull()
        ->and(Employee::find($baru->id))->toBeNull();
});

test('menghapus akun user tidak ikut menghapus profil dan riwayat karyawan', function () {
    $user = User::factory()->create(['store_id' => $this->store->id]);
    $this->employee->update(['user_id' => $user->id]);
    Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'status' => Attendance::STATUS_ABSENT,
        'source' => Attendance::SOURCE_IMPORTED,
    ]);

    $user->delete();

    expect($this->employee->fresh())->not->toBeNull()
        ->and($this->employee->fresh()->user_id)->toBeNull()
        ->and(Attendance::count())->toBe(1);
});

test('admin tidak bisa menyetujui cuti atau lembur miliknya sendiri', function () {
    $this->employee->update(['user_id' => $this->admin->id]);
    $leave = LeaveRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'leave_type' => 'annual',
        'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'status' => 'pending',
    ]);
    $overtime = OvertimeRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'work_date' => '2026-11-10',
        'hours' => 3, 'status' => 'pending',
    ]);

    $this->actingAs($this->admin)->put("/attendance/leaves/{$leave->id}", ['status' => 'approved'])->assertSessionHas('error');
    $this->actingAs($this->admin)->put("/attendance/overtimes/{$overtime->id}", ['status' => 'approved'])->assertSessionHas('error');

    expect($leave->fresh()->status)->toBe('pending')
        ->and($overtime->fresh()->status)->toBe('pending');

    $owner = User::factory()->create(['store_id' => $this->store->id, 'role' => 'owner', 'email_verified_at' => now()]);
    $this->actingAs($owner)->put("/attendance/overtimes/{$overtime->id}", ['status' => 'approved'])->assertSessionHas('success');

    expect($overtime->fresh()->status)->toBe('approved')
        ->and(ActivityLog::where('action', 'overtime.approved')->exists())->toBeTrue();
});

test('koreksi absen wajib alasan, tercatat di log, dan mendukung pulang lewat tengah malam', function () {
    $attendance = Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'status' => Attendance::STATUS_ABSENT,
        'source' => Attendance::SOURCE_IMPORTED,
    ]);

    $this->actingAs($this->admin)
        ->post("/attendance/report/{$attendance->id}", ['clock_in' => '22:00', 'clock_out' => '06:00'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)
        ->post("/attendance/report/{$attendance->id}", ['clock_in' => '22:00', 'clock_out' => '06:00', 'reason' => 'Lupa check-out, shift malam'])
        ->assertSessionHasNoErrors();

    $attendance->refresh();
    $log = ActivityLog::where('action', 'attendance.corrected')->firstOrFail();

    expect($attendance->clock_out->toDateString())->toBe('2026-11-03')
        ->and($attendance->status)->toBe(Attendance::STATUS_PRESENT)
        ->and($attendance->notes)->toContain('Lupa check-out')
        ->and($log->metadata['before']['status'])->toBe('absent')
        ->and($log->metadata['reason'])->toBe('Lupa check-out, shift malam');
});

test('menghapus jam masuk lewat koreksi menjadikan absen alpa, bukan hadir', function () {
    $attendance = Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'clock_in' => '2026-11-02 08:00:00',
        'status' => Attendance::STATUS_PRESENT,
        'source' => Attendance::SOURCE_MANUAL,
    ]);

    $this->actingAs($this->admin)
        ->post("/attendance/report/{$attendance->id}", ['clock_in' => '', 'clock_out' => '', 'reason' => 'Ternyata tidak masuk'])
        ->assertSessionHasNoErrors();

    expect($attendance->fresh()->status)->toBe(Attendance::STATUS_ABSENT);
});

test('check-in dengan akurasi GPS sangat rendah ditolak', function () {
    AttendanceSettings::update($this->store, [
        'require_geolocation' => true,
        'latitude' => -6.2,
        'longitude' => 106.816666,
        'radius_meters' => 100,
    ]);
    $this->employee->update(['user_id' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->post('/attendance/clock-in', ['latitude' => -6.2, 'longitude' => 106.816666, 'accuracy' => 2500])
        ->assertSessionHasErrors('location');

    $this->actingAs($this->admin)
        ->post('/attendance/clock-in', ['latitude' => -6.2, 'longitude' => 106.816666, 'accuracy' => 25])
        ->assertSessionHasNoErrors();

    expect(Attendance::count())->toBe(1);
});

test('foto selfie hanya bisa dilihat admin toko atau karyawan pemiliknya', function () {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('selfie.jpg')->store('attendance', 'local');
    $attendance = Attendance::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'work_date' => '2026-11-02',
        'status' => Attendance::STATUS_PRESENT, 'source' => Attendance::SOURCE_MANUAL,
    ]);
    $photo = AttendancePhoto::create([
        'store_id' => $this->store->id, 'attendance_id' => $attendance->id, 'employee_id' => $this->employee->id,
        'type' => 'in', 'path' => $path, 'mime' => 'image/jpeg', 'size_bytes' => 100, 'captured_at' => now(),
    ]);

    $this->actingAs($this->admin)->get("/attendance/photos/{$photo->id}")->assertOk();

    $pemilik = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $this->employee->update(['user_id' => $pemilik->id]);
    $this->actingAs($pemilik)->get("/attendance/photos/{$photo->id}")->assertOk();

    $kasirLain = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $this->actingAs($kasirLain)->get("/attendance/photos/{$photo->id}")->assertForbidden();

    $tokoLain = Store::query()->create([
        'name' => 'Toko Lain', 'slug' => 'lain-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'settings' => [],
        'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($tokoLain, ['enabled' => true]);
    $adminLain = User::factory()->create(['store_id' => $tokoLain->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($adminLain)->get("/attendance/photos/{$photo->id}")->assertNotFound();
});

test('perubahan komponen gaji dan status PTKP tercatat di log aktivitas', function () {
    $component = SalaryComponent::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id,
        'name' => 'Gaji Pokok', 'type' => 'earning', 'amount' => 3_000_000,
    ]);

    $this->actingAs($this->admin)->put("/payroll/salaries/{$component->id}", [
        'name' => 'Gaji Pokok', 'type' => 'earning', 'amount' => 3_500_000, 'is_fixed' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->put("/payroll/salaries/{$this->employee->id}/tax", ['tax_status' => 'K/1'])
        ->assertSessionHasNoErrors();

    $update = ActivityLog::where('action', 'salary_component.updated')->firstOrFail();

    expect((float) $update->metadata['before']['amount'])->toBe(3_000_000.0)
        ->and((float) $update->metadata['after']['amount'])->toBe(3_500_000.0)
        ->and(ActivityLog::where('action', 'employee.tax_status_changed')->exists())->toBeTrue();
});

test('admin melihat jumlah pengajuan cuti & lembur yang menunggu sebagai badge menu', function () {
    LeaveRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'leave_type' => 'annual',
        'start_date' => '2026-11-12', 'end_date' => '2026-11-12', 'status' => 'pending',
    ]);
    LeaveRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'leave_type' => 'sick',
        'start_date' => '2026-11-05', 'end_date' => '2026-11-05', 'status' => 'approved',
    ]);
    OvertimeRequest::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-11-11', 'hours' => 1, 'status' => 'pending',
    ]);

    $this->actingAs($this->admin)->get('/attendance/leaves')
        ->assertInertia(fn ($page) => $page
            ->where('pending_hr_requests.leave', 1)
            ->where('pending_hr_requests.overtime', 1));

    // Kasir (bukan back-office) tidak mendapat badge
    $kasir = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $this->actingAs($kasir)->get('/attendance')
        ->assertInertia(fn ($page) => $page
            ->where('pending_hr_requests.leave', 0)
            ->where('pending_hr_requests.overtime', 0));
});

test('halaman absen karyawan berisi profil, jadwal, rekap bulan, dan grafik 7 hari ala aplikasi', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));
    $kasir = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $employee = Employee::query()->create(['store_id' => $this->store->id, 'user_id' => $kasir->id, 'name' => 'Sari Dewi', 'is_active' => true]);
    \App\Modules\Attendance\Models\WorkSchedule::create([
        'store_id' => $this->store->id, 'employee_id' => $employee->id, 'name' => 'Pagi',
        'work_days' => [1, 2, 3, 4, 5, 6, 7], 'shift_start' => '08:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'is_active' => true,
    ]);
    Attendance::create([
        'store_id' => $this->store->id, 'employee_id' => $employee->id, 'work_date' => '2026-11-09',
        'clock_in' => '2026-11-09 08:20:00', 'clock_out' => '2026-11-09 16:00:00',
        'status' => Attendance::STATUS_LATE, 'late_minutes' => 20, 'hours_worked' => 6.5, 'source' => Attendance::SOURCE_DEVICE,
    ]);

    $this->actingAs($kasir)->get('/attendance')
        ->assertInertia(fn ($page) => $page
            ->component('Attendance/Index')
            ->where('profile.name', 'Sari Dewi')
            ->where('profile.schedule.shift_start', '08:00')
            ->where('profile.month.late', 1)
            ->where('profile.month.on_time', 0)
            ->has('profile.week', 7)
            ->where('profile.week.5.date', '2026-11-09')
            ->where('profile.week.5.hours', 6.5)
            ->where('profile.week.6.date', '2026-11-10'));

    // Foto profil sendiri bisa dibuka; belum ada foto = 404
    $this->actingAs($kasir)->get('/attendance/my-photo')->assertNotFound();
});
