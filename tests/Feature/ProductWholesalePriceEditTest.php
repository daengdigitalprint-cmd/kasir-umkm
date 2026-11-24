<?php

/*
 * Harga grosir tidak boleh hilang saat produk diedit: halaman edit harus menerima
 * harga grosir produk & varian, dan toko yang mematikan modul grosir tidak boleh
 * kehilangan harga grosir yang sudah tersimpan.
 */

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User}
 */
function pweStore(bool $wholesaleEnabled = true): array
{
    $store = Store::query()->create([
        'name' => 'Toko Grosir Uji',
        'slug' => 'grosir-uji-'.uniqid(),
        'settings' => ['wholesale_enabled' => $wholesaleEnabled],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    return [$store, $admin];
}

function pweProduct(Store $store, array $attributes = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Gula Pasir 1kg',
        'price' => 18000,
        'wholesale_price' => 16500,
        'stock_quantity' => '0.0000',
        'unit' => 'pcs',
        'track_stock' => false,
        'is_active' => true,
    ], $attributes));
}

/** Payload yang dikirim form edit (Products/Edit.vue) bila pengguna tidak mengubah apa pun. */
function pweEditPayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'name' => $product->name,
        'sku' => null,
        'barcode' => null,
        'description' => null,
        'brand' => null,
        'cost_price' => null,
        'tax_rate' => 0,
        'price' => (float) $product->price,
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'unit' => 'pcs',
        'inventory_type' => 'sellable',
        'track_stock' => false,
        'is_active' => true,
    ], $overrides);
}

it('mengirim harga grosir produk dan varian ke halaman edit', function () {
    [$store, $admin] = pweStore();
    $product = pweProduct($store, ['has_variants' => true]);
    ProductVariant::query()->create([
        'store_id' => $store->id, 'product_id' => $product->id, 'name' => 'Karung 50kg',
        'price' => 850000, 'wholesale_price' => 815000, 'stock_quantity' => '0.0000', 'is_active' => true,
    ]);

    $this->actingAs($admin)->get("/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Edit')
            ->where('wholesaleEnabled', true)
            ->where('product.wholesale_price', fn ($value) => (float) $value === 16500.0)
            ->where('variants.0.wholesale_price', fn ($value) => (float) $value === 815000.0));
});

it('menyimpan ulang form edit tanpa perubahan tidak menghapus harga grosir', function () {
    [$store, $admin] = pweStore();
    $product = pweProduct($store);

    // Form mengisi kolom Harga Grosir dari props halaman edit, lalu mengirimkannya kembali apa adanya.
    $props = $this->actingAs($admin)->get("/products/{$product->id}/edit")->viewData('page')['props'];
    $wholesaleFromPage = $props['product']['wholesale_price'] ?? 0;

    $this->actingAs($admin)
        ->put("/products/{$product->id}", pweEditPayload($product, ['name' => 'Gula Pasir Premium 1kg', 'wholesale_price' => $wholesaleFromPage]))
        ->assertSessionHasNoErrors();

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('Gula Pasir Premium 1kg')
        ->and((float) $fresh->wholesale_price)->toBe(16500.0);
});

it('menyimpan ulang varian dari halaman edit tidak menghapus harga grosir varian', function () {
    [$store, $admin] = pweStore();
    $product = pweProduct($store, ['has_variants' => true]);
    $variant = ProductVariant::query()->create([
        'store_id' => $store->id, 'product_id' => $product->id, 'name' => 'Karung 50kg',
        'price' => 850000, 'wholesale_price' => 815000, 'stock_quantity' => '0.0000', 'is_active' => true,
    ]);

    $props = $this->actingAs($admin)->get("/products/{$product->id}/edit")->viewData('page')['props'];
    $row = $props['variants'][0];

    $this->actingAs($admin)
        ->put("/products/{$product->id}", pweEditPayload($product, [
            'has_variants' => true,
            'wholesale_price' => $props['product']['wholesale_price'] ?? 0,
            'variants_json' => json_encode([[
                'id' => $row['id'],
                'name' => $row['name'],
                'price' => $row['price'],
                'wholesale_price' => $row['wholesale_price'] ?? null,
                'stock_quantity' => 0,
                'min_stock_level' => 0,
                'is_active' => true,
            ]]),
        ]))
        ->assertSessionHasNoErrors();

    expect((float) $variant->fresh()->wholesale_price)->toBe(815000.0)
        ->and((float) $product->fresh()->wholesale_price)->toBe(16500.0);
});

it('mengosongkan harga grosir saat modul aktif tetap menghapusnya', function () {
    [$store, $admin] = pweStore();
    $product = pweProduct($store);

    $this->actingAs($admin)
        ->put("/products/{$product->id}", pweEditPayload($product, ['wholesale_price' => 0]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->wholesale_price)->toBeNull();
});

it('mempertahankan harga grosir tersimpan saat produk diedit ketika modul grosir mati', function () {
    [$store, $admin] = pweStore(false);
    $product = pweProduct($store, ['has_variants' => true]);
    $variant = ProductVariant::query()->create([
        'store_id' => $store->id, 'product_id' => $product->id, 'name' => 'Karung 50kg',
        'price' => 850000, 'wholesale_price' => 815000, 'stock_quantity' => '0.0000', 'is_active' => true,
    ]);

    // Modul mati: kolom Harga Grosir tidak tampil, form mengirim 0 / tanpa nilai.
    $this->actingAs($admin)
        ->put("/products/{$product->id}", pweEditPayload($product, [
            'name' => 'Gula Pasir Curah',
            'has_variants' => true,
            'wholesale_price' => 0,
            'variants_json' => json_encode([[
                'id' => $variant->id, 'name' => 'Karung 50kg', 'price' => 850000, 'wholesale_price' => null,
                'stock_quantity' => 0, 'min_stock_level' => 0, 'is_active' => true,
            ]]),
        ]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->name)->toBe('Gula Pasir Curah')
        ->and((float) $product->fresh()->wholesale_price)->toBe(16500.0)
        ->and((float) $variant->fresh()->wholesale_price)->toBe(815000.0);
});
