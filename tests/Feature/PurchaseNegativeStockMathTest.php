<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryCosting;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Verifikasi: saat pembelian (stok masuk), apakah stok negatif yang sudah ada
 * dikurangi / dikompensasi, atau hanya ditambah biasa?
 */
function makeNegStockStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Stok Minus '.uniqid(),
        'slug' => 'stok-minus-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
    ]);
}

function makeNegStockUser(Store $store): User
{
    return User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
}

function makeNegStockProduct(Store $store, string $initialStock, bool $allowNegative = true): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Barang Stok Minus '.uniqid(),
        'price' => 1000,
        'cost_price' => 500,
        'average_cost' => 500,
        'stock_quantity' => $initialStock,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'allow_negative_stock' => $allowNegative,
        'allows_decimal' => false,
        'has_variants' => false,
        'is_bundle' => false,
        'is_active' => true,
    ]);
}

it('pembelian menambah stok secara matematis tanpa mengurangi nilai minus (stok -5 + beli 10 = 5)', function () {
    $store = makeNegStockStore();
    $user = makeNegStockUser($store);
    $product = makeNegStockProduct($store, '-5.0000');

    expect((string) $product->stock_quantity)->toBe('-5.0000')
        ->and($product->allow_negative_stock)->toBeTrue();

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '10',
                'input_unit' => 'pcs',
                'unit_cost' => '500',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();

    // increment() Eloquent: stok_lama + qty_masuk = -5 + 10 = 5
    // BUKAN: dikurangi stok minus lalu sisanya yang jadi stok (yaitu 10 - 5 = 5 hasilnya sama, tapi logikanya tambah)
    expect((string) $product->stock_quantity)->toBe('5.0000')
        ->and(PurchaseLine::query()->sole()->quantity)->toBe('10.0000');
});

it('pembelian kecil di atas stok minus tetap menghasilkan stok negatif (stok -10 + beli 3 = -7)', function () {
    $store = makeNegStockStore();
    $user = makeNegStockUser($store);
    $product = makeNegStockProduct($store, '-10.0000');

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '3',
                'input_unit' => 'pcs',
                'unit_cost' => '500',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();

    // -10 + 3 = -7. Stok masih negatif karena qty masuk lebih kecil dari defisit.
    expect((string) $product->stock_quantity)->toBe('-7.0000');
});

it('HPP (average_cost) ikut biaya masuk terbaru saat stok lama negatif', function () {
    $store = makeNegStockStore();
    $user = makeNegStockUser($store);
    // Stok -5, average_cost lama 5.000000 (nilai negatif -2500)
    $product = makeNegStockProduct($store, '-5.0000');
    $product->update(['average_cost' => '5.000000']);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '10',
                'input_unit' => 'pcs',
                'unit_cost' => '8',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();

    // InventoryCosting::weightedAverage baris 48-50: stok lama <= 0 → biaya = biaya masuk terbaru.
    // Karena base_unit = unit = 'pcs' (rasio 1:1), unitCostBase = 8.00 → average_cost = 8.000000.
    $expectedAvg = InventoryCosting::weightedAverage('-5.0000', '5.000000', '10.0000', '8.000000');
    expect((string) $product->average_cost)->toBe($expectedAvg)
        ->and((string) $product->cost_price)->toBe('8.00')
        ->and((string) $product->stock_quantity)->toBe('5.0000');
});

it('retur pembelian mengurangi stok kembali (stok 5 - retur 3 = 2)', function () {
    $store = makeNegStockStore();
    $user = makeNegStockUser($store);
    $product = makeNegStockProduct($store, '5.0000');

    // Catat pembelian dulu agar ada stok & history
    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchases.store'), [
            '_token' => 'csrf-token',
            'purchased_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '3',
                'input_unit' => 'pcs',
                'unit_cost' => '500',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();
    expect((string) $product->stock_quantity)->toBe('8.0000');

    // Retur sebagian
    $purchase = Purchase::query()->sole();

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('purchase-returns.store'), [
            '_token' => 'csrf-token',
            'purchase_id' => $purchase->id,
            'returned_at' => now()->format('Y-m-d'),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '3',
            ]],
        ])
        ->assertRedirect();

    $product->refresh();
    // 8 - 3 = 5 (kembali ke nilai awal karena pembelian 3 diretur)
    expect((string) $product->stock_quantity)->toBe('5.0000');
});
