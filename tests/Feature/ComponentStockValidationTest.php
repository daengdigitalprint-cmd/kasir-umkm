<?php

use App\Exceptions\ComponentStockInsufficientException;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\SaleConsumptionConfig;
use App\Support\SaleConsumptionResolver;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function csMakeOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'cs-test-'.uniqid(),
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

function csMakeProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk CS',
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

function csCreateBatch(Store $store, Product $output, string $qty, string $unitCost): ProductionBatch
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

// ═══════════════════════════════════════════════════════
// Skenario 1: Salah satu komponen habis stok (0)
// ═══════════════════════════════════════════════════════

test('validateStock melempar ComponentStockInsufficientException ketika salah satu komponen habis stok', function () {
    [$store] = csMakeOrg('CS1');
    $sellable = csMakeProduct($store, ['name' => 'Es Teh']);
    $biangTeh = csMakeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $gula = csMakeProduct($store, [
        'name' => 'Gula',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '0.0000', // HABIS STOK
        'track_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $biangTeh->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $gula->id, 'quantity' => '10.0000', 'input_unit' => 'gram'],
    ]);

    // Batch biang teh cukup
    csCreateBatch($store, $biangTeh, '1000.0000', '0.066667');

    try {
        SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
        $this->fail('Seharusnya melempar ComponentStockInsufficientException');
    } catch (ComponentStockInsufficientException $e) {
        $issues = $e->getIssues();
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['component_name'])->toBe('Gula')
            ->and($issues[0]['available'])->toBe('0.0000')
            ->and($issues[0]['needed'])->toBe('10.0000')
            ->and($issues[0]['unit'])->toBe('gram');

        // Pastikan pesan exception mengandung nama komponen
        expect($e->getMessage())->toContain('Gula');
    }
});

// ═══════════════════════════════════════════════════════
// Skenario 2: Beberapa komponen kurang stok
// ═══════════════════════════════════════════════════════

test('validateStock mengumpulkan semua komponen yang bermasalah saat beberapa komponen kurang stok', function () {
    [$store] = csMakeOrg('CS2');
    $sellable = csMakeProduct($store, ['name' => 'Kopi Spesial']);
    $kopiHitam = csMakeProduct($store, [
        'name' => 'Kopi Hitam',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $susu = csMakeProduct($store, [
        'name' => 'Susu',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '50.0000', // Kurang: butuh 200ml
        'track_stock' => true,
    ]);
    $cangkir = csMakeProduct($store, [
        'name' => 'Cangkir Kertas',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '1.0000', // Kurang: butuh 2pcs
        'track_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $kopiHitam->id, 'quantity' => '150.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $susu->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $cangkir->id, 'quantity' => '2.0000', 'input_unit' => 'pcs'],
    ]);

    // Batch kopi hitam cukup
    csCreateBatch($store, $kopiHitam, '500.0000', '0.050000');

    try {
        SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');
        $this->fail('Seharusnya melempar ComponentStockInsufficientException');
    } catch (ComponentStockInsufficientException $e) {
        $issues = $e->getIssues();

        // Harus ada 2 komponen bermasalah (susu dan cangkir)
        expect($issues)->toHaveCount(2);

        $names = array_column($issues, 'component_name');
        expect($names)->toContain('Susu')
            ->and($names)->toContain('Cangkir Kertas');

        // Cek detail susu
        $susuIssue = collect($issues)->first(fn ($i) => $i['component_name'] === 'Susu');
        expect($susuIssue['available'])->toBe('50.0000')
            ->and($susuIssue['needed'])->toBe('200.0000')
            ->and($susuIssue['unit'])->toBe('ml');

        // Cek detail cangkir
        $cangkirIssue = collect($issues)->first(fn ($i) => $i['component_name'] === 'Cangkir Kertas');
        expect($cangkirIssue['available'])->toBe('1.0000')
            ->and($cangkirIssue['needed'])->toBe('2.0000')
            ->and($cangkirIssue['unit'])->toBe('pcs');
    }
});

// ═══════════════════════════════════════════════════════
// Skenario 3: Semua komponen stok mencukupi
// ═══════════════════════════════════════════════════════

test('validateStock lolos tanpa exception ketika semua komponen stok mencukupi', function () {
    [$store] = csMakeOrg('CS3');
    $sellable = csMakeProduct($store, ['name' => 'Es Teh']);
    $biangTeh = csMakeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $gula = csMakeProduct($store, [
        'name' => 'Gula',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000', // Cukup untuk 10g/sale × 5
        'track_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $biangTeh->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $gula->id, 'quantity' => '10.0000', 'input_unit' => 'gram'],
    ]);

    // Batch biang teh cukup (1000ml untuk 1 sale × 200ml)
    csCreateBatch($store, $biangTeh, '1000.0000', '0.066667');

    // Tidak harus throw — semua komponen mencukupi
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');

    expect(true)->toBeTrue();
});

// ═══════════════════════════════════════════════════════
// Skenario tambahan: Qty jual lebih besar memperparah defisit
// ═══════════════════════════════════════════════════════

test('validateStock menghitung kebutuhan berdasarkan jumlah jual yang benar', function () {
    [$store] = csMakeOrg('CS4');
    $sellable = csMakeProduct($store, ['name' => 'Es Teh']);
    $biangTeh = csMakeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $biangTeh->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
    ]);

    // Batch 500ml — cukup untuk 2 sale (400ml), tapi tidak untuk 3 (600ml)
    csCreateBatch($store, $biangTeh, '500.0000', '0.066667');

    // Jual 2: butuh 400ml, tersedia 500ml → lolos
    SaleConsumptionResolver::validateStock($store, $sellable, null, '2.0000');
    expect(true)->toBeTrue();

    // Jual 3: butuh 600ml, tersedia 500ml → gagal
    try {
        SaleConsumptionResolver::validateStock($store, $sellable, null, '3.0000');
        $this->fail('Seharusnya melempar ComponentStockInsufficientException');
    } catch (ComponentStockInsufficientException $e) {
        $issues = $e->getIssues();
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['component_name'])->toBe('Biang Teh')
            ->and((float) $issues[0]['needed'])->toBe(600.0)
            ->and((float) $issues[0]['available'])->toBe(500.0)
            ->and($issues[0]['unit'])->toBe('ml');
    }
});

// ═══════════════════════════════════════════════════════
// Skenario 5: Produk induk allow_negative_stock=true → semua komponen diizinkan minus
// ═══════════════════════════════════════════════════════

test('validateStock lolos ketika produk induk allow_negative_stock true meskipun komponen semua stok habis', function () {
    [$store] = csMakeOrg('CS5');
    $sellable = csMakeProduct($store, [
        'name' => 'Teh Tarik',
        'allow_negative_stock' => true,
    ]);
    $biangTeh = csMakeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $skm = csMakeProduct($store, [
        'name' => 'SKM',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
        'track_stock' => true,
    ]);
    $sedotan = csMakeProduct($store, [
        'name' => 'Sedotan',
        'inventory_type' => InventoryType::PACKAGING,
        'base_unit' => BaseUnit::PIECE,
        'stock_quantity' => '0.0000',
        'track_stock' => true,
    ]);

    SaleConsumptionConfig::replaceForProduct($sellable, $store, [
        ['component_product_id' => $biangTeh->id, 'quantity' => '200.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $skm->id, 'quantity' => '30.0000', 'input_unit' => 'ml'],
        ['component_product_id' => $sedotan->id, 'quantity' => '1.0000', 'input_unit' => 'pcs'],
    ]);

    // Batch biang teh habis juga
    csCreateBatch($store, $biangTeh, '0.0000', '0.066667');

    // Semua komponen stok 0, tapi produk induk allow_negative_stock=true → lolos
    SaleConsumptionResolver::validateStock($store, $sellable, null, '1.0000');

    expect(true)->toBeTrue();
});
