<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Reports\BranchComparisonTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCompareStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Compare',
        'slug' => 'toko-compare-'.uniqid(),
        'is_active' => true,
    ]);
}

/**
 * @return array{0: Store, 1: Store} Dua cabang dalam satu grup usaha (organization).
 */
function createCompareGroup(): array
{
    $organization = Organization::query()->create([
        'name' => 'Grup Compare '.uniqid(),
        'slug' => 'grup-compare-'.uniqid(),
    ]);

    $branchA = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Compare A',
        'slug' => 'cabang-compare-a-'.uniqid(),
        'is_active' => true,
    ]);

    $branchB = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Compare B',
        'slug' => 'cabang-compare-b-'.uniqid(),
        'is_active' => true,
    ]);

    return [$branchA, $branchB];
}

function createCompareSoloGroup(): Store
{
    $organization = Organization::query()->create([
        'name' => 'Grup Compare Tunggal '.uniqid(),
        'slug' => 'grup-compare-tunggal-'.uniqid(),
    ]);

    return Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Compare Tunggal',
        'slug' => 'cabang-compare-tunggal-'.uniqid(),
        'is_active' => true,
    ]);
}

function createCompareProduct(Store $store): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Compare',
        'price' => 100000,
        'cost_price' => 60000,
        'stock_quantity' => 100,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function recordCompareSale(Store $store, User $user, Product $product, float $unitPrice, \Carbon\Carbon $createdAt, float $grossProfit): void
{
    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-CMP-'.uniqid(),
        'subtotal' => $unitPrice,
        'total_amount' => $unitPrice,
        'amount_paid' => $unitPrice,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'total_price' => $unitPrice,
        'unit_cost_snapshot' => $unitPrice - $grossProfit,
        'total_cost_snapshot' => $unitPrice - $grossProfit,
        'gross_profit_snapshot' => $grossProfit,
    ]);
}

it('membandingkan performa cabang dengan ranking dan porsi pendapatan', function () {
    [$branchA, $branchB] = createCompareGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $productA = createCompareProduct($branchA);
    $productB = createCompareProduct($branchB);

    $saleDate = now()->startOfMonth()->addHours(12);

    for ($i = 0; $i < 2; $i++) {
        recordCompareSale($branchA, $user, $productA, 150000, $saleDate, 40000);
    }

    for ($i = 0; $i < 4; $i++) {
        recordCompareSale($branchB, $user, $productB, 25000, $saleDate, 10000);
    }

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['branches'][0]['store_name'])->toBe('Cabang Compare A')
        ->and($result->data['branches'][0]['rank'])->toBe(1)
        ->and($result->data['branches'][0]['revenue'])->toBe(300000.0)
        ->and($result->data['branches'][0]['transactions'])->toBe(2)
        ->and($result->data['branches'][0]['average_transaction'])->toBe(150000.0)
        ->and($result->data['branches'][0]['gross_profit'])->toBe(80000.0)
        ->and($result->data['branches'][0]['revenue_share_pct'])->toBe(75.0)
        ->and($result->data['branches'][1]['store_name'])->toBe('Cabang Compare B')
        ->and($result->data['branches'][1]['rank'])->toBe(2)
        ->and($result->data['branches'][1]['revenue'])->toBe(100000.0)
        ->and($result->data['branches'][1]['transactions'])->toBe(4)
        ->and($result->data['branches'][1]['average_transaction'])->toBe(25000.0)
        ->and($result->data['branches'][1]['gross_profit'])->toBe(40000.0)
        ->and($result->data['branches'][1]['revenue_share_pct'])->toBe(25.0)
        ->and($result->data['total_revenue'])->toBe(400000.0)
        ->and($result->data['best_branch'])->toBe('Cabang Compare A')
        ->and($result->data['worst_branch'])->toBe('Cabang Compare B');
});

it('menyajikan ringkasan tabel peringkat cabang', function () {
    [$branchA, $branchB] = createCompareGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $productA = createCompareProduct($branchA);
    $productB = createCompareProduct($branchB);

    $saleDate = now()->startOfMonth()->addHours(12);

    recordCompareSale($branchA, $user, $productA, 300000, $saleDate, 120000);
    recordCompareSale($branchB, $user, $productB, 100000, $saleDate, 30000);

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->message)->toContain('| # | Cabang | Pendapatan | Transaksi | Laba Kotor | Porsi |')
        ->and($result->message)->toContain('| 1 | Cabang Compare A | Rp 300.000 | 1 | Rp 120.000 | 75% |')
        ->and($result->message)->toContain('| 2 | Cabang Compare B | Rp 100.000 | 1 | Rp 30.000 | 25% |')
        ->and($result->message)->toContain('**Total semua cabang: Rp 400.000**')
        ->and($result->message)->toContain('Cabang terbaik: **Cabang Compare A** — terendah: **Cabang Compare B**');
});

it('menolak user yang bukan owner admin atau manager', function () {
    [$branchA] = createCompareGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'cashier']);

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('hanya boleh dilihat oleh owner/admin/manager');
});

it('menolak toko yang belum memiliki grup usaha', function () {
    $store = createCompareStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('belum memiliki grup usaha');
});

it('menolak grup usaha yang hanya punya satu outlet', function () {
    $store = createCompareSoloGroup();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('minimal 2 outlet');
});

it('menolak user yang tidak memiliki toko', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $result = (new BranchComparisonTool)->execute([], $user);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('tidak memiliki toko');
});
