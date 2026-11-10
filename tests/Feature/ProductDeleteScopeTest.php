<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Grup usaha dengan outlet utama + satu cabang, dan admin yang menjadi anggota
 * kedua outlet (kondisi nyata admin multi-outlet).
 *
 * @return array{0: Store, 1: Store, 2: User}
 */
function makeMultiOutletGroup(): array
{
    $owner = User::factory()->create();

    $org = Organization::query()->create([
        'name' => 'Grup Hapus',
        'slug' => 'grup-hapus-'.uniqid(),
        'owner_user_id' => $owner->id,
    ]);

    $primary = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Outlet Utama',
        'slug' => 'outlet-utama-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_primary' => true,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $branch = Store::query()->create([
        'organization_id' => $org->id,
        'name' => 'Cabang',
        'slug' => 'cabang-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $admin = User::factory()->create([
        'store_id' => $primary->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    // Admin grup terdaftar sebagai anggota semua outlet.
    $primary->members()->attach($admin->id, ['role' => 'admin']);
    $branch->members()->attach($admin->id, ['role' => 'admin']);

    return [$primary, $branch, $admin];
}

function makeOutletProduct(Store $store, string $name): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => 'SKU-'.uniqid(),
        'price' => 10000,
        'stock_quantity' => 5,
        'inventory_type' => InventoryType::SELLABLE,
        'is_active' => true,
    ]);
}

test('hapus massal dari outlet utama tidak menyentuh produk cabang', function () {
    [$primary, $branch, $admin] = makeMultiOutletGroup();

    $menuUtama = makeOutletProduct($primary, 'Kopi Susu');
    $menuCabang = makeOutletProduct($branch, 'Kopi Susu');

    $response = $this->actingAs($admin)->postJson('/products/bulk-delete', [
        // Id cabang sengaja ikut terkirim, meniru pilihan yang tersisa dari outlet lain.
        'ids' => [$menuUtama->id, $menuCabang->id],
    ]);

    $response->assertOk()->assertJsonPath('deleted', 1);

    expect(Product::query()->whereKey($menuUtama->id)->exists())->toBeFalse()
        ->and(Product::query()->whereKey($menuCabang->id)->exists())->toBeTrue();

    expect($response->json('errors'))->toHaveCount(1);
});

test('hapus satuan produk cabang ditolak saat outlet utama sedang aktif', function () {
    [, $branch, $admin] = makeMultiOutletGroup();

    $menuCabang = makeOutletProduct($branch, 'Teh Manis');

    $this->actingAs($admin)
        ->delete('/products/'.$menuCabang->id)
        ->assertForbidden();

    expect(Product::query()->whereKey($menuCabang->id)->exists())->toBeTrue();
});

test('setelah pindah ke cabang, produk cabang baru bisa dihapus', function () {
    [, $branch, $admin] = makeMultiOutletGroup();

    $menuCabang = makeOutletProduct($branch, 'Es Jeruk');

    // Berpindah outlet mengubah toko aktif pengguna.
    $admin->update(['store_id' => $branch->id]);

    $this->actingAs($admin->fresh())
        ->delete('/products/'.$menuCabang->id)
        ->assertRedirect();

    expect(Product::query()->whereKey($menuCabang->id)->exists())->toBeFalse();
});

test('hapus massal mengabaikan id produk milik grup usaha lain', function () {
    [$primary, , $admin] = makeMultiOutletGroup();
    [$otherStore] = makeMultiOutletGroup();

    $milikSendiri = makeOutletProduct($primary, 'Roti');
    $milikOrangLain = makeOutletProduct($otherStore, 'Roti');

    $this->actingAs($admin)
        ->postJson('/products/bulk-delete', ['ids' => [$milikSendiri->id, $milikOrangLain->id]])
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    expect(Product::query()->whereKey($milikOrangLain->id)->exists())->toBeTrue();
});
