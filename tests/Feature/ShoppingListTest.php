<?php

/*
 * F2 — Daftar Belanja otomatis.
 * Barang dengan stok <= Stok Minimum (Stok Minimum > 0) masuk daftar dengan
 * saran jumlah beli; "Jadikan Pembelian" mengisi form Pembelian.
 */

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function shoppingStore(array $overrides = []): array
{
    $store = Store::query()->create(array_merge([
        'name' => 'Warung Belanja',
        'slug' => 'belanja-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
    ], $overrides));

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

function shoppingProduct(Store $store, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => 'Gula Pasir',
        'price' => 18000,
        'cost_price' => 15000,
        'stock_quantity' => 2,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'unlimited_stock' => false,
        'is_active' => true,
    ], $overrides));
}

it('menghitung saran beli sampai 2 x Stok Minimum', function () {
    expect(ShoppingList::suggestedQuantity(2, 5, false))->toBe(8.0)
        ->and(ShoppingList::suggestedQuantity(0, 5, false))->toBe(10.0)
        ->and(ShoppingList::suggestedQuantity(-3, 5, false))->toBe(13.0)
        ->and(ShoppingList::suggestedQuantity(9.5, 5, false))->toBe(1.0)
        ->and(ShoppingList::suggestedQuantity(1.25, 2, true))->toBe(2.75)
        ->and(ShoppingList::suggestedQuantity(2.4, 1.5, false))->toBe(1.0);
});

it('hanya memuat barang yang stoknya sampai Stok Minimum', function () {
    [$store] = shoppingStore();

    $low = shoppingProduct($store, ['name' => 'Gula', 'stock_quantity' => 2]);
    $equal = shoppingProduct($store, ['name' => 'Kopi', 'stock_quantity' => 5]);
    shoppingProduct($store, ['name' => 'Aman', 'stock_quantity' => 6]);
    shoppingProduct($store, ['name' => 'Tanpa minimum', 'stock_quantity' => 0, 'min_stock_level' => 0]);
    shoppingProduct($store, ['name' => 'Tak terbatas', 'stock_quantity' => 0, 'unlimited_stock' => true]);
    shoppingProduct($store, ['name' => 'Tidak dilacak', 'stock_quantity' => 0, 'track_stock' => false]);
    shoppingProduct($store, ['name' => 'Nonaktif', 'stock_quantity' => 0, 'is_active' => false]);

    [$other] = shoppingStore();
    shoppingProduct($other, ['name' => 'Toko lain', 'stock_quantity' => 0]);

    $items = ShoppingList::items($store);

    expect($items->pluck('product_id')->all())->toEqualCanonicalizing([$low->id, $equal->id])
        ->and($items->firstWhere('product_id', $low->id)['suggested_quantity'])->toBe(8.0)
        ->and($items->firstWhere('product_id', $equal->id)['suggested_quantity'])->toBe(5.0);
});

it('mendahulukan barang yang sudah habis', function () {
    [$store] = shoppingStore();
    shoppingProduct($store, ['name' => 'A menipis', 'stock_quantity' => 4]);
    $habis = shoppingProduct($store, ['name' => 'Z habis', 'stock_quantity' => 0]);

    expect(ShoppingList::items($store)->first()['product_id'])->toBe($habis->id);
});

it('memuat varian yang menipis dan supplier pembelian terakhir', function () {
    [$store, $user] = shoppingStore();

    $parent = shoppingProduct($store, ['name' => 'Kaos', 'has_variants' => true, 'stock_quantity' => 0, 'min_stock_level' => 0]);
    $variant = ProductVariant::query()->create([
        'store_id' => $store->id,
        'product_id' => $parent->id,
        'name' => 'Merah L',
        'price' => 50000,
        'cost_price' => 30000,
        'stock_quantity' => 1,
        'min_stock_level' => 3,
        'is_active' => true,
    ]);

    $supplier = Supplier::query()->create(['store_id' => $store->id, 'name' => 'CV Konveksi']);
    $purchase = Purchase::query()->create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'purchase_number' => 'PB-TEST-1',
        'purchased_at' => now()->subDay(),
    ]);
    $purchase->lines()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Kaos',
        'quantity' => 5,
        'unit_cost' => 30000,
        'subtotal' => 150000,
    ]);

    $row = ShoppingList::items($store)->sole();

    expect($row['product_variant_id'])->toBe($variant->id)
        ->and($row['variant_name'])->toBe('Merah L')
        ->and($row['suggested_quantity'])->toBe(5.0)
        ->and($row['unit_cost'])->toBe(30000.0)
        ->and($row['last_supplier_name'])->toBe('CV Konveksi');
});

it('menampilkan halaman Daftar Belanja', function () {
    [$store, $user] = shoppingStore();
    $product = shoppingProduct($store);

    $this->actingAs($user)->get('/shopping-list')->assertOk()->assertInertia(
        fn ($page) => $page->component('ShoppingList/Index')
            ->where('canPurchase', true)
            ->has('items', 1)
            ->where('items.0.product_id', $product->id)
    );
});

it('mengisi form Pembelian dari Daftar Belanja dan membuang barang toko lain', function () {
    [$store, $user] = shoppingStore();
    $product = shoppingProduct($store);
    $supplier = Supplier::query()->create(['store_id' => $store->id, 'name' => 'Toko Grosir']);

    [$other] = shoppingStore();
    $foreign = shoppingProduct($other);

    $query = http_build_query([
        'items' => [$product->id.'::8', $foreign->id.'::3', 'sampah'],
        'supplier_id' => $supplier->id,
    ]);

    $this->actingAs($user)->get('/purchases/create?'.$query)->assertOk()->assertInertia(
        fn ($page) => $page->component('Purchases/Create')
            ->where('prefill.supplier_id', $supplier->id)
            ->has('prefill.lines', 1)
            ->where('prefill.lines.0.product_id', $product->id)
            ->where('prefill.lines.0.quantity', 8)
    );
});

it('tanpa parameter Daftar Belanja form Pembelian kosong seperti biasa', function () {
    [$store, $user] = shoppingStore();
    shoppingProduct($store);

    $this->actingAs($user)->get('/purchases/create')->assertOk()->assertInertia(
        fn ($page) => $page->component('Purchases/Create')->where('prefill', null)
    );
});
