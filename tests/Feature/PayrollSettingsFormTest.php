<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Support\AttendanceSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Formulir Pengaturan SDM · Penggajian: data dikirim persis seperti dari peramban
 * (nominal sebagai teks angka polos, pengali sebagai angka).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->store = Store::query()->create([
        'name' => 'Toko Gaji', 'slug' => 'toko-gaji-'.uniqid(), 'is_active' => true, 'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta', 'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, ['enabled' => true, 'require_selfie' => false, 'require_geolocation' => false]);
    $this->store->refresh();

    $this->admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->store->members()->attach($this->admin->id, ['role' => 'admin']);
});

function payrollSettingsPayload(array $ubah = []): array
{
    return array_merge([
        'late_penalty_per_day' => '15000',
        'late_penalty_per_minute' => '250',
        'late_penalty_method' => 'per_minute',
        'overtime_rate_first_hour' => 1.5,
        'overtime_rate_next_hour' => 2,
        'overtime_rate_holiday' => 2,
        'bpjs_enabled' => false,
        'bpjs_health_employee' => 1,
        'bpjs_health_employer' => 4,
        'bpjs_jht_employee' => 2,
        'bpjs_jht_employer' => 3.7,
        'bpjs_jp_employee' => 1,
        'bpjs_jp_employer' => 2,
        'bpjs_jkk' => 0.24,
        'bpjs_jkm' => 0.3,
        'bpjs_health_wage_cap' => '12000000',
        'bpjs_jp_wage_cap' => '11086300',
        'absence_deduction_enabled' => true,
        'pph21_enabled' => false,
        'owner_approval' => false,
    ], $ubah);
}

test('denda keterlambatan yang diisi tersimpan dan tampil lagi saat halaman dibuka', function () {
    $this->actingAs($this->admin)
        ->put('/payroll/settings', payrollSettingsPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $store = $this->store->fresh();
    expect($store->late_penalty_method)->toBe('per_minute')
        ->and((float) $store->late_penalty_per_minute)->toBe(250.0)
        ->and((float) $store->late_penalty_per_day)->toBe(15000.0)
        ->and((bool) $store->absence_deduction_enabled)->toBeTrue();

    $this->actingAs($this->admin)->get('/payroll/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Payroll/Settings')
            ->where('settings.late_penalty_method', 'per_minute')
            ->where('settings.late_penalty_per_minute', 250)
            ->where('settings.late_penalty_per_day', 15000)
            ->where('settings.absence_deduction_enabled', true));
});

test('pengali lembur dan persen BPJS yang tidak wajar ditolak dengan pesan berbahasa Indonesia', function () {
    $sebelum = $this->store->fresh()->only(['overtime_rate_first_hour', 'bpjs_health_employee', 'late_penalty_per_minute']);

    $this->actingAs($this->admin)
        ->put('/payroll/settings', payrollSettingsPayload(['overtime_rate_first_hour' => 99, 'bpjs_enabled' => true, 'bpjs_health_employee' => 150]))
        ->assertSessionHasErrors([
            'overtime_rate_first_hour' => 'Pengali lembur jam pertama terlalu besar (paling banyak 20).',
            'bpjs_health_employee' => 'Iuran BPJS Kesehatan karyawan terlalu besar (paling banyak 100).',
        ]);

    // Penolakan tidak menyimpan sebagian.
    expect($this->store->fresh()->only(array_keys($sebelum)))->toEqual($sebelum);
});

test('nominal denda yang dikosongkan ditolak dengan petunjuk yang jelas', function () {
    $this->actingAs($this->admin)
        ->put('/payroll/settings', payrollSettingsPayload(['late_penalty_per_minute' => '']))
        ->assertSessionHasErrors(['late_penalty_per_minute' => 'Denda per menit telat wajib diisi. Isi 0 bila tidak dipakai.']);
});

test('denda yang tersimpan langsung dipakai penghitung gaji', function () {
    $this->actingAs($this->admin)->put('/payroll/settings', payrollSettingsPayload())->assertSessionHasNoErrors();
    $service = app(App\Modules\Payroll\Services\PayrollService::class);

    // 2 hari telat, total 30 menit, metode per menit × Rp250.
    expect($service->latePenalty($this->store->fresh(), 2, 30))->toBe(7500.0);

    $this->actingAs($this->admin)->put('/payroll/settings', payrollSettingsPayload(['late_penalty_method' => 'per_day']))->assertSessionHasNoErrors();
    expect($service->latePenalty($this->store->fresh(), 2, 30))->toBe(30000.0);

    $this->actingAs($this->admin)->put('/payroll/settings', payrollSettingsPayload(['late_penalty_method' => 'none']))->assertSessionHasNoErrors();
    expect($service->latePenalty($this->store->fresh(), 2, 30))->toBe(0.0);
});
