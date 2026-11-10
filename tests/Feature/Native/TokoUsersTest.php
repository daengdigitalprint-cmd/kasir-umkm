<?php

use App\Models\Store;
use App\Models\User;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Toko\Users as TokoUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Test',
        'code' => 'TTT',
        'is_active' => true,
    ]);

    $this->owner = User::factory()->create([
        'name' => 'Pemilik Toko',
        'email' => 'owner@toko.com',
        'role' => 'owner',
        'store_id' => $this->store->id,
        'is_active' => true,
    ]);

    $this->kasir = User::factory()->create([
        'name' => 'Budi Kasir',
        'email' => 'budi@toko.com',
        'role' => 'cashier',
        'store_id' => $this->store->id,
        'is_active' => true,
    ]);
});

test('halaman manajemen kasir menampilkan statistik staf', function () {
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('totalUsers', 1)   // kasir (owner dikecualikan)
        ->assertSet('totalActive', 1)
        ->assertSet('totalCashier', 1);
});

test('owner dapat menambah staf baru', function () {
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal')
        ->assertSet('showAddModal', true)
        ->set('newName', 'Sari Admin')
        ->set('newEmail', 'sari@toko.com')
        ->set('newPassword', 'password123')
        ->set('newRole', 'manager')
        ->call('saveUser')
        ->assertSet('showAddModal', false)
        ->assertSet('addError', '');

    expect(User::where('email', 'sari@toko.com')->exists())->toBeTrue();
    expect(User::where('email', 'sari@toko.com')->first()->role)->toBe('manager');
});

test('validasi mencegah menambah staf dengan email duplikat', function () {
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal')
        ->set('newName', 'Budi Duplikat')
        ->set('newEmail', 'budi@toko.com')  // sudah ada
        ->set('newPassword', 'password123')
        ->call('saveUser')
        ->assertSet('addError', 'Email sudah terdaftar.');
});

test('validasi mencegah menambah staf dengan password pendek', function () {
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal')
        ->set('newName', 'Staf Baru')
        ->set('newEmail', 'staf@toko.com')
        ->set('newPassword', '123')
        ->call('saveUser')
        ->assertSet('addError', 'Password minimal 6 karakter.');
});

test('owner dapat menonaktifkan kasir', function () {
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openToggleModal', $this->kasir->id, 'Budi Kasir', true)
        ->assertSet('showToggleModal', true)
        ->assertSet('toggleToActive', false)
        ->call('confirmToggle')
        ->assertSet('showToggleModal', false);

    expect(User::find($this->kasir->id)->is_active)->toBeFalse();
});

test('owner dapat mengaktifkan kembali kasir yang nonaktif', function () {
    $this->kasir->update(['is_active' => false]);
    $this->actingAs($this->owner);

    $component = Native::test(TokoUsers::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openToggleModal', $this->kasir->id, 'Budi Kasir', false)
        ->assertSet('toggleToActive', true)
        ->call('confirmToggle');

    expect(User::find($this->kasir->id)->is_active)->toBeTrue();
});
