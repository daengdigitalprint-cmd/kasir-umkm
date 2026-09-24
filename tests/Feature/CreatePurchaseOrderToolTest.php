<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Tools\Purchases\CreatePurchaseOrderTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePoStore(array $attributes = []): Store
{
    return Store::query()->create(array_merge([
        'name' => 'Toko PO Test',
        'slug' => 'toko-po-'.uniqid(),
        'location_type' => 'central_warehouse',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
    ], $attributes));
}

function makePoUser(Store $store): User
{
    return User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
}

function makePoProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Biji Kopi',
        'price' => 15000,
        'cost_price' => 4000,
        'average_cost' => 4000,
        'stock_quantity' => 2,
        'base_unit' => 'pcs',
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ], $attributes));
}

test('tool membuat pembelian, menambah stok, dan mencatat pergerakan', function () {
    $store = makePoStore();
    $user = makePoUser($store);
    $product = makePoProduct($store);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [
            ['product_name' => 'Biji Kopi', 'quantity' => 10],
        ],
        'notes' => 'Restock mingguan',
    ], $user);

    expect($result->isSuccess())->toBeTrue();

    $purchase = Purchase::query()->sole();
    $line = PurchaseLine::query()->sole();
    $movement = StockMovement::query()->where('type', StockMovement::TYPE_PURCHASE)->sole();
    $product->refresh();

    expect($purchase->purchase_number)->toStartWith('PUR-')
        ->and($purchase->notes)->toBe('Restock mingguan')
        ->and($purchase->is_paid)->toBeTrue()
        ->and($purchase->payment_method)->toBe('cash')
        ->and($purchase->dpp_amount)->toBe('40000.00')
        ->and($purchase->total_amount)->toBe('40000.00')
        ->and($line->product_name_snapshot)->toBe('Biji Kopi')
        ->and($line->qty_base)->toBe('10.0000')
        ->and($line->unit_cost_base)->toBe('4000.000000')
        ->and($line->subtotal)->toBe('40000.00')
        ->and($product->stock_quantity)->toBe('12.0000')
        ->and($movement->quantity_change)->toBe('10.0000')
        ->and($movement->balance_after)->toBe('12.0000')
        ->and($result->message)->toContain($purchase->purchase_number);
});

test('supplier dicari dari nama yang diberikan', function () {
    $store = makePoStore();
    $user = makePoUser($store);
    makePoProduct($store);
    $supplier = Supplier::query()->create([
        'store_id' => $store->id,
        'name' => 'PT Maju Jaya',
    ]);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [['product_name' => 'Biji Kopi', 'quantity' => 5]],
        'supplier_name' => 'Maju',
        'payment_method' => 'credit',
    ], $user);

    expect($result->isSuccess())->toBeTrue();

    $purchase = Purchase::query()->sole();

    expect($purchase->supplier_id)->toBe($supplier->id)
        ->and($purchase->payment_method)->toBe('credit')
        ->and($purchase->is_paid)->toBeFalse()
        ->and((float) $purchase->amount_paid)->toBe(0.0);
});

test('supplier yang belum terdaftar dibuat otomatis dan menempel di pembelian', function () {
    $store = makePoStore();
    $user = makePoUser($store);
    makePoProduct($store);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [['product_name' => 'Biji Kopi', 'quantity' => 5]],
        'supplier_name' => 'Maju Jaya',
    ], $user);

    expect($result->isSuccess())->toBeTrue();

    $purchase = Purchase::query()->sole();
    $supplier = Supplier::query()->sole();

    expect($purchase->supplier_id)->toBe($supplier->id)
        ->and($supplier->name)->toBe('Maju Jaya')
        ->and($supplier->store_id)->toBe($store->id);
});

test('tanpa nama supplier pembelian tetap dibuat tanpa supplier', function () {
    $store = makePoStore();
    $user = makePoUser($store);
    makePoProduct($store);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [['product_name' => 'Biji Kopi', 'quantity' => 5]],
    ], $user);

    expect($result->isSuccess())->toBeTrue();

    $purchase = Purchase::query()->sole();

    expect($purchase->supplier_id)->toBeNull()
        ->and(Supplier::query()->count())->toBe(0);
});

test('produk yang tidak ditemukan mengembalikan error tanpa membuat pembelian', function () {
    $store = makePoStore();
    $user = makePoUser($store);
    $product = makePoProduct($store);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [['product_name' => 'Produk Tidak Ada', 'quantity' => 5]],
    ], $user);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->error)->toContain('tidak ditemukan')
        ->and(Purchase::query()->count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe('2.0000');
});

test('paket starter ditolak membuat pembelian', function () {
    $store = makePoStore(['is_subscription_active' => false]);
    $user = makePoUser($store);
    makePoProduct($store);

    $result = (new CreatePurchaseOrderTool)->execute([
        'items' => [['product_name' => 'Biji Kopi', 'quantity' => 5]],
    ], $user);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->error)->toContain('Paket Starter')
        ->and(Purchase::query()->count())->toBe(0);
});

test('items kosong mengembalikan error', function () {
    $store = makePoStore();
    $user = makePoUser($store);

    $result = (new CreatePurchaseOrderTool)->execute(['items' => []], $user);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->error)->toContain('minimal satu produk');
});

test('create_purchase_order memerlukan konfirmasi user sebelum dieksekusi', function () {
    expect(config('ai.confirmation_tools'))->toContain('create_purchase_order');
});
