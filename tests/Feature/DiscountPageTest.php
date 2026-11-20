<?php

use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function discountPageStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Promo',
        'slug' => 'promo-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $admin];
}

test('promo bisa dibatasi ke kategori atau produk tertentu dan diubah', function () {
    [$store, $admin] = discountPageStore();
    ProductCategory::query()->create(['store_id' => $store->id, 'name' => 'Minuman']);
    $teh = Product::query()->create(['store_id' => $store->id, 'name' => 'Teh', 'price' => 5000, 'is_active' => true]);

    $this->actingAs($admin)->post('/discounts', [
        'name' => 'Diskon Minuman', 'type' => 'percentage', 'value' => 10,
        'applies_to' => 'categories', 'applicable_categories' => ['Minuman'],
    ])->assertSessionHasNoErrors();

    $d = Discount::query()->where('store_id', $store->id)->firstOrFail();
    expect($d->applicable_categories)->toBe(['Minuman'])->and($d->applicable_products)->toBeNull();

    $this->actingAs($admin)->patch("/discounts/{$d->id}", [
        'name' => 'Diskon Teh', 'type' => 'fixed_amount', 'value' => 1000, 'is_active' => true,
        'applies_to' => 'products', 'applicable_products' => [$teh->id],
    ])->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->name)->toBe('Diskon Teh')
        ->and($d->applicable_products)->toBe([$teh->id])
        ->and($d->applicable_categories)->toBeNull();
});

test('promo menolak produk toko lain dan pilihan kosong', function () {
    [$store, $admin] = discountPageStore();
    [$other] = discountPageStore();
    $asing = Product::query()->create(['store_id' => $other->id, 'name' => 'Asing', 'price' => 1, 'is_active' => true]);

    $this->actingAs($admin)->post('/discounts', [
        'name' => 'X', 'type' => 'percentage', 'value' => 5,
        'applies_to' => 'products', 'applicable_products' => [$asing->id],
    ])->assertSessionHasErrors('applicable_products.0');

    $this->actingAs($admin)->post('/discounts', [
        'name' => 'X', 'type' => 'percentage', 'value' => 5, 'applies_to' => 'categories',
    ])->assertSessionHasErrors('applicable_categories');

    expect(Discount::query()->where('store_id', $store->id)->count())->toBe(0);
});

test('daftar promo menampilkan status yang sebenarnya', function () {
    [$store, $admin] = discountPageStore();
    $base = ['store_id' => $store->id, 'type' => 'percentage', 'value' => 10, 'is_active' => true];
    Discount::query()->create($base + ['name' => 'Berakhir', 'expires_at' => now()->subDay()]);
    Discount::query()->create($base + ['name' => 'Nanti', 'starts_at' => now()->addDay()]);
    Discount::query()->create($base + ['name' => 'Habis', 'maximum_uses' => 2, 'used_count' => 2]);
    Discount::query()->create(['is_active' => false] + $base + ['name' => 'Mati']);
    Discount::query()->create($base + ['name' => 'Jalan']);

    $this->actingAs($admin)->get('/discounts')->assertInertia(function ($page) {
        $status = collect($page->toArray()['props']['discounts'])->pluck('status', 'name');
        expect($status->all())->toMatchArray([
            'Berakhir' => 'ended', 'Nanti' => 'scheduled', 'Habis' => 'quota', 'Mati' => 'off', 'Jalan' => 'running',
        ]);
    });
});
