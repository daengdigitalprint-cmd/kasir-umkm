<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\NativeComponents\Gaji\Index as GajiIndex;
use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Gaji Test',
        'code' => 'TGT',
        'is_active' => true,
        'attendance_payroll_until' => now()->addDays(30),
    ]);

    $this->user = User::factory()->create([
        'name' => 'Dewi Owner',
        'email' => 'dewi@toko.com',
        'role' => 'owner',
        'store_id' => $this->store->id,
    ]);

    $this->employee = Employee::create([
        'store_id' => $this->store->id,
        'name' => 'Rudi Karyawan',
        'position' => 'Kasir',
        'is_active' => true,
    ]);
});

test('halaman penggajian menampilkan statistik tanpa periode aktif', function () {
    $this->actingAs($this->user);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalKaryawan', 1)
        ->assertSet('totalGajiBulanIni', 'Rp 0')
        ->assertSet('sudahDibayar', 0)
        ->assertSet('belumDibayar', 1);
});

test('halaman penggajian menampilkan total gaji dari periode aktif bulan ini', function () {
    $this->actingAs($this->user);

    $period = PayrollPeriod::create([
        'store_id' => $this->store->id,
        'name' => 'Gaji Oktober 2026',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'draft',
    ]);

    PayrollRun::create([
        'store_id' => $this->store->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $this->employee->id,
        'basic_salary' => 2500000,
        'deductions' => 100000,
        'overtime_hours' => 2,
        'overtime_pay' => 150000,
        'gross_salary' => 2650000,
        'net_salary' => 2550000,
    ]);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalGajiBulanIni', 'Rp 2.550.000');
});

test('detail gaji karyawan menampilkan rincian yang benar', function () {
    $this->actingAs($this->user);

    $period = PayrollPeriod::create([
        'store_id' => $this->store->id,
        'name' => 'Gaji Oktober 2026',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'draft',
    ]);

    $run = PayrollRun::create([
        'store_id' => $this->store->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $this->employee->id,
        'basic_salary' => 3000000,
        'deductions' => 200000,
        'overtime_hours' => 4,
        'overtime_pay' => 300000,
        'gross_salary' => 3300000,
        'net_salary' => 3100000,
        'attendance_summary' => ['present_days' => 22, 'late_days' => 2, 'absent_days' => 1],
    ]);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openDetail', $run->id)
        ->assertSet('showDetailModal', true)
        ->assertSet('detailNama', 'Rudi Karyawan')
        ->assertSet('detailPosisi', 'Kasir')
        ->assertSet('detailGajiPokok', 'Rp 3.000.000')
        ->assertSet('detailPotongan', 'Rp 200.000')
        ->assertSet('detailLembur', 'Rp 300.000')
        ->assertSet('detailGajiBersih', 'Rp 3.100.000')
        ->assertSet('detailHadir', 22)
        ->assertSet('detailAlpha', 1);
});

test('menutup modal detail gaji mengubah showDetailModal menjadi false', function () {
    $this->actingAs($this->user);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->set('showDetailModal', true)
        ->call('closeDetail')
        ->assertSet('showDetailModal', false);
});

test('kasir tidak bisa membuka data penggajian di mobile', function () {
    $kasir = User::factory()->create(['role' => 'cashier', 'store_id' => $this->store->id]);
    $this->actingAs($kasir);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalKaryawan', 0)
        ->assertSet('totalGajiBulanIni', 'Rp 0');
});

test('penggajian mobile tertutup saat add-on berakhir', function () {
    $this->store->update(['attendance_payroll_until' => now()->subDay()]);
    $this->actingAs($this->user);

    $component = Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalKaryawan', 0);
});

test('slip gaji toko lain tidak bisa dibuka lewat ID', function () {
    $tokoLain = Store::query()->create(['name' => 'Toko Lain', 'code' => 'TLN', 'is_active' => true]);
    $karyawanLain = Employee::create(['store_id' => $tokoLain->id, 'name' => 'Orang Lain', 'is_active' => true]);
    $periodLain = PayrollPeriod::create([
        'store_id' => $tokoLain->id,
        'name' => 'Gaji Toko Lain',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'draft',
    ]);
    $runLain = PayrollRun::create([
        'store_id' => $tokoLain->id,
        'payroll_period_id' => $periodLain->id,
        'employee_id' => $karyawanLain->id,
        'basic_salary' => 9000000,
        'deductions' => 0,
        'overtime_hours' => 0,
        'overtime_pay' => 0,
        'gross_salary' => 9000000,
        'net_salary' => 9000000,
    ]);

    $this->actingAs($this->user);

    Native::test(GajiIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID)
        ->call('openDetail', $runLain->id)
        ->assertSet('showDetailModal', false)
        ->assertSet('detailNama', '');
});
