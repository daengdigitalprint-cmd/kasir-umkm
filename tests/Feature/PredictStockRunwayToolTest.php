<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Stock\PredictStockRunwayTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRunwayStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Runway',
        'slug' => 'toko-runway-'.uniqid(),
        'is_active' => true,
    ]);
}

function createRunwayProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Runway',
        'price' => 10000,
        'stock_quantity' => 10,
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));
}

function recordRunwaySale(Store $store, User $user, Product $product, int $quantity, int $daysAgo): void
{
    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-RW-'.uniqid(),
        'subtotal' => $product->price * $quantity,
        'total_amount' => $product->price * $quantity,
        'amount_paid' => $product->price * $quantity,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $product->price * $quantity,
    ]);
}

it('memprediksi kapan stok habis berdasarkan rata-rata penjualan', function () {
    $store = createRunwayStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $product = createRunwayProduct($store, ['name' => 'Teh Botol', 'stock_quantity' => 10]);

    // 30 unit terjual dalam 30 hari => rata-rata 1 unit/hari => stok 10 habis dalam 10 hari
    foreach (range(1, 6) as $i) {
        recordRunwaySale($store, $user, $product, 5, $i * 5);
    }

    $result = (new PredictStockRunwayTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(1)
        ->and($result->data['items'][0]['name'])->toBe('Teh Botol')
        ->and($result->data['items'][0]['avg_per_day'])->toBe(1.0)
        ->and($result->data['items'][0]['days_left'])->toBe(10)
        ->and($result->data['items'][0]['urgency'])->toBe('waspada')
        ->and($result->data['items'][0]['estimated_out_of_stock_date'])
        ->not->toBeNull();
});

it('menandai produk dengan stok habis sebagai paling mendesak', function () {
    $store = createRunwayStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $habis = createRunwayProduct($store, ['name' => 'Stok Habis', 'stock_quantity' => 0]);
    $menipis = createRunwayProduct($store, ['name' => 'Stok Menipis', 'stock_quantity' => 2]);

    recordRunwaySale($store, $user, $habis, 20, 10);
    recordRunwaySale($store, $user, $menipis, 25, 10);

    $result = (new PredictStockRunwayTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'][0]['name'])->toBe('Stok Habis')
        ->and($result->data['items'][0]['urgency'])->toBe('habis')
        ->and($result->data['items'][0]['days_left'])->toBe(0)
        ->and($result->data['items'][1]['name'])->toBe('Stok Menipis')
        ->and($result->data['items'][1]['urgency'])->toBe('kritis')
        ->and($result->data['critical_count'])->toBe(2);
});

it('mengabaikan produk yang tidak ada penjualannya', function () {
    $store = createRunwayStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createRunwayProduct($store, ['name' => 'Tidak Laku', 'stock_quantity' => 50]);

    $result = (new PredictStockRunwayTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(0)
        ->and($result->message)->toContain('Tidak ada produk');
});

it('dapat memfilter berdasarkan nama produk', function () {
    $store = createRunwayStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $teh = createRunwayProduct($store, ['name' => 'Teh Botol', 'stock_quantity' => 10]);
    $kopi = createRunwayProduct($store, ['name' => 'Kopi Sachet', 'stock_quantity' => 10]);

    recordRunwaySale($store, $user, $teh, 30, 15);
    recordRunwaySale($store, $user, $kopi, 30, 15);

    $result = (new PredictStockRunwayTool)->execute(['product_name' => 'Teh Botol'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(1)
        ->and($result->data['items'][0]['name'])->toBe('Teh Botol');
});
