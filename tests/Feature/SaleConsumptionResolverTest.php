<?php

use App\Models\CashShift;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\SaleConsumptionAllocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\SaleConsumptionConfig;
use App\Support\SaleConsumptionResolver;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeResOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'resolver-test-'.uniqid(),
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

function makeResProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Resolver',
        'price' => 15000,
        'cost_price' => 5000,
        'average_cost' => '5.000000',
        'stock_quantity' => 0,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'unit' => 'pcs',
        'track_stock' => false,
        'is_active' => true,
    ], $attrs));
}

function createCompletedBatch(Store $store, Product $output, string $qty, string $unitCost): ProductionBatch
{
    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Resep '.$output->name,
        'code' => 'R-'.$output->id.'-'.Str::random(5),
        'product_id' => $output->id,
        'output_quantity' => $qty,
        'output_unit' => $output->base_unit ?: BaseUnit::MILLILITER,
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
        'total_cost' => bcmul($qty, $unitCost, 2),
        'unit_cost' => $unitCost,
        'recipe_name' => $recipe->name,
        'recipe_code' => $recipe->code,
        'recipe_version' => $recipe->version,
        'recipe_output_quantity' => $qty,
        'recipe_output_unit' => $output->base_unit ?: BaseUnit::MILLILITER,
    ]);
}

function createTransaction(Store $store, User $user, float $amount = 15000): Transaction
{
    $shift = CashShift::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'opening_balance' => 0,
        'status' => 'open',
    ]);

    return Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'cash_shift_id' => $shift->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => $amount,
        'total_amount' => $amount,
        'amount_paid' => $amount,
        'change_amount' => 0,
        'completed_at' => now(),
    ]);
}

// ═══════════════════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════════════════

test('loadRows mengembalikan baris konsumsi untuk produk', function () {
    [$store, $user] = makeResOrg('LR1');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    $rows = SaleConsumptionResolver::loadRows($store, $sellable, null);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->component_product_id)->toBe($output->id);
});

test('hasConfig mengembalikan true jika ada konfigurasi', function () {
    [$store] = makeResOrg('HC1');
    $sellable = makeResProduct($store, ['name' => 'Kopi']);
    $output = makeResProduct($store, [
        'name' => 'Bubuk Kopi',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::GRAM,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '15.0000', 'input_unit' => 'gram'],
    ]);

    expect(SaleConsumptionResolver::hasConfig($store, $sellable, null))->toBeTrue();
});

test('hasConfig mengembalikan false jika tidak ada konfigurasi', function () {
    [$store] = makeResOrg('HC2');
    $product = makeResProduct($store, ['name' => 'Produk Biasa']);

    expect(SaleConsumptionResolver::hasConfig($store, $product, null))->toBeFalse();
});

test('validateStock melewati jika tidak ada konfigurasi', function () {
    [$store] = makeResOrg('VS1');
    $product = makeResProduct($store);

    // Tidak harus throw
    SaleConsumptionResolver::validateStock($store, $product, null, '1.0000');
    expect(true)->toBeTrue();
});

test('validateStock lolos jika stok production_output mencukupi', function () {
    [$store] = makeResOrg('VS2');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    createCompletedBatch($store, $output, '1000.0000', '0.066667');

    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
    expect(true)->toBeTrue();
});

test('validateStock throw jika stok production_output tidak cukup', function () {
    [$store] = makeResOrg('VS3');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    // Batch dengan 100ml, butuh 200ml → gagal
    createCompletedBatch($store, $output, '100.0000', '0.066667');

    $this->expectException(\RuntimeException::class);
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
});

test('validateStock throw jika stok packaging tidak cukup', function () {
    [$store] = makeResOrg('VS4');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $packaging = makeResProduct($store, [
        'name' => 'Cup Plastik',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '3.0000',
        'track_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $packaging->id, 'quantity' => '5.0000', 'input_unit' => 'pcs'],
    ]);

    $this->expectException(\RuntimeException::class);
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
});

test('consume mengurangi output_quantity_remaining batch secara FIFO', function () {
    [$store, $user] = makeResOrg('CO1');
    $sellable = makeResProduct($store, ['name' => 'Es Teh', 'price' => 15000]);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '0.066667',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    $batch1 = createCompletedBatch($store, $output, '500.0000', '0.066667');
    $batch2 = createCompletedBatch($store, $output, '500.0000', '0.083333');

    $txn = createTransaction($store, $user);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Es Teh',
        'quantity' => '2.0000',
        'unit_price' => '15000.00',
        'total_price' => '30000.00',
    ]);

    $hpp = SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '2.0000', $item, $txn->transaction_number,
    );

    expect($hpp)->not->toBeNull()
        ->and($hpp['unit_cost'])->toBeNumeric()
        ->and((float) $hpp['total_cost'])->toBeGreaterThan(0);

    // FIFO: butuh 400ml (2×200), batch1 (500) cukup semua → batch2 tetap 500
    $batch1->refresh();
    $batch2->refresh();
    expect((float) $batch1->output_quantity_remaining)->toBe(100.0);
    expect((float) $batch2->output_quantity_remaining)->toBe(500.0);

    // 1 alokasi dari batch1 saja
    $allocs = SaleConsumptionAllocation::where('transaction_item_id', $item->id)->get();
    expect($allocs)->toHaveCount(1)
        ->and($allocs->first()->production_batch_id)->toBe($batch1->id);
});

test('consume menyimpan snapshot HPP ke transaction_item', function () {
    [$store, $user] = makeResOrg('CO2');
    $sellable = makeResProduct($store, ['name' => 'Kopi Susu', 'price' => 20000]);
    $output = makeResProduct($store, [
        'name' => 'Kopi Hitam',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '0.050000',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '150.0000', 'input_unit' => 'ml'],
    ]);

    createCompletedBatch($store, $output, '1000.0000', '0.050000');

    $txn = createTransaction($store, $user, 20000);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Kopi Susu',
        'quantity' => '1.0000',
        'unit_price' => '20000.00',
        'total_price' => '20000.00',
    ]);

    $hpp = SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '1.0000', $item, $txn->transaction_number,
    );

    $item->update([
        'unit_cost_snapshot' => $hpp['unit_cost'],
        'total_cost_snapshot' => $hpp['total_cost'],
        'gross_profit_snapshot' => $hpp['gross_profit'],
    ]);

    $item->refresh();
    expect($item->unit_cost_snapshot)->not->toBeNull()
        ->and($item->total_cost_snapshot)->not->toBeNull()
        ->and($item->gross_profit_snapshot)->not->toBeNull()
        ->and((float) $item->gross_profit_snapshot)->toBeGreaterThan(0);
});

test('consume melewati jika tidak ada konfigurasi', function () {
    [$store, $user] = makeResOrg('CO3');
    $product = makeResProduct($store, ['name' => 'Produk Biasa']);
    $txn = createTransaction($store, $user);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $product->id,
        'product_name' => 'Produk Biasa',
        'quantity' => '1.0000',
        'unit_price' => '15000.00',
        'total_price' => '15000.00',
    ]);

    $result = SaleConsumptionResolver::consume(
        $store, $user, $product, null, '1.0000', $item, $txn->transaction_number,
    );

    expect($result)->toBeNull();
    expect(SaleConsumptionAllocation::where('transaction_item_id', $item->id)->count())->toBe(0);
});

test('consume mencatat stock_movement tipe sale untuk production_output', function () {
    [$store, $user] = makeResOrg('CO4');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '0.066667',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    createCompletedBatch($store, $output, '1000.0000', '0.066667');

    $txn = createTransaction($store, $user);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Es Teh',
        'quantity' => '1.0000',
        'unit_price' => '15000.00',
        'total_price' => '15000.00',
    ]);

    SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '1.0000', $item, $txn->transaction_number,
    );

    $movement = StockMovement::where('product_id', $output->id)
        ->where('type', 'sale')
        ->where('reference_id', $txn->id)
        ->sole();

    expect($movement->quantity_change)->toBe('-200.0000')
        ->and($movement->store_id)->toBe($store->id)
        ->and($movement->user_id)->toBe($user->id);
});

test('consume sinkronkan stok produk dari total batch', function () {
    [$store, $user] = makeResOrg('CO5');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '1000.0000',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    createCompletedBatch($store, $output, '1000.0000', '0.066667');

    $txn = createTransaction($store, $user);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Es Teh',
        'quantity' => '2.0000',
        'unit_price' => '15000.00',
        'total_price' => '30000.00',
    ]);

    SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '2.0000', $item, $txn->transaction_number,
    );

    $output->refresh();
    expect((float) $output->stock_quantity)->toBe(600.0);
});

test('consume melewati produk tanpa track_stock untuk packaging', function () {
    [$store, $user] = makeResOrg('CO6');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $packaging = makeResProduct($store, [
        'name' => 'Sedotan',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '100.0000',
        'track_stock' => false,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $packaging->id, 'quantity' => '1.0000', 'input_unit' => 'pcs'],
    ]);

    $txn = createTransaction($store, $user);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Es Teh',
        'quantity' => '1.0000',
        'unit_price' => '15000.00',
        'total_price' => '15000.00',
    ]);

    $hpp = SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '1.0000', $item, $txn->transaction_number,
    );

    expect($hpp)->not->toBeNull()
        ->and((float) $hpp['total_cost'])->toBe(5.0);

    // Stok packaging tidak berubah karena track_stock=false
    $packaging->refresh();
    expect((float) $packaging->stock_quantity)->toBe(100.0);
});

test('penjualan ukuran 250 ml mengurangi batch tepat 250 ml', function () {
    [$store, $user] = makeResOrg('CO7');
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '1.388889',
    ]);
    $sellable = makeResProduct($store, [
        'name' => 'Es Teh Besar',
        'price' => 10000,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '250.0000', 'input_unit' => 'ml'],
    ]);

    $batch = createCompletedBatch($store, $output, '1000.0000', '1.388889');

    $txn = createTransaction($store, $user, 10000);
    $item = TransactionItem::create([
        'transaction_id' => $txn->id,
        'product_id' => $sellable->id,
        'product_name' => 'Es Teh Besar',
        'quantity' => '1.0000',
        'unit_price' => '10000.00',
        'total_price' => '10000.00',
    ]);

    $hpp = SaleConsumptionResolver::consume(
        $store, $user, $sellable, null, '1.0000', $item, $txn->transaction_number,
    );

    expect($hpp)->not->toBeNull()
        ->and((float) $hpp['unit_cost'])->toBe(347.22);

    $batch->refresh();
    expect((float) $batch->output_quantity_remaining)->toBe(750.0);
});

test('validateStock lolos untuk production_output dengan allow_negative_stock', function () {
    [$store] = makeResOrg('VAN1');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $output = makeResProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'allow_negative_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    // Batch dengan 100ml, butuh 200ml → normalnya gagal, tapi allow_negative_stock=true → lolos
    createCompletedBatch($store, $output, '100.0000', '0.066667');

    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
    expect(true)->toBeTrue();
});

test('validateStock lolos untuk packaging dengan allow_negative_stock', function () {
    [$store] = makeResOrg('VAN2');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $packaging = makeResProduct($store, [
        'name' => 'Cup Plastik',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '3.0000',
        'track_stock' => true,
        'allow_negative_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $packaging->id, 'quantity' => '5.0000', 'input_unit' => 'pcs'],
    ]);

    // Stok 3, butuh 5 → normalnya gagal, tapi allow_negative_stock=true → lolos
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
    expect(true)->toBeTrue();
});

test('validateStock tetap gagal untuk packaging tanpa allow_negative_stock', function () {
    [$store] = makeResOrg('VAN3');
    $sellable = makeResProduct($store, ['name' => 'Es Teh']);
    $packaging = makeResProduct($store, [
        'name' => 'Cup Plastik',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '3.0000',
        'track_stock' => true,
        'allow_negative_stock' => false,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $packaging->id, 'quantity' => '5.0000', 'input_unit' => 'pcs'],
    ]);

    $this->expectException(\RuntimeException::class);
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
});

test('beberapa ukuran berbeda mengonsumsi batch yang sama', function () {
    [$store, $user] = makeResOrg('CO8');
    $output = makeResProduct($store, [
        'name' => 'Biang Kopi',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '2.000000',
    ]);

    $small = makeResProduct($store, ['name' => 'Kopi Small', 'price' => 12000]);
    $large = makeResProduct($store, ['name' => 'Kopi Large', 'price' => 18000]);

    SaleConsumptionConfig::replaceForProduct($small, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);
    SaleConsumptionConfig::replaceForProduct($large, $store, [
        ['component_product_id' => $output->id, 'quantity' => '350.0000', 'input_unit' => 'ml'],
    ]);

    $batch = createCompletedBatch($store, $output, '2000.0000', '2.000000');

    // Penjualan 1: Kopi Small (200 ml)
    $txn1 = createTransaction($store, $user, 12000);
    $item1 = TransactionItem::create([
        'transaction_id' => $txn1->id,
        'product_id' => $small->id,
        'product_name' => 'Kopi Small',
        'quantity' => '1.0000',
        'unit_price' => '12000.00',
        'total_price' => '12000.00',
    ]);

    $hpp1 = SaleConsumptionResolver::consume(
        $store, $user, $small, null, '1.0000', $item1, $txn1->transaction_number,
    );

    expect($hpp1)->not->toBeNull();
    $batch->refresh();
    expect((float) $batch->output_quantity_remaining)->toBe(1800.0);

    // Penjualan 2: Kopi Large (350 ml) — dari batch yang sama
    $txn2 = createTransaction($store, $user, 18000);
    $item2 = TransactionItem::create([
        'transaction_id' => $txn2->id,
        'product_id' => $large->id,
        'product_name' => 'Kopi Large',
        'quantity' => '1.0000',
        'unit_price' => '18000.00',
        'total_price' => '18000.00',
    ]);

    $hpp2 = SaleConsumptionResolver::consume(
        $store, $user, $large, null, '1.0000', $item2, $txn2->transaction_number,
    );

    expect($hpp2)->not->toBeNull();
    $batch->refresh();
    expect((float) $batch->output_quantity_remaining)->toBe(1450.0);

    // Total konsumsi: 200 + 350 = 550 ml dari batch 2000 ml
    $totalAllocated = SaleConsumptionAllocation::whereIn('transaction_item_id', [$item1->id, $item2->id])
        ->sum('quantity_consumed');
    expect((float) $totalAllocated)->toBe(550.0);
});
