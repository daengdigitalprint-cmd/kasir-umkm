<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Tools\Stock\PurchaseSuggestionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSuggestionStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Sugesti',
        'slug' => 'toko-sugesti-'.uniqid(),
        'is_active' => true,
    ]);
}

function createSuggestionProduct(Store $store, string $name, float $stock, float $minStock, float $costPrice): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => $costPrice * 2,
        'cost_price' => $costPrice,
        'stock_quantity' => $stock,
        'min_stock_level' => $minStock,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function attachSuggestionSupplier(Store $store, User $user, Product $product, string $supplierName, float $purchasePrice): Supplier
{
    $supplier = Supplier::query()->create([
        'store_id' => $store->id,
        'name' => $supplierName,
    ]);

    $purchase = Purchase::query()->create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'purchased_at' => now()->subDays(10),
        'total_amount' => $purchasePrice * 10,
    ]);

    PurchaseLine::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_name_snapshot' => $product->name,
        'quantity' => 10,
        'unit_cost' => $purchasePrice,
        'subtotal' => 10 * $purchasePrice,
    ]);

    return $supplier;
}

it('mengelompokkan produk perlu restock berdasarkan supplier dengan harga beli terakhir', function () {
    $store = createSuggestionStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $teh = createSuggestionProduct($store, 'Teh Botol', stock: 3, minStock: 10, costPrice: 5000);
    $kopi = createSuggestionProduct($store, 'Kopi Sachet', stock: 2, minStock: 8, costPrice: 3000);

    attachSuggestionSupplier($store, $user, $teh, 'Supplier Maju', 4500);
    attachSuggestionSupplier($store, $user, $kopi, 'Supplier Maju', 2800);

    $result = (new PurchaseSuggestionTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['suppliers'])->toHaveCount(1)
        ->and($result->data['suppliers'][0]['name'])->toBe('Supplier Maju')
        ->and($result->data['suppliers'][0]['item_count'])->toBe(2)
        ->and($result->data['total_items'])->toBe(2);

    $tehItem = collect($result->data['suppliers'][0]['items'])->firstWhere('name', 'Teh Botol');
    $kopiItem = collect($result->data['suppliers'][0]['items'])->firstWhere('name', 'Kopi Sachet');

    expect($tehItem['suggested_quantity'])->toBe(7)
        ->and($tehItem['last_purchase_price'])->toBe(4500.0)
        ->and($tehItem['estimated_cost'])->toBe(35000.0)
        ->and($kopiItem['suggested_quantity'])->toBe(6)
        ->and($kopiItem['last_purchase_price'])->toBe(2800.0);
});

it('menghitung total estimasi per supplier dan grand total', function () {
    $store = createSuggestionStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $teh = createSuggestionProduct($store, 'Teh Botol', stock: 3, minStock: 10, costPrice: 5000);
    $kopi = createSuggestionProduct($store, 'Kopi Sachet', stock: 2, minStock: 8, costPrice: 3000);

    attachSuggestionSupplier($store, $user, $teh, 'Supplier A', 4500);
    attachSuggestionSupplier($store, $user, $kopi, 'Supplier B', 2800);

    $result = (new PurchaseSuggestionTool)->execute([], $user);

    expect($result->data['suppliers'])->toHaveCount(2)
        ->and($result->data['grand_total'])->toBeGreaterThan(0);

    $totalPerSupplier = collect($result->data['suppliers'])->sum('total_estimated_cost');
    expect($totalPerSupplier)->toBe($result->data['grand_total']);
});

it('menampilkan produk tanpa supplier dalam grup Tanpa Supplier', function () {
    $store = createSuggestionStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createSuggestionProduct($store, 'Gula Pasir', stock: 1, minStock: 10, costPrice: 12000);

    $result = (new PurchaseSuggestionTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['suppliers'])->toHaveCount(1)
        ->and($result->data['suppliers'][0]['name'])->toBe('Tanpa Supplier')
        ->and($result->data['suppliers'][0]['items'][0]['last_purchase_price'])->toBeNull();
});

it('tidak merekomendasikan produk yang stoknya masih aman', function () {
    $store = createSuggestionStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createSuggestionProduct($store, 'Aman Sentosa', stock: 100, minStock: 5, costPrice: 5000);

    $result = (new PurchaseSuggestionTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['suppliers'])->toBe([])
        ->and($result->data['total_items'])->toBe(0)
        ->and($result->message)->toContain('Semua stok masih aman');
});

it('mendukung scope semua cabang', function () {
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Grup Sugesti '.uniqid(),
        'slug' => 'grup-sugesti-'.uniqid(),
    ]);

    $branchA = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Sugesti A',
        'slug' => 'cabang-sugesti-a-'.uniqid(),
        'is_active' => true,
    ]);

    $branchB = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Sugesti B',
        'slug' => 'cabang-sugesti-b-'.uniqid(),
        'is_active' => true,
    ]);

    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);

    createSuggestionProduct($branchA, 'Produk A', stock: 1, minStock: 10, costPrice: 5000);
    createSuggestionProduct($branchB, 'Produk B', stock: 2, minStock: 8, costPrice: 4000);

    $result = (new PurchaseSuggestionTool)->execute(['scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_items'])->toBe(2);
});

it('menampilkan pesan yang mengandung nama supplier dan harga beli terakhir', function () {
    $store = createSuggestionStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $teh = createSuggestionProduct($store, 'Teh Botol', stock: 3, minStock: 10, costPrice: 5000);
    attachSuggestionSupplier($store, $user, $teh, 'PT Sumber Rejeki', 4500);

    $result = (new PurchaseSuggestionTool)->execute([], $user);

    expect($result->message)->toContain('PT Sumber Rejeki')
        ->and($result->message)->toContain('harga beli terakhir')
        ->and($result->message)->toContain('Total semua supplier');
});
