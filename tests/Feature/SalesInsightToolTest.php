<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\Reports\SalesInsightTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: Store} Dua cabang dalam satu grup usaha (organization).
 */
if (! function_exists('createInsightBranchGroup')) {
    function createInsightBranchGroup(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Grup Insight '.uniqid(),
            'slug' => 'grup-insight-'.uniqid(),
        ]);

        $branchA = Store::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Cabang Insight A',
            'slug' => 'cabang-insight-a-'.uniqid(),
            'is_active' => true,
        ]);

        $branchB = Store::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Cabang Insight B',
            'slug' => 'cabang-insight-b-'.uniqid(),
            'is_active' => true,
        ]);

        return [$branchA, $branchB];
    }
}

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

if (! function_exists('createInsightProduct')) {
    function createInsightProduct(Store $store, string $name): Product
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
}

if (! function_exists('recordInsightSale')) {
    function recordInsightSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt, float $grossProfit = 0): void
    {
        $total = $product->price * $quantity;

        $transaction = Transaction::create([
            'store_id' => $store->id,
            'user_id' => $user->id,
            'transaction_number' => 'TRX-INS-'.uniqid(),
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
            'gross_profit_snapshot' => $grossProfit,
        ]);
    }
}

it('membandingkan penjualan periode berjalan dengan periode sebelumnya', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Teh Botol');

    $thisWeek = now()->startOfWeek()->addHour(12);
    $lastWeek = now()->subWeek()->startOfWeek()->addHour(12);

    // Minggu lalu: 5 unit (Rp 100.000), minggu ini: 10 unit (Rp 200.000)
    recordInsightSale($store, $user, $product, 5, $lastWeek);
    recordInsightSale($store, $user, $product, 10, $thisWeek);

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['current']['revenue'])->toBe(200000.0)
        ->and($result->data['previous']['revenue'])->toBe(100000.0)
        ->and($result->data['revenue_change_pct'])->toBe(100.0)
        ->and($result->data['direction'])->toBe('naik')
        ->and($result->data['biggest_increases'][0]['product_name'])->toBe('Teh Botol')
        ->and($result->data['biggest_increases'][0]['qty_change'])->toBe(5.0);
});

it('mendeteksi penurunan penjualan dan produk penyebabnya', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $naik = createInsightProduct($store, 'Produk Naik');
    $turun = createInsightProduct($store, 'Produk Turun');

    $thisWeek = now()->startOfWeek()->addHour(12);
    $lastWeek = now()->subWeek()->startOfWeek()->addHour(12);

    recordInsightSale($store, $user, $turun, 20, $lastWeek);
    recordInsightSale($store, $user, $naik, 2, $thisWeek);

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['direction'])->toBe('turun')
        ->and($result->data['revenue_change_pct'])->toBe(-90.0)
        ->and($result->data['biggest_decreases'][0]['product_name'])->toBe('Produk Turun')
        ->and($result->data['biggest_decreases'][0]['qty_change'])->toBe(-20.0);
});

it('menghitung margin kotor periode berjalan', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Kopi Susu');

    recordInsightSale($store, $user, $product, 10, now()->startOfWeek()->addHour(12), grossProfit: 60000);

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['margin']['total_gross_profit'])->toBe(60000.0)
        ->and($result->data['margin']['gross_margin_pct'])->toBe(30.0)
        ->and($result->data['margin']['top_products'][0]['product_name'])->toBe('Kopi Susu');
});

it('mengembalikan arah stabil saat penjualan tidak berubah signifikan', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Produk Stabil');

    recordInsightSale($store, $user, $product, 10, now()->startOfWeek()->addHour(12));
    recordInsightSale($store, $user, $product, 10, now()->subWeek()->startOfWeek()->addHour(12));

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['direction'])->toBe('stabil')
        ->and($result->data['revenue_change_pct'])->toBe(0.0);
});

it('menampilkan margin kotor yang benar di pesan ringkasan', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Kopi Susu');

    recordInsightSale($store, $user, $product, 10, now()->startOfWeek()->addHour(12), grossProfit: 60000);

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toContain('Margin kotor periode ini: 30%.');
});

it('tidak menampilkan kenaikan sebagai penurunan', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Alvocado B');

    // Periode sebelumnya kosong, periode berjalan naik → tidak ada penurunan sama sekali.
    recordInsightSale($store, $user, $product, 1, now()->startOfWeek()->addHour(12));

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['biggest_increases'])->not->toBeEmpty()
        ->and($result->data['biggest_decreases'])->toBe([])
        ->and($result->message)->not->toContain('Penurunan terbesar');
});

it('menyertakan rekomendasi di data dan pesan ringkasan', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = createInsightProduct($store, 'Produk Promo');

    recordInsightSale($store, $user, $product, 10, now()->startOfWeek()->addHour(12));

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['recommendations'])->not->toBeEmpty()
        ->and($result->message)->toContain('**Rekomendasi:**');
});

it('memberi saran promo saat penjualan periode ini nol', function () {
    $store = createInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['recommendations'][0])->toContain('Belum ada penjualan');
});

it('menganalisa semua cabang dalam satu grup usaha saat scope all', function () {
    [$branchA, $branchB] = createInsightBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $product = createInsightProduct($branchA, 'Teh Botol');

    $thisWeek = now()->startOfWeek()->addHour(12);

    recordInsightSale($branchA, $user, $product, 10, $thisWeek); // Rp 200.000
    recordInsightSale($branchB, $user, $product, 5, $thisWeek);  // Rp 100.000

    $result = (new SalesInsightTool)->execute(['scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('all')
        ->and($result->data['stores_analyzed'])->toBe(2)
        ->and($result->data['current']['revenue'])->toBe(300000.0)
        ->and($result->message)->toContain('semua cabang');
});

it('tetap membatasi analisa ke outlet aktif saat tanpa scope all', function () {
    [$branchA, $branchB] = createInsightBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'admin']);
    $product = createInsightProduct($branchA, 'Teh Botol');

    $thisWeek = now()->startOfWeek()->addHour(12);

    recordInsightSale($branchA, $user, $product, 10, $thisWeek); // Rp 200.000
    recordInsightSale($branchB, $user, $product, 5, $thisWeek);  // diabaikan

    $result = (new SalesInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('current')
        ->and($result->data['stores_analyzed'])->toBe(1)
        ->and($result->data['current']['revenue'])->toBe(200000.0);
});

it('mengabaikan scope all untuk role di luar back-office (kasir)', function () {
    [$branchA, $branchB] = createInsightBranchGroup();
    $user = User::factory()->create(['store_id' => $branchA->id, 'role' => 'cashier']);
    $product = createInsightProduct($branchA, 'Teh Botol');

    $thisWeek = now()->startOfWeek()->addHour(12);

    recordInsightSale($branchA, $user, $product, 10, $thisWeek); // Rp 200.000
    recordInsightSale($branchB, $user, $product, 5, $thisWeek);  // diabaikan

    $result = (new SalesInsightTool)->execute(['scope' => 'all'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['scope'])->toBe('current')
        ->and($result->data['stores_analyzed'])->toBe(1)
        ->and($result->data['current']['revenue'])->toBe(200000.0);
});
