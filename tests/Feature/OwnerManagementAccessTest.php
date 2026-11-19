<?php

use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Default sistem hak akses menu (sama seperti produksi).
    $this->seed(\Database\Seeders\RoleMenuPermissionSeeder::class);
    RoleMenuPermission::flushMenuCache();
});

function mgmtStore(string $name): Store
{
    return Store::query()->create([
        'name' => $name,
        'slug' => 'mgmt-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

function mgmtUser(Store $active, array $roles): User
{
    $user = User::factory()->create([
        'store_id' => $active->id,
        'role' => $roles[$active->id],
        'email_verified_at' => now(),
    ]);
    foreach ($roles as $storeId => $role) {
        $user->stores()->attach($storeId, ['role' => $role]);
    }

    return $user;
}

test('owner mendapat menu manajemen tetapi tidak menu operasional kasir', function () {
    $store = mgmtStore('Toko Owner');
    $owner = mgmtUser($store, [$store->id => 'owner']);

    $menus = $owner->allowedMenus();

    expect($menus)->toContain('customers', 'receivables', 'discounts', 'loyalty', 'manage_staff', 'manage_stores', 'manage_products', 'stock_opnames', 'stock_movements')
        ->and($menus)->not->toContain('cashier', 'shift', 'table_orders', 'queues', 'kitchen_queue');
});

test('owner toko aktif bisa membuka tim & akses dan pelanggan', function () {
    $store = mgmtStore('Toko Owner');
    $owner = mgmtUser($store, [$store->id => 'owner']);

    expect($owner->canManageStoreSettings())->toBeTrue();
    $this->actingAs($owner)->get('/manage-staff')->assertOk();
    $this->actingAs($owner)->get('/customers')->assertOk();
});

test('owner toko lain tidak mendapat hak kelola di toko tempat ia hanya kasir', function () {
    $ownStore = mgmtStore('Toko Milik Sendiri');
    $otherStore = mgmtStore('Toko Orang Lain');
    // Owner di toko sendiri, tetapi hanya kasir di toko lain yang sedang aktif.
    $user = mgmtUser($otherStore, [$ownStore->id => 'owner', $otherStore->id => 'cashier']);

    expect($user->canManageStoreSettings())->toBeFalse();
    // Middleware mengalihkan ke dashboard dengan pesan "Akses ditolak".
    $this->actingAs($user)->get('/manage-staff')->assertRedirect(route('dashboard'))->assertSessionHas('error');
});

test('kasir tetap tidak bisa membuka tim & akses', function () {
    $store = mgmtStore('Toko Kasir');
    $cashier = mgmtUser($store, [$store->id => 'cashier']);

    expect($cashier->canManageStoreSettings())->toBeFalse();
    $this->actingAs($cashier)->get('/manage-staff')->assertRedirect(route('dashboard'))->assertSessionHas('error');
});

test('owner tidak bisa menugaskan peran owner (tetap khusus admin)', function () {
    $store = mgmtStore('Toko Owner');
    $owner = mgmtUser($store, [$store->id => 'owner']);
    $staff = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($staff->id, ['role' => 'cashier']);

    // Rute toko memakai slug sebagai kunci URL.
    $this->actingAs($owner)->patch("/stores/{$store->slug}/members/{$staff->id}", ['role' => 'owner'])
        ->assertSessionHas('error');

    expect($store->members()->where('users.id', $staff->id)->first()->pivot->role)->toBe('cashier');
});
