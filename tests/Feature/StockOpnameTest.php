<?php

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AdjustmentStockOperations;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test Tahap 4 — Stock Opname.
 */
function makeOpnameStore(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Opname',
        'slug' => 'opname-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeOpnameProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Opname',
        'price' => 15000,
        'cost_price' => 9000,
        'average_cost' => 9000,
        'stock_quantity' => 60,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ], $attrs));
}

/**
 * Replika StockOpnameController::store — membuka sesi dan membekukan snapshot.
 */
function openOpnameSession(User $user, Store $store, $products): StockAdjustment
{
    return DB::transaction(function () use ($user, $store, $products) {
        $document = StockAdjustment::create([
            'store_id' => $store->id,
            'type' => StockAdjustment::TYPE_OPNAME,
            'reason_code' => StockAdjustment::REASON_OPNAME,
            'status' => StockAdjustment::STATUS_DRAFT,
            'counted_at' => now(),
            'created_by' => $user->id,
        ]);

        foreach ($products as $product) {
            $systemQty = AdjustmentStockOperations::currentQuantity($product);
            StockAdjustmentLine::create([
                'stock_adjustment_id' => $document->id,
                'product_id' => $product->id,
                'system_quantity' => $systemQty,
                'counted_quantity' => $systemQty,
                'variance_quantity' => '0.0000',
                'unit_cost' => InventoryCosting::currentAverageCost($product),
                'variance_value' => '0.00',
            ]);
        }

        return $document->fresh();
    });
}

/**
 * Replika StockOpnameController::saveDraft.
 */
function saveOpnameDraft(StockAdjustment $document, array $counts): void
{
    DB::transaction(function () use ($document, $counts) {
        $lines = $document->lines()->with('product')->get()->keyBy('id');

        foreach ($counts as $lineId => $counted) {
            $line = $lines->get($lineId);
            $normalized = ProductQuantity::normalizeStorageAmount($line->product, $counted);
            $variance = AdjustmentStockOperations::variance($normalized, (string) $line->system_quantity);

            $line->update([
                'counted_quantity' => $normalized,
                'variance_quantity' => $variance,
                'variance_value' => InventoryCosting::extendedCost($variance, (string) $line->unit_cost),
            ]);
        }

        $document->update(['total_variance_value' => (string) $document->lines()->sum('variance_value')]);
    });
}

/**
 * Replika StockOpnameController::post.
 */
function postOpnameSession(User $user, Store $store, StockAdjustment $document): StockAdjustment
{
    return DB::transaction(function () use ($user, $store, $document) {
        $document->assertCanTransitionTo(StockAdjustment::STATUS_POSTED);

        $lines = $document->lines()->get();
        $products = Product::query()
            ->whereIn('id', $lines->pluck('product_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $total = '0.00';
        foreach ($lines as $line) {
            $product = $products->get((int) $line->product_id);
            AdjustmentStockOperations::assertAdjustable($store, $product);
            AdjustmentStockOperations::applyLine($store, $user, $product, $document, $line);
            $total = bcadd($total, (string) $line->fresh()->variance_value, 2);
        }

        $document->update([
            'status' => StockAdjustment::STATUS_POSTED,
            'posted_by' => $user->id,
            'posted_at' => now(),
            'total_variance_value' => $total,
        ]);

        return $document->fresh();
    });
}

it('membekukan stok sistem saat sesi dibuka', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 60]);

    $doc = openOpnameSession($user, $store, [$product]);

    expect($doc->status)->toBe(StockAdjustment::STATUS_DRAFT);
    expect($doc->adjustment_number)->toStartWith('OPN/');

    $line = $doc->lines()->first();
    expect((string) $line->system_quantity)->toBe('60.0000');
    // Hitungan awal disamakan dengan sistem agar baris tak tersentuh tidak menimbulkan selisih.
    expect((string) $line->variance_quantity)->toBe('0.0000');
});

it('tidak mengubah stok saat sesi masih draft', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 60]);

    $doc = openOpnameSession($user, $store, [$product]);
    saveOpnameDraft($doc, [$doc->lines()->first()->id => 55]);

    expect((int) $product->fresh()->stock_quantity)->toBe(60);
    expect(StockMovement::query()->count())->toBe(0);
});

it('menyimpan draft dan melanjutkannya tanpa kehilangan data', function () {
    [$store, $user] = makeOpnameStore();
    $a = makeOpnameProduct($store, ['name' => 'Produk A', 'stock_quantity' => 30]);
    $b = makeOpnameProduct($store, ['name' => 'Produk B', 'stock_quantity' => 20]);

    $doc = openOpnameSession($user, $store, [$a, $b]);
    $lines = $doc->lines()->orderBy('id')->get();

    // Hari pertama: hanya produk A dihitung.
    saveOpnameDraft($doc, [$lines[0]->id => 28]);
    expect((string) $lines[0]->fresh()->variance_quantity)->toBe('-2.0000');

    // Hari kedua: produk B dihitung, hasil produk A tetap tersimpan.
    saveOpnameDraft($doc, [$lines[1]->id => 22]);
    expect((string) $lines[0]->fresh()->variance_quantity)->toBe('-2.0000');
    expect((string) $lines[1]->fresh()->variance_quantity)->toBe('2.0000');
});

it('menerapkan seluruh selisih saat diposting', function () {
    [$store, $user] = makeOpnameStore();
    $a = makeOpnameProduct($store, ['name' => 'Produk A', 'stock_quantity' => 30, 'average_cost' => 1000]);
    $b = makeOpnameProduct($store, ['name' => 'Produk B', 'stock_quantity' => 20, 'average_cost' => 2000]);

    $doc = openOpnameSession($user, $store, [$a, $b]);
    $lines = $doc->lines()->orderBy('id')->get();

    saveOpnameDraft($doc, [$lines[0]->id => 28, $lines[1]->id => 23]);
    $posted = postOpnameSession($user, $store, $doc);

    expect((int) $a->fresh()->stock_quantity)->toBe(28);
    expect((int) $b->fresh()->stock_quantity)->toBe(23);
    expect($posted->status)->toBe(StockAdjustment::STATUS_POSTED);
    // (-2 x 1000) + (+3 x 2000) = +4000
    expect((float) $posted->total_variance_value)->toBe(4000.0);
});

it('mencatat mutasi bertipe opname', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 40]);

    $doc = openOpnameSession($user, $store, [$product]);
    saveOpnameDraft($doc, [$doc->lines()->first()->id => 37]);
    postOpnameSession($user, $store, $doc);

    $movement = StockMovement::query()->where('product_id', $product->id)->first();

    expect($movement->type)->toBe(StockMovement::TYPE_OPNAME);
    expect((string) $movement->quantity_change)->toBe('-3.0000');
    expect((string) $movement->balance_after)->toBe('37.0000');
});

it('tidak menulis mutasi untuk produk yang cocok dengan sistem', function () {
    [$store, $user] = makeOpnameStore();
    $a = makeOpnameProduct($store, ['name' => 'Cocok', 'stock_quantity' => 10]);
    $b = makeOpnameProduct($store, ['name' => 'Selisih', 'stock_quantity' => 10]);

    $doc = openOpnameSession($user, $store, [$a, $b]);
    $lines = $doc->lines()->orderBy('id')->get();

    saveOpnameDraft($doc, [$lines[0]->id => 10, $lines[1]->id => 8]);
    postOpnameSession($user, $store, $doc);

    expect(StockMovement::query()->where('product_id', $a->id)->count())->toBe(0);
    expect(StockMovement::query()->where('product_id', $b->id)->count())->toBe(1);
});

it('tetap benar walau terjadi penjualan antara hitung dan posting', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 50]);

    $doc = openOpnameSession($user, $store, [$product]);
    saveOpnameDraft($doc, [$doc->lines()->first()->id => 48]);

    // Toko menjual 3 unit sebelum sesi diposting.
    $product->update(['stock_quantity' => 47]);

    postOpnameSession($user, $store, $doc);

    // Selisih -2 diterapkan pada stok berjalan 47.
    expect((int) $product->fresh()->stock_quantity)->toBe(45);
});

it('menolak posting ganda', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 25]);

    $doc = openOpnameSession($user, $store, [$product]);
    saveOpnameDraft($doc, [$doc->lines()->first()->id => 24]);
    $posted = postOpnameSession($user, $store, $doc);

    expect(fn () => postOpnameSession($user, $store, $posted))->toThrow(RuntimeException::class);
    expect((int) $product->fresh()->stock_quantity)->toBe(24);
});

it('mengembalikan stok saat sesi yang sudah diposting dibatalkan', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 100]);

    $doc = openOpnameSession($user, $store, [$product]);
    saveOpnameDraft($doc, [$doc->lines()->first()->id => 94]);
    $posted = postOpnameSession($user, $store, $doc);

    expect((int) $product->fresh()->stock_quantity)->toBe(94);

    $line = $posted->lines()->first();
    $product->refresh();
    AdjustmentStockOperations::reverseLine($store, $user, $product, $posted, $line);

    expect((int) $product->fresh()->stock_quantity)->toBe(100);
});

it('memisahkan dokumen opname dari dokumen penyesuaian', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store);

    openOpnameSession($user, $store, [$product]);
    StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_ADJUSTMENT,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'created_by' => $user->id,
    ]);

    expect(StockAdjustment::where('type', StockAdjustment::TYPE_OPNAME)->count())->toBe(1);
    expect(StockAdjustment::where('type', StockAdjustment::TYPE_ADJUSTMENT)->count())->toBe(1);
});

it('menghitung nilai selisih memakai hpp yang dibekukan saat sesi dibuka', function () {
    [$store, $user] = makeOpnameStore();
    $product = makeOpnameProduct($store, ['stock_quantity' => 20, 'average_cost' => 5000]);

    $doc = openOpnameSession($user, $store, [$product]);

    // HPP berubah setelah sesi dibuka, misalnya karena pembelian baru.
    $product->update(['average_cost' => 8000]);

    saveOpnameDraft($doc, [$doc->lines()->first()->id => 18]);

    // Nilai selisih memakai HPP 5000 yang dibekukan di baris.
    expect((float) $doc->lines()->first()->variance_value)->toBe(-10000.0);
});
