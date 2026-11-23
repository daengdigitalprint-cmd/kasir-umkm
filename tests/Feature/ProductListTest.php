<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function productListStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Daftar',
        'slug' => 'toko-daftar-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'business_type' => 'fnb',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

function productListAdmin(Store $store): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return $user;
}

function productListItem(Store $store, array $attrs = []): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk '.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 50,
        'min_stock_level' => 5,
        'track_stock' => true,
        'is_active' => true,
        'inventory_type' => 'sellable',
        ...$attrs,
    ]);
}

test('hitungan ringkasan memakai seluruh toko dan filter bisa dipakai', function () {
    $store = productListStore();
    productListItem($store, ['name' => 'Kopi']);
    productListItem($store, ['name' => 'Gula', 'stock_quantity' => 2, 'inventory_type' => 'raw_material']);
    productListItem($store, ['name' => 'Teh lama', 'is_active' => false]);
    // Menu tanpa hitung stok tidak dianggap stok rendah walau stoknya 0.
    productListItem($store, ['name' => 'Nasi Goreng', 'stock_quantity' => 0, 'track_stock' => false]);

    $this->actingAs(productListAdmin($store));

    $this->get('/products')->assertInertia(fn ($page) => $page
        ->where('counts.all', 4)
        ->where('counts.low', 1)
        ->where('counts.inactive', 1));

    $this->get('/products?stock=low')->assertInertia(fn ($page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Gula'));

    $this->get('/products?status=inactive')->assertInertia(fn ($page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Teh lama'));

    $this->get('/products?sort=name&dir=asc')->assertInertia(fn ($page) => $page
        ->where('products.data.0.name', 'Gula')
        ->where('filters.sort', 'name'));
});

test('produk yang pernah terjual ditandai has_sales', function () {
    $store = productListStore();
    $admin = productListAdmin($store);
    $sold = productListItem($store, ['name' => 'Laris']);
    productListItem($store, ['name' => 'Baru']);

    $txId = DB::table('transactions')->insertGetId([
        'store_id' => $store->id, 'user_id' => $admin->id, 'transaction_number' => 'TXN-'.uniqid(),
        'status' => 'completed', 'payment_method' => 'cash', 'subtotal' => 10000, 'discount_amount' => 0,
        'tax_amount' => 0, 'total_amount' => 10000, 'amount_paid' => 10000, 'change_amount' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('transaction_items')->insert([
        'transaction_id' => $txId, 'product_id' => $sold->id, 'product_name' => 'Laris', 'quantity' => 1,
        'unit_price' => 10000, 'total_price' => 10000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)->get('/products?sort=name&dir=asc')->assertInertia(fn ($page) => $page
        ->where('products.data.0.name', 'Baru')
        ->where('products.data.0.has_sales', false)
        ->where('products.data.1.has_sales', true));
});

test('produk bisa diaktifkan dan dinonaktifkan dari daftar', function () {
    $store = productListStore();
    $product = productListItem($store);

    $this->actingAs(productListAdmin($store))
        ->patch("/products/{$product->id}/active", ['is_active' => false])
        ->assertSessionHas('success');

    expect($product->fresh()->is_active)->toBeFalse();
});

test('aksi massal hanya mengubah produk di toko aktif', function () {
    $store = productListStore();
    $other = productListStore();
    $mine = productListItem($store);
    $theirs = productListItem($other);
    $category = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Kategori Uji']);

    $this->actingAs(productListAdmin($store));

    $this->post('/products/bulk-update', ['ids' => [$mine->id, $theirs->id], 'action' => 'deactivate'])
        ->assertSessionHas('success');
    expect($mine->fresh()->is_active)->toBeFalse()
        ->and($theirs->fresh()->is_active)->toBeTrue();

    $this->post('/products/bulk-update', ['ids' => [$mine->id], 'action' => 'category', 'product_category_id' => $category->id])
        ->assertSessionHas('success');
    expect($mine->fresh()->product_category_id)->toBe($category->id)
        ->and($mine->fresh()->category)->toBe('Kategori Uji');
});

test('stok habis dipisah dari stok rendah, termasuk stok minus', function () {
    $store = productListStore();
    productListItem($store, ['name' => 'Aman']);
    productListItem($store, ['name' => 'Menipis', 'stock_quantity' => 3]);
    productListItem($store, ['name' => 'Kosong', 'stock_quantity' => 0, 'min_stock_level' => 0]);
    productListItem($store, ['name' => 'Minus', 'stock_quantity' => -4]);
    productListItem($store, ['name' => 'Tanpa batas', 'stock_quantity' => 0, 'unlimited_stock' => true]);
    productListItem($store, ['name' => 'Menu resep', 'stock_quantity' => -2, 'track_stock' => false]);

    // Produk bervarian memakai total stok semua varian (kolom stok produk).
    productListItem($store, ['name' => 'Kaos', 'has_variants' => true, 'stock_quantity' => 0]);

    $this->actingAs(productListAdmin($store));

    $this->get('/products')->assertInertia(fn ($page) => $page
        ->where('counts.low', 1)
        ->where('counts.out', 3));

    $this->get('/products?stock=out&sort=name&dir=asc')->assertInertia(fn ($page) => $page
        ->where('filters.stock', 'out')
        ->has('products.data', 3)
        ->where('products.data.0.name', 'Kaos')
        ->where('products.data.1.name', 'Kosong')
        ->where('products.data.2.name', 'Minus'));

    $this->get('/products?stock=low')->assertInertia(fn ($page) => $page
        ->has('products.data', 1)
        ->where('products.data.0.name', 'Menipis'));
});
