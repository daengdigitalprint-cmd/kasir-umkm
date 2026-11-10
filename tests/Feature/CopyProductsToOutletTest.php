<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSaleConsumption;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function copyTestGroup(): array
{
    $owner = User::factory()->create();

    $org = Organization::query()->create([
        'name' => 'Grup Salin',
        'slug' => 'grup-salin-'.uniqid(),
        'owner_user_id' => $owner->id,
    ]);

    $source = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Outlet 1',
        'slug' => 'outlet-1-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_primary' => true,
        'is_active' => true,
    ]);

    $dest = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Outlet 6',
        'slug' => 'outlet-6-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
    ]);

    return [$owner, $org, $source, $dest];
}

function makeMenuWithComponent(Store $store): array
{
    $gula = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Gula Pasir',
        'sku' => 'BHN-GULA',
        'price' => 0,
        'inventory_type' => InventoryType::RAW_MATERIAL,
        'unit' => 'gram',
        'base_unit' => 'gram',
    ]);

    $kopi = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'sku' => 'MENU-KOPSU',
        'price' => 18000,
        'inventory_type' => InventoryType::SELLABLE,
        'track_stock' => false,
    ]);

    ProductSaleConsumption::query()->create([
        'store_id' => $store->id,
        'product_id' => $kopi->id,
        'component_product_id' => $gula->id,
        'quantity' => 15,
        'base_unit' => 'gram',
        'sort_order' => 1,
    ]);

    return [$kopi, $gula];
}

test('salin semua ikut menyalin komponen menu ke outlet tujuan', function () {
    [, , $source, $dest] = copyTestGroup();
    [$kopi] = makeMenuWithComponent($source);

    $result = $dest->copyProductsFrom($source, 'all');

    expect($result['copied'])->toBe(2)
        ->and($result['components'])->toBe(1)
        ->and($result['errors'])->toBe([]);

    $destKopi = $dest->products()->where('sku', 'MENU-KOPSU')->firstOrFail();
    $destGula = $dest->products()->where('sku', 'BHN-GULA')->firstOrFail();
    $consumption = $destKopi->saleConsumptions()->firstOrFail();

    expect($dest->products()->count())->toBe(2)
        ->and((float) $consumption->quantity)->toBe(15.0)
        ->and($consumption->base_unit)->toBe('gram')
        ->and($consumption->component_product_id)->toBe($destGula->id)
        ->and($consumption->store_id)->toBe($dest->id)
        // lacak stok mengikuti sumber, tidak dipaksa aktif
        ->and($destKopi->track_stock)->toBeFalse()
        ->and($kopi->saleConsumptions()->count())->toBe(1);
});

test('varian dan komponen per varian ikut tersalin', function () {
    [, , $source, $dest] = copyTestGroup();
    [$kopi, $gula] = makeMenuWithComponent($source);

    $kopi->update(['has_variants' => true]);

    $large = ProductVariant::query()->create([
        'store_id' => $source->id,
        'product_id' => $kopi->id,
        'name' => 'Large',
        'sku' => 'MENU-KOPSU-L',
        'price' => 22000,
        'sort_order' => 1,
    ]);

    ProductSaleConsumption::query()->create([
        'store_id' => $source->id,
        'product_id' => $kopi->id,
        'product_variant_id' => $large->id,
        'component_product_id' => $gula->id,
        'quantity' => 25,
        'base_unit' => 'gram',
    ]);

    $result = $dest->copyProductsFrom($source, 'all');

    $destKopi = $dest->products()->where('sku', 'MENU-KOPSU')->firstOrFail();
    $destLarge = $destKopi->variants()->where('sku', 'MENU-KOPSU-L')->firstOrFail();
    $variantConsumption = $destKopi->saleConsumptions()->whereNotNull('product_variant_id')->firstOrFail();

    expect($result['errors'])->toBe([])
        ->and($destKopi->has_variants)->toBeTrue()
        ->and((float) $destLarge->price)->toBe(22000.0)
        ->and((float) $destLarge->stock_quantity)->toBe(0.0)
        ->and($variantConsumption->product_variant_id)->toBe($destLarge->id)
        ->and((float) $variantConsumption->quantity)->toBe(25.0);
});

test('outlet yang produknya sudah ada tapi komponennya kosong akan dilengkapi', function () {
    [, , $source, $dest] = copyTestGroup();
    makeMenuWithComponent($source);

    // Penyalinan versi lama: produk saja, tanpa komponen
    foreach ($source->products as $sp) {
        Product::query()->create([
            'store_id' => $dest->id,
            'name' => $sp->name,
            'sku' => $sp->sku,
            'price' => $sp->price,
            'inventory_type' => $sp->inventory_type,
            'unit' => $sp->unit,
            'base_unit' => $sp->base_unit,
        ]);
    }

    $result = $dest->copyProductsFrom($source, 'all');

    $destKopi = $dest->products()->where('sku', 'MENU-KOPSU')->firstOrFail();

    expect($result['copied'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and($result['components'])->toBe(1)
        ->and($dest->products()->count())->toBe(2)
        ->and($destKopi->saleConsumptions()->count())->toBe(1);
});

test('komponen yang sudah disetel di outlet tujuan tidak ditimpa', function () {
    [, , $source, $dest] = copyTestGroup();
    makeMenuWithComponent($source);

    $dest->copyProductsFrom($source, 'all');

    $destKopi = $dest->products()->where('sku', 'MENU-KOPSU')->firstOrFail();
    $destKopi->saleConsumptions()->update(['quantity' => 40]);

    $dest->copyProductsFrom($source, 'all');

    expect($destKopi->saleConsumptions()->count())->toBe(1)
        ->and((float) $destKopi->saleConsumptions()->first()->quantity)->toBe(40.0);
});

test('halaman kelola produk menawarkan outlet utama sebagai sumber', function () {
    [$owner, , $source, $dest] = copyTestGroup();

    $owner->update(['store_id' => $dest->id, 'role' => 'admin']);
    $owner->stores()->syncWithoutDetaching([$source->id => ['role' => 'admin'], $dest->id => ['role' => 'admin']]);

    makeMenuWithComponent($source);

    $response = $this->actingAs($owner)->get('/manage-products');

    $response->assertOk();

    $sources = collect($response->viewData('page')['props']['sources']);

    expect($sources->pluck('id'))->toContain($source->id)
        ->and($sources->firstWhere('id', $source->id)['type'])->toBe('primary_outlet');
});
