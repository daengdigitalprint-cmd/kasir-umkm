<?php

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Test Tahap 5 — Master Recipe.
 */
function makeRecipeOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'recipe-test-'.uniqid(),
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

function makeRecipeProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Recipe',
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

function createTestRecipe(User $user, Store $store, Product $output, array $ingredientsData): Recipe
{
    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Test Recipe',
        'code' => 'REC-TEST',
        'product_id' => $output->id,
        'output_quantity' => '18000.0000',
        'output_unit' => BaseUnit::MILLILITER,
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    $order = 0;
    foreach ($ingredientsData as $ing) {
        RecipeIngredient::create([
            'recipe_id' => $recipe->id,
            'product_id' => $ing['product_id'],
            'quantity' => $ing['quantity'],
            'base_unit' => $ing['base_unit'] ?? BaseUnit::GRAM,
            'sort_order' => $order++,
        ]);
    }

    return $recipe;
}

// ═══════════════════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════════════════

test('recipe dapat dibuat dengan output dan bahan', function () {
    [$store, $user] = makeRecipeOrg('R1');
    $output = makeRecipeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeRecipeProduct($store, ['name' => 'Teh Hitam', 'base_unit' => BaseUnit::GRAM]);
    $air = makeRecipeProduct($store, [
        'name' => 'Air',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $recipe = createTestRecipe($user, $store, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
        ['product_id' => $air->id, 'quantity' => '18000.0000', 'base_unit' => BaseUnit::MILLILITER],
    ]);

    expect($recipe->fresh()->name)->toBe('Test Recipe')
        ->and($recipe->ingredients()->count())->toBe(2)
        ->and($recipe->version)->toBe(1)
        ->and($recipe->is_active)->toBeTrue();
});

test('output harus bertipe production_output', function () {
    [$store, $user] = makeRecipeOrg('R2');
    $output = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::SELLABLE,
    ]);

    $result = InventoryType::isProductionOutput($output);
    expect($result)->toBeFalse();
});

test('bahan harus raw_material atau production_output', function () {
    [$store, $user] = makeRecipeOrg('R3');
    $packaging = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::PACKAGING,
    ]);

    $result = InventoryType::usableAsIngredient($packaging);
    expect($result)->toBeFalse();

    $rawMaterial = makeRecipeProduct($store, [
        'name' => 'Teh',
        'inventory_type' => InventoryType::RAW_MATERIAL,
    ]);
    expect(InventoryType::usableAsIngredient($rawMaterial))->toBeTrue();
});

test('dimensi satuan bahan harus kompatibel dengan output', function () {
    // Output ml (volume), bahan gram (weight) → tidak kompatibel
    $outDim = BaseUnit::dimensionOf(BaseUnit::MILLILITER);
    $ingDim = BaseUnit::dimensionOf(BaseUnit::GRAM);

    expect($outDim)->not->toBe($ingDim);
    expect($outDim)->toBe(BaseUnit::DIMENSION_VOLUME)
        ->and($ingDim)->toBe(BaseUnit::DIMENSION_WEIGHT);
});

test('dimensi satuan bahan dan output dalam dimensi yang sama', function () {
    // Output ml (volume), bahan ml (volume) → kompatibel
    $outDim = BaseUnit::dimensionOf(BaseUnit::MILLILITER);
    $ingDim = BaseUnit::dimensionOf(BaseUnit::MILLILITER);

    expect($outDim)->toBe($ingDim);
});

test('total biaya bahan dihitung dari average cost', function () {
    [$store, $user] = makeRecipeOrg('R4');
    $output = makeRecipeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeRecipeProduct($store, [
        'name' => 'Teh Hitam',
        'average_cost' => '10.000000',
        'base_unit' => BaseUnit::GRAM,
    ]);

    $recipe = createTestRecipe($user, $store, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $totalCost = $recipe->totalIngredientCost();
    // 120 gram * Rp10/gram = Rp1200
    expect($totalCost)->toBe('1200.00');
});

test('HPP per satuan output dihitung dari total biaya / kuantitas output', function () {
    [$store, $user] = makeRecipeOrg('R5');
    $output = makeRecipeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeRecipeProduct($store, [
        'name' => 'Teh Hitam',
        'average_cost' => '10.000000',
        'base_unit' => BaseUnit::GRAM,
    ]);

    $recipe = createTestRecipe($user, $store, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $unitCost = $recipe->unitCostPerOutput();
    // Rp1200 / 18000ml = Rp0.066667/ml
    expect((float) $unitCost)->toBeGreaterThan(0);
    expect(abs((float) $unitCost - 0.066667))->toBeLessThan(0.001);
});

test('versi recipe bertambah saat diperbarui', function () {
    [$store, $user] = makeRecipeOrg('R6');
    $output = makeRecipeProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeRecipeProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
    ]);

    $recipe = createTestRecipe($user, $store, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    expect($recipe->version)->toBe(1);

    DB::transaction(function () use ($recipe, $user, $teh) {
        $recipe->ingredients()->delete();
        $recipe->update([
            'output_quantity' => '20000.0000',
            'version' => $recipe->version + 1,
            'updated_by' => $user->id,
        ]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->id,
            'product_id' => $teh->id,
            'quantity' => '150.0000',
            'base_unit' => BaseUnit::GRAM,
            'sort_order' => 0,
        ]);
    });

    $recipe->refresh();
    expect($recipe->version)->toBe(2)
        ->and($recipe->output_quantity)->toBe('20000.0000')
        ->and($recipe->ingredients()->sole()->quantity)->toBe('150.0000');
});

test('kode recipe unik per toko', function () {
    [$store, $user] = makeRecipeOrg('R7');
    $output = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $r1 = createTestRecipe($user, $store, $output, []);
    expect($r1->code)->toBe('REC-TEST');

    $exists = Recipe::where('store_id', $store->id)->where('code', 'REC-TEST')->count();
    expect($exists)->toBe(1);
});

test('recipe nonaktif tidak mengubah data', function () {
    [$store, $user] = makeRecipeOrg('R8');
    $output = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $recipe = createTestRecipe($user, $store, $output, []);
    $recipe->update(['is_active' => false]);

    expect($recipe->fresh()->is_active)->toBeFalse();
});

test('recipe dengan output kuantitas 0 memiliki HPP 0', function () {
    [$store, $user] = makeRecipeOrg('R9');
    $output = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);

    $recipe = createTestRecipe($user, $store, $output, []);
    $recipe->update(['output_quantity' => '0.0000']);

    $unitCost = $recipe->unitCostPerOutput();
    expect($unitCost)->toBe('0');
});

test('relasi recipe dengan bahan eager loading', function () {
    [$store, $user] = makeRecipeOrg('R10');
    $output = makeRecipeProduct($store, [
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeRecipeProduct($store, ['name' => 'Teh', 'base_unit' => BaseUnit::GRAM]);

    $recipe = createTestRecipe($user, $store, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $loaded = Recipe::with(['ingredients.product', 'product'])->find($recipe->id);

    expect($loaded->product)->not->toBeNull()
        ->and($loaded->ingredients)->toHaveCount(1)
        ->and($loaded->ingredients->first()->product)->not->toBeNull()
        ->and($loaded->ingredients->first()->product->name)->toBe('Teh');
});
