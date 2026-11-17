<?php

/*
 * F3 — Satuan beli & isi.
 * Beli 2 karton (isi 24) → stok +48 pcs, HPP per pcs = harga karton ÷ 24.
 * Produk tanpa satuan beli tetap memakai konversi lama.
 */

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Models\User;
use App\Support\PurchaseUnitResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function unitStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Satuan',
        'slug' => 'satuan-'.uniqid(),
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

function unitProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Air Mineral',
        'price' => 4000,
        'cost_price' => 0,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'base_unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_active' => true,
    ], $overrides));
}

function purchasePayload(Product $product, float $qty, string $unit, float $cost): array
{
    return [
        'purchased_at' => now()->toDateString(),
        'lines' => [[
            'product_id' => $product->id,
            'quantity' => $qty,
            'input_unit' => $unit,
            'unit_cost' => $cost,
        ]],
    ];
}

it('menyimpan pembelian per karton ke stok pcs dengan HPP per pcs', function () {
    [$store, $user] = unitStore();
    $product = unitProduct($store);
    $product->units()->create(['name' => 'karton', 'factor' => 24]);

    $this->actingAs($user)
        ->post('/purchases', purchasePayload($product, 2, 'karton', 240000))
        ->assertSessionHasNoErrors();

    $product->refresh();
    $line = PurchaseLine::query()->where('product_id', $product->id)->latest('id')->first();

    expect((float) $product->stock_quantity)->toBe(48.0)
        ->and((float) $product->average_cost)->toBe(10000.0)
        ->and($line->input_unit)->toBe('karton')
        ->and((float) $line->quantity_input)->toBe(2.0)
        ->and((float) $line->qty_base)->toBe(48.0)
        ->and((float) $line->unit_cost_base)->toBe(10000.0)
        ->and((float) $line->subtotal)->toBe(480000.0);
});

it('mengonversi satuan beli lintas dimensi (1 pcs bawang = 250 gram)', function () {
    [$store] = unitStore();
    $product = unitProduct($store, ['name' => 'Bawang', 'base_unit' => 'gram', 'unit' => 'gram', 'allows_decimal' => true]);
    $product->units()->create(['name' => 'pcs besar', 'factor' => 250]);
    $product->load('units');

    expect(PurchaseUnitResolver::toBaseQuantity($product, 4, 'pcs besar'))->toBe('1000.0000')
        ->and((float) PurchaseUnitResolver::toBaseUnitCost($product, 5000, 'pcs besar'))->toBe(20.0)
        ->and(PurchaseUnitResolver::inputUnitsFor($product))->toBe(['kg', 'kilogram', 'gram', 'g', 'pcs besar']);
});

it('produk tanpa satuan beli tetap memakai konversi lama (0,5 kg → 500 gram)', function () {
    [$store, $user] = unitStore();
    $product = unitProduct($store, ['name' => 'Gula', 'base_unit' => 'gram', 'unit' => 'gram', 'allows_decimal' => true]);

    $this->actingAs($user)
        ->post('/purchases', purchasePayload($product, 0.5, 'kg', 16000))
        ->assertSessionHasNoErrors();

    $product->refresh();
    expect((float) $product->stock_quantity)->toBe(500.0)
        ->and((float) $product->average_cost)->toBe(16.0);
});

it('menolak satuan yang tidak dikenal produk', function () {
    [$store, $user] = unitStore();
    $product = unitProduct($store);

    $this->actingAs($user)
        ->post('/purchases', purchasePayload($product, 1, 'karton', 1000));

    expect((float) $product->refresh()->stock_quantity)->toBe(0.0);
});

it('mengirim satuan beli dan pilihan awal ke form Pembelian', function () {
    [$store, $user] = unitStore();
    $product = unitProduct($store);
    $product->units()->create(['name' => 'karton', 'factor' => 24, 'is_purchase_default' => true]);

    $this->actingAs($user)->get('/purchases/create')->assertOk()->assertInertia(
        fn ($page) => $page->component('Purchases/Create')
            ->where('products.0.input_units', ['pcs', 'buah', 'karton'])
            ->where('products.0.unit_factors.karton', 24)
            ->where('products.0.default_input_unit', 'karton')
            ->missing('products.0.units')
    );
});

it('menyimpan satuan beli dari halaman produk', function () {
    [$store, $user] = unitStore();
    $product = unitProduct($store);

    $this->actingAs($user)->put("/products/{$product->id}/purchase-units", [
        'units' => [
            ['name' => ' Karton ', 'factor' => 24, 'is_purchase_default' => true],
            ['name' => 'pack', 'factor' => 6, 'is_purchase_default' => true],
        ],
    ])->assertSessionHasNoErrors();

    $units = $product->units()->get();
    expect($units->pluck('name')->all())->toBe(['pack', 'karton'])
        ->and($units->where('is_purchase_default', true)->pluck('name')->all())->toBe(['karton']);

    // Kirim daftar kosong = hapus semua.
    $this->actingAs($user)->put("/products/{$product->id}/purchase-units", ['units' => []])->assertSessionHasNoErrors();
    expect($product->units()->count())->toBe(0);
});

it('menolak nama satuan standar, ganda, dan isi nol', function (array $units, string $errorKey) {
    [$store, $user] = unitStore();
    $product = unitProduct($store);

    $this->actingAs($user)
        ->put("/products/{$product->id}/purchase-units", ['units' => $units])
        ->assertSessionHasErrors($errorKey);
})->with([
    'nama standar' => [[['name' => 'kg', 'factor' => 2]], 'units.0.name'],
    'nama ganda' => [[['name' => 'dus', 'factor' => 2], ['name' => 'DUS', 'factor' => 3]], 'units.1.name'],
    'isi nol' => [[['name' => 'dus', 'factor' => 0]], 'units.0.factor'],
]);

it('tidak bisa mengubah satuan beli produk toko lain', function () {
    [$store] = unitStore();
    [, $intruder] = unitStore();
    $product = unitProduct($store);

    $this->actingAs($intruder)
        ->put("/products/{$product->id}/purchase-units", ['units' => [['name' => 'dus', 'factor' => 2]]]);

    expect($product->units()->count())->toBe(0);
});

it('menghapus satuan beli saat satuan dasar produk diganti', function (string $newBaseUnit, int $remaining) {
    [$store, $user] = unitStore();
    $product = unitProduct($store, ['sku' => 'AIR-1']);
    $product->units()->create(['name' => 'karton', 'factor' => 24]);

    $this->actingAs($user)->put("/products/{$product->id}", [
        'name' => $product->name,
        'sku' => 'AIR-1',
        'barcode' => '',
        'description' => '',
        'brand' => '',
        'tax_rate' => 0,
        'price' => 4000,
        'cost_price' => 0,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'inventory_type' => 'sellable',
        'base_unit' => $newBaseUnit,
        'track_stock' => true,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($product->units()->count())->toBe($remaining);
})->with([
    'satuan dasar berubah' => ['gram', 0],
    'satuan dasar tetap' => ['pcs', 1],
]);
