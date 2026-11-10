<?php

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Pelanggan\Index as PelangganIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Wave 3',
        'code' => 'TW3',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Ana Owner',
        'email' => 'ana@toko.com',
        'role' => 'owner',
        'store_id' => $this->store->id,
    ]);
});

test('halaman pelanggan menampilkan statistik dan daftar pelanggan aktif', function () {
    $this->actingAs($this->user);

    Customer::query()->create([
        'store_id' => $this->store->id,
        'name' => 'Budi Santoso',
        'phone' => '081234567890',
        'is_active' => true,
        'total_spent' => 500000,
        'total_orders' => 5,
    ]);

    Customer::query()->create([
        'store_id' => $this->store->id,
        'name' => 'Pelanggan Nonaktif',
        'phone' => '089999999999',
        'is_active' => false,
        'total_spent' => 0,
        'total_orders' => 0,
    ]);

    $component = Native::test(PelangganIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSee('Budi Santoso')
        ->assertDontSee('Pelanggan Nonaktif')
        ->assertSet('totalCustomers', 1);
});

test('kasir dapat menambah pelanggan baru melalui modal', function () {
    $this->actingAs($this->user);

    $component = Native::test(PelangganIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal')
        ->assertSet('showAddModal', true)
        ->set('newName', 'Pelanggan Baru')
        ->set('newPhone', '085111222333')
        ->set('newEmail', 'baru@email.com')
        ->call('saveCustomer');

    $component->assertSet('showAddModal', false)
        ->assertSet('totalCustomers', 1);

    expect(Customer::where('name', 'Pelanggan Baru')->exists())->toBeTrue();
});

test('validasi mencegah menyimpan pelanggan tanpa nama', function () {
    $this->actingAs($this->user);

    $component = Native::test(PelangganIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->call('openAddModal')
        ->set('newName', '')
        ->call('saveCustomer')
        ->assertSet('showAddModal', true)
        ->assertSet('addError', 'Nama pelanggan wajib diisi.');

    expect(Customer::count())->toBe(0);
});
