<?php

use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Models\LeaveRequest;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * API aplikasi Absensi Android (Flutter).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Storage::fake('local');

    $this->store = Store::query()->create([
        'name' => 'Toko API', 'slug' => 'toko-api-'.uniqid(), 'is_active' => true, 'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta', 'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, [
        'enabled' => true, 'require_selfie' => true, 'require_geolocation' => true,
        'latitude' => -6.2, 'longitude' => 106.816666, 'radius_meters' => 100, 'grace_minutes' => 15,
    ]);
    $this->store->refresh();

    $this->user = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email' => 'rina@toko.test', 'password' => 'rahasia123']);
    $this->employee = Employee::query()->create(['store_id' => $this->store->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    WorkSchedule::create([
        'store_id' => $this->store->id, 'employee_id' => $this->employee->id, 'name' => 'Pagi',
        'work_days' => [1, 2, 3, 4, 5, 6, 7], 'shift_start' => '08:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'is_active' => true,
    ]);

    $this->token = $this->postJson('/api/v1/auth/login', ['email' => 'rina@toko.test', 'password' => 'rahasia123', 'device_name' => 'Absensi Android'])
        ->assertOk()->json('token');
    $this->api = fn (string $device = 'HP-A') => $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'X-Device-Id' => $device,
        'X-Device-Name' => 'Samsung A15',
        'Accept' => 'application/json',
    ]);
    $this->capture = fn (array $extra = []) => [
        'latitude' => -6.2, 'longitude' => 106.816666, 'accuracy' => 12,
        'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ...$extra,
    ];
});

test('tanpa token ditolak', function () {
    $this->getJson('/api/v1/attendance/me')->assertUnauthorized();
});

test('beranda berisi jadwal hari ini, aturan absen, dan langkah berikutnya', function () {
    ($this->api)()->getJson('/api/v1/attendance/me')
        ->assertOk()
        ->assertJsonPath('employee.name', 'Rina')
        ->assertJsonPath('today.schedule.shift_start', '08:00')
        ->assertJsonPath('policy.require_selfie', true)
        ->assertJsonPath('policy.office.latitude', -6.2)
        ->assertJsonPath('next_action', 'clock_in')
        ->assertJsonPath('device.bound', false)
        ->assertJsonCount(1, 'week_schedule');
});

test('akun yang bukan karyawan atau toko tanpa add-on mendapat pesan yang jelas', function () {
    $this->employee->update(['is_active' => false]);
    ($this->api)()->getJson('/api/v1/attendance/me')->assertStatus(403)->assertJsonPath('code', 'not_employee');

    $this->employee->update(['is_active' => true]);
    $this->store->update(['attendance_payroll_until' => now()->subDay()]);
    $this->app['auth']->forgetGuards(); // setiap request API di production dimulai tanpa cache user
    ($this->api)()->getJson('/api/v1/attendance/me')->assertStatus(403)->assertJsonPath('code', 'module_inactive');
});

test('absen masuk dari HP pertama mendaftarkan HP, HP lain ditolak', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    ($this->api)('HP-A')->post('/api/v1/attendance/clock-in', ($this->capture)())
        ->assertOk()
        ->assertJsonPath('attendance.clock_in', '07:55')
        ->assertJsonPath('attendance.status', 'present');

    expect($this->employee->fresh())
        ->device_id->toBe('HP-A')
        ->device_name->toBe('Samsung A15');

    ($this->api)('HP-B')->post('/api/v1/attendance/clock-out', ($this->capture)())
        ->assertStatus(423)
        ->assertJsonPath('code', 'device_mismatch');

    expect(Attendance::firstOrFail()->photos()->count())->toBe(1);
});

test('fake GPS, akurasi buruk, di luar radius, dan tanpa selfie ditolak', function () {
    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['is_mocked' => 1]))
        ->assertStatus(422)->assertJsonValidationErrors('location');

    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['accuracy' => 3000]))
        ->assertStatus(422)->assertJsonValidationErrors('location');

    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.3]))
        ->assertStatus(422)->assertJsonValidationErrors('location');

    $noPhoto = ($this->capture)();
    unset($noPhoto['photo']);
    ($this->api)()->post('/api/v1/attendance/clock-in', $noPhoto)
        ->assertStatus(422)->assertJsonValidationErrors('photo');

    expect(Attendance::count())->toBe(0);
});

test('absen pulang, riwayat bulanan, dan rekap', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:30:00', 'Asia/Jakarta'));
    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk()->assertJsonPath('attendance.status', 'late');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:30:00', 'Asia/Jakarta'));
    ($this->api)()->post('/api/v1/attendance/clock-out', ($this->capture)())
        ->assertOk()
        ->assertJsonPath('attendance.clock_out', '16:30');

    ($this->api)()->getJson('/api/v1/attendance/me')->assertJsonPath('next_action', 'done');

    ($this->api)()->getJson('/api/v1/attendance/history?month=2026-11')
        ->assertOk()
        ->assertJsonCount(1, 'days')
        ->assertJsonPath('days.0.work_date', '2026-11-10')
        ->assertJsonPath('summary.late_days', 1)
        ->assertJsonPath('summary.present_days', 1);
});

test('karyawan bisa mengajukan dan melihat cuti serta lembur', function () {
    ($this->api)()->postJson('/api/v1/attendance/leaves', ['type' => 'sick', 'start_date' => '2026-11-12', 'end_date' => '2026-11-13', 'reason' => 'Demam'])
        ->assertCreated();
    ($this->api)()->getJson('/api/v1/attendance/leaves')
        ->assertOk()
        ->assertJsonPath('items.0.type_label', 'Sakit')
        ->assertJsonPath('items.0.status', 'pending');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 20:00:00', 'Asia/Jakarta'));
    ($this->api)()->postJson('/api/v1/attendance/overtimes', ['work_date' => '2026-11-10', 'hours' => 2, 'reason' => 'Stok opname'])
        ->assertCreated();
    ($this->api)()->postJson('/api/v1/attendance/overtimes', ['work_date' => '2026-11-20', 'hours' => 2])
        ->assertStatus(422)->assertJsonValidationErrors('work_date');

    expect(LeaveRequest::firstOrFail()->employee_id)->toBe($this->employee->id);
});

test('slip gaji: hanya periode disetujui, dan hanya milik sendiri', function () {
    $period = PayrollPeriod::create(['store_id' => $this->store->id, 'name' => 'Gaji Oktober 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'draft']);
    $mine = PayrollRun::create(['store_id' => $this->store->id, 'payroll_period_id' => $period->id, 'employee_id' => $this->employee->id,
        'basic_salary' => 4_000_000, 'deductions' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'gross_salary' => 4_000_000, 'net_salary' => 4_000_000]);
    $other = Employee::query()->create(['store_id' => $this->store->id, 'name' => 'Joko', 'is_active' => true]);
    $theirs = PayrollRun::create(['store_id' => $this->store->id, 'payroll_period_id' => $period->id, 'employee_id' => $other->id,
        'basic_salary' => 9_000_000, 'deductions' => 0, 'overtime_hours' => 0, 'overtime_pay' => 0, 'gross_salary' => 9_000_000, 'net_salary' => 9_000_000]);

    ($this->api)()->getJson('/api/v1/attendance/payslips')->assertOk()->assertJsonCount(0, 'items');
    ($this->api)()->get("/api/v1/attendance/payslips/{$mine->id}/pdf")->assertNotFound();

    $period->update(['status' => 'approved']);

    ($this->api)()->getJson('/api/v1/attendance/payslips')->assertOk()->assertJsonPath('items.0.net_salary', 4000000);
    ($this->api)()->get("/api/v1/attendance/payslips/{$mine->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    ($this->api)()->get("/api/v1/attendance/payslips/{$theirs->id}/pdf")->assertNotFound();
});

test('admin bisa melepas HP terdaftar sehingga karyawan bisa absen dari HP baru', function () {
    $this->employee->forceFill(['device_id' => 'HP-LAMA', 'device_name' => 'Oppo A5', 'device_bound_at' => now()])->save();
    // Rina juga terdaftar di outlet lain dengan HP yang sama
    $outletB = makeOutlet('Outlet B', -6.3);
    $employeeB = Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    $employeeB->forceFill(['device_id' => 'HP-LAMA', 'device_name' => 'Oppo A5', 'device_bound_at' => now()])->save();
    $admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);

    $this->actingAs($admin)->post("/attendance/employees/{$this->employee->id}/reset-device")->assertSessionHas('success');

    expect($this->employee->fresh()->device_id)->toBeNull()
        ->and($employeeB->fresh()->device_id)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:58:00', 'Asia/Jakarta'));
    $this->app['auth']->forgetGuards();
    ($this->api)('HP-BARU')->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk();

    expect($this->employee->fresh()->device_id)->toBe('HP-BARU');
});

test('karyawan bisa mengunggah, melihat, dan menghapus foto profil', function () {
    ($this->api)()->post('/api/v1/attendance/profile/photo', ['photo' => UploadedFile::fake()->image('wajah.jpg', 600, 600)])
        ->assertOk()
        ->assertJsonPath('message', 'Foto profil diperbarui.');

    $path = $this->employee->fresh()->photo_path;
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    $url = ($this->api)()->getJson('/api/v1/attendance/me')->json('employee.photo_url');
    expect($url)->toStartWith('/api/v1/attendance/profile/photo?v=');
    ($this->api)()->get('/api/v1/attendance/profile/photo')->assertOk();

    // Ganti foto: file lama dihapus
    ($this->api)()->post('/api/v1/attendance/profile/photo', ['photo' => UploadedFile::fake()->image('baru.png', 400, 400)])->assertOk();
    Storage::disk('local')->assertMissing($path);

    ($this->api)()->deleteJson('/api/v1/attendance/profile/photo')->assertOk();
    expect($this->employee->fresh()->photo_path)->toBeNull();
    ($this->api)()->get('/api/v1/attendance/profile/photo')->assertNotFound();
});

test('foto profil ditolak bila bukan gambar atau terlalu kecil', function () {
    ($this->api)()->post('/api/v1/attendance/profile/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors('photo');
    ($this->api)()->post('/api/v1/attendance/profile/photo', ['photo' => UploadedFile::fake()->image('kecil.jpg', 40, 40)])
        ->assertStatus(422)->assertJsonValidationErrors('photo');
});

test('admin toko bisa melihat foto karyawan, toko lain tidak', function () {
    ($this->api)()->post('/api/v1/attendance/profile/photo', ['photo' => UploadedFile::fake()->image('wajah.jpg', 300, 300)])->assertOk();
    $this->app['auth']->forgetGuards();

    $admin = User::factory()->create(['store_id' => $this->store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($admin)->get("/attendance/employees/{$this->employee->id}/photo")->assertOk();
    $this->actingAs($admin)->get('/attendance/employees')
        ->assertInertia(fn ($page) => $page->where('employees.0.photo_url', fn ($url) => str_contains((string) $url, '/photo?v=')));

    $otherStore = Store::query()->create(['name' => 'Lain', 'slug' => 'lain-'.uniqid(), 'is_active' => true, 'currency' => 'IDR', 'settings' => [], 'attendance_payroll_until' => now()->addYear()]);
    AttendanceSettings::update($otherStore, ['enabled' => true]);
    $otherAdmin = User::factory()->create(['store_id' => $otherStore->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($otherAdmin)->get("/attendance/employees/{$this->employee->id}/photo")->assertNotFound();
});

/*
 * Multi-outlet: outlet ditentukan dari data karyawan + posisi GPS,
 * bukan dari toko yang sedang dipilih di web (users.store_id).
 */
function makeOutlet(string $name, float $latitude): Store
{
    $outlet = Store::query()->create([
        'name' => $name, 'slug' => 'outlet-'.uniqid(), 'is_active' => true, 'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta', 'settings' => [], 'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($outlet, [
        'enabled' => true, 'require_selfie' => true, 'require_geolocation' => true,
        'latitude' => $latitude, 'longitude' => 106.816666, 'radius_meters' => 100,
    ]);

    return $outlet->refresh();
}

test('karyawan yang hanya terdaftar di outlet lain tetap bisa absen walau toko aktif di web berbeda', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    $this->employee->update(['store_id' => $outletB->id]);
    WorkSchedule::query()->update(['store_id' => $outletB->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    ($this->api)()->getJson('/api/v1/attendance/me')
        ->assertOk()
        ->assertJsonPath('store.name', 'Outlet B')
        ->assertJsonPath('policy.office.latitude', -6.3);

    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.3]))->assertOk();
    expect(Attendance::query()->sole()->store_id)->toBe($outletB->id);
});

test('karyawan di dua outlet: absen masuk ke outlet yang radiusnya mencakup posisi, pulang ke outlet yang sama', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    $employeeB = Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    $this->user->update(['store_id' => $this->store->id]); // web sedang di outlet A
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    ($this->api)()->getJson('/api/v1/attendance/me')
        ->assertOk()
        ->assertJsonCount(2, 'outlets')
        ->assertJsonPath('outlets.1.name', 'Outlet B')
        ->assertJsonPath('outlets.1.office.latitude', -6.3);

    // Berdiri di outlet B
    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.3]))->assertOk();
    $attendance = Attendance::query()->sole();
    expect($attendance->store_id)->toBe($outletB->id)
        ->and($attendance->employee_id)->toBe($employeeB->id);

    // Beranda kini menampilkan outlet B dengan aksi berikutnya pulang
    ($this->api)()->getJson('/api/v1/attendance/me')
        ->assertJsonPath('store.name', 'Outlet B')
        ->assertJsonPath('next_action', 'clock_out');

    // Pulang dari lokasi outlet A ditolak (absen masuknya di B)
    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:05:00', 'Asia/Jakarta'));
    ($this->api)()->post('/api/v1/attendance/clock-out', ($this->capture)(['latitude' => -6.2]))
        ->assertStatus(422)->assertJsonValidationErrors('location');

    ($this->api)()->post('/api/v1/attendance/clock-out', ($this->capture)(['latitude' => -6.3]))->assertOk();
    expect($attendance->fresh()->clock_out)->not->toBeNull();
});

test('ganti toko aktif di web tidak memindahkan outlet absen di aplikasi', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    $this->user->update(['store_id' => $outletB->id]); // web sedang di outlet B
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    // Berdiri di outlet A
    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk();
    expect(Attendance::query()->sole()->store_id)->toBe($this->store->id);
});

test('di luar radius semua outlet: pesan menyebut outlet yang tersedia', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);

    ($this->api)()->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.5]))
        ->assertStatus(422)
        ->assertJsonPath('errors.location.0', 'Anda berada di luar radius semua outlet tempat Anda terdaftar (Toko API, Outlet B).');
    expect(Attendance::query()->count())->toBe(0);
});

test('outlet yang add-on absensinya tidak aktif tidak dipakai', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    $outletB->update(['attendance_payroll_until' => now()->subDay()]);
    Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    $this->user->update(['store_id' => $outletB->id]);

    ($this->api)()->getJson('/api/v1/attendance/me')
        ->assertOk()
        ->assertJsonPath('store.name', 'Toko API')
        ->assertJsonCount(1, 'outlets');
});

/*
 * Kunci HP per orang: HP masing-masing, berlaku di semua outlet, satu HP tidak untuk dua orang.
 */
test('HP yang terdaftar berlaku di semua outlet tempat karyawan terdaftar', function () {
    $outletB = makeOutlet('Outlet B', -6.3);
    $employeeB = Employee::query()->create(['store_id' => $outletB->id, 'user_id' => $this->user->id, 'name' => 'Rina', 'is_active' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));

    // Absen pertama di outlet A dengan HP-A: terdaftar juga untuk outlet B
    ($this->api)('HP-A')->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk();
    expect($employeeB->fresh()->device_id)->toBe('HP-A');

    // Besoknya di outlet B dengan HP lain ditolak
    $this->travelTo(CarbonImmutable::parse('2026-11-11 07:55:00', 'Asia/Jakarta'));
    ($this->api)('HP-B')->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.3]))
        ->assertStatus(423)->assertJsonPath('code', 'device_mismatch');
    ($this->api)('HP-A')->post('/api/v1/attendance/clock-in', ($this->capture)(['latitude' => -6.3]))->assertOk();
});

test('satu HP tidak bisa dipakai absen oleh dua karyawan berbeda', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:55:00', 'Asia/Jakarta'));
    ($this->api)('HP-RINA')->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk();

    $budi = User::factory()->create(['store_id' => $this->store->id, 'role' => 'cashier', 'email' => 'budi@toko.test', 'password' => 'rahasia123']);
    Employee::query()->create(['store_id' => $this->store->id, 'user_id' => $budi->id, 'name' => 'Budi', 'is_active' => true]);
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'budi@toko.test', 'password' => 'rahasia123', 'device_name' => 'x'])->json('token');
    $this->app['auth']->forgetGuards();
    $asBudi = fn (string $device) => $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Device-Id' => $device, 'Accept' => 'application/json']);

    // Budi login di HP Rina
    $asBudi('HP-RINA')->getJson('/api/v1/attendance/me')
        ->assertOk()
        ->assertJsonPath('device.is_this_device', false)
        ->assertJsonPath('device.message', 'HP ini sudah terdaftar untuk Rina. Absen harus memakai HP Anda sendiri.');
    $asBudi('HP-RINA')->post('/api/v1/attendance/clock-in', ($this->capture)())
        ->assertStatus(423)->assertJsonPath('code', 'device_taken');

    // Dari HP-nya sendiri bisa
    $asBudi('HP-BUDI')->post('/api/v1/attendance/clock-in', ($this->capture)())->assertOk();
    expect(Employee::query()->where('user_id', $budi->id)->value('device_id'))->toBe('HP-BUDI');
});
