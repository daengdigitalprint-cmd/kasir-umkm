<?php

use App\Models\Store;
use App\Models\User;
use App\Support\BarbershopFeatures;
use App\Support\BusinessTypePresets;
use App\Support\StoreDefaultProductCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeBarbershopStore(): Store
{
    return Store::query()->create([
        'name' => 'Barber Baru',
        'slug' => 'barber-baru-'.uniqid(),
        'business_type' => 'barbershop',
        'is_active' => true,
    ]);
}

test('preset barbershop terdaftar di daftar jenis usaha', function () {
    expect(BusinessTypePresets::TYPES['barbershop'] ?? null)->toBe('Barbershop / Salon')
        ->and(BusinessTypePresets::isValidType('barbershop'))->toBeTrue();
});

test('katalog barbershop menyediakan kategori layanan dan satuan jasa', function () {
    $catalog = BusinessTypePresets::catalog('barbershop');

    expect($catalog['categories'])->toContain('Layanan Potong', 'Produk Retail', 'Paket')
        ->and($catalog['units'])->toContain('jasa', 'paket', 'pcs');
});

test('katalog jenis usaha lain tidak terpengaruh penambahan barbershop', function () {
    expect(BusinessTypePresets::catalog('laundry')['categories'])->toContain('Cuci Kiloan')
        ->and(BusinessTypePresets::catalog('fnb')['units'])->toContain('porsi')
        ->and(BusinessTypePresets::catalog('general')['categories'])->toContain('Umum');
});

test('BarbershopFeatures hanya aktif untuk business_type barbershop', function () {
    $barbershop = makeBarbershopStore();
    $laundry = Store::query()->create([
        'name' => 'Laundry Lama',
        'slug' => 'laundry-lama-'.uniqid(),
        'business_type' => 'laundry',
        'is_active' => true,
    ]);
    $legacy = Store::query()->create([
        'name' => 'Toko Umum',
        'slug' => 'toko-umum-'.uniqid(),
        'is_active' => true,
    ]);

    expect(BarbershopFeatures::enabled($barbershop))->toBeTrue()
        ->and(BarbershopFeatures::enabled($laundry))->toBeFalse()
        ->and(BarbershopFeatures::enabled($legacy))->toBeFalse()
        ->and(BarbershopFeatures::enabled(null))->toBeFalse();
});

test('toko barbershop mendapat kategori default layanan', function () {
    $store = makeBarbershopStore();

    $names = $store->productCategories()->pluck('name')->all();

    expect($names)->toContain('Layanan Potong', 'Prewash & Styling', 'Perawatan Rambut', 'Paket')
        ->and($names)->not->toContain('Umum');
});

test('ensureForStore idempotent untuk toko barbershop', function () {
    $store = makeBarbershopStore();

    StoreDefaultProductCategories::ensureForStore($store);
    StoreDefaultProductCategories::ensureForStore($store);

    expect($store->productCategories()->count())->toBe(
        count(BusinessTypePresets::catalog('barbershop')['categories']),
    );
});

test('toko barbershop dapat dibuat oleh pemilik saat registrasi', function () {
    $user = User::factory()->create();
    $store = Store::query()->create([
        'name' => 'Barbershop Uji',
        'slug' => 'barbershop-uji-'.uniqid(),
        'business_type' => 'barbershop',
        'is_active' => true,
        'created_by' => null,
    ]);

    expect($store->exists)->toBeTrue()
        ->and($store->business_type)->toBe('barbershop')
        ->and($user)->not->toBeNull();
});
