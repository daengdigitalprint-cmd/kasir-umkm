<?php

use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\User;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Employee;
use App\Modules\Attendance\Support\AttendanceSettings;
use App\Modules\CashierPin\Services\CashierPinService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Peran Karyawan ('staff'): karyawan biasa yang hanya memakai Absensi
 * (absen, riwayat, pengajuan cuti/lembur, slip gaji sendiri).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->store = Store::query()->create([
        'name' => 'Toko Karyawan',
        'slug' => 'toko-karyawan-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'currency' => 'IDR',
        'timezone' => 'Asia/Jakarta',
        'settings' => [],
        'cashier_login_mode' => 'pin',
        'attendance_payroll_until' => now()->addYear(),
    ]);
    AttendanceSettings::update($this->store, [
        'enabled' => true,
        'require_selfie' => false,
        'require_geolocation' => false,
    ]);
    $this->store->refresh();

    $this->member = function (string $role): User {
        $user = User::factory()->create([
            'store_id' => $this->store->id,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
        $user->stores()->attach($this->store->id, ['role' => $role]);

        return $user;
    };

    $this->staff = ($this->member)('staff');
});

test('hak akses menu bawaan Karyawan hanya Absensi', function () {
    expect(RoleMenuPermission::defaultMenus('staff'))->toBe(['attendance'])
        ->and($this->staff->allowedMenus())->toBe(['attendance']);
});

test('Karyawan yang membuka dashboard langsung diarahkan ke Absensi', function () {
    $this->actingAs($this->staff)->get(route('dashboard'))
        ->assertRedirect(route('attendance.index'));
});

test('bila Absensi belum aktif, dashboard Karyawan hanya menampilkan pesan tanpa data toko', function () {
    AttendanceSettings::update($this->store, ['enabled' => false]);

    $this->actingAs($this->staff)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('staffAttendanceUnavailable', true)
            ->where('store', null)
            ->missing('recentTransactions')
            ->missing('weeklyTrend'));

    // Halaman Absensi tidak melempar Karyawan ke Langganan/Pengaturan yang tertutup untuknya.
    $this->actingAs($this->staff)->get(route('attendance.index'))
        ->assertRedirect(route('dashboard'));
});

test('Karyawan bisa membuka halaman Absensi pribadinya dan absen masuk', function () {
    foreach (['attendance.index', 'attendance.history', 'attendance.my-leaves', 'attendance.my-overtimes', 'attendance.my-payslips'] as $name) {
        $this->actingAs($this->staff)->get(route($name))->assertOk();
    }

    // Profil karyawan dibuat otomatis seperti peran staf lain.
    $employee = Employee::query()->where('store_id', $this->store->id)->where('user_id', $this->staff->id)->first();
    expect($employee)->not->toBeNull();

    $this->actingAs($this->staff)->post(route('attendance.clock-in'))->assertSessionHas('success');
    expect(Attendance::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('Karyawan tidak bisa membuka menu SDM pengelola maupun penggajian', function () {
    foreach ([
        'attendance.report', 'attendance.employees.index', 'attendance.schedules.index',
        'attendance.leaves.index', 'attendance.overtimes.index', 'attendance.settings', 'payroll.index',
    ] as $name) {
        $this->actingAs($this->staff)->get(route($name))->assertForbidden();
    }
});

test('Karyawan tidak bisa membuka kasir, produk, pelanggan, laporan, dan katalog', function () {
    foreach (['pos.index', 'products.index', 'customers.index', 'reports.index', 'subscription.index'] as $name) {
        $this->actingAs($this->staff)->get(route($name))->assertRedirect(route('dashboard'));
    }

    $this->actingAs($this->staff)->getJson(route('products.catalog'))->assertForbidden();
});

test('Asisten AI dan API sinkron kasir tertutup untuk Karyawan', function () {
    $this->actingAs($this->staff)->get(route('ai.chat.page'))->assertRedirect(route('dashboard'));
    $this->actingAs($this->staff)->postJson(route('ai.chat'), ['message' => 'Berapa omzet hari ini?'])->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson(route('api.v1.sync.products'))->assertForbidden();
    $this->postJson(route('api.v1.sync.transactions'), [])->assertForbidden();
});

test('Karyawan ber-PIN hanya muncul di layar absen, tidak di layar PIN kasir', function () {
    app(CashierPinService::class)->setPin($this->staff, '4826');
    $cashier = ($this->member)('cashier');
    app(CashierPinService::class)->setPin($cashier, '5937');
    $admin = ($this->member)('admin');

    $this->actingAs($admin)->get(route('cashier-pin.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashiers', fn ($list) => collect($list)->pluck('id')->all() === [$cashier->id]));

    $this->actingAs($admin)->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashiers', fn ($list) => collect($list)->pluck('id')->contains($this->staff->id)));
});

test('PIN Karyawan ditolak di kasir tetapi diterima di halaman Absensi', function () {
    app(CashierPinService::class)->setPin($this->staff, '4826');
    $admin = ($this->member)('admin');

    $this->actingAs($admin)
        ->post(route('cashier-pin.verify'), ['employee_id' => $this->staff->id, 'pin' => '4826'])
        ->assertSessionHasErrors('pin')
        ->assertSessionMissing('active_cashier');

    $this->actingAs($admin)
        ->post(route('cashier-pin.verify'), ['employee_id' => $this->staff->id, 'pin' => '4826', 'redirect_to' => '/attendance'])
        ->assertRedirect('/attendance')
        ->assertSessionHas('active_cashier.user_id', $this->staff->id);
});

test('admin bisa membuat akun Karyawan ber-PIN dan mengubah anggota menjadi Karyawan', function () {
    $admin = ($this->member)('admin');

    $this->actingAs($admin)->post(route('stores.members.create', $this->store), [
        'name' => 'Siti Pramusaji',
        'login_method' => 'pin',
        'pin' => '7391',
        'role' => 'staff',
    ])->assertSessionHasNoErrors();

    $created = User::query()->where('name', 'Siti Pramusaji')->firstOrFail();
    expect($created->isPinOnly())->toBeTrue()
        ->and($created->role)->toBe('staff')
        ->and($created->stores()->where('stores.id', $this->store->id)->first()->pivot->role)->toBe('staff');

    $cashier = ($this->member)('cashier');
    $this->actingAs($admin)->patch(route('stores.members.update', [$this->store, $cashier]), [
        'name' => $cashier->name,
        'email' => $cashier->email,
        'role' => 'staff',
    ])->assertSessionHasNoErrors();

    expect($cashier->fresh()->forgetActiveRoleMemo()->getRoleForActiveStore())->toBe('staff');
});
