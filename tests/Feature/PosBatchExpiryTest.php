<?php

use App\Http\Controllers\POSController;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePosBatchStore(string $businessType): array
{
    $store = Store::query()->create([
        'name' => "Toko {$businessType}",
        'slug' => "pos-batch-{$businessType}-".uniqid(),
        'business_type' => $businessType,
        'settings' => ['batch_tracking_enabled' => true],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Batch',
        'price' => 10000,
        'stock_quantity' => '2.0000',
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $user, $product];
}

function deductPosBatchStock(Store $store, User $user, Product $product): void
{
    $controller = app(POSController::class);
    $method = new ReflectionMethod($controller, 'applySaleStockDeduction');
    $method->invoke($controller, $store, $user, $product, null, '1.0000', Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 10000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 10000,
        'amount_paid' => 10000,
        'change_amount' => 0,
    ]));
}

test('POS pharmacy tidak memotong batch yang kedaluwarsa sebelum hari ini', function () {
    [$store, $user, $product] = makePosBatchStore('pharmacy');
    $expired = ProductBatch::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity_remaining' => '1.0000',
        'expiry_date' => today()->subDay(),
    ]);
    $valid = ProductBatch::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity_remaining' => '1.0000',
        'expiry_date' => today(),
    ]);

    deductPosBatchStock($store, $user, $product);

    expect($expired->fresh()->quantity_remaining)->toBe('1.0000')
        ->and($valid->fresh()->quantity_remaining)->toBe('0.0000');
});

test('POS toko non-pharmacy tetap memotong batch kedaluwarsa', function () {
    [$store, $user, $product] = makePosBatchStore('general');
    $expired = ProductBatch::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity_remaining' => '1.0000',
        'expiry_date' => today()->subDay(),
    ]);
    $valid = ProductBatch::query()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity_remaining' => '1.0000',
        'expiry_date' => today(),
    ]);

    deductPosBatchStock($store, $user, $product);

    expect($expired->fresh()->quantity_remaining)->toBe('0.0000')
        ->and($valid->fresh()->quantity_remaining)->toBe('1.0000');
});
