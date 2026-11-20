<?php

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\InventoryMenuAccess;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Menu yang dicabut untuk admin gudang di "Tim & Akses" harus benar-benar menutup
 * halamannya di server, bukan hanya menyembunyikan tautannya di sidebar.
 */
function menuEnforceStore(?array $warehouseMenus): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $settings = [];
    if ($warehouseMenus !== null) {
        $settings['role_menu_permissions'] = ['warehouse' => $warehouseMenus];
    }

    return Store::query()->create([
        'name' => 'Toko Menu',
        'slug' => 'menu-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
        'settings' => $settings,
    ]);
}

function menuEnforceUser(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

test('gudang ditolak di halaman yang menunya dicabut', function () {
    $store = menuEnforceStore(['dashboard', 'stock_overview', 'products', 'stock_transfers']);
    $gudang = menuEnforceUser($store, 'warehouse');

    foreach (['purchases.index', 'suppliers.index', 'stock-opnames.index', 'stock-adjustments.index', 'stock-movements.index', 'recipes.index'] as $route) {
        $this->actingAs($gudang)->get(route($route))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'Menu ini tidak diizinkan untuk peran Anda. Hubungi pemilik atau admin toko.');
    }
});

test('gudang tetap dapat membuka halaman yang menunya diizinkan', function () {
    $store = menuEnforceStore(['dashboard', 'stock_overview', 'products', 'stock_transfers']);
    $gudang = menuEnforceUser($store, 'warehouse');

    foreach (['products.index', 'stock-transfers.index', 'stock-overview'] as $route) {
        $this->actingAs($gudang)->get(route($route))->assertOk();
    }
});

test('aksi tulis juga ditolak bila menunya dicabut', function () {
    $store = menuEnforceStore(['dashboard', 'products']);
    $gudang = menuEnforceUser($store, 'warehouse');

    $this->actingAs($gudang)->post(route('purchases.store'), [])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});

test('toko tanpa pengaturan menu memakai default gudang', function () {
    $store = menuEnforceStore(null);
    $gudang = menuEnforceUser($store, 'warehouse');

    $this->actingAs($gudang)->get(route('stock-opnames.index'))->assertOk();
    $this->actingAs($gudang)->get(route('stock-adjustments.index'))->assertOk();
});

test('admin toko tidak dibatasi pengaturan menu di sini', function () {
    $store = menuEnforceStore(['dashboard']);
    $store->update(['settings' => ['role_menu_permissions' => ['admin' => ['dashboard']]]]);
    $admin = menuEnforceUser($store, 'admin');

    $this->actingAs($admin)->get(route('purchases.index'))->assertOk();
});

test('kasir yang diberi menu stok tetap dapat membukanya', function () {
    $store = menuEnforceStore(null);
    $store->update(['settings' => ['role_menu_permissions' => ['cashier' => ['dashboard', 'pos', 'stock_transfers']]]]);
    $kasir = menuEnforceUser($store, 'cashier');

    $this->actingAs($kasir)->get(route('stock-transfers.index'))->assertOk();
    $this->actingAs($kasir)->get(route('purchases.index'))->assertRedirect(route('dashboard'));
});

test('peta rute ke menu mengenali rute turunan', function () {
    expect(InventoryMenuAccess::menuKeyFor('product-categories.quick-store'))->toBe('products')
        ->and(InventoryMenuAccess::menuKeyFor('product-categories.index'))->toBe('product_categories')
        ->and(InventoryMenuAccess::menuKeyFor('products.option-groups.index'))->toBe('products')
        ->and(InventoryMenuAccess::menuKeyFor('purchase-returns.index'))->toBe('purchase_returns')
        ->and(InventoryMenuAccess::menuKeyFor('stock-repacks.create'))->toBe('stock_adjustment')
        ->and(InventoryMenuAccess::menuKeyFor('dashboard'))->toBeNull();
});
