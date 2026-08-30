<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\StorePlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test Tahap 3 — Penguncian field stok pada form Edit Produk.
 *
 * Toko GrowUp wajib memakai dokumen penyesuaian agar ada jejak audit.
 * Toko Starter tetap memakai form edit karena tidak punya jalur pengganti.
 */
function makeLockStore(string $planSlug): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => str_starts_with($planSlug, 'starter') ? 0 : 99000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko '.$planSlug,
        'slug' => 'lock-'.uniqid(),
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

function makeLockProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Uji Kunci',
        'price' => 10000,
        'cost_price' => 7000,
        'average_cost' => 7000,
        'stock_quantity' => 75,
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

/**
 * Replika keputusan penguncian pada ProductController::update().
 */
function resolveStockForUpdate(Store $store, Product $product, string $submittedStock): string
{
    $locked = StorePlanLimits::allowsAdvancedRetailFeatures($store);

    return $locked
        ? (string) $product->stock_quantity
        : $submittedStock;
}

it('mengabaikan stok yang dikirim form edit pada toko growup', function () {
    [$store] = makeLockStore('bisnis');
    $product = makeLockProduct($store);

    $resolved = resolveStockForUpdate($store, $product, '9999');

    expect((int) $resolved)->toBe(75);
});

it('tetap menerima stok dari form edit pada toko starter', function () {
    [$store] = makeLockStore('starter');
    $product = makeLockProduct($store);

    $resolved = resolveStockForUpdate($store, $product, '120');

    expect((int) $resolved)->toBe(120);
});

it('menandai penguncian hanya untuk paket growup', function () {
    [$growUp] = makeLockStore('bisnis');
    [$starter] = makeLockStore('starter');
    [$starterYearly] = makeLockStore('starter-tahunan');

    expect(StorePlanLimits::allowsAdvancedRetailFeatures($growUp))->toBeTrue();
    expect(StorePlanLimits::allowsAdvancedRetailFeatures($starter))->toBeFalse();
    expect(StorePlanLimits::allowsAdvancedRetailFeatures($starterYearly))->toBeFalse();
});

it('mengirim flag penguncian ke halaman edit produk', function () {
    [$store, $user] = makeLockStore('bisnis');
    $product = makeLockProduct($store);

    $this->actingAs($user)
        ->get("/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stockLockedToAdjustment', true));
});

it('tidak mengunci halaman edit produk bagi toko starter', function () {
    [$store, $user] = makeLockStore('starter');
    $product = makeLockProduct($store);

    $this->actingAs($user)
        ->get("/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stockLockedToAdjustment', false));
});
