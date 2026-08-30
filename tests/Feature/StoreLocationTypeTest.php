<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function makeOrganization(User $owner): Organization
{
    return Organization::query()->create([
        'name' => 'Grup Uji',
        'slug' => 'grup-uji-'.uniqid(),
        'owner_user_id' => $owner->id,
    ]);
}

test('toko lama tanpa tipe lokasi dianggap outlet', function () {
    $store = Store::query()->create([
        'name' => 'Outlet Lama',
        'slug' => 'outlet-lama-'.uniqid(),
        'is_active' => true,
    ]);

    expect($store->location_type)->toBe(StoreLocationType::OUTLET)
        ->and($store->isOutlet())->toBeTrue()
        ->and($store->isCentralWarehouse())->toBeFalse();
});

test('gudang pusat dapat dibedakan dari outlet', function () {
    $owner = User::factory()->create();
    $org = makeOrganization($owner);

    $warehouse = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Gudang Pusat',
        'slug' => 'gudang-pusat-'.uniqid(),
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
    ]);

    $outlet = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Outlet A',
        'slug' => 'outlet-a-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
    ]);

    expect($warehouse->isCentralWarehouse())->toBeTrue()
        ->and($outlet->isOutlet())->toBeTrue()
        ->and(StoreLocationType::centralWarehouseFor($org->id)?->id)->toBe($warehouse->id)
        ->and(Store::query()->centralWarehouse()->count())->toBe(1)
        ->and(Store::query()->outlet()->count())->toBe(1);
});

test('satu grup usaha hanya boleh punya satu gudang pusat', function () {
    $owner = User::factory()->create();
    $org = makeOrganization($owner);

    Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Gudang Pusat',
        'slug' => 'gudang-pusat-'.uniqid(),
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
    ]);

    StoreLocationType::assertOrganizationHasNoCentralWarehouse($org->id);
})->throws(ValidationException::class);

test('gudang pusat wajib berada dalam grup usaha', function () {
    StoreLocationType::assertOrganizationHasNoCentralWarehouse(null);
})->throws(ValidationException::class);

test('stok hanya mengalir antar lokasi dalam grup usaha yang sama', function () {
    $owner = User::factory()->create();
    $orgA = makeOrganization($owner);
    $orgB = makeOrganization($owner);

    $warehouse = Store::query()->create([
        'organization_id' => $orgA->id,
        'name' => 'Gudang Pusat',
        'slug' => 'gudang-'.uniqid(),
        'location_type' => StoreLocationType::CENTRAL_WAREHOUSE,
        'is_active' => true,
    ]);

    $sameGroupOutlet = Store::query()->create([
        'organization_id' => $orgA->id,
        'name' => 'Outlet Segrup',
        'slug' => 'outlet-segrup-'.uniqid(),
        'is_active' => true,
    ]);

    $otherGroupOutlet = Store::query()->create([
        'organization_id' => $orgB->id,
        'name' => 'Outlet Grup Lain',
        'slug' => 'outlet-lain-'.uniqid(),
        'is_active' => true,
    ]);

    $noGroupOutlet = Store::query()->create([
        'name' => 'Outlet Tanpa Grup',
        'slug' => 'outlet-tanpa-grup-'.uniqid(),
        'is_active' => true,
    ]);

    expect(StoreLocationType::sharesOrganization($warehouse, $sameGroupOutlet))->toBeTrue()
        ->and(StoreLocationType::sharesOrganization($warehouse, $otherGroupOutlet))->toBeFalse()
        ->and(StoreLocationType::sharesOrganization($warehouse, $noGroupOutlet))->toBeFalse();
});

test('nilai tipe lokasi tidak dikenal dinormalisasi menjadi outlet', function () {
    expect(StoreLocationType::normalize('gudang'))->toBe(StoreLocationType::OUTLET)
        ->and(StoreLocationType::normalize(null))->toBe(StoreLocationType::OUTLET)
        ->and(StoreLocationType::normalize(StoreLocationType::CENTRAL_WAREHOUSE))
        ->toBe(StoreLocationType::CENTRAL_WAREHOUSE);
});
