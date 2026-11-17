<?php

/*
 * F1 — Penanda stok menipis di kasir.
 * Kasir menandai "Sisa N" saat stok <= Stok Minimum; untuk itu Stok Minimum
 * harus ikut terkirim ke halaman kasir (muat awal & katalog sinkron/offline).
 */

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lowStockStore(): array
{
    $store = Store::query()->create([
        'name' => 'Warung Uji Stok',
        'slug' => 'uji-stok-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function lowStockProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'price' => 5000,
        'cost_price' => 1500,
        'stock_quantity' => 3,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_active' => true,
    ], $overrides));
}

it('mengirim Stok Minimum di katalog produk kasir', function () {
    [$store, $user] = lowStockStore();
    $product = lowStockProduct($store);

    $json = $this->actingAs($user)->getJson('/products/catalog')->assertOk()->json();
    $row = collect($json['products'] ?? $json)->firstWhere('id', $product->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['min_stock_level'])->toBe(5.0);
});

it('mengirim 0 bila Stok Minimum tidak diatur, yang di kasir berarti tanpa penanda', function () {
    [$store, $user] = lowStockStore();
    $product = lowStockProduct($store, ['min_stock_level' => 0]);

    $json = $this->actingAs($user)->getJson('/products/catalog')->assertOk()->json();
    $rows = collect($json['products'] ?? $json);

    expect((float) $rows->firstWhere('id', $product->id)['min_stock_level'])->toBe(0.0);
});

it('membawa Stok Minimum di data produk halaman kasir', function () {
    [$store, $user] = lowStockStore();
    $product = lowStockProduct($store);

    $this->actingAs($user)->get('/pos')->assertOk()->assertInertia(
        fn ($page) => $page->component('POS/Index')
            ->where('products', fn ($products) => (float) collect($products)->firstWhere('id', $product->id)['min_stock_level'] === 5.0)
    );
});
