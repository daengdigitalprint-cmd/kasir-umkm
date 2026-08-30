<?php

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryCosting;
use App\Support\InventoryType;
use App\Support\ProductQuantity;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeProdOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'prod-test-'.uniqid(),
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

function makeProdProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Produksi',
        'price' => 10000,
        'cost_price' => 5000,
        'average_cost' => '5.000000',
        'stock_quantity' => '0.0000',
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'base_unit' => BaseUnit::GRAM,
        'unit' => 'gram',
        'track_stock' => true,
        'is_active' => true,
    ], $attrs));
}

function createProdRecipe(Store $store, User $user, Product $output, array $ingredientsData): Recipe
{
    $recipe = Recipe::create([
        'store_id' => $store->id,
        'name' => 'Test Recipe',
        'code' => 'REC-PROD',
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

/**
 * Replika logika ProductionController::store (draft).
 */
function createDraftBatch(User $user, Store $store, Recipe $recipe, float $multiplier = 1): ProductionBatch
{
    return DB::transaction(function () use ($store, $recipe, $multiplier) {
        $ingredientProductIds = $recipe->ingredients->pluck('product_id')->sort()->values();
        if ($ingredientProductIds->isNotEmpty()) {
            Product::query()->whereIn('id', $ingredientProductIds)->orderBy('id')->lockForUpdate()->get();
        }

        foreach ($recipe->ingredients as $ing) {
            $product = $ing->product;
            $product->refresh();
            $requiredQty = bcmul((string) $ing->quantity, (string) $multiplier, 4);
            if (ProductQuantity::lt((string) $product->stock_quantity, $requiredQty)) {
                throw new \RuntimeException("Stok bahan \"{$product->name}\" tidak mencukupi.");
            }
        }

        Product::query()->whereKey($recipe->product_id)->lockForUpdate()->first();

        $outputQty = bcmul((string) $recipe->output_quantity, (string) $multiplier, 4);

        $batch = ProductionBatch::create([
            'store_id' => $store->id,
            'recipe_id' => $recipe->id,
            'product_id' => $recipe->product_id,
            'status' => ProductionBatch::STATUS_DRAFT,
            'multiplier' => $multiplier,
            'output_quantity_initial' => $outputQty,
            'output_quantity_remaining' => $outputQty,
            'total_cost' => 0,
            'unit_cost' => 0,
            'recipe_name' => $recipe->name,
            'recipe_code' => $recipe->code,
            'recipe_version' => $recipe->version,
            'recipe_output_quantity' => $recipe->output_quantity,
            'recipe_output_unit' => $recipe->output_unit,
        ]);

        foreach ($recipe->ingredients as $ing) {
            $product = $ing->product->fresh();
            $requiredQty = bcmul((string) $ing->quantity, (string) $multiplier, 4);
            $avgCost = InventoryCosting::currentAverageCost($product);
            $lineCost = InventoryCosting::extendedCost($requiredQty, $avgCost, 2);

            ProductionBatchLine::create([
                'production_batch_id' => $batch->id,
                'product_id' => $product->id,
                'quantity_required' => $requiredQty,
                'quantity_consumed' => 0,
                'base_unit' => $ing->base_unit,
                'unit_cost_snapshot' => $avgCost,
                'total_cost' => $lineCost,
            ]);
        }

        return $batch;
    });
}

/**
 * Replika logika complete.
 */
function executeCompleteBatch(User $user, ProductionBatch $batch): void
{
    DB::transaction(function () use ($user, $batch) {
        $batch->lockForUpdate();
        $batch->assertCanTransitionTo(ProductionBatch::STATUS_COMPLETED);

        $lines = $batch->lines()->with('product')->get();
        $totalCost = '0';

        $lineProductIds = $lines->pluck('product_id')->sort()->values();
        Product::query()->whereIn('id', $lineProductIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $product = $line->product;
            $product->refresh();
            $qty = (string) $line->quantity_required;

            if (ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                throw new \RuntimeException("Stok bahan \"{$product->name}\" tidak mencukupi.");
            }

            $newStock = bcsub((string) $product->stock_quantity, $qty, 4);
            $product->update(['stock_quantity' => $newStock]);

            StockMovement::create([
                'store_id' => $batch->store_id,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'quantity_change' => '-'.$qty,
                'type' => StockMovement::TYPE_PRODUCTION_CONSUMPTION,
                'reference_type' => ProductionBatchLine::class,
                'reference_id' => $line->id,
                'balance_after' => $newStock,
                'notes' => "Batch #{$batch->batch_number}",
            ]);

            $line->update(['quantity_consumed' => $qty]);
            $totalCost = bcadd($totalCost, (string) $line->total_cost, 2);
        }

        $outputProduct = Product::lockForUpdate()->find($batch->product_id);
        $outputQty = (string) $batch->output_quantity_initial;
        $newOutputStock = bcadd((string) $outputProduct->stock_quantity, $outputQty, 4);
        $outputProduct->update(['stock_quantity' => $newOutputStock]);

        $oldAvgCost = InventoryCosting::currentAverageCost($outputProduct);
        $unitCost = InventoryCosting::unitCostFromTotal($totalCost, $outputQty);
        $newAvgCost = InventoryCosting::weightedAverage(
            bcsub((string) $outputProduct->stock_quantity, $outputQty, 4),
            $oldAvgCost,
            $outputQty,
            $unitCost,
        );
        $outputProduct->update([
            'average_cost' => $newAvgCost,
            'cost_price' => round((float) $newAvgCost, 2),
        ]);

        StockMovement::create([
            'store_id' => $batch->store_id,
            'product_id' => $outputProduct->id,
            'user_id' => $user->id,
            'quantity_change' => $outputQty,
            'type' => StockMovement::TYPE_PRODUCTION_OUTPUT,
            'reference_type' => ProductionBatch::class,
            'reference_id' => $batch->id,
            'balance_after' => $newOutputStock,
            'notes' => "Batch #{$batch->batch_number}",
        ]);

        $batch->update([
            'status' => ProductionBatch::STATUS_COMPLETED,
            'total_cost' => $totalCost,
            'unit_cost' => $unitCost,
            'produced_by' => $user->id,
            'produced_at' => now(),
        ]);
    });
}

// ═══════════════════════════════════════════════════════
// Tests
// ═══════════════════════════════════════════════════════

test('draft batch tidak mengubah stok', function () {
    [$store, $user] = makeProdOrg('P1');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);

    $teh->refresh();
    $output->refresh();

    expect($batch->status)->toBe(ProductionBatch::STATUS_DRAFT)
        ->and($teh->stock_quantity)->toBe('500.0000')
        ->and($output->stock_quantity)->toBe('0.0000')
        ->and(StockMovement::count())->toBe(0);
});

test('selesaikan batch mengurangi bahan dan menambah output', function () {
    [$store, $user] = makeProdOrg('P2');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
        'average_cost' => '10.000000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);
    executeCompleteBatch($user, $batch);

    $teh->refresh();
    $output->refresh();

    expect($batch->fresh()->status)->toBe(ProductionBatch::STATUS_COMPLETED)
        ->and($teh->stock_quantity)->toBe('380.0000')
        ->and($output->stock_quantity)->toBe('18000.0000')
        ->and(StockMovement::where('type', StockMovement::TYPE_PRODUCTION_CONSUMPTION)->count())->toBe(1)
        ->and(StockMovement::where('type', StockMovement::TYPE_PRODUCTION_OUTPUT)->count())->toBe(1);
});

test('HPP per satuan dihitung dari total biaya / output', function () {
    [$store, $user] = makeProdOrg('P3');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
        'average_cost' => '10.000000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);
    executeCompleteBatch($user, $batch);

    $batch->refresh();
    // Total cost = 120 * Rp10 = Rp1200, output = 18000 ml
    // unit_cost = Rp1200 / 18000 = Rp0.066667/ml
    expect((float) $batch->unit_cost)->toBeGreaterThan(0);
    expect(abs((float) $batch->unit_cost - 0.066667))->toBeLessThan(0.001);
});

test('multiplier menggandakan bahan dan output', function () {
    [$store, $user] = makeProdOrg('P4');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '0.0000',
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe, 2);

    $line = $batch->lines()->sole();
    expect($batch->output_quantity_initial)->toBe('36000.0000')
        ->and($line->quantity_required)->toBe('240.0000')
        ->and((string) $batch->multiplier)->toBe('2.0000');
});

test('batch draft dibatalkan tanpa mutasi stok', function () {
    [$store, $user] = makeProdOrg('P5');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);

    DB::transaction(function () use ($user, $batch) {
        $batch->lockForUpdate();
        $batch->assertCanTransitionTo(ProductionBatch::STATUS_CANCELLED);
        $batch->update([
            'status' => ProductionBatch::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancel_reason' => 'Tidak jadi',
        ]);
    });

    $teh->refresh();

    expect($batch->fresh()->status)->toBe(ProductionBatch::STATUS_CANCELLED)
        ->and($teh->stock_quantity)->toBe('500.0000')
        ->and(StockMovement::count())->toBe(0);
});

test('stok bahan tidak cukup saat diselesaikan', function () {
    [$store, $user] = makeProdOrg('P6');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);

    // Override stok setelah draft dibuat (simulasi bahan dipakai di tempat lain)
    $teh->update(['stock_quantity' => '50.0000']);

    $this->expectException(\RuntimeException::class);
    executeCompleteBatch($user, $batch);
});

test('snapshot recipe tersimpan pada batch', function () {
    [$store, $user] = makeProdOrg('P7');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);

    expect($batch->recipe_name)->toBe('Test Recipe')
        ->and($batch->recipe_code)->toBe('REC-PROD')
        ->and($batch->recipe_version)->toBe(1)
        ->and($batch->recipe_output_quantity)->toBe('18000.0000')
        ->and($batch->recipe_output_unit)->toBe(BaseUnit::MILLILITER);
});

test('snapshot biaya bahan tersimpan pada baris', function () {
    [$store, $user] = makeProdOrg('P8');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
        'average_cost' => '10.000000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);
    $line = $batch->lines()->sole();

    expect($line->unit_cost_snapshot)->toBe('10.000000')
        ->and($line->base_unit)->toBe(BaseUnit::GRAM)
        ->and($line->total_cost)->not->toBeNull();
});

test('state machine menolak transisi tidak valid', function () {
    [$store, $user] = makeProdOrg('P9');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);
    executeCompleteBatch($user, $batch);

    $this->expectException(\RuntimeException::class);
    $batch->fresh()->assertCanTransitionTo(ProductionBatch::STATUS_COMPLETED);
});

test('batch menambah stok output product dengan benar', function () {
    [$store, $user] = makeProdOrg('P10');
    $output = makeProdProduct($store, [
        'name' => 'Biang Teh',
        'inventory_type' => InventoryType::PRODUCTION_OUTPUT,
        'base_unit' => BaseUnit::MILLILITER,
        'stock_quantity' => '5000.0000',
        'average_cost' => '2.000000',
    ]);
    $teh = makeProdProduct($store, [
        'name' => 'Teh Hitam',
        'base_unit' => BaseUnit::GRAM,
        'stock_quantity' => '500.0000',
        'average_cost' => '10.000000',
    ]);

    $recipe = createProdRecipe($store, $user, $output, [
        ['product_id' => $teh->id, 'quantity' => '120.0000', 'base_unit' => BaseUnit::GRAM],
    ]);

    $batch = createDraftBatch($user, $store, $recipe);
    executeCompleteBatch($user, $batch);

    $output->refresh();
    expect($output->stock_quantity)->toBe('23000.0000')
        ->and((float) $output->average_cost)->toBeGreaterThan(0);
});
