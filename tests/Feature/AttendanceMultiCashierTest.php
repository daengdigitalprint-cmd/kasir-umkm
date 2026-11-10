<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\CashierPin\Services\CashierPinService;
use App\Support\StoreLocationType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Beberapa kasir di satu outlet: tiap kasir tercatat sebagai karyawan sendiri,
 * baik lewat akun masing-masing maupun lewat satu perangkat bersama + PIN.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    $this->store = Store::query()->create([
        'name' => 'Outlet Ramai', 'slug' => 'outlet-ramai-'.uniqid(), 'location_type' => StoreLocationType::OUTLET,
        'is_active' => true, 'is_subscription_active' => true, 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta',
        'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, ['enabled' => true, 'require_selfie' => false, 'require_geolocation' => false]);
    $this->store->refresh();

    $this->kasir = collect(['Ani', 'Budi', 'Citra'])->map(function (string $name) {
        $user = User::factory()->create(['name' => $name, 'store_id' => $this->store->id, 'role' => 'cashier', 'email_verified_at' => now(), 'can_login_pos' => true]);
        $this->store->members()->attach($user->id, ['role' => 'cashier']);
        expect(app(CashierPinService::class)->setPin($user, '582914'))->toBeTrue();

        return $user->fresh();
    });
});

test('tiap kasir dengan akun sendiri tercatat sebagai karyawan terpisah', function () {
    foreach ($this->kasir as $user) {
        $this->actingAs($user)->post('/attendance/clock-in')->assertSessionHas('success');
    }

    expect(Attendance::query()->where('store_id', $this->store->id)->count())->toBe(3)
        ->and(Employee::query()->where('store_id', $this->store->id)->pluck('name')->sort()->values()->all())->toBe(['Ani', 'Budi', 'Citra']);
});

test('mode PIN: halaman absen menampilkan daftar kasir ber-PIN untuk dipilih', function () {
    $this->store->update(['cashier_login_mode' => 'pin']);
    [$ani] = $this->kasir;

    $this->actingAs($ani)->get('/attendance')
        ->assertInertia(fn ($page) => $page
            ->where('cashier_login_mode', 'pin')
            ->has('cashiers', 3)
            ->where('cashiers.0.name', 'Ani')
            ->where('cashiers.0.id', $ani->id)
            ->where('cashiers.0.has_pin', true));
});

test('mode PIN: beberapa kasir bergantian absen di satu perangkat bersama', function () {
    $this->store->update(['cashier_login_mode' => 'pin']);
    [$ani, $budi] = $this->kasir;
    $device = $ani; // perangkat kasir bersama login dengan akun Ani

    $this->actingAs($device)->post('/cashier-pin/login', ['employee_id' => $budi->id, 'pin' => '582914', 'redirect_to' => '/attendance'])
        ->assertRedirect('/attendance');
    $this->actingAs($device)->post('/attendance/clock-in')->assertSessionHas('success');

    // Ganti karyawan: keluar PIN, lalu Ani masuk dan absen
    $this->actingAs($device)->post('/cashier-pin/logout', ['redirect_to' => '/attendance'])->assertRedirect('/attendance');
    $this->actingAs($device)->post('/cashier-pin/login', ['employee_id' => $ani->id, 'pin' => '582914', 'redirect_to' => '/attendance']);
    $this->actingAs($device)->post('/attendance/clock-in')->assertSessionHas('success');

    $names = Attendance::query()->with('employee')->get()->pluck('employee.name')->sort()->values()->all();
    expect($names)->toBe(['Ani', 'Budi']);
});

test('redirect setelah PIN hanya ke halaman internal yang diizinkan', function () {
    $this->store->update(['cashier_login_mode' => 'pin']);
    [$ani] = $this->kasir;

    $this->actingAs($ani)->post('/cashier-pin/login', ['employee_id' => $ani->id, 'pin' => '582914', 'redirect_to' => 'https://evil.example'])
        ->assertRedirect(route('pos.index'));
});
