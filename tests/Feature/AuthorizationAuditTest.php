<?php

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAuthOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'auth-test-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $warehouse = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'warehouse',
        'email_verified_at' => now(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);

    // Attach to pivot
    $store->members()->attach($admin->id, ['role' => 'admin']);
    $store->members()->attach($warehouse->id, ['role' => 'warehouse']);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $admin, $cashier, $warehouse];
}

function makeAuthOutput(Store $store, string $code): array
{
    $output = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Output '.$code,
        'price' => 5000,
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Resep '.$code,
        'code' => $code.'-'.Str::random(5),
        'product_id' => $output->id,
        'output_quantity' => '1000.0000',
        'output_unit' => BaseUnit::MILLILITER,
        'is_active' => true,
    ]);

    $batch = ProductionBatch::create([
        'store_id' => $store->id,
        'product_id' => $output->id,
        'recipe_id' => $recipe->id,
        'status' => ProductionBatch::STATUS_DRAFT,
        'multiplier' => '1.0000',
        'output_quantity_initial' => '1000.0000',
        'output_quantity_remaining' => '1000.0000',
        'total_cost' => 0,
        'unit_cost' => '0.000000',
        'recipe_name' => $recipe->name,
        'recipe_code' => $recipe->code,
        'recipe_version' => 1,
        'recipe_output_quantity' => '1000.0000',
        'recipe_output_unit' => BaseUnit::MILLILITER,
    ]);

    return [$output, $recipe, $batch];
}

// ═══════════════════════════════════════════════════════
// Middleware-level: cashier tidak bisa akses inventory
// (diblokir oleh role.inventory middleware)
// ═══════════════════════════════════════════════════════

test('cashier tidak dapat mengakses halaman transfer', function () {
    [, , $cashier] = makeAuthOrg('MW2');

    $this->actingAs($cashier);
    $response = $this->get('/stock-transfers');
    $response->assertStatus(302);
});

// ═══════════════════════════════════════════════════════
// Controller-level: warehouse bisa akses tapi tidak bisa complete/cancel
// (role check di dalam controller)
// ═══════════════════════════════════════════════════════

test('warehouse tidak dapat menyelesaikan produksi', function () {
    [$store, , , $warehouse] = makeAuthOrg('CA2');
    [, , $batch] = makeAuthOutput($store, 'CA2');

    $this->actingAs($warehouse);
    $response = $this->post("/productions/{$batch->id}/complete");
    // Warehouse akses inventory tapi di-block role check → 403 atau redirect
    expect($response->status())->not->toBe(200);
});

test('warehouse tidak dapat membatalkan produksi', function () {
    [$store, , , $warehouse] = makeAuthOrg('CA3');
    [, , $batch] = makeAuthOutput($store, 'CA3');

    $this->actingAs($warehouse);
    $response = $this->post("/productions/{$batch->id}/cancel", [
        'cancel_reason' => 'Tidak jadi diproduksi',
    ]);
    expect($response->status())->not->toBe(200);
});

test('admin dapat menyelesaikan produksi', function () {
    [$store, $admin] = makeAuthOrg('CA4');
    [, , $batch] = makeAuthOutput($store, 'CA4');

    $this->actingAs($admin);
    $response = $this->post("/productions/{$batch->id}/complete");
    // Admin bisa complete → redirect ke show (302) atau success
    $response->assertDontSee('403');
});

test('admin dapat membatalkan produksi', function () {
    [$store, $admin] = makeAuthOrg('CA5');
    [, , $batch] = makeAuthOutput($store, 'CA5');

    $this->actingAs($admin);
    $response = $this->post("/productions/{$batch->id}/cancel", [
        'cancel_reason' => 'Tidak jadi diproduksi',
    ]);
    $response->assertDontSee('403');
});

// ═══════════════════════════════════════════════════════
// Stock Transfer: warehouse bisa akses tapi tidak bisa send/receive/cancel
// ═══════════════════════════════════════════════════════

test('warehouse dapat mengakses indeks transfer', function () {
    [$store, , , $warehouse] = makeAuthOrg('TA1');

    $this->actingAs($warehouse);
    $response = $this->get('/stock-transfers');
    $response->assertStatus(200);
});

test('warehouse tidak dapat mengirim transfer', function () {
    [$store, $admin, , $warehouse] = makeAuthOrg('TA2');

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk A',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '100.0000',
        'is_active' => true,
    ]);

    $destStore = Store::query()->create([
        'name' => 'Dest Store',
        'slug' => 'dest-ta2-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $destProduct = Product::query()->create([
        'store_id' => $destStore->id,
        'name' => 'Produk A',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '0.0000',
        'is_active' => true,
    ]);

    $transfer = StockTransfer::create([
        'from_store_id' => $store->id,
        'to_store_id' => $destStore->id,
        'user_id' => $admin->id,
        'status' => StockTransfer::STATUS_DRAFT,
        'notes' => 'Test transfer',
    ]);

    $transfer->lines()->create([
        'from_product_id' => $product->id,
        'to_product_id' => $destProduct->id,
        'quantity' => '10.0000',
    ]);

    $this->actingAs($warehouse);
    $response = $this->post("/stock-transfers/{$transfer->id}/send");
    expect($response->status())->not->toBe(200);
});

test('warehouse tidak dapat menerima transfer', function () {
    [$store, $admin, , $warehouse] = makeAuthOrg('TA3');

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk C',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '100.0000',
        'is_active' => true,
    ]);

    $destStore = Store::query()->create([
        'name' => 'Dest Store',
        'slug' => 'dest-ta3-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $destProduct = Product::query()->create([
        'store_id' => $destStore->id,
        'name' => 'Produk C',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '0.0000',
        'is_active' => true,
    ]);

    $destStore->members()->attach($warehouse->id, ['role' => 'warehouse']);

    $transfer = StockTransfer::create([
        'from_store_id' => $store->id,
        'to_store_id' => $destStore->id,
        'user_id' => $admin->id,
        'status' => StockTransfer::STATUS_SENT,
        'notes' => 'Test transfer',
        'sent_at' => now(),
        'sent_by' => $admin->id,
    ]);

    $transfer->lines()->create([
        'from_product_id' => $product->id,
        'to_product_id' => $destProduct->id,
        'quantity' => '10.0000',
        'unit_cost_snapshot' => '100.0000',
    ]);

    $this->actingAs($warehouse);
    $response = $this->post("/stock-transfers/{$transfer->id}/receive");
    expect($response->status())->not->toBe(200);
});

test('admin dapat mengirim transfer', function () {
    [$store, $admin] = makeAuthOrg('TA4');

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk B',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '100.0000',
        'is_active' => true,
    ]);

    $destStore = Store::query()->create([
        'name' => 'Dest Store',
        'slug' => 'dest-ta4-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $destProduct = Product::query()->create([
        'store_id' => $destStore->id,
        'name' => 'Produk B',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '0.0000',
        'is_active' => true,
    ]);

    $transfer = StockTransfer::create([
        'from_store_id' => $store->id,
        'to_store_id' => $destStore->id,
        'user_id' => $admin->id,
        'status' => StockTransfer::STATUS_DRAFT,
        'notes' => 'Test transfer',
    ]);

    $transfer->lines()->create([
        'from_product_id' => $product->id,
        'to_product_id' => $destProduct->id,
        'quantity' => '10.0000',
    ]);

    $this->actingAs($admin);
    $response = $this->post("/stock-transfers/{$transfer->id}/send");
    $response->assertDontSee('403');
});

test('admin dapat menerima transfer', function () {
    [$store, $admin] = makeAuthOrg('TA5');

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk D',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '100.0000',
        'is_active' => true,
    ]);

    $destStore = Store::query()->create([
        'name' => 'Dest Store',
        'slug' => 'dest-ta5-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $destProduct = Product::query()->create([
        'store_id' => $destStore->id,
        'name' => 'Produk D',
        'price' => 10000,
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'stock_quantity' => '0.0000',
        'is_active' => true,
    ]);

    $destStore->members()->attach($admin->id, ['role' => 'admin']);

    $transfer = StockTransfer::create([
        'from_store_id' => $store->id,
        'to_store_id' => $destStore->id,
        'user_id' => $admin->id,
        'status' => StockTransfer::STATUS_SENT,
        'notes' => 'Test transfer',
        'sent_at' => now(),
        'sent_by' => $admin->id,
    ]);

    $transfer->lines()->create([
        'from_product_id' => $product->id,
        'to_product_id' => $destProduct->id,
        'quantity' => '10.0000',
        'unit_cost_snapshot' => '100.0000',
    ]);

    $this->actingAs($admin);
    $response = $this->post("/stock-transfers/{$transfer->id}/receive");
    $response->assertDontSee('403');
});
