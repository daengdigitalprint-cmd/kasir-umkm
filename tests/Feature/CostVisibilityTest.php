<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\CostVisibility;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * HPP, biaya rata-rata, dan nilai stok hanya untuk pengelola (admin/manajer/pemilik).
 * Untuk staf, field biaya dibuang dari props di server.
 */
function costStore(): Store
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    return Store::query()->create([
        'name' => 'Toko HPP',
        'slug' => 'hpp-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);
}

function costUser(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

function costProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Bubuk',
        'sku' => 'KOPI-1',
        'price' => 25000,
        'cost_price' => 12000,
        'average_cost' => 12500,
        'stock_quantity' => 40,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ]);
}

/** Cari kunci biaya di mana pun dalam props. */
function propsHaveCostKey(array $props): bool
{
    foreach ($props as $key => $value) {
        if (is_string($key) && in_array($key, CostVisibility::COST_KEYS, true)) {
            return true;
        }
        if (is_array($value) && propsHaveCostKey($value)) {
            return true;
        }
    }

    return false;
}

test('gudang tidak menerima field HPP di halaman stok', function (string $route) {
    $store = costStore();
    costProduct($store);
    $gudang = costUser($store, 'warehouse');

    $page = $this->actingAs($gudang)->get(route($route))->assertOk()->viewData('page');

    expect(propsHaveCostKey($page['props']))->toBeFalse()
        ->and($page['props']['abilities']['view_cost'])->toBeFalse();
})->with(['products.index', 'stock-overview', 'stock-adjustments.create']);

test('admin tetap menerima HPP di daftar produk', function () {
    $store = costStore();
    costProduct($store);

    $this->actingAs(costUser($store, 'admin'))->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.view_cost', true)
            ->where('products.data.0.cost_price', fn ($v) => (float) $v === 12000.0)
        );
});

test('halaman edit produk untuk gudang tidak memuat HPP', function () {
    $store = costStore();
    $product = costProduct($store);

    $page = $this->actingAs(costUser($store, 'warehouse'))->get(route('products.edit', $product))->assertOk()->viewData('page');

    expect(propsHaveCostKey($page['props']))->toBeFalse();
});

test('gudang menyimpan produk tanpa mengubah HPP yang ada', function () {
    $store = costStore();
    $product = costProduct($store);

    $this->actingAs(costUser($store, 'warehouse'))->put(route('products.update', $product), [
        'name' => 'Kopi Bubuk 250g',
        'sku' => 'KOPI-1',
        'barcode' => '',
        'description' => '',
        'brand' => '',
        'tax_rate' => 0,
        'price' => 26000,
        'cost_price' => null,
        'stock_quantity' => 40,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('Kopi Bubuk 250g')
        ->and((float) $fresh->cost_price)->toBe(12000.0)
        ->and((float) $fresh->average_cost)->toBe(12500.0);
});

test('gudang tidak dapat mengekspor atau mengimpor produk', function () {
    $store = costStore();
    $gudang = costUser($store, 'warehouse');

    $this->actingAs($gudang)->get(route('products.export'))
        ->assertRedirect(route('products.index'))->assertSessionHas('error');
    $this->actingAs($gudang)->get(route('products.export.with-components'))
        ->assertRedirect(route('products.index'));
    $this->actingAs($gudang)->post(route('products.import'), [])
        ->assertRedirect(route('products.index'));
});
