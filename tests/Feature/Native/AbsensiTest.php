<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\NativeComponents\Absensi\Index as AbsensiIndex;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Absensi Test',
        'code' => 'TAT',
        'is_active' => true,
        'attendance_payroll_until' => now()->addDays(30),
        'settings' => ['attendance' => ['enabled' => true]],
    ]);

    $this->user = User::factory()->create([
        'name' => 'Budi Owner',
        'email' => 'budi@toko.com',
        'role' => 'owner',
        'store_id' => $this->store->id,
    ]);

    $this->employee = Employee::create([
        'store_id' => $this->store->id,
        'name' => 'Andi Karyawan',
        'position' => 'Kasir',
        'is_active' => true,
    ]);
});

test('halaman absensi menampilkan statistik karyawan hari ini', function () {
    $this->actingAs($this->user);

    // Buat satu absensi masuk hari ini
    Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => today()->toDateString(),
        'clock_in' => now(),
        'status' => Attendance::STATUS_PRESENT,
        'source' => Attendance::SOURCE_MANUAL,
    ]);

    $component = Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalKaryawan', 1)
        ->assertSet('hadirHariIni', 1)
        ->assertSet('belumAbsen', 0);
});

test('karyawan belum absen ditampilkan dengan benar', function () {
    $this->actingAs($this->user);

    // Tambah karyawan kedua — belum ada absensi
    Employee::create([
        'store_id' => $this->store->id,
        'name' => 'Sari Karyawan',
        'position' => 'Admin',
        'is_active' => true,
    ]);

    $component = Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalKaryawan', 2)
        ->assertSet('hadirHariIni', 0)
        ->assertSet('belumAbsen', 2);
});

test('manager dapat melakukan clock-in karyawan', function () {
    $this->actingAs($this->user);

    $component = Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->assertSet('showClockModal', true)
        ->assertSet('clockType', 'in')
        ->assertSet('selectedEmployeeName', 'Andi Karyawan')
        ->call('saveClock')
        ->assertSet('showClockModal', false)
        ->assertSet('clockError', '');

    $attendance = Attendance::where('employee_id', $this->employee->id)
        ->whereDate('work_date', today())
        ->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->clock_in)->not->toBeNull()
        ->and($attendance->status)->toBe(Attendance::STATUS_PRESENT);
});

test('clock-in dua kali menghasilkan error', function () {
    $this->actingAs($this->user);

    // Buat absensi dengan clock_in yang sudah ada
    Attendance::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'work_date' => today()->toDateString(),
        'clock_in' => now()->subHours(3),
        'status' => Attendance::STATUS_PRESENT,
        'source' => Attendance::SOURCE_MANUAL,
    ]);

    $component = Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->call('saveClock')
        ->assertSet('clockError', 'Karyawan sudah clock-in hari ini.');
});

test('clock-out sebelum clock-in menghasilkan error', function () {
    $this->actingAs($this->user);

    $component = Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'out')
        ->call('saveClock')
        ->assertSet('clockError', 'Karyawan belum clock-in hari ini.');
});

test('kasir tidak bisa mencatat absen karyawan lain', function () {
    $kasir = User::factory()->create(['role' => 'cashier', 'store_id' => $this->store->id]);
    $this->actingAs($kasir);

    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->call('saveClock')
        ->assertSet('clockError', 'Menu ini hanya untuk pemilik, admin, atau manager toko.');

    expect(Attendance::count())->toBe(0);
});

test('absen mobile tertutup saat add-on berakhir', function () {
    $this->store->update(['attendance_payroll_until' => now()->subDay()]);
    $this->actingAs($this->user);

    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->call('saveClock')
        ->assertSet('clockError', 'Paket Absensi + Payroll belum aktif atau sudah berakhir.');

    expect(Attendance::count())->toBe(0);
});

test('karyawan toko lain tidak bisa diabsenkan lewat ID', function () {
    $tokoLain = Store::query()->create(['name' => 'Toko Lain', 'code' => 'TLN', 'is_active' => true]);
    $karyawanLain = Employee::create(['store_id' => $tokoLain->id, 'name' => 'Orang Lain', 'is_active' => true]);

    $this->actingAs($this->user);

    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $karyawanLain->id, 'Orang Lain', 'in')
        ->call('saveClock')
        ->assertSet('clockError', 'Karyawan tidak ditemukan di toko ini.');

    expect(Attendance::where('employee_id', $karyawanLain->id)->exists())->toBeFalse();
});

test('karyawan nonaktif tidak bisa diabsenkan', function () {
    $this->employee->update(['is_active' => false]);
    $this->actingAs($this->user);

    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->call('saveClock')
        ->assertSet('clockError', 'Karyawan tidak ditemukan di toko ini.');
});

test('clock-out mobile tidak menghasilkan jam kerja negatif dan memakai jadwal', function () {
    $this->actingAs($this->user);

    $this->travelTo(now()->setTime(8, 0));
    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'in')
        ->call('saveClock')
        ->assertSet('clockError', '');

    \App\Modules\Attendance\Models\WorkSchedule::create([
        'store_id' => $this->store->id,
        'employee_id' => $this->employee->id,
        'name' => 'Pagi',
        'work_days' => [1, 2, 3, 4, 5, 6, 7],
        'shift_start' => '08:00',
        'shift_end' => '16:00',
        'break_minutes' => 60,
        'is_active' => true,
    ]);

    $this->travelTo(now()->setTime(16, 0));
    Native::test(AbsensiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openClockModal', $this->employee->id, 'Andi Karyawan', 'out')
        ->call('saveClock')
        ->assertSet('clockError', '');

    $attendance = Attendance::where('employee_id', $this->employee->id)->first();

    expect((float) $attendance->hours_worked)->toBe(7.0)
        ->and($attendance->source)->toBe(Attendance::SOURCE_DEVICE);
});
