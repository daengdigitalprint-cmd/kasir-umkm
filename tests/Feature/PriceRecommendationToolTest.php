<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Products\PriceRecommendationTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePriceStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Harga',
        'slug' => 'toko-harga-'.uniqid(),
        'is_active' => true,
    ]);
}

function makePriceProduct(Store $store, string $name, float $price, float $costPrice = 0, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => $name,
        'price' => $price,
        'cost_price' => $costPrice,
        'stock_quantity' => 100,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ], $overrides));
}

function recordPriceSale(Store $store, User $user, Product $product, float $unitPrice): void
{
    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-PRC-'.uniqid(),
        'subtotal' => $unitPrice,
        'total_amount' => $unitPrice,
        'amount_paid' => $unitPrice,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
        'is_void' => false,
    ]);

    $transaction->forceFill(['created_at' => now()->subDays(5)])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'total_price' => $unitPrice,
    ]);
}

it('menghitung saran harga dari HPP dan margin target default', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Teh Botol', price: 20000, costPrice: 5000);

    $result = (new PriceRecommendationTool)->execute([], $user);
    $row = $result->data['products'][0];

    expect($result->success)->toBeTrue()
        ->and($result->data['total'])->toBe(1)
        ->and($row['hpp'])->toBe(5000.0)
        ->and($row['current_margin_pct'])->toBe(300.0)
        ->and($row['price_from_margin'])->toBe(6500.0)
        ->and($row['suggested_price'])->toBe(6500.0)
        ->and($row['price_gap'])->toBe(-13500.0)
        ->and($row['market_price'])->toBeNull()
        ->and($result->message)->toContain('margin target **30%**')
        ->and($result->message)->toContain('Rp 6.500');
});

it('memakai margin target kustom', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Kopi Sachet', price: 20000, costPrice: 5000);

    $result = (new PriceRecommendationTool)->execute(['target_margin_pct' => 50], $user);

    expect($result->data['target_margin_pct'])->toBe(50.0)
        ->and($result->data['products'][0]['price_from_margin'])->toBe(7500.0)
        ->and($result->data['products'][0]['suggested_price'])->toBe(7500.0)
        ->and($result->message)->toContain('margin target **50%**');
});

it('memakai rata-rata harga historis sebagai harga pasar', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = makePriceProduct($store, 'Teh Botol', price: 20000, costPrice: 5000);

    recordPriceSale($store, $user, $product, 18000);

    $result = (new PriceRecommendationTool)->execute([], $user);
    $row = collect($result->data['products'])->firstWhere('name', 'Teh Botol');

    expect($row['market_price'])->toBe(18000.0)
        ->and($row['market_source'])->toBe('historis')
        ->and($row['suggested_price'])->toBe(6500.0)
        ->and($result->message)->toContain('(historis)');
});

it('memakai harga produk sejenis dalam kategori yang sama', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Teh Botol', price: 20000, costPrice: 5000, overrides: ['category' => 'Minuman']);
    makePriceProduct($store, 'Kopi Botol', price: 15000, costPrice: 4000, overrides: ['category' => 'Minuman']);

    $result = (new PriceRecommendationTool)->execute([], $user);
    $row = collect($result->data['products'])->firstWhere('name', 'Teh Botol');

    expect($row['market_price'])->toBe(15000.0)
        ->and($row['market_source'])->toBe('produk sejenis')
        ->and($result->message)->toContain('(produk sejenis)');
});

it('membatasi saran harga maksimal 15 persen di atas harga pasar', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Produk A', price: 6000, costPrice: 5000, overrides: ['category' => 'Minuman']);
    makePriceProduct($store, 'Produk B', price: 8000, costPrice: 4000, overrides: ['category' => 'Minuman']);

    $result = (new PriceRecommendationTool)->execute(['target_margin_pct' => 100], $user);
    $row = collect($result->data['products'])->firstWhere('name', 'Produk A');

    expect($row['market_price'])->toBe(8000.0)
        ->and($row['price_from_margin'])->toBe(10000.0)
        ->and($row['suggested_price'])->toBe(9500.0);
});

it('memberi saran kosong saat HPP belum tercatat', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Tanpa Modal', price: 10000);

    $result = (new PriceRecommendationTool)->execute([], $user);
    $row = $result->data['products'][0];

    expect($row['hpp'])->toBeNull()
        ->and($row['suggested_price'])->toBeNull()
        ->and($row['advice'])->toContain('HPP belum tercatat')
        ->and($result->message)->toContain('HPP belum tercatat');
});

it('menyaring produk berdasarkan nama', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Teh Botol', price: 20000, costPrice: 5000);
    makePriceProduct($store, 'Kopi Sachet', price: 15000, costPrice: 4000);

    $result = (new PriceRecommendationTool)->execute(['product_name' => 'Teh Botol'], $user);

    expect($result->data['total'])->toBe(1)
        ->and($result->data['products'][0]['name'])->toBe('Teh Botol');
});

it('menandai harga yang sudah pas dengan saran', function () {
    $store = makePriceStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    makePriceProduct($store, 'Pas Seratus', price: 6500, costPrice: 5000);

    $result = (new PriceRecommendationTool)->execute([], $user);
    $row = $result->data['products'][0];

    expect($row['suggested_price'])->toBe(6500.0)
        ->and($row['price_gap'])->toBe(0.0)
        ->and($result->message)->toContain('harga sudah pas')
        ->and($result->message)->toContain('Harga sudah sesuai');
});

it('menolak user tanpa toko', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $result = (new PriceRecommendationTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('tidak memiliki toko');
});
