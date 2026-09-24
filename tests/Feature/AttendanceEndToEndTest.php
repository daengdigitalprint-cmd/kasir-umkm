<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

/**
 * Alur end-to-end modul absensi:
 * beli paket → buka pengaturan → set lokasi kantor → check-in/check-out.
 */
test('alur lengkap: pembelian, pengaturan lokasi, check-in dan check-out', function () {
    $store = Store::query()->create([
        'name' => 'Toko Absensi',
        'slug' => 'toko-absensi-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'settings' => [],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $employee = Employee::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'name' => $user->name,
        'position' => 'Kasir',
        'is_active' => true,
    ]);

    // 1. Paket belum dibeli: halaman pengaturan menampilkan status belum aktif.
    $this->actingAs($user)
        ->get('/attendance/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Attendance/Settings')
            ->where('package.is_purchased', false)
        );

    // 2. Simulasikan paket sudah dibeli + aktifkan toggle modul + set lokasi kantor.
    $store->update(['attendance_payroll_until' => now()->addDays(30)]);
    AttendanceSettings::update($store, ['enabled' => true]);
    $user->unsetRelation('store');

    $this->actingAs($user)
        ->post('/attendance/settings', [
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'radius_meters' => 100,
            'require_selfie' => false,
            'require_geolocation' => true,
            'grace_minutes' => 15,
            'overtime_requires_approval' => true,
            'late_deduction_method' => 'none',
            'late_deduction_rate' => 0,
        ])
        ->assertRedirect();

    expect(AttendanceSettings::enabled($store->fresh()))->toBeTrue();
    expect(AttendanceSettings::hasOfficeLocation($store->fresh()))->toBeTrue();

    // 3. Check-in dari dalam radius kantor (koordinat sama dengan lokasi kantor).
    $this->actingAs($user)
        ->post('/attendance/clock-in', [
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'accuracy' => 5,
        ])
        ->assertRedirect();

    $attendance = Attendance::query()->where('employee_id', $employee->id)->first();
    expect($attendance)->not->toBeNull();
    expect($attendance->clock_in)->not->toBeNull();
    expect($attendance->status)->toBe('present');

    // 4. Check-out dari dalam radius kantor.
    $this->actingAs($user)
        ->post('/attendance/clock-out', [
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'accuracy' => 5,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($attendance->fresh()->clock_out)->not->toBeNull();
    expect($attendance->fresh()->status)->toBe('present');
});

test('check-in diblokir di luar radius kantor', function () {
    $store = Store::query()->create([
        'name' => 'Toko Absensi',
        'slug' => 'toko-absensi-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'settings' => [],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    Employee::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'name' => $user->name,
        'position' => 'Kasir',
        'is_active' => true,
    ]);

    $store->update(['attendance_payroll_until' => now()->addDays(30)]);

    AttendanceSettings::update($store, [
        'enabled' => true,
        'latitude' => -6.200000,
        'longitude' => 106.816666,
        'radius_meters' => 100,
        'require_selfie' => false,
        'require_geolocation' => true,
    ]);

    // Koordinat sekitar 5 km dari kantor, pasti di luar radius 100 m.
    $this->actingAs($user)
        ->post('/attendance/clock-in', [
            'latitude' => -6.250000,
            'longitude' => 106.816666,
            'accuracy' => 5,
        ])
        ->assertSessionHasErrors();

    expect(Attendance::query()->count())->toBe(0);
});
