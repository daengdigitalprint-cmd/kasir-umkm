<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Tools\Products\SearchProductTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSearchProductStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Cari Produk',
        'slug' => 'toko-cari-produk-'.uniqid(),
        'is_active' => true,
    ]);
}

function createSearchProductProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Produk Cari',
        'price' => 10000,
        'stock_quantity' => 5,
        'is_active' => true,
    ], $overrides));
}

it('mencari produk berdasarkan nama dan menampilkan kategori', function () {
    $store = createSearchProductStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $category = ProductCategory::query()->create([
        'store_id' => $store->id,
        'name' => 'Minuman',
        'slug' => 'minuman-'.uniqid(),
    ]);

    createSearchProductProduct($store, ['name' => 'Air Mineral 900ml', 'product_category_id' => $category->id]);
    createSearchProductProduct($store, ['name' => 'Roti Coklat']);

    $result = (new SearchProductTool)->execute(['query' => 'Air Mineral'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['products'])->toHaveCount(1)
        ->and($result->data['products'][0]['name'])->toBe('Air Mineral 900ml')
        ->and($result->data['products'][0]['category'])->toBe('Minuman');
});

it('memfilter berdasarkan kategori tanpa error relasi', function () {
    $store = createSearchProductStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $category = ProductCategory::query()->create([
        'store_id' => $store->id,
        'name' => 'Minuman',
        'slug' => 'minuman-'.uniqid(),
    ]);

    createSearchProductProduct($store, ['name' => 'Air Mineral 900ml', 'product_category_id' => $category->id]);
    createSearchProductProduct($store, ['name' => 'Air Teh']);

    $result = (new SearchProductTool)->execute(['query' => 'Air', 'category' => 'Minuman'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['products'])->toHaveCount(1)
        ->and($result->data['products'][0]['name'])->toBe('Air Mineral 900ml');
});

it('menampilkan strip untuk produk tanpa kategori', function () {
    $store = createSearchProductStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createSearchProductProduct($store, ['name' => 'Roti Coklat']);

    $result = (new SearchProductTool)->execute(['query' => 'Roti'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['products'][0]['category'])->toBe('-');
});

it('mengembalikan pesan kosong saat produk tidak ditemukan', function () {
    $store = createSearchProductStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new SearchProductTool)->execute(['query' => 'Tidak Ada'], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['products'])->toBeEmpty()
        ->and($result->message)->toContain('Tidak ditemukan');
});
