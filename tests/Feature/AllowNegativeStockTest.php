<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function makeAnsStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko ANS',
        'slug' => 'ans-'.uniqid(),
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

function makeAnsProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk ANS',
        'price' => 10000,
        'cost_price' => 7000,
        'stock_quantity' => 0,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'allow_negative_stock' => false,
        'is_active' => true,
    ], $overrides));
}

// ═══════════════════════════════════════════════════════
// 1. Migration test
// ═══════════════════════════════════════════════════════

it('memiliki kolom allow_negative_stock di tabel products', function () {
    expect(Schema::hasColumn('products', 'allow_negative_stock'))->toBeTrue();
});

it('memiliki kolom allow_negative_stock di tabel product_variants', function () {
    expect(Schema::hasColumn('product_variants', 'allow_negative_stock'))->toBeTrue();
});

// ═══════════════════════════════════════════════════════
// 2. Model test
// ═══════════════════════════════════════════════════════

it('product baru memiliki default allow_negative_stock false', function () {
    [$store] = makeAnsStore();
    $product = makeAnsProduct($store);

    expect($product->allow_negative_stock)->toBeFalse();
});

// ═══════════════════════════════════════════════════════
// 3. CRUD test
// ═══════════════════════════════════════════════════════

it('menyimpan produk dengan allow_negative_stock true', function () {
    [$store] = makeAnsStore();
    $product = makeAnsProduct($store, ['allow_negative_stock' => true]);

    expect($product->fresh()->allow_negative_stock)->toBeTrue();
});

// ═══════════════════════════════════════════════════════
// 4. POS addItem — allow_negative_stock = true, stok 0, qty 1 → lolos
// ═══════════════════════════════════════════════════════

it('pos add-item lolos untuk produk dengan allow_negative_stock true dan stok 0', function () {
    [$store, $user] = makeAnsStore();
    $product = makeAnsProduct($store, [
        'allow_negative_stock' => true,
        'stock_quantity' => 0,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('pos.add-item'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

    $response->assertOk();
    $response->assertJsonPath('product.id', $product->id);
});

// ═══════════════════════════════════════════════════════
// 5. POS addItem — allow_negative_stock = false, stok 0, qty 1 → ditolak
// ═══════════════════════════════════════════════════════

it('pos add-item ditolak untuk produk dengan allow_negative_stock false dan stok 0', function () {
    [$store, $user] = makeAnsStore();
    $product = makeAnsProduct($store, [
        'allow_negative_stock' => false,
        'stock_quantity' => 0,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('pos.add-item'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'Stok tidak mencukupi');
});

// ═══════════════════════════════════════════════════════
// 6. POS unlimited_stock override — unlimited + allow_negative → tetap lolos
// ═══════════════════════════════════════════════════════

it('pos add-item lolos untuk produk unlimited_stock dengan allow_negative_stock true', function () {
    [$store, $user] = makeAnsStore();
    $product = makeAnsProduct($store, [
        'unlimited_stock' => true,
        'allow_negative_stock' => true,
        'stock_quantity' => 0,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('pos.add-item'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

    $response->assertOk();
});

// ═══════════════════════════════════════════════════════
// 7. Stock deduction — allow_negative_stock true, stok 2, beli 5 → stok jadi -3
// ═══════════════════════════════════════════════════════

it('stok menjadi negatif setelah pembelian yang melebihi stok saat allow_negative_stock true', function () {
    [$store, $user] = makeAnsStore();
    $product = makeAnsProduct($store, [
        'allow_negative_stock' => true,
        'stock_quantity' => 2,
    ]);

    $product->decrement('stock_quantity', 5);

    expect((float) $product->fresh()->stock_quantity)->toBe(-3.0);
});

// ═══════════════════════════════════════════════════════
// 8. Restok — stok -5, restok 10 → stok jadi 5
// ═══════════════════════════════════════════════════════

it('restok mengembalikan stok dari negatif ke positif', function () {
    [$store] = makeAnsStore();
    $product = makeAnsProduct($store, [
        'allow_negative_stock' => true,
        'stock_quantity' => -5,
    ]);

    $product->increment('stock_quantity', 10);

    expect((float) $product->fresh()->stock_quantity)->toBe(5.0);
});
