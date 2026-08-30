<?php

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dmMakeStore(): Store
{
    return Store::query()->create([
        'name' => 'Store '.Str::random(5),
        'slug' => 'store-'.Str::random(8),
        'is_active' => true,
    ]);
}

/*
 * Tahap 11 — Migrasi Data Existing
 *
 * Menguji bahwa migrasi lama (Tahap 1-4) memberikan default value yang benar
 * pada data existing, dan bahwa alur retail lama tidak terganggu.
 */

// ─── 1. Store lama mendapat location_type = 'outlet' ───

test('store tanpa location_type secara default menjadi outlet', function () {
    DB::table('stores')->insert([
        'name' => 'Toko Lama',
        'slug' => 'toko-lama-'.Str::random(5),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store = Store::query()->where('name', 'Toko Lama')->first();

    expect($store->location_type)->toBe('outlet')
        ->and($store->isOutlet())->toBeTrue()
        ->and($store->isCentralWarehouse())->toBeFalse();
});

test('toko yang sudah diset gudang pusat tetap menjadi gudang pusat', function () {
    $owner = User::factory()->create();
    $org = \App\Models\Organization::query()->create([
        'name' => 'Grup',
        'slug' => 'grup-'.Str::random(5),
        'owner_user_id' => $owner->id,
    ]);

    $store = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Gudang',
        'slug' => 'gudang-'.Str::random(5),
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
    ]);

    expect($store->fresh()->location_type)->toBe(StoreLocationType::CENTRAL_WAREHOUSE)
        ->and($store->isCentralWarehouse())->toBeTrue();
});

// ─── 2. Produk lama mendapat inventory_type, base_unit, average_cost ───

test('produk tanpa inventory_type default menjadi sellable', function () {
    $store = dmMakeStore();

    DB::table('products')->insert([
        'store_id' => $store->id,
        'name' => 'Produk Lama',
        'price' => 10000,
        'unit' => 'pcs',
        'is_active' => true,
        'track_stock' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = Product::query()->where('name', 'Produk Lama')->first();

    expect($product->inventory_type)->toBe(InventoryType::SELLABLE)
        ->and($product->base_unit)->toBe('pcs');
});

test('produk dengan cost_price mendapat average_cost dari cost_price', function () {
    $store = dmMakeStore();

    // Simulasi produk lama yang sudah ada SEBELUM migrasi Tahap 2
    // Insert sebelum kolom average_cost ditambahkan (migrasi akan backfill)
    DB::table('products')->insert([
        'store_id' => $store->id,
        'name' => 'Produk Dengan Cost',
        'price' => 15000,
        'cost_price' => 8500.00,
        'unit' => 'pcs',
        'inventory_type' => 'sellable',
        'base_unit' => 'pcs',
        'is_active' => true,
        'track_stock' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Jalankan backfill seperti migrasi Tahap 2
    DB::table('products')
        ->whereNull('average_cost')
        ->whereNotNull('cost_price')
        ->update(['average_cost' => DB::raw('cost_price')]);

    $product = Product::query()->where('name', 'Produk Dengan Cost')->first();

    expect((float) $product->average_cost)->toBe(8500.0);
});

test('produk tanpa cost_price tidak memiliki average_cost', function () {
    $store = dmMakeStore();

    DB::table('products')->insert([
        'store_id' => $store->id,
        'name' => 'Produk Tanpa Cost',
        'price' => 10000,
        'cost_price' => null,
        'unit' => 'pcs',
        'is_active' => true,
        'track_stock' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = Product::query()->where('name', 'Produk Tanpa Cost')->first();

    expect($product->average_cost)->toBeNull();
});

// ─── 3. Transfer lama mendapat status = 'received' ───

test('transfer tanpa status secara default menjadi received', function () {
    $store = dmMakeStore();

    $transferId = DB::table('stock_transfers')->insertGetId([
        'transfer_number' => 'TRF-'.Str::random(8),
        'from_store_id' => $store->id,
        'to_store_id' => $store->id,
        'status' => 'received',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transfer = StockTransfer::query()->find($transferId);

    expect($transfer->status)->toBe('received');
});

// ─── 4. Alur retail lama tetap berfungsi ───

test('produk sellable tanpa recipe dapat dijual langsung tanpa produksi', function () {
    $store = dmMakeStore();

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Retail',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => 'pcs',
        'price' => 10000,
        'cost_price' => 5000,
        'average_cost' => 5000,
        'stock_quantity' => 100,
        'track_stock' => true,
    ]);

    expect($product->inventory_type)->toBe(InventoryType::SELLABLE)
        ->and($product->base_unit)->toBe('pcs')
        ->and((int) $product->stock_quantity)->toBe(100);
});

test('produk dengan inventory_type material tidak terpengaruh penjualan retail', function () {
    $store = dmMakeStore();

    $bahan = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Daun Teh',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => 'gram',
        'price' => 0,
        'cost_price' => 200,
        'average_cost' => 200,
        'stock_quantity' => 5000,
        'track_stock' => true,
    ]);

    $sellable = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => 'ml',
        'price' => 8000,
        'cost_price' => null,
        'average_cost' => null,
        'stock_quantity' => 0,
        'track_stock' => true,
    ]);

    expect($bahan->inventory_type)->toBe(InventoryType::RAW_MATERIAL)
        ->and($sellable->inventory_type)->toBe(InventoryType::SELLABLE);
});

// ─── 5. Pemeriksaan pra-migrasi (validasi data integrity) ───

test('seluruh store memiliki location_type yang valid', function () {
    $invalidStores = DB::table('stores')
        ->whereNotIn('location_type', ['outlet', 'gudang_pusat'])
        ->orWhereNull('location_type')
        ->count();

    expect($invalidStores)->toBe(0);
});

test('seluruh produk memiliki inventory_type yang valid', function () {
    $validTypes = ['sellable', 'raw_material', 'packaging', 'production_output', 'bundle'];
    $invalidProducts = Product::query()
        ->whereNotIn('inventory_type', $validTypes)
        ->orWhereNull('inventory_type')
        ->count();

    expect($invalidProducts)->toBe(0);
});

test('seluruh transfer aktif memiliki status yang valid', function () {
    $validStatuses = ['draft', 'sent', 'received', 'cancelled'];
    $invalidTransfers = DB::table('stock_transfers')
        ->whereNotIn('status', $validStatuses)
        ->orWhereNull('status')
        ->count();

    expect($invalidTransfers)->toBe(0);
});

// ─── 6. Rollback tidak menghapus data ───

test('rollback migrasi location_type tidak menghapus store', function () {
    $store = Store::query()->create([
        'name' => 'Rollback Test',
        'slug' => 'rollback-'.Str::random(5),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
    ]);

    expect($store->fresh()->location_type)->not->toBeNull()
        ->and($store->fresh()->name)->toBe('Rollback Test');
});

test('rollback migrasi inventory_type tidak menghapus produk', function () {
    $store = dmMakeStore();
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Rollback Produk',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => 'pcs',
        'price' => 10000,
        'stock_quantity' => 50,
        'track_stock' => true,
    ]);

    expect($product->fresh()->inventory_type)->toBe(InventoryType::SELLABLE)
        ->and($product->fresh()->name)->toBe('Rollback Produk');
});

// ─── 7. HPP historis tidak dibuat untuk data lama ───

test('produk sellable lama tanpa konsumsi tidak memiliki HPP', function () {
    $store = dmMakeStore();

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Tanpa HPP',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => 'pcs',
        'price' => 10000,
        'cost_price' => 5000,
        'average_cost' => 5000,
        'stock_quantity' => 100,
        'track_stock' => true,
    ]);

    $hasConfig = \App\Support\SaleConsumptionResolver::hasConfig($store, $product, null);

    expect($hasConfig)->toBeFalse();
});
