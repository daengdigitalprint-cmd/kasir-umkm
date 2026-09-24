<?php

use App\Models\OperationalExpense;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Reports\ProfitReportTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProfitStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Profit',
        'slug' => 'toko-profit-'.uniqid(),
        'is_active' => true,
    ]);
}

/**
 * @return array{0: Store, 1: Store} Dua cabang dalam satu grup usaha (organization).
 */
function createProfitBranchGroup(): array
{
    $organization = Organization::query()->create([
        'name' => 'Grup Profit '.uniqid(),
        'slug' => 'grup-profit-'.uniqid(),
    ]);

    $branchA = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Profit A',
        'slug' => 'cabang-profit-a-'.uniqid(),
        'is_active' => true,
    ]);

    $branchB = Store::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Cabang Profit B',
        'slug' => 'cabang-profit-b-'.uniqid(),
        'is_active' => true,
    ]);

    return [$branchA, $branchB];
}

function createProfitProduct(Store $store, string $name): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'price' => 20000,
        'stock_quantity' => 100,
        'track_stock' => true,
        'is_active' => true,
    ]);
}

function recordProfitSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt, float $unitCost, ?float $grossProfit = null): void
{
    $total = $product->price * $quantity;
    $totalCost = $unitCost * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-PRO-'.uniqid(),
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
        'unit_cost_snapshot' => $unitCost,
        'total_cost_snapshot' => $totalCost,
        'gross_profit_snapshot' => $grossProfit ?? ($total - $totalCost),
    ]);
}

function createProfitExpense(Store $store, User $user, string $category, float $amount, \Carbon\Carbon $expenseDate): OperationalExpense
{
    return OperationalExpense::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'category' => $category,
        'description' => 'Beban uji '.$category,
        'amount' => $amount,
        'expense_date' => $expenseDate,
        'payment_method' => 'cash',
    ]);
}

it('menyusun laporan laba rugi lengkap dengan beban operasional', function () {
    $store = createProfitStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createProfitProduct($store, 'Teh Botol');

    // Pendapatan Rp 200.000, HPP Rp 120.000, laba kotor Rp 80.000.
    recordProfitSale($store, $user, $product, 10, now()->startOfMonth()->addHours(12), unitCost: 12000);

    createProfitExpense($store, $user, 'gaji', 50000, now()->startOfMonth()->addDay());
    createProfitExpense($store, $user, 'listrik', 20000, now()->startOfMonth()->addDay());

    $result = (new ProfitReportTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['revenue'])->toBe(200000.0)
        ->and($result->data['hpp'])->toBe(120000.0)
        ->and($result->data['gross_profit'])->toBe(80000.0)
        ->and($result->data['gross_margin_pct'])->toBe(40.0)
        ->and($result->data['operational_expenses'])->toBe(70000.0)
        ->and($result->data['net_profit'])->toBe(10000.0)
        ->and($result->data['net_margin_pct'])->toBe(5.0)
        ->and($result->data['expense_breakdown'])->toHaveCount(2)
        ->and($result->data['expense_breakdown'][0]['label'])->toBe('Gaji Karyawan')
        ->and($result->data['expense_breakdown'][0]['total'])->toBe(50000.0)
        ->and($result->data['expense_breakdown'][1]['label'])->toBe('Listrik')
        ->and($result->data['expense_breakdown'][1]['total'])->toBe(20000.0)
        ->and($result->message)->toContain('Laba kotor: Rp 80.000')
        ->and($result->message)->toContain('Laba bersih: Rp 10.000')
        ->and($result->message)->toContain('Gaji Karyawan Rp 50.000');
});

it('menghitung ulang laba kotor dari pendapatan dan HPP saat snapshot laba kosong', function () {
    $store = createProfitStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createProfitProduct($store, 'Kopi Sachet');

    recordProfitSale($store, $user, $product, 10, now()->startOfMonth()->addHours(12), unitCost: 12000, grossProfit: 0.0);

    $result = (new ProfitReportTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['revenue'])->toBe(200000.0)
        ->and($result->data['hpp'])->toBe(120000.0)
        ->and($result->data['gross_profit'])->toBe(80000.0);
});

it('menandai periode dengan rugi bersih', function () {
    $store = createProfitStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createProfitProduct($store, 'Roti Tawar');

    // Laba kotor Rp 20.000 tapi beban gaji Rp 50.000 → rugi bersih Rp 30.000.
    recordProfitSale($store, $user, $product, 5, now()->startOfMonth()->addHours(12), unitCost: 16000);

    createProfitExpense($store, $user, 'gaji', 50000, now()->startOfMonth()->addDay());

    $result = (new ProfitReportTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['gross_profit'])->toBe(20000.0)
        ->and($result->data['net_profit'])->toBe(-30000.0)
        ->and($result->data['net_margin_pct'])->toBe(-30.0)
        ->and($result->message)->toContain('rugi bersih');
});

it('menggabungkan laba rugi semua cabang saat scope all', function () {
    [$branchA, $branchB] = createProfitBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $product = createProfitProduct($branchA, 'Teh Botol');

    recordProfitSale($branchA, $user, $product, 10, now()->startOfMonth()->addHours(12), unitCost: 12000); // Rp 200.000
    recordProfitSale($branchB, $user, $product, 5, now()->startOfMonth()->addHours(12), unitCost: 12000);  // Rp 100.000

    $result = (new ProfitReportTool)->execute(['scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('all')
        ->and($result->data['stores_analyzed'])->toBe(2)
        ->and($result->data['revenue'])->toBe(300000.0)
        ->and($result->data['hpp'])->toBe(180000.0)
        ->and($result->data['gross_profit'])->toBe(120000.0)
        ->and($result->message)->toContain('semua cabang');
});

it('tetap membatasi laporan ke outlet aktif saat tanpa scope all', function () {
    [$branchA, $branchB] = createProfitBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $product = createProfitProduct($branchA, 'Teh Botol');

    recordProfitSale($branchA, $user, $product, 10, now()->startOfMonth()->addHours(12), unitCost: 12000); // Rp 200.000
    recordProfitSale($branchB, $user, $product, 5, now()->startOfMonth()->addHours(12), unitCost: 12000);  // diabaikan

    $result = (new ProfitReportTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('current')
        ->and($result->data['stores_analyzed'])->toBe(1)
        ->and($result->data['revenue'])->toBe(200000.0);
});
