<?php

use App\Models\Store;
use App\Models\User;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Toko\Pengaturan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko ABC',
        'code' => 'ABC',
        'phone' => '081234567890',
        'email' => 'toko@abc.com',
        'address' => 'Jl. Merdeka No. 1',
        'is_active' => true,
        'tax_enabled' => true,
        'tax_rate' => 11,
        'tax_name' => 'PPN',
        'is_subscription_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Admin Toko',
        'role' => 'admin',
        'store_id' => $this->store->id,
    ]);
});

test('halaman pengaturan toko menampilkan info toko yang benar', function () {
    $this->actingAs($this->user);

    $component = Native::test(Pengaturan::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('storeName', 'Toko ABC')
        ->assertSet('storePhone', '081234567890')
        ->assertSet('storeEmail', 'toko@abc.com')
        ->assertSet('storeAddress', 'Jl. Merdeka No. 1')
        ->assertSet('taxEnabled', true)
        ->assertSet('taxRate', '11.00')
        ->assertSet('taxName', 'PPN')
        ->assertSet('subscriptionActive', true);
});

test('admin dapat mengedit info toko melalui modal', function () {
    $this->actingAs($this->user);

    $component = Native::test(Pengaturan::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openEditModal')
        ->assertSet('showEditModal', true)
        ->assertSet('editName', 'Toko ABC')
        ->set('editName', 'Toko XYZ')
        ->set('editPhone', '089876543210')
        ->call('saveStore')
        ->assertSet('showEditModal', false)
        ->assertSet('saveSuccess', true)
        ->assertSet('storeName', 'Toko XYZ')
        ->assertSet('storePhone', '089876543210');

    expect(Store::find($this->store->id)->name)->toBe('Toko XYZ');
});

test('validasi mencegah menyimpan nama toko kosong', function () {
    $this->actingAs($this->user);

    $component = Native::test(Pengaturan::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openEditModal')
        ->set('editName', '')
        ->call('saveStore')
        ->assertSet('editError', 'Nama toko tidak boleh kosong.')
        ->assertSet('showEditModal', true);
});

test('menutup modal edit tidak menyimpan perubahan', function () {
    $this->actingAs($this->user);

    $component = Native::test(Pengaturan::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openEditModal')
        ->set('editName', 'Nama Berubah')
        ->call('closeEditModal')
        ->assertSet('showEditModal', false);

    expect(Store::find($this->store->id)->name)->toBe('Toko ABC');
});
