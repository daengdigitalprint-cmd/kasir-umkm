<?php

use App\Models\ProductCategory;
use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\NativeComponents\Auth\Login;
use App\Support\MobileDefaultData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

// Seed bawaan mobile dilewati pada migrasi saat test (lihat 2026_10_09_145556_seed_mobile_initial_data),
// jadi file ini menjalankannya sendiri — persis seperti instalasi mobile pertama kali.
beforeEach(fn () => MobileDefaultData::ensure());

test('seed migration otomatis membuat toko dan paket starter jika database kosong', function () {
    expect(Store::query()->count())->toBeGreaterThanOrEqual(1);

    $store = Store::query()->first();
    expect($store)->not->toBeNull()
        ->and($store->name)->toBe('Toko Kasir UMKM')
        ->and($store->is_active)->toBeTrue();

    $plan = SubscriptionPlan::query()->where('slug', 'starter')->first();
    expect($plan)->not->toBeNull()
        ->and((float) $plan->price)->toBe(0.0);
});

test('seed migration otomatis membuat pengguna owner dan kasir default', function () {
    $owner = User::query()->where('email', 'owner@kasir.local')->first();
    $cashier = User::query()->where('email', 'kasir@kasir.local')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->role)->toBe('owner')
        ->and($cashier)->not->toBeNull()
        ->and($cashier->role)->toBe('cashier');
});

test('seed migration mengisi kategori produk dan hak akses menu dasar', function () {
    expect(ProductCategory::query()->count())->toBeGreaterThanOrEqual(5);

    $cashierPermissions = RoleMenuPermission::query()->where('role', 'cashier')->pluck('menu_key')->all();
    expect($cashierPermissions)->toContain('pos', 'pos_transactions', 'cash_shifts');
});

test('akun default hasil seed dapat digunakan untuk login di aplikasi mobile', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'owner@kasir.local')
        ->set('password', 'password123')
        ->call('login');

    $login->assertReplacedWith('/app');
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe('owner@kasir.local');
});

test('login tetap berhasil meski keyboard menambah huruf kapital dan spasi pada email', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', ' Owner@Kasir.local ')
        ->set('password', 'password123')
        ->call('login');

    $login->assertReplacedWith('/app');
    expect(auth()->user()->email)->toBe('owner@kasir.local');
});

test('login menampilkan pesan khusus jika email tidak terdaftar di perangkat', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'tidakada@kasir.local')
        ->set('password', 'password123')
        ->call('login')
        ->assertSet('errorMessage', 'Email tidak terdaftar di perangkat ini.');

    expect(auth()->check())->toBeFalse();
});

test('login menampilkan pesan kata sandi salah jika email terdaftar', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'owner@kasir.local')
        ->set('password', 'salah-total')
        ->call('login')
        ->assertSet('errorMessage', 'Kata sandi salah.');

    expect(auth()->check())->toBeFalse();
});

test('akun kasir default hasil seed dapat digunakan untuk login di aplikasi mobile', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'kasir@kasir.local')
        ->set('password', 'kasir123')
        ->call('login');

    $login->assertReplacedWith('/app');
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->role)->toBe('cashier');
});

test('constraint CHECK role lama di sqlite dihapus agar role owner dapat disimpan', function () {
    DB::statement('CREATE TABLE "legacy_roles" ("id" integer primary key autoincrement not null, "role" varchar check ("role" in (\'admin\', \'cashier\')) not null default \'cashier\', "name" varchar)');
    DB::statement('CREATE INDEX "legacy_roles_name_index" ON "legacy_roles" ("name")');
    DB::table('legacy_roles')->insert(['role' => 'admin', 'name' => 'Lama']);

    expect(fn () => DB::table('legacy_roles')->insert(['role' => 'owner', 'name' => 'Gagal']))
        ->toThrow(QueryException::class);

    MobileDefaultData::removeSqliteRoleCheckConstraint('legacy_roles');

    DB::table('legacy_roles')->insert(['role' => 'owner', 'name' => 'Owner']);

    expect(DB::table('legacy_roles')->pluck('role')->all())->toBe(['admin', 'owner']);
    expect(Schema::hasIndex('legacy_roles', 'legacy_roles_name_index'))->toBeTrue();
});

test('login otomatis membuat akun default jika database perangkat belum berisi user', function () {
    User::query()->delete();
    expect(User::query()->count())->toBe(0);

    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'owner@kasir.local')
        ->set('password', 'password123')
        ->call('login');

    $login->assertReplacedWith('/app');
    expect(User::query()->count())->toBe(2);
    expect(auth()->user()->email)->toBe('owner@kasir.local');
});
