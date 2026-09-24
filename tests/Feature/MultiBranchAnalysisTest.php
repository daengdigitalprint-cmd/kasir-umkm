<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiPromptBuilder;
use App\Tools\Reports\SalesSummaryTool;
use App\Tools\Reports\TopProductsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: Store} Dua cabang aktif dalam satu grup usaha.
 */
function createMultiBranchGroup(): array
{
    $organization = Organization::query()->create([
        'name' => 'Grup Multi '.uniqid(),
        'slug' => 'grup-multi-'.uniqid(),
    ]);

    $branchA = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Outlet Multi A',
        'slug' => 'outlet-multi-a-'.uniqid(),
        'is_active' => true,
    ]);

    $branchB = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Outlet Multi B',
        'slug' => 'outlet-multi-b-'.uniqid(),
        'is_active' => true,
    ]);

    return [$branchA, $branchB];
}

function createMultiProduct(Store $store, string $name): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 10000,
        'stock_quantity' => 100,
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function recordMultiSale(Store $store, User $user, Product $product, int $quantity): void
{
    $total = $product->price * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-MULTI-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => now()->startOfDay()->addHour(10)])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $total,
        'gross_profit_snapshot' => 0,
    ]);
}

it('menggabungkan ringkasan penjualan semua cabang dengan scope all', function () {
    [$branchA, $branchB] = createMultiBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'owner']);
    $product = createMultiProduct($branchA, 'Air Mineral');

    recordMultiSale($branchA, $user, $product, 4); // Rp 40.000
    recordMultiSale($branchB, $user, $product, 6); // Rp 60.000

    $result = (new SalesSummaryTool)->execute(['period' => 'today', 'scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('all')
        ->and($result->data['stores_analyzed'])->toBe(2)
        ->and($result->data['total_sales'])->toBe('Rp 100.000')
        ->and($result->data['total_transactions'])->toBe(2)
        ->and($result->message)->toContain('semua cabang');
});

it('membatasi ringkasan penjualan ke outlet aktif saat tanpa scope all', function () {
    [$branchA, $branchB] = createMultiBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'owner']);
    $product = createMultiProduct($branchA, 'Air Mineral');

    recordMultiSale($branchA, $user, $product, 4); // Rp 40.000
    recordMultiSale($branchB, $user, $product, 6); // diabaikan

    $result = (new SalesSummaryTool)->execute(['period' => 'today'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('current')
        ->and($result->data['stores_analyzed'])->toBe(1)
        ->and($result->data['total_sales'])->toBe('Rp 40.000');
});

it('menggabungkan produk terlaris lintas cabang dengan scope all', function () {
    [$branchA, $branchB] = createMultiBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $product = createMultiProduct($branchA, 'Teh Celup');

    recordMultiSale($branchA, $user, $product, 3);
    recordMultiSale($branchB, $user, $product, 5);

    $result = (new TopProductsTool)->execute(['period' => 'this_month', 'scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('all')
        ->and($result->data['stores_analyzed'])->toBe(2)
        ->and($result->data['products'][0]['product_name'])->toBe('Teh Celup')
        ->and($result->data['products'][0]['quantity_sold'])->toBe(8.0)
        ->and($result->message)->toContain('semua cabang');
});

it('menyertakan daftar outlet pada konteks prompt usaha multi-cabang', function () {
    [$branchA, $branchB] = createMultiBranchGroup();

    $prompt = (new AiPromptBuilder(null, $branchA))->buildSystemPrompt();

    expect($prompt)->toContain('Outlet Multi A')
        ->toContain('Outlet Multi B')
        ->toContain('multi-cabang')
        ->toContain('scope "all"');
});
