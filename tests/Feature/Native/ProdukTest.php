<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Produk\Index as ProdukIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Mobile\Platform;
use Native\Mobile\Testing\Native;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::query()->create([
        'name' => 'Toko Test Wave 2',
        'code' => 'TTW2',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Budi Admin',
        'email' => 'budi@toko.com',
        'role' => 'cashier',
        'store_id' => $this->store->id,
    ]);

    $this->category = ProductCategory::query()->create([
        'store_id' => $this->store->id,
        'name' => 'Makanan',
        'slug' => 'makanan',
        'sort_order' => 1,
    ]);
});

test('halaman produk menampilkan statistik dan daftar produk aktif', function () {
    $this->actingAs($this->user);

    $activeProduct = Product::query()->create([
        'store_id' => $this->store->id,
        'product_category_id' => $this->category->id,
        'name' => 'Nasi Goreng Spesial',
        'sku' => 'NGS-001',
        'price' => 25000,
        'stock_quantity' => 50,
        'min_stock_level' => 5,
        'is_active' => true,
    ]);

    $inactiveProduct = Product::query()->create([
        'store_id' => $this->store->id,
        'product_category_id' => $this->category->id,
        'name' => 'Menu Tidak Aktif',
        'sku' => 'MTA-001',
        'price' => 10000,
        'stock_quantity' => 0,
        'is_active' => false,
    ]);

    $component = Native::test(ProdukIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSee('Nasi Goreng Spesial')
        ->assertDontSee('Menu Tidak Aktif')
        ->assertSet('totalProducts', 1);
});

test('halaman produk menandai produk dengan stok menipis', function () {
    $this->actingAs($this->user);

    Product::query()->create([
        'store_id' => $this->store->id,
        'product_category_id' => $this->category->id,
        'name' => 'Produk Hampir Habis',
        'sku' => 'PHH-001',
        'price' => 15000,
        'stock_quantity' => 2,
        'min_stock_level' => 5,
        'is_active' => true,
    ]);

    $component = Native::test(ProdukIndex::class, layout: TabsLayout::class, platform: Platform::ANDROID);

    $component->assertSet('lowStockCount', 1);
});
