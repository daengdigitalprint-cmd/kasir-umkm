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
 * Test Tahap 2 — Penyesuaian Stok dasar.
 *
 * Mengikuti pola StockTransferWorkflowTest: logika bisnis dipanggil langsung
 * melalui DB transaction karena CSRF 419 bersifat pre-existing pada test POST.
 */
function makePostingStore(string $planSlug = 'bisnis'): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        [
            'name' => 'Paket '.$planSlug,
            'price' => $planSlug === 'starter' ? 0 : 99000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]
    );

    $store = Store::query()->create([
        'name' => 'Toko Posting',
        'slug' => 'posting-'.uniqid(),
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

function makePostingProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Koreksi',
        'price' => 20000,
        'cost_price' => 12000,
        'average_cost' => 12000,
        'stock_quantity' => 100,
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
 * Replika StockAdjustmentController::store.
 */
function postAdjustment(User $user, Store $store, string $reason, array $rows): StockAdjustment
{
    return DB::transaction(function () use ($user, $store, $reason, $rows) {
        $ids = collect($rows)->pluck('product_id')->sort()->values();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $document = StockAdjustment::create([
            'store_id' => $store->id,
            'type' => StockAdjustment::TYPE_ADJUSTMENT,
            'reason_code' => $reason,
            'status' => StockAdjustment::STATUS_DRAFT,
            'counted_at' => now(),
            'created_by' => $user->id,
        ]);

        $total = '0.00';

        foreach ($rows as $row) {
            $product = $products->get((int) $row['product_id']);
            AdjustmentStockOperations::assertAdjustable($store, $product);

            $systemQty = AdjustmentStockOperations::currentQuantity($product);
            $countedQty = ProductQuantity::normalizeStorageAmount($product, $row['counted_quantity']);
            $variance = AdjustmentStockOperations::variance($countedQty, $systemQty);

            $line = StockAdjustmentLine::create([
                'stock_adjustment_id' => $document->id,
                'product_id' => $product->id,
                'system_quantity' => $systemQty,
                'counted_quantity' => $countedQty,
                'variance_quantity' => $variance,
                'unit_cost' => InventoryCosting::currentAverageCost($product),
                'variance_value' => '0.00',
            ]);

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

it('mengurangi stok saat hasil hitung lebih kecil', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 100]);

    $doc = postAdjustment($user, $store, StockAdjustment::REASON_DAMAGE, [
        ['product_id' => $product->id, 'counted_quantity' => 95],
    ]);

    expect((int) $product->fresh()->stock_quantity)->toBe(95);
    expect($doc->status)->toBe(StockAdjustment::STATUS_POSTED);

    $line = $doc->lines()->first();
    expect((string) $line->variance_quantity)->toBe('-5.0000');
    expect((float) $line->variance_value)->toBe(-60000.0);
});

it('menambah stok saat hasil hitung lebih besar', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 40]);

    postAdjustment($user, $store, StockAdjustment::REASON_FOUND, [
        ['product_id' => $product->id, 'counted_quantity' => 47],
    ]);

    expect((int) $product->fresh()->stock_quantity)->toBe(47);
});

it('tidak menggeser average cost saat koreksi', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 100, 'average_cost' => 12000]);

    postAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    expect((float) $product->fresh()->average_cost)->toBe(12000.0);
});

it('mencatat mutasi stok dengan referensi ke baris dokumen', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 30]);

    $doc = postAdjustment($user, $store, StockAdjustment::REASON_DAMAGE, [
        ['product_id' => $product->id, 'counted_quantity' => 28],
    ]);

    $line = $doc->lines()->first();
    $movement = StockMovement::query()
        ->where('reference_type', StockAdjustmentLine::class)
        ->where('reference_id', $line->id)
        ->first();

    expect($movement)->not->toBeNull();
    expect($movement->type)->toBe(StockMovement::TYPE_ADJUSTMENT);
    expect((string) $movement->quantity_change)->toBe('-2.0000');
    expect((string) $movement->balance_after)->toBe('28.0000');
    expect($movement->user_id)->toBe($user->id);
    expect($movement->notes)->toContain($doc->adjustment_number);
});

it('memakai tipe mutasi opname untuk dokumen opname', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 10]);

    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_OPNAME,
        'reason_code' => StockAdjustment::REASON_OPNAME,
        'created_by' => $user->id,
    ]);

    $line = StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => '10.0000',
        'counted_quantity' => '8.0000',
        'variance_quantity' => '-2.0000',
    ]);

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    $movement = StockMovement::query()->where('reference_id', $line->id)->first();
    expect($movement->type)->toBe(StockMovement::TYPE_OPNAME);
});

it('menolak koreksi yang membuat stok negatif', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 5]);

    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'reason_code' => StockAdjustment::REASON_LOST,
        'created_by' => $user->id,
    ]);

    $line = StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => '5.0000',
        'counted_quantity' => '0.0000',
        'variance_quantity' => '-8.0000',
    ]);

    expect(fn () => AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line))
        ->toThrow(RuntimeException::class);

    expect((int) $product->fresh()->stock_quantity)->toBe(5);
});

it('menghitung selisih terhadap snapshot walau ada penjualan di sela proses', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 50]);

    // Petugas menghitung fisik: sistem 50, hasil hitung 48.
    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_OPNAME,
        'reason_code' => StockAdjustment::REASON_OPNAME,
        'created_by' => $user->id,
    ]);

    $systemSnapshot = AdjustmentStockOperations::currentQuantity($product);
    $line = StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => $systemSnapshot,
        'counted_quantity' => '48.0000',
        'variance_quantity' => AdjustmentStockOperations::variance('48.0000', $systemSnapshot),
    ]);

    // Toko tetap berjualan 3 unit sebelum dokumen diposting.
    $product->update(['stock_quantity' => 47]);
    $product->refresh();

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    // Selisih -2 diterapkan pada stok berjalan 47, bukan menimpa menjadi 48.
    expect((int) $product->fresh()->stock_quantity)->toBe(45);
});

it('mengembalikan stok saat dokumen dibatalkan', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 100]);

    $doc = postAdjustment($user, $store, StockAdjustment::REASON_DAMAGE, [
        ['product_id' => $product->id, 'counted_quantity' => 90],
    ]);

    expect((int) $product->fresh()->stock_quantity)->toBe(90);

    $line = $doc->lines()->first();
    $product->refresh();
    AdjustmentStockOperations::reverseLine($store, $user, $product, $doc, $line);

    expect((int) $product->fresh()->stock_quantity)->toBe(100);

    $reversal = StockMovement::query()
        ->where('type', StockMovement::TYPE_ADJUSTMENT_REVERSAL)
        ->where('reference_id', $line->id)
        ->first();

    expect($reversal)->not->toBeNull();
    expect((string) $reversal->quantity_change)->toBe('10.0000');
});

it('menolak produk paket, varian, tanpa track stok, dan stok tak terbatas', function () {
    [$store] = makePostingStore();

    $bundle = makePostingProduct($store, ['name' => 'Paket Hemat', 'is_bundle' => true]);
    $variant = makePostingProduct($store, ['name' => 'Kaos', 'has_variants' => true]);
    $untracked = makePostingProduct($store, ['name' => 'Jasa', 'track_stock' => false]);
    $unlimited = makePostingProduct($store, ['name' => 'Es Batu', 'unlimited_stock' => true]);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $bundle))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $variant))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $untracked))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $unlimited))
        ->toThrow(InvalidArgumentException::class);
});

it('menolak produk milik toko lain', function () {
    [$storeA] = makePostingStore();
    [$storeB] = makePostingStore();

    $product = makePostingProduct($storeB);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($storeA, $product))
        ->toThrow(InvalidArgumentException::class);
});

it('menjumlahkan nilai selisih beberapa baris ke total dokumen', function () {
    [$store, $user] = makePostingStore();
    $a = makePostingProduct($store, ['name' => 'Produk A', 'stock_quantity' => 100, 'average_cost' => 10000]);
    $b = makePostingProduct($store, ['name' => 'Produk B', 'stock_quantity' => 50, 'average_cost' => 5000]);

    $doc = postAdjustment($user, $store, StockAdjustment::REASON_CORRECTION, [
        ['product_id' => $a->id, 'counted_quantity' => 98],   // -2 x 10000 = -20000
        ['product_id' => $b->id, 'counted_quantity' => 53],   // +3 x 5000  = +15000
    ]);

    expect((float) $doc->total_variance_value)->toBe(-5000.0);
    expect((int) $a->fresh()->stock_quantity)->toBe(98);
    expect((int) $b->fresh()->stock_quantity)->toBe(53);
});

it('melewati baris tanpa selisih tanpa membuat mutasi', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, ['stock_quantity' => 25]);

    postAdjustment($user, $store, StockAdjustment::REASON_CORRECTION, [
        ['product_id' => $product->id, 'counted_quantity' => 25],
    ]);

    expect((int) $product->fresh()->stock_quantity)->toBe(25);
    expect(StockMovement::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('mendukung kuantitas desimal untuk produk timbangan', function () {
    [$store, $user] = makePostingStore();
    $product = makePostingProduct($store, [
        'name' => 'Beras Curah',
        'unit' => 'kg',
        'allows_decimal' => true,
        'stock_quantity' => '10.5000',
        'average_cost' => 14000,
    ]);

    postAdjustment($user, $store, StockAdjustment::REASON_LOST, [
        ['product_id' => $product->id, 'counted_quantity' => '10.2500'],
    ]);

    expect((string) $product->fresh()->stock_quantity)->toBe('10.2500');
});
