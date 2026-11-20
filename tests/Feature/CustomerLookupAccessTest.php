<?php

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Cari & tambah cepat pelanggan hanya untuk layar kasir (POS): nama + nomor HP
 * pelanggan tidak boleh bisa dicari oleh gudang atau dapur.
 */
function lookupStore(array $menuOverrides = []): Store
{
    return Store::query()->create([
        'name' => 'Toko Pelanggan',
        'slug' => 'pelanggan-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $menuOverrides === [] ? [] : ['role_menu_permissions' => $menuOverrides],
    ]);
}

function lookupUser(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

test('gudang dan dapur tidak dapat mencari atau menambah pelanggan', function (string $role) {
    $store = lookupStore();
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Ani', 'phone' => '081234']);
    $user = lookupUser($store, $role);

    $this->actingAs($user)->getJson('/customers/search?q=Ani')->assertRedirect(route('dashboard'));
    $this->actingAs($user)->postJson('/customers/quick-store', ['name' => 'Pak Budi'])->assertRedirect(route('dashboard'));

    expect(Customer::query()->where('name', 'Pak Budi')->exists())->toBeFalse();
})->with(['warehouse', 'kitchen']);

test('kasir tetap dapat mencari pelanggan dari POS', function () {
    $store = lookupStore();
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Ani', 'phone' => '081234']);

    $this->actingAs(lookupUser($store, 'cashier'))->getJson('/customers/search?q=Ani')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Bu Ani']);
});

test('peran yang diberi menu Kasir di Tim & Akses dapat mencari pelanggan', function () {
    $store = lookupStore(['warehouse' => ['dashboard', 'pos']]);
    Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Ani', 'phone' => '081234']);

    $this->actingAs(lookupUser($store, 'warehouse'))->getJson('/customers/search?q=Ani')->assertOk();
});
