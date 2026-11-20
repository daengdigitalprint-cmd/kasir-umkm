<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Staf (gudang, kasir, dapur) tidak boleh menyentuh data/aksi milik pemilik:
 * backup seluruh data toko, langganan & pembayaran, serta menambah outlet ke grup usaha.
 */
function makeStaffAccessOrg(): array
{
    $org = Organization::query()->create(['name' => 'Grup Uji Akses', 'slug' => 'grup-uji-akses-'.uniqid()]);

    $store = Store::query()->create([
        'name' => 'Outlet Uji Akses',
        'slug' => 'akses-staf-'.uniqid(),
        'organization_id' => $org->id,
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $make = function (string $role) use ($store): User {
        $user = User::factory()->create([
            'store_id' => $store->id,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
        $user->stores()->attach($store->id, ['role' => $role]);

        return $user;
    };

    return [$org, $store, $make];
}

dataset('staf', ['warehouse', 'cashier', 'kitchen']);

test('staf tidak dapat mengunduh, membuat, atau melihat riwayat backup toko', function (string $role) {
    [, , $make] = makeStaffAccessOrg();
    $staff = $make($role);

    $this->actingAs($staff)->post(route('backup.download'))->assertRedirect(route('dashboard'));
    $this->actingAs($staff)->post(route('backup.create'))->assertRedirect(route('dashboard'));
    $this->actingAs($staff)->get(route('backup.history'))->assertRedirect(route('dashboard'));
})->with('staf');

test('admin toko tetap dapat mengunduh backup', function () {
    [, , $make] = makeStaffAccessOrg();
    $admin = $make('admin');

    $this->actingAs($admin)->post(route('backup.download'))
        ->assertOk()
        ->assertJsonStructure(['backup_version', 'store', 'transactions']);
});

test('staf tidak dapat membuka langganan atau pembayaran', function (string $role) {
    [, , $make] = makeStaffAccessOrg();
    $staff = $make($role);

    $this->actingAs($staff)->get(route('subscription.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
    $this->actingAs($staff)->get(route('subscription.checkout-outlet'))->assertRedirect(route('dashboard'));
    $this->actingAs($staff)->get(route('payment'))->assertRedirect(route('dashboard'));
    $this->actingAs($staff)->post(route('subscription.apply-voucher'), ['code' => 'X'])->assertRedirect(route('dashboard'));
})->with('staf');

test('pesan add-on yang mengarah ke Langganan tetap terbaca oleh staf', function () {
    [, , $make] = makeStaffAccessOrg();
    $staff = $make('warehouse');

    $this->actingAs($staff)
        ->withSession(['warning' => 'Paket Gudang Pusat belum aktif.'])
        ->get(route('subscription.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning', 'Paket Gudang Pusat belum aktif. Hubungi pemilik atau admin toko.');
});

test('admin toko tetap dapat membuka halaman langganan', function () {
    [, , $make] = makeStaffAccessOrg();
    $admin = $make('admin');

    $this->actingAs($admin)->get(route('subscription.index'))->assertOk();
});

test('staf tidak dapat membuka form atau membuat outlet baru di grup usaha', function (string $role) {
    [$org, , $make] = makeStaffAccessOrg();
    $staff = $make($role);

    $this->actingAs($staff)->get(route('stores.create'))->assertRedirect(route('dashboard'));

    $before = Store::count();
    $this->actingAs($staff)->post(route('stores.store'), [
        'name' => 'Outlet Liar',
        'currency' => 'IDR',
        'business_type' => 'general',
        'location_type' => StoreLocationType::OUTLET,
        'organization_mode' => 'join',
        'organization_id' => $org->id,
    ])->assertRedirect(route('dashboard'));

    expect(Store::count())->toBe($before);
})->with('staf');

test('staf akun lama tanpa pivot tetap tidak dapat membuat outlet', function () {
    [, $store] = makeStaffAccessOrg();
    $legacy = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'warehouse',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($legacy)->get(route('stores.create'))->assertRedirect(route('dashboard'));
});

test('admin toko tetap dapat membuka form tambah outlet', function () {
    [, , $make] = makeStaffAccessOrg();
    $admin = $make('admin');

    $this->actingAs($admin)->get(route('stores.create'))->assertOk();
});

test('pengguna baru tanpa toko tetap dapat onboarding', function () {
    $user = User::factory()->create(['store_id' => null, 'email_verified_at' => now()]);

    $this->actingAs($user)->get(route('stores.create'))->assertOk();
});
