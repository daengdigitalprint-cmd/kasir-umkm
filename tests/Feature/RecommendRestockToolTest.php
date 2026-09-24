<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Stock\RecommendRestockTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRestockStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Restock',
        'slug' => 'toko-restock-'.uniqid(),
        'is_active' => true,
    ]);
}

/**
 * @return array{0: Store, 1: Store} Dua cabang dalam satu grup usaha (organization).
 */
function createRestockBranchGroup(): array
{
    $organization = Organization::query()->create([
        'name' => 'Grup Restock '.uniqid(),
        'slug' => 'grup-restock-'.uniqid(),
    ]);

    $branchA = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Restock A',
        'slug' => 'cabang-restock-a-'.uniqid(),
        'is_active' => true,
    ]);

    $branchB = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Restock B',
        'slug' => 'cabang-restock-b-'.uniqid(),
        'is_active' => true,
    ]);

    return [$branchA, $branchB];
}

function createRestockProduct(Store $store, string $name, float $stock, float $minStock, float $costPrice): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 20000,
        'cost_price' => $costPrice,
        'stock_quantity' => $stock,
        'min_stock_level' => $minStock,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function recordRestockSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt): void
{
    $total = $product->price * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-RST-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $total,
    ]);
}

function attachRestockSupplier(Store $store, User $user, Product $product, string $supplierName): void
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
        'total_amount' => 100000,
    ]);

    PurchaseLine::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_name_snapshot' => $product->name,
        'quantity' => 10,
        'unit_cost' => $product->cost_price,
        'subtotal' => 10 * (float) $product->cost_price,
    ]);
}

it('merekomendasikan produk di bawah stok minimum lengkap dengan estimasi modal dan supplier', function () {
    $store = createRestockStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createRestockProduct($store, 'Teh Botol', stock: 3, minStock: 10, costPrice: 5000);

    attachRestockSupplier($store, $user, $product, 'Supplier Maju');

    $result = (new RecommendRestockTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total'])->toBe(1)
        ->and($result->data['items'][0]['name'])->toBe('Teh Botol')
        ->and($result->data['items'][0]['suggested_quantity'])->toBe(7)
        ->and($result->data['items'][0]['estimated_cost'])->toBe(35000.0)
        ->and($result->data['items'][0]['reason'])->toBe('stok di bawah minimum')
        ->and($result->data['items'][0]['supplier'])->toBe('Supplier Maju')
        ->and($result->data['total_estimated_cost'])->toBe(35000.0)
        ->and($result->message)->toContain('beli **7 pcs**')
        ->and($result->message)->toContain('Estimasi total modal: Rp 35.000')
        ->and($result->message)->toContain('Supplier Maju');
});

it('menghitung jumlah beli dari laju penjualan untuk produk fast-moving', function () {
    $store = createRestockStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createRestockProduct($store, 'Kopi Sachet', stock: 50, minStock: 5, costPrice: 3000);

    // 150 unit dalam 30 hari = 5 unit/hari → stok 50 cukup ±10 hari (di bawah target 14 hari).
    recordRestockSale($store, $user, $product, 150, now()->subDays(5)->setTime(12, 0));

    $result = (new RecommendRestockTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'][0]['name'])->toBe('Kopi Sachet')
        ->and($result->data['items'][0]['avg_sales_per_day'])->toBe(5.0)
        ->and($result->data['items'][0]['days_left'])->toBe(10)
        ->and($result->data['items'][0]['suggested_quantity'])->toBe(20)
        ->and($result->data['items'][0]['estimated_cost'])->toBe(60000.0)
        ->and($result->data['items'][0]['reason'])->toBe('diprediksi habis dalam 10 hari')
        ->and($result->message)->toContain('beli **20 pcs**');
});

it('tidak merekomendasikan produk yang stoknya masih aman', function () {
    $store = createRestockStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createRestockProduct($store, 'Aman Sentosa', stock: 100, minStock: 5, costPrice: 5000);

    // 30 unit dalam 30 hari = 1 unit/hari → stok 100 masih tahan ±100 hari.
    recordRestockSale($store, $user, $product, 30, now()->subDays(5)->setTime(12, 0));

    $result = (new RecommendRestockTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toBe([])
        ->and($result->data['total_estimated_cost'])->toBe(0)
        ->and($result->message)->toContain('Semua stok masih aman');
});

it('menggabungkan rekomendasi semua cabang saat scope all', function () {
    [$branchA, $branchB] = createRestockBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);

    createRestockProduct($branchA, 'Teh Cabang A', stock: 3, minStock: 10, costPrice: 5000);
    createRestockProduct($branchB, 'Kopi Cabang B', stock: 2, minStock: 8, costPrice: 4000);

    $result = (new RecommendRestockTool)->execute(['scope' => 'all'], $user);

    $itemA = collect($result->data['items'])->firstWhere('name', 'Teh Cabang A');
    $itemB = collect($result->data['items'])->firstWhere('name', 'Kopi Cabang B');

    expect($result->success)->toBeTrue()
        ->and($result->data['items'])->toHaveCount(2)
        ->and($itemA['store_name'])->toBe('Cabang Restock A')
        ->and($itemA['suggested_quantity'])->toBe(7)
        ->and($itemB['store_name'])->toBe('Cabang Restock B')
        ->and($itemB['suggested_quantity'])->toBe(6)
        ->and($result->data['total_estimated_cost'])->toBe(59000.0)
        ->and($result->message)->toContain('semua cabang')
        ->and($result->message)->toContain('[Cabang Restock B]');
});
