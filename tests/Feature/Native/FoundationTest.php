<?php

use App\Models\User;
use App\NativeComponents\Auth\Login;
use App\NativeComponents\Kasir\Index as KasirIndex;
use App\NativeComponents\Lainnya\Index as LainnyaIndex;
use App\NativeComponents\Layouts\TabsLayout;
use App\Support\NativeDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

test('helper NativeDevice::isMobileApp mendeteksi status dengan benar', function () {
    expect(NativeDevice::isMobileApp())->toBeFalse();

    config(['nativephp-internal.running' => true]);
    expect(NativeDevice::isMobileApp())->toBeTrue();
});

test('TabsLayout menyediakan BottomNav 4 tab lengkap', function () {
    $component = Native::test(KasirIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertHasTabBar()
        ->assertHasTab('Kasir')
        ->assertHasTab('Produk')
        ->assertHasTab('Laporan')
        ->assertHasTab('Lainnya')
        ->assertNavTitle('Kasir (POS)');
});

test('layar tab lainnya menampilkan info profil dan navigasi judul', function () {
    $user = User::factory()->create([
        'name' => 'Budi Santoso',
        'role' => 'cashier',
    ]);

    $this->actingAs($user);

    $component = Native::test(LainnyaIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertNavTitle('Menu Lainnya')
        ->assertSee('Budi Santoso')
        ->assertSee('Cashier');
});

test('layar login memvalidasi input kosong', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->call('login');

    $login->assertSet('errorMessage', 'Email dan password wajib diisi.');
});

test('layar login memproses autentikasi gagal dengan pesan error ramah', function () {
    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'wrong@example.com')
        ->set('password', 'wrongpassword')
        ->call('login');

    $login->assertSet('errorMessage', 'Email tidak terdaftar di perangkat ini.');
});

test('layar login berhasil login dan melakukan replace ke rute /app', function () {
    $user = User::factory()->create([
        'email' => 'kasir@toko.com',
        'password' => bcrypt('secret123'),
    ]);

    $login = Native::test(Login::class, platform: Platform::ANDROID);

    $login->set('email', 'kasir@toko.com')
        ->set('password', 'secret123')
        ->call('login');

    $login->assertReplacedWith('/app');
    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($user->id);
});
