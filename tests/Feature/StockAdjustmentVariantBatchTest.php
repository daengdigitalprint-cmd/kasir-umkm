<?php

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AdjustmentStockOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test Tahap 5 — Koreksi stok untuk produk bervarian dan produk bermode batch.
 */
function makeVariantBatchStore(bool $batchTracking = false): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'bisnis'],
        ['name' => 'Paket GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true]
    );

    $store = Store::query()->create([
        'name' => 'Toko Varian Batch',
        'slug' => 'varbatch-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
        'settings' => $batchTracking ? ['batch_tracking_enabled' => true] : null,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function makeVbProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk VB',
        'price' => 25000,
        'cost_price' => 15000,
        'average_cost' => 15000,
        'stock_quantity' => 0,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_bundle' => false,
        'has_variants' => false,
        'allows_decimal' => false,
        'is_active' => true,
    ], $attrs));
}

function makeVbLine(StockAdjustment $doc, Product $product, string $variance, ?ProductVariant $variant = null): StockAdjustmentLine
{
    return StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'system_quantity' => AdjustmentStockOperations::currentQuantity($product, $variant),
        'counted_quantity' => '0.0000',
        'variance_quantity' => $variance,
        'unit_cost' => '15000.000000',
        'variance_value' => '0.00',
    ]);
}

function makeVbDoc(Store $store, User $user): StockAdjustment
{
    return StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_ADJUSTMENT,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'created_by' => $user->id,
    ]);
}

// ── Varian ──

it('mengoreksi stok pada varian yang dipilih', function () {
    [$store, $user] = makeVariantBatchStore();
    $product = makeVbProduct($store, ['name' => 'Kaos', 'has_variants' => true, 'stock_quantity' => 30]);

    $merah = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'name' => 'Merah', 'stock_quantity' => 20, 'is_active' => true,
    ]);
    ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'name' => 'Biru', 'stock_quantity' => 10, 'is_active' => true,
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-4.0000', $merah);

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line, $merah);

    expect((int) $merah->fresh()->stock_quantity)->toBe(16);
    // Induk disinkronkan dari penjumlahan varian: 16 + 10.
    expect((int) $product->fresh()->stock_quantity)->toBe(26);
});

it('mencatat product_variant_id pada mutasi varian', function () {
    [$store, $user] = makeVariantBatchStore();
    $product = makeVbProduct($store, ['has_variants' => true, 'stock_quantity' => 12]);
    $variant = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'name' => 'L', 'stock_quantity' => 12, 'is_active' => true,
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-2.0000', $variant);

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line, $variant);

    $movement = StockMovement::query()->where('reference_id', $line->id)->first();

    expect($movement->product_variant_id)->toBe($variant->id);
    expect((string) $movement->balance_after)->toBe('10.0000');
});

it('menolak koreksi varian tanpa memilih varian', function () {
    [$store] = makeVariantBatchStore();
    $product = makeVbProduct($store, ['has_variants' => true, 'stock_quantity' => 5]);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $product, null))
        ->toThrow(InvalidArgumentException::class);
});

it('menolak varian milik produk lain', function () {
    [$store] = makeVariantBatchStore();
    $a = makeVbProduct($store, ['name' => 'Produk A', 'has_variants' => true]);
    $b = makeVbProduct($store, ['name' => 'Produk B', 'has_variants' => true]);

    $variantB = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $b->id,
        'name' => 'XL', 'stock_quantity' => 3, 'is_active' => true,
    ]);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $a, $variantB))
        ->toThrow(InvalidArgumentException::class);
});

it('menolak varian pada produk tanpa varian', function () {
    [$store] = makeVariantBatchStore();
    $polos = makeVbProduct($store, ['stock_quantity' => 8]);
    $lain = makeVbProduct($store, ['name' => 'Lain', 'has_variants' => true]);
    $variant = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $lain->id,
        'name' => 'S', 'stock_quantity' => 2, 'is_active' => true,
    ]);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $polos, $variant))
        ->toThrow(InvalidArgumentException::class);
});

it('menolak koreksi varian yang membuat stok negatif', function () {
    [$store, $user] = makeVariantBatchStore();
    $product = makeVbProduct($store, ['has_variants' => true, 'stock_quantity' => 3]);
    $variant = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'name' => 'M', 'stock_quantity' => 3, 'is_active' => true,
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-5.0000', $variant);

    expect(fn () => AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line, $variant))
        ->toThrow(RuntimeException::class);

    expect((int) $variant->fresh()->stock_quantity)->toBe(3);
});

it('mengembalikan stok varian saat dibatalkan', function () {
    [$store, $user] = makeVariantBatchStore();
    $product = makeVbProduct($store, ['has_variants' => true, 'stock_quantity' => 40]);
    $variant = ProductVariant::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'name' => 'Reguler', 'stock_quantity' => 40, 'is_active' => true,
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-6.0000', $variant);

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line, $variant);
    expect((int) $variant->fresh()->stock_quantity)->toBe(34);

    $variant->refresh();
    $product->refresh();
    AdjustmentStockOperations::reverseLine($store, $user, $product, $doc, $line, $variant);

    expect((int) $variant->fresh()->stock_quantity)->toBe(40);
    expect((int) $product->fresh()->stock_quantity)->toBe(40);
});

// ── Batch FIFO ──

it('memotong batch secara fifo berdasarkan tanggal kedaluwarsa', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['name' => 'Obat A', 'stock_quantity' => 30]);

    $lama = ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 10, 'expiry_date' => now()->addDays(10)->toDateString(),
        'batch_code' => 'B-LAMA',
    ]);
    $baru = ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 20, 'expiry_date' => now()->addDays(90)->toDateString(),
        'batch_code' => 'B-BARU',
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-12.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    // ED terdekat habis lebih dulu, sisanya diambil dari batch berikutnya.
    expect((int) $lama->fresh()->quantity_remaining)->toBe(0);
    expect((int) $baru->fresh()->quantity_remaining)->toBe(18);
    expect((int) $product->fresh()->stock_quantity)->toBe(18);
});

it('membuat batch baru saat koreksi menambah stok', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['stock_quantity' => 5]);

    ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 5, 'expiry_date' => null, 'batch_code' => 'AWAL',
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '3.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    $created = ProductBatch::where('product_id', $product->id)
        ->where('batch_code', 'like', 'ADJ %')
        ->first();

    expect($created)->not->toBeNull();
    expect((int) $created->quantity_remaining)->toBe(3);
    expect((int) $product->fresh()->stock_quantity)->toBe(8);
});

it('menjaga stok produk tetap sama dengan jumlah batch', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['stock_quantity' => 50]);

    ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 30, 'expiry_date' => now()->addDays(20)->toDateString(), 'batch_code' => 'A',
    ]);
    ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 20, 'expiry_date' => now()->addDays(60)->toDateString(), 'batch_code' => 'B',
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-35.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    $batchSum = (int) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');

    expect($batchSum)->toBe(15);
    expect((int) $product->fresh()->stock_quantity)->toBe(15);
});

it('menolak koreksi batch melebihi stok tersedia', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['stock_quantity' => 6]);

    ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 6, 'expiry_date' => null, 'batch_code' => 'SATU',
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-10.0000');

    expect(fn () => AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line))
        ->toThrow(RuntimeException::class);
});

it('membentuk batch awal saat produk berstok tapi belum punya batch', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['stock_quantity' => 25]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-5.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    expect((int) $product->fresh()->stock_quantity)->toBe(20);
    expect((int) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining'))->toBe(20);
});

it('tetap memakai jalur biasa saat batch tracking mati', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: false);
    $product = makeVbProduct($store, ['stock_quantity' => 18]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-3.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);

    expect((int) $product->fresh()->stock_quantity)->toBe(15);
    expect(ProductBatch::where('product_id', $product->id)->count())->toBe(0);
});

it('mengembalikan stok batch saat dibatalkan', function () {
    [$store, $user] = makeVariantBatchStore(batchTracking: true);
    $product = makeVbProduct($store, ['stock_quantity' => 40]);

    ProductBatch::create([
        'store_id' => $store->id, 'product_id' => $product->id,
        'quantity_remaining' => 40, 'expiry_date' => null, 'batch_code' => 'AWAL',
    ]);

    $doc = makeVbDoc($store, $user);
    $line = makeVbLine($doc, $product, '-8.0000');

    AdjustmentStockOperations::applyLine($store, $user, $product, $doc, $line);
    expect((int) $product->fresh()->stock_quantity)->toBe(32);

    $product->refresh();
    AdjustmentStockOperations::reverseLine($store, $user, $product, $doc, $line);

    expect((int) $product->fresh()->stock_quantity)->toBe(40);
    expect((int) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining'))->toBe(40);
});

it('tetap menolak produk paket meski varian dan batch sudah didukung', function () {
    [$store] = makeVariantBatchStore(batchTracking: true);
    $bundle = makeVbProduct($store, ['name' => 'Paket Hemat', 'is_bundle' => true]);

    expect(fn () => AdjustmentStockOperations::assertAdjustable($store, $bundle))
        ->toThrow(InvalidArgumentException::class);
});
