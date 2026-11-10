<?php

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\AdjustmentStockOperations;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\ProductionStockOperations;
use App\Support\SaleConsumptionConfig;
use App\Support\SaleConsumptionResolver;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Koreksi stok produk hasil produksi harus ikut menggeser sisa batch produksi.
 *
 * Tanpa itu, penjualan berikutnya menimpa `stock_quantity` dengan total sisa
 * batch sehingga stok yang sudah dinolkan kasir muncul kembali.
 */
function psStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Produksi',
        'slug' => 'prodsync-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function psOutputProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Biang Teh Cair',
        'price' => 0,
        'stock_quantity' => 0,
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'unit' => 'ml',
        'track_stock' => true,
        'unlimited_stock' => false,
        'allows_decimal' => true,
        'is_active' => true,
    ], $attrs));
}

function psSellableProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'price' => 5000,
        'stock_quantity' => 0,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'unit' => 'pcs',
        'track_stock' => false,
        'is_active' => true,
    ], $attrs));
}

function psBatch(Store $store, Product $output, string $qty): ProductionBatch
{
    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Resep '.$output->name,
        'code' => 'R-'.$output->id.'-'.Str::random(6),
        'product_id' => $output->id,
        'output_quantity' => $qty === '0.0000' ? '1.0000' : $qty,
        'output_unit' => $output->base_unit,
        'is_active' => true,
    ]);

    return ProductionBatch::create([
        'store_id' => $store->id,
        'product_id' => $output->id,
        'recipe_id' => $recipe->id,
        'status' => ProductionBatch::STATUS_COMPLETED,
        'multiplier' => '1.0000',
        'output_quantity_initial' => $qty,
        'output_quantity_remaining' => $qty,
        'total_cost' => '0.00',
        'unit_cost' => '1.000000',
    ]);
}

function psAdjustmentDoc(Store $store, User $user): StockAdjustment
{
    return StockAdjustment::create([
        'store_id' => $store->id,
        'type' => StockAdjustment::TYPE_ADJUSTMENT,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'created_by' => $user->id,
    ]);
}

function psAdjustmentLine(StockAdjustment $doc, Product $product, string $variance): StockAdjustmentLine
{
    return StockAdjustmentLine::create([
        'stock_adjustment_id' => $doc->id,
        'product_id' => $product->id,
        'system_quantity' => AdjustmentStockOperations::currentQuantity($product),
        'counted_quantity' => '0.0000',
        'variance_quantity' => $variance,
        'unit_cost' => '1.000000',
        'variance_value' => '0.00',
    ]);
}

it('menolkan seluruh sisa batch saat stok dikoreksi ke nol', function () {
    [$store] = psStore();
    $output = psOutputProduct($store);

    $lama = psBatch($store, $output, '2200.0000');
    $baru = psBatch($store, $output, '12000.0000');

    ProductionStockOperations::syncBatchesToStock($output, '0');

    expect((float) $lama->fresh()->output_quantity_remaining)->toBe(0.0)
        ->and((float) $baru->fresh()->output_quantity_remaining)->toBe(0.0);
});

it('ikut membersihkan batch bersisa negatif saat stok dinolkan', function () {
    [$store] = psStore();
    $output = psOutputProduct($store);

    $minus = psBatch($store, $output, '0.0000');
    $minus->update(['output_quantity_remaining' => '-950.0000']);
    $baru = psBatch($store, $output, '3800.0000');

    ProductionStockOperations::syncBatchesToStock($output, '0');

    expect((float) $minus->fresh()->output_quantity_remaining)->toBe(0.0)
        ->and((float) $baru->fresh()->output_quantity_remaining)->toBe(0.0)
        ->and((float) ProductionStockOperations::remainingTotal($output))->toBe(0.0);
});

it('memotong sisa batch secara FIFO saat stok dikurangi sebagian', function () {
    [$store] = psStore();
    $output = psOutputProduct($store);

    $lama = psBatch($store, $output, '1000.0000');
    $baru = psBatch($store, $output, '2000.0000');

    // Total 3000 dikoreksi menjadi 2500 → 500 dipotong dari batch tertua.
    ProductionStockOperations::syncBatchesToStock($output, '2500');

    expect((float) $lama->fresh()->output_quantity_remaining)->toBe(500.0)
        ->and((float) $baru->fresh()->output_quantity_remaining)->toBe(2000.0);
});

it('menambahkan kelebihan koreksi ke batch terbaru', function () {
    [$store] = psStore();
    $output = psOutputProduct($store);

    $lama = psBatch($store, $output, '1000.0000');
    $baru = psBatch($store, $output, '500.0000');

    ProductionStockOperations::syncBatchesToStock($output, '2000');

    expect((float) $lama->fresh()->output_quantity_remaining)->toBe(1000.0)
        ->and((float) $baru->fresh()->output_quantity_remaining)->toBe(1000.0);
});

it('melewati produk yang bukan hasil produksi', function () {
    [$store] = psStore();
    $retail = psSellableProduct($store, ['track_stock' => true, 'stock_quantity' => 10]);

    expect(ProductionStockOperations::syncBatchesToStock($retail, '0'))->toBeFalse();
});

it('penyesuaian stok ikut memotong sisa batch produksi', function () {
    [$store, $user] = psStore();
    $output = psOutputProduct($store, ['stock_quantity' => 2200]);
    $batch = psBatch($store, $output, '2200.0000');

    $doc = psAdjustmentDoc($store, $user);
    $line = psAdjustmentLine($doc, $output, '-2200.0000');

    AdjustmentStockOperations::applyLine($store, $user, $output, $doc, $line);

    expect((float) $output->fresh()->stock_quantity)->toBe(0.0)
        ->and((float) $batch->fresh()->output_quantity_remaining)->toBe(0.0);
});

it('stok tidak hidup lagi pada penjualan setelah dikoreksi ke nol', function () {
    [$store, $user] = psStore();

    $output = psOutputProduct($store, ['stock_quantity' => 2200, 'allow_negative_stock' => true]);
    $sisaKemarin = psBatch($store, $output, '2200.0000');

    // Kasir menolkan stok karena fisiknya sudah habis.
    $doc = psAdjustmentDoc($store, $user);
    AdjustmentStockOperations::applyLine($store, $user, $output, $doc, psAdjustmentLine($doc, $output, '-2200.0000'));

    // Produksi hari ini.
    $hariIni = psBatch($store, $output, '12000.0000');
    $output->update(['stock_quantity' => '12000.0000']);

    $sellable = psSellableProduct($store);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 5000,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'change_amount' => 0,
        'completed_at' => now(),
    ]);
    $item = TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $sellable->id,
        'product_name' => $sellable->name,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    SaleConsumptionResolver::consume($store, $user, $sellable, null, '1.0000', $item, 'TXN-TEST-001');

    // Tanpa perbaikan, stok melompat ke 14.000 karena sisa 2.200 hidup lagi.
    expect((float) $output->fresh()->stock_quantity)->toBe(11800.0)
        ->and((float) $sisaKemarin->fresh()->output_quantity_remaining)->toBe(0.0)
        ->and((float) $hariIni->fresh()->output_quantity_remaining)->toBe(11800.0);
});
