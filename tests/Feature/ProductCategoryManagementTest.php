<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function categoryTestStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Kategori',
        'slug' => 'toko-kategori-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
}

function categoryTestAdmin(Store $store): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return $user;
}

function categoryTestProduct(Store $store, array $attrs = []): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk '.uniqid(),
        'price' => 5000,
        'stock_quantity' => 10,
        'min_stock_level' => 0,
        'is_active' => true,
        ...$attrs,
    ]);
}

test('nama kategori produk selalu mengikuti kategori terpilih', function () {
    $store = categoryTestStore();
    $minuman = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Minuman Uji']);
    $makanan = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Makanan Uji']);

    $product = categoryTestProduct($store, ['product_category_id' => $minuman->id]);
    expect($product->fresh()->category)->toBe('Minuman Uji');

    $product->update(['product_category_id' => $makanan->id]);
    expect($product->fresh()->category)->toBe('Makanan Uji');
});

test('migration mengisi ulang nama kategori yang kosong', function () {
    $store = categoryTestStore();
    $cat = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Bahan Uji']);
    $product = categoryTestProduct($store, ['product_category_id' => $cat->id]);
    DB::table('products')->where('id', $product->id)->update(['category' => null]);

    $migration = require database_path('migrations/2026_11_20_120000_sync_product_category_names.php');
    $migration->up();

    expect($product->fresh()->category)->toBe('Bahan Uji');
});

test('daftar produk menampilkan nama dari kategori terpilih', function () {
    $store = categoryTestStore();
    $cat = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Snack Uji', 'sort_order' => 1]);
    $product = categoryTestProduct($store, ['product_category_id' => $cat->id]);
    DB::table('products')->where('id', $product->id)->update(['category' => null]);

    $this->actingAs(categoryTestAdmin($store))->get('/products')
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.category', 'Snack Uji')
            ->where('categories', fn ($names) => collect($names)->contains('Snack Uji')));
});

test('urutan kategori bisa diatur ulang dan kategori baru di paling akhir', function () {
    $store = categoryTestStore();
    $this->actingAs(categoryTestAdmin($store));
    $a = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'A Uji', 'sort_order' => 1]);
    $b = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'B Uji', 'sort_order' => 2]);

    $this->post('/product-categories/reorder', ['ids' => [$b->id, $a->id]])->assertRedirect();
    expect($b->fresh()->sort_order)->toBeLessThan($a->fresh()->sort_order);

    $this->post('/product-categories', ['name' => 'C Uji'])->assertSessionHasNoErrors();
    $c = ProductCategory::query()->where('store_id', $store->id)->where('name', 'C Uji')->first();
    expect($c->sort_order)->toBeGreaterThan(ProductCategory::query()->where('store_id', $store->id)->whereKeyNot($c->id)->max('sort_order'));

    // Nama kembar ditolak dengan pesan, bukan error server.
    $this->post('/product-categories', ['name' => 'C Uji'])->assertSessionHasErrors('name');
});

test('hapus kategori bisa memindahkan produknya ke kategori lain', function () {
    $store = categoryTestStore();
    $old = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Lama Uji']);
    $new = ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Baru Uji']);
    $product = categoryTestProduct($store, ['product_category_id' => $old->id]);

    $this->actingAs(categoryTestAdmin($store))
        ->delete("/product-categories/{$old->id}", ['move_to' => $new->id])
        ->assertSessionHas('success');

    expect(ProductCategory::query()->find($old->id))->toBeNull()
        ->and($product->fresh()->product_category_id)->toBe($new->id)
        ->and($product->fresh()->category)->toBe('Baru Uji');
});
