<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\StockAdjustmentApproval;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDseStore(string $planSlug = 'bisnis'): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => $planSlug === 'starter' ? 0 : 99000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko DSE',
        'slug' => 'dse-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeDseProduct(Store $store, int $stock = 100): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk DSE',
        'price' => 20000,
        'cost_price' => 12000,
        'average_cost' => 12000,
        'stock_quantity' => $stock,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ]);
}

it('default direct_stock_edit bernilai false', function () {
    [$store, $user] = makeDseStore();

    expect(StockAdjustmentApproval::directStockEdit($store))->toBeFalse();
});

it('admin bisa mengaktifkan direct_stock_edit', function () {
    [$store, $user] = makeDseStore();

    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);

    expect(StockAdjustmentApproval::directStockEdit($store))->toBeTrue();
});

it('admin bisa menonaktifkan direct_stock_edit', function () {
    [$store, $user] = makeDseStore();
    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);

    StockAdjustmentApproval::update($store, ['direct_stock_edit' => false]);

    expect(StockAdjustmentApproval::directStockEdit($store))->toBeFalse();
});

it('direct_stock_edit tidak mempengaruhi toko lain', function () {
    [$store1, $user1] = makeDseStore();
    [$store2, $user2] = makeDseStore();

    StockAdjustmentApproval::update($store1, ['direct_stock_edit' => true]);

    expect(StockAdjustmentApproval::directStockEdit($store2))->toBeFalse();
});

it('form edit produk mengunci stok jika direct_stock_edit false', function () {
    [$store, $user] = makeDseStore();
    $product = makeDseProduct($store);

    $response = $this->actingAs($user)->get("/products/{$product->id}/edit");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Products/Edit')
        ->where('stockLockedToAdjustment', true)
    );
});

it('form edit produk membuka stok jika direct_stock_edit true', function () {
    [$store, $user] = makeDseStore();
    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);
    $product = makeDseProduct($store);

    $response = $this->actingAs($user)->get("/products/{$product->id}/edit");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Products/Edit')
        ->where('stockLockedToAdjustment', false)
    );
});

it('form edit produk tetap membuka stok untuk paket starter', function () {
    [$store, $user] = makeDseStore('starter');
    $product = makeDseProduct($store);

    $response = $this->actingAs($user)->get("/products/{$product->id}/edit");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Products/Edit')
        ->where('stockLockedToAdjustment', false)
    );
});

it('penyesuaian stok redirect ke produk jika direct_stock_edit true', function () {
    [$store, $user] = makeDseStore();
    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);

    $response = $this->actingAs($user)->get('/stock-adjustments/create');

    $response->assertRedirect();
});

it('penyesuaian stok tetap bisa diakses jika direct_stock_edit false', function () {
    [$store, $user] = makeDseStore();

    $response = $this->actingAs($user)->get('/stock-adjustments/create');

    $response->assertOk();
});

it('stock opname redirect ke produk jika direct_stock_edit true', function () {
    [$store, $user] = makeDseStore();
    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);

    $response = $this->actingAs($user)->get('/stock-opnames/create');

    $response->assertRedirect();
});

it('stock opname tetap bisa diakses jika direct_stock_edit false', function () {
    [$store, $user] = makeDseStore();

    $response = $this->actingAs($user)->get('/stock-opnames/create');

    $response->assertOk();
});

it('toggle direct_stock_edit hanya tersedia untuk paket growup', function () {
    [$store, $user] = makeDseStore();

    expect(StorePlanLimits::allowsAdvancedRetailFeatures($store))->toBeTrue();
    expect(StockAdjustmentApproval::directStockEdit($store))->toBeFalse();

    StockAdjustmentApproval::update($store, ['direct_stock_edit' => true]);

    expect(StockAdjustmentApproval::directStockEdit($store))->toBeTrue();
});

it('paket starter tidak bisa mengubah direct_stock_edit', function () {
    [$store, $user] = makeDseStore('starter');

    expect(StorePlanLimits::allowsAdvancedRetailFeatures($store))->toBeFalse();
    // Starter tidak punya akses ke fitur ini, jadi direct_stock_edit tetap false
    expect(StockAdjustmentApproval::directStockEdit($store))->toBeFalse();
});
