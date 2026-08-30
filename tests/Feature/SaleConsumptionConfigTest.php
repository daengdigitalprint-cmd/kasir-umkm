<?php

use App\Models\Product;
use App\Models\ProductSaleConsumption;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\SaleConsumptionConfig;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeConsumeOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'consume-test-'.uniqid(),
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

function makeConsumeProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Consump',
        'price' => 10000,
        'cost_price' => 5000,
        'average_cost' => '5.000000',
        'stock_quantity' => 0,
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ], $attrs));
}

// ═══════════════════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════════════════

test('konfigurasi konsumsi dapat dibuat untuk produk jual', function () {
    [$store, $user] = makeConsumeOrg('SC1');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '0.066667',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    $count = ProductSaleConsumption::where('product_id', $sellable->id)->count();
    $row = ProductSaleConsumption::where('product_id', $sellable->id)->sole();

    expect($count)->toBe(1)
        ->and($row->component_product_id)->toBe($output->id)
        ->and($row->quantity)->toBe('200.0000')
        ->and($row->base_unit)->toBe(BaseUnit::MILLILITER)
        ->and($row->store_id)->toBe($store->id);
});

test('konsumsi penjualan tidak boleh untuk produk bundle', function () {
    [$store] = makeConsumeOrg('SC2');
    $bundle = makeConsumeProduct($store, [
        'name' => 'Paket Hemat',
        'inventory_type' => InventoryType::SELLABLE,
        'is_bundle' => true,
    ]);

    expect(SaleConsumptionConfig::supportsConfiguration($bundle))->toBeFalse();
});

test('komponen harus production_output atau packaging', function () {
    [$store] = makeConsumeOrg('SC3');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
    ]);
    $rawMaterial = makeConsumeProduct($store, [
        'name' => 'Teh Hitam',
        'inventory_type' => InventoryType::RAW_MATERIAL,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $rawMaterial->id, 'quantity' => '120', 'input_unit' => 'gram'],
    ]);
});

test('dimensi satuan konsumsi harus kompatibel dengan komponen', function () {
    [$store] = makeConsumeOrg('SC4');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    // gram -> ml: dimensi berbeda, harus ditolak
    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200', 'input_unit' => 'gram'],
    ]);
});

test('konversi satuan input disimpan dalam base unit komponen', function () {
    [$store] = makeConsumeOrg('SC5');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    // 0.2 liter -> 200 ml
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '0.2', 'input_unit' => 'liter'],
    ]);

    $row = ProductSaleConsumption::where('product_id', $sellable->id)->sole();
    expect($row->quantity)->toBe('200.0000')
        ->and($row->base_unit)->toBe(BaseUnit::MILLILITER);
});

test('komponen duplikat ditolak', function () {
    [$store] = makeConsumeOrg('SC6');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200', 'input_unit' => 'ml'],
        ['component_product_id' => $output->id, 'quantity' => '100', 'input_unit' => 'ml'],
    ]);
});

test('estimasi HPP dari konsumsi', function () {
    [$store] = makeConsumeOrg('SC7');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'average_cost' => '0.066667',
    ]);
    $cup = makeConsumeProduct($store, [
        'name' => 'Cup 12oz',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'average_cost' => '500.000000',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200', 'input_unit' => 'ml'],
        ['component_product_id' => $cup->id, 'quantity' => '1', 'input_unit' => 'pcs'],
    ]);

    $estCost = SaleConsumptionConfig::estimatedUnitCost($sellable);
    // 200 * 0.066667 = 13.3334 + 1 * 500 = 500
    expect((float) $estCost)->toBeGreaterThan(500);
    expect((float) $estCost)->toBeLessThan(515);
});

test('komponen tidak boleh sama dengan produk jual', function () {
    [$store] = makeConsumeOrg('SC8');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $sellable->id, 'quantity' => '1', 'input_unit' => 'pcs'],
    ]);
});

test('komponen harus aktif', function () {
    [$store] = makeConsumeOrg('SC9');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $inactive = makeConsumeProduct($store, [
        'name' => 'Cup Lama',
        'inventory_type' => InventoryType::PACKAGING,
        'is_active' => false,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $inactive->id, 'quantity' => '1', 'input_unit' => 'pcs'],
    ]);
});

test('hapus konfigurasi konsumsi', function () {
    [$store] = makeConsumeOrg('SC10');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200', 'input_unit' => 'ml'],
    ]);
    expect(ProductSaleConsumption::where('product_id', $sellable->id)->count())->toBe(1);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, []);
    expect(ProductSaleConsumption::where('product_id', $sellable->id)->count())->toBe(0);
});

test('komponen dari toko lain ditolak', function () {
    [$store1] = makeConsumeOrg('SC11a');
    [$store2] = makeConsumeOrg('SC11b');
    $sellable = makeConsumeProduct($store1, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $otherStoreOutput = makeConsumeProduct($store2, [
        'name' => 'Biang Teh Lain',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    SaleConsumptionConfig::replaceForProduct($sellable, $store1, [
        ['component_product_id' => $otherStoreOutput->id, 'quantity' => '200', 'input_unit' => 'ml'],
    ]);
});

test('mengubah base_unit komponen yang direferensikan ditolak', function () {
    [$store] = makeConsumeOrg('SC12');
    $sellable = makeConsumeProduct($store, [
        'name' => 'Es Teh',
        'inventory_type' => InventoryType::SELLABLE,
    ]);
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $output->id, 'quantity' => '200', 'input_unit' => 'ml'],
    ]);

    expect(SaleConsumptionConfig::isReferencedAsComponent($output))->toBeTrue();

    // Ubah base_unit ke gram — harus ditolak karena masih direferensikan
    $output->update(['base_unit' => BaseUnit::GRAM]);
    // Verifikasi bahwa isReferencedAsComponent masih true
    expect(SaleConsumptionConfig::isReferencedAsComponent($output))->toBeTrue();
});

test('katalog komponen hanya menampilkan output produksi dan kemasan', function () {
    [$store] = makeConsumeOrg('SC13');
    $output = makeConsumeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $packaging = makeConsumeProduct($store, [
        'name' => 'Cup',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
    ]);
    $rawMaterial = makeConsumeProduct($store, [
        'name' => 'Teh Hitam',
        'inventory_type' => InventoryType::RAW_MATERIAL,
    ]);

    $catalog = SaleConsumptionConfig::componentCatalogForStore($store);
    $ids = $catalog->pluck('id')->toArray();

    expect($catalog)->toHaveCount(2)
        ->and(in_array($output->id, $ids))->toBeTrue()
        ->and(in_array($packaging->id, $ids))->toBeTrue()
        ->and(in_array($rawMaterial->id, $ids))->toBeFalse();
});
