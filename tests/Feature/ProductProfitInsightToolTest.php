<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Reports\ProductProfitInsightTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

if (! function_exists('createInsightStore')) {
    function createInsightStore(): Store
    {
        return Store::query()->create([
            'name' => 'Toko Insight',
            'slug' => 'toko-insight-'.uniqid(),
            'is_active' => true,
        ]);
    }
}

if (! function_exists('createProfitInsightProduct')) {
    function createProfitInsightProduct(Store $store, string $name, float $price, float $cost): Product
    {
        return Product::query()->create([
            'store_id' => $store->id,
            'name' => $name,
            'price' => $price,
            'cost_price' => $cost,
            'stock_quantity' => 100,
            'min_stock_level' => 5,
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
        ]);
    }
}

if (! function_exists('createInsightTransactionWithItems')) {
    function createInsightTransactionWithItems(Store $store, User $user, array $items, ?Carbon $createdAt = null): Transaction
    {
        $total = array_sum(array_map(fn ($i) => $i['total_price'] - ($i['discount_amount'] ?? 0), $items));

        $transaction = Transaction::query()->create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'transaction_number' => 'TRX-INSIGHT-'.uniqid(),
            'subtotal' => $total,
            'total_amount' => $total,
            'amount_paid' => $total,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        if ($createdAt) {
            $transaction->forceFill(['created_at' => $createdAt])->save();
        }

        foreach ($items as $item) {
            TransactionItem::query()->create(array_merge([
                'transaction_id' => $transaction->id,
            ], $item));
        }

        return $transaction;
    }
}

it('mendeteksi produk bocor dengan margin tipis', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    // Produk A: terlaris tapi margin sangat tipis (revenue 10000, cost 9500, profit = 500 → 5%)
    $produkA = createProfitInsightProduct($store, 'Mie Instan', 10000, 9500);
    // Produk B: normal margin (revenue 10000, cost 5000, profit = 5000 → 50%)
    $produkB = createProfitInsightProduct($store, 'Air Mineral', 10000, 5000);

    for ($i = 0; $i < 10; $i++) {
        createInsightTransactionWithItems($store, $user, [
            [
                'product_id' => $produkA->id,
                'product_name' => 'Mie Instan',
                'quantity' => 1,
                'unit_price' => 10000,
                'total_price' => 10000,
                'discount_amount' => 0,
                'unit_cost_snapshot' => 9500,
                'total_cost_snapshot' => 9500,
                'gross_profit_snapshot' => 500,
            ],
        ]);
    }

    for ($i = 0; $i < 10; $i++) {
        createInsightTransactionWithItems($store, $user, [
            [
                'product_id' => $produkB->id,
                'product_name' => 'Air Mineral',
                'quantity' => 1,
                'unit_price' => 10000,
                'total_price' => 10000,
                'discount_amount' => 0,
                'unit_cost_snapshot' => 5000,
                'total_cost_snapshot' => 5000,
                'gross_profit_snapshot' => 5000,
            ],
        ]);
    }

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['leaking_products'])->toHaveCount(1)
        ->and($result->data['leaking_products'][0]['product_name'])->toBe('Mie Instan')
        ->and($result->data['leaking_products'][0]['margin_pct'])->toBeLessThan(15)
        ->and($result->data['bundling_suggestions'])->toBeArray()
        ->and($result->data['slow_movers_high_margin'])->toBeArray();
});

it('mendeteksi produk dengan diskon tinggi sebagai produk bocor', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $produk = createProfitInsightProduct($store, 'Shampoo', 15000, 5000);

    // Diskon tinggi: total_price 15000, discount 5000 → diskon ratio ~25%
    for ($i = 0; $i < 5; $i++) {
        createInsightTransactionWithItems($store, $user, [
            [
                'product_id' => $produk->id,
                'product_name' => 'Shampoo',
                'quantity' => 1,
                'unit_price' => 15000,
                'total_price' => 15000,
                'discount_amount' => 5000,
                'unit_cost_snapshot' => 5000,
                'total_cost_snapshot' => 5000,
                'gross_profit_snapshot' => 5000,
            ],
        ]);
    }

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['leaking_products'])->toHaveCount(1)
        ->and($result->data['leaking_products'][0]['discount_ratio_pct'])->toBeGreaterThan(20);
});

it('menganalisis bundling dari produk yang sering dibeli bersama', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $produkA = createProfitInsightProduct($store, 'Roti', 8000, 4000);
    $produkB = createProfitInsightProduct($store, 'Susu', 12000, 7000);
    $produkC = createProfitInsightProduct($store, 'Permen', 3000, 1500);

    // Roti + Susu selalu dibeli bareng (5 transaksi).
    for ($i = 0; $i < 5; $i++) {
        createInsightTransactionWithItems($store, $user, [
            [
                'product_id' => $produkA->id,
                'product_name' => 'Roti',
                'quantity' => 1,
                'unit_price' => 8000,
                'total_price' => 8000,
                'discount_amount' => 0,
                'unit_cost_snapshot' => 4000,
                'total_cost_snapshot' => 4000,
                'gross_profit_snapshot' => 4000,
            ],
            [
                'product_id' => $produkB->id,
                'product_name' => 'Susu',
                'quantity' => 1,
                'unit_price' => 12000,
                'total_price' => 12000,
                'discount_amount' => 0,
                'unit_cost_snapshot' => 7000,
                'total_cost_snapshot' => 7000,
                'gross_profit_snapshot' => 5000,
            ],
        ]);
    }

    // Permen hanya sendiri (1 transaksi).
    createInsightTransactionWithItems($store, $user, [
        [
            'product_id' => $produkC->id,
            'product_name' => 'Permen',
            'quantity' => 1,
            'unit_price' => 3000,
            'total_price' => 3000,
            'discount_amount' => 0,
            'unit_cost_snapshot' => 1500,
            'total_cost_snapshot' => 1500,
            'gross_profit_snapshot' => 1500,
        ],
    ]);

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['bundling_suggestions'])->toHaveCount(1)
        ->and($result->data['bundling_suggestions'][0]['product_a']['name'])->toBe('Roti')
        ->and($result->data['bundling_suggestions'][0]['product_b']['name'])->toBe('Susu')
        ->and($result->data['bundling_suggestions'][0]['co_occurrence'])->toBe(5);
});

it('mendeteksi produk slow-moving dengan margin tinggi', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    // Produk laris (banyak transaksi).
    $laris = createProfitInsightProduct($store, 'Kopi', 5000, 2000);
    // Produk slow-moving tapi margin tinggi.
    $premium = createProfitInsightProduct($store, 'Coklat Import', 50000, 15000);

    // Kopi: 20 transaksi (laris).
    for ($i = 0; $i < 20; $i++) {
        createInsightTransactionWithItems($store, $user, [
            [
                'product_id' => $laris->id,
                'product_name' => 'Kopi',
                'quantity' => 1,
                'unit_price' => 5000,
                'total_price' => 5000,
                'discount_amount' => 0,
                'unit_cost_snapshot' => 2000,
                'total_cost_snapshot' => 2000,
                'gross_profit_snapshot' => 3000,
            ],
        ]);
    }

    // Coklat Import: hanya 1 transaksi (slow-moving) tapi margin 70%.
    createInsightTransactionWithItems($store, $user, [
        [
            'product_id' => $premium->id,
            'product_name' => 'Coklat Import',
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
            'discount_amount' => 0,
            'unit_cost_snapshot' => 15000,
            'total_cost_snapshot' => 15000,
            'gross_profit_snapshot' => 35000,
        ],
    ]);

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and(count($result->data['slow_movers_high_margin']))->toBeGreaterThanOrEqual(1);

    $slowNames = array_column($result->data['slow_movers_high_margin'], 'product_name');
    expect($slowNames)->toContain('Coklat Import');
});

it('mengembalikan pesan kosong ketika tidak ada transaksi', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['leaking_products'])->toBe([])
        ->and($result->data['bundling_suggestions'])->toBe([])
        ->and($result->data['slow_movers_high_margin'])->toBe([])
        ->and($result->message)->toContain('Belum ada data transaksi');
});

it('mendukung scope semua cabang', function () {
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Grup Insight '.uniqid(),
        'slug' => 'grup-insight-'.uniqid(),
    ]);

    $storeA = Store::query()->create([
        'name' => 'Toko Insight A',
        'slug' => 'toko-insight-a-'.uniqid(),
        'is_active' => true,
        'organization_id' => $organization->id,
    ]);
    $storeB = Store::query()->create([
        'name' => 'Toko Insight B',
        'slug' => 'toko-insight-b-'.uniqid(),
        'is_active' => true,
        'organization_id' => $organization->id,
    ]);

    $user = User::factory()->create([
        'store_id' => $storeA->id,
        'role' => 'owner',
    ]);

    // Attach stores ke user.
    $user->stores()->attach($storeA->id, ['role' => 'owner']);
    $user->stores()->attach($storeB->id, ['role' => 'owner']);

    $produkA = createProfitInsightProduct($storeA, 'Produk A', 10000, 8000);
    $produkB = createProfitInsightProduct($storeB, 'Produk B', 10000, 8000);

    createInsightTransactionWithItems($storeA, $user, [
        [
            'product_id' => $produkA->id,
            'product_name' => 'Produk A',
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
            'discount_amount' => 0,
            'unit_cost_snapshot' => 8000,
            'total_cost_snapshot' => 8000,
            'gross_profit_snapshot' => 2000,
        ],
    ]);

    createInsightTransactionWithItems($storeB, $user, [
        [
            'product_id' => $produkB->id,
            'product_name' => 'Produk B',
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
            'discount_amount' => 0,
            'unit_cost_snapshot' => 8000,
            'total_cost_snapshot' => 8000,
            'gross_profit_snapshot' => 2000,
        ],
    ]);

    $result = (new ProductProfitInsightTool)->execute(['scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('all')
        ->and($result->data['summary']['total_products_analyzed'])->toBe(2);
});

it('menampilkan ringkasan yang mengandung nama produk dan margin', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $produk = createProfitInsightProduct($store, 'Sabun', 8000, 7500);

    createInsightTransactionWithItems($store, $user, [
        [
            'product_id' => $produk->id,
            'product_name' => 'Sabun',
            'quantity' => 5,
            'unit_price' => 8000,
            'total_price' => 40000,
            'discount_amount' => 0,
            'unit_cost_snapshot' => 7500,
            'total_cost_snapshot' => 37500,
            'gross_profit_snapshot' => 2500,
        ],
    ]);

    $result = (new ProductProfitInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toContain('Sabun')
        ->and($result->message)->toContain('margin');
});
