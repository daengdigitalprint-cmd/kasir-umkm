<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\User;
use App\Support\InventoryType;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * Kemudahan Tim & Outlet: pratinjau salin produk, karyawan tanpa email (PIN),
 * hak akses ke semua outlet grup, dan daftar langkah setelah outlet dibuat.
 */
function easeGroup(string $pinMode = 'email'): array
{
    $org = Organization::query()->create(['name' => 'Grup Mudah', 'slug' => 'grup-mudah-'.uniqid()]);
    $make = fn (string $name, string $type = StoreLocationType::OUTLET) => Store::query()->create([
        'organization_id' => $org->id,
        'name' => $name,
        'slug' => 'mudah-'.uniqid(),
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
        'cashier_login_mode' => $pinMode,
    ]);
    $a = $make('Outlet A');
    $b = $make('Outlet B');

    $owner = User::factory()->create(['store_id' => $a->id, 'role' => 'owner', 'email_verified_at' => now()]);
    $owner->stores()->attach([$a->id => ['role' => 'owner'], $b->id => ['role' => 'owner']]);

    return [$org, $a, $b, $owner];
}

function easeProduct(Store $store, string $name, string $sku, string $type = InventoryType::RAW_MATERIAL): Product
{
    return Product::query()->create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => $sku,
        'price' => 0,
        'inventory_type' => $type,
        'unit' => 'pcs',
        'is_active' => true,
    ]);
}

// ---------- 5. Pratinjau salin produk ----------

test('pratinjau salin produk menghitung baru vs sudah ada tanpa mengubah data', function () {
    [, $a, $b, $owner] = easeGroup();
    easeProduct($a, 'Gula', 'GULA');
    easeProduct($a, 'Kopi', 'KOPI');
    easeProduct($a, 'Susu', 'SUSU');
    easeProduct($b, 'Gula lama', 'GULA'); // cocok lewat SKU
    easeProduct($b, 'Kopi', 'KOPI-B'); // cocok lewat nama + jenis

    $before = Product::query()->count();

    $this->actingAs($owner)->postJson(route('manage-products.preview'), [
        'source_store_id' => $a->id,
        'copy_type' => 'raw_material',
        'outlet_ids' => [$b->id],
    ])->assertOk()->assertJson(['outlets' => [['id' => $b->id, 'new' => 1, 'existing' => 2]]]);

    expect(Product::query()->count())->toBe($before);
});

test('pratinjau menolak sumber di luar grup', function () {
    [, , $b, $owner] = easeGroup();
    [, $foreign] = easeGroup();

    $this->actingAs($owner)->postJson(route('manage-products.preview'), [
        'source_store_id' => $foreign->id,
        'copy_type' => 'all',
        'outlet_ids' => [$b->id],
    ])->assertStatus(422);
});

// ---------- 6. Karyawan tanpa email (PIN) ----------

test('pemilik dapat membuat karyawan PIN tanpa email bila mode PIN aktif', function () {
    [, $a, , $owner] = easeGroup('pin');

    $this->actingAs($owner)->post("/stores/{$a->slug}/members/create", [
        'name' => 'Budi Kasir',
        'phone' => '0812-3456-7890',
        'login_method' => 'pin',
        'pin' => '5823',
        'role' => 'cashier',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $budi = User::query()->where('name', 'Budi Kasir')->firstOrFail();
    expect($budi->isPinOnly())->toBeTrue()
        ->and($budi->phone)->toBe('0812-3456-7890')
        ->and($budi->can_login_pos)->toBeTrue()
        ->and(Hash::check('5823', $budi->cashier_pin_hash))->toBeTrue()
        ->and($a->members()->whereKey($budi->id)->first()->pivot->role)->toBe('cashier');

    // Email internal tidak ditampilkan di halaman Tim & Akses.
    $this->actingAs($owner)->get(route('manage-staff'))
        ->assertInertia(fn ($page) => $page->where('store.team_members', fn ($members) => collect($members)
            ->contains(fn ($m) => $m['name'] === 'Budi Kasir' && $m['email'] === null && $m['is_pin_only'] === true)));
});

test('akun PIN ditolak bila mode PIN belum aktif', function () {
    [, $a, , $owner] = easeGroup('email');

    $this->actingAs($owner)->post("/stores/{$a->slug}/members/create", [
        'name' => 'Sari',
        'login_method' => 'pin',
        'pin' => '5823',
        'role' => 'cashier',
    ])->assertSessionHas('error');

    expect(User::query()->where('name', 'Sari')->exists())->toBeFalse();
});

test('akun PIN tidak bisa untuk peran pengelola dan PIN mudah ditolak', function () {
    [, $a, , $owner] = easeGroup('pin');

    $this->actingAs($owner)->post("/stores/{$a->slug}/members/create", [
        'name' => 'Manajer PIN', 'login_method' => 'pin', 'pin' => '5823', 'role' => 'manager',
    ])->assertSessionHasErrors('role');

    $this->actingAs($owner)->post("/stores/{$a->slug}/members/create", [
        'name' => 'PIN Mudah', 'login_method' => 'pin', 'pin' => '1234', 'role' => 'cashier',
    ])->assertSessionHasErrors('pin');

    expect(User::query()->whereIn('name', ['Manajer PIN', 'PIN Mudah'])->exists())->toBeFalse();
});

test('edit akun PIN: ganti PIN dan HP, tidak bisa dijadikan admin', function () {
    [, $a, , $owner] = easeGroup('pin');
    $this->actingAs($owner)->post("/stores/{$a->slug}/members/create", [
        'name' => 'Rina', 'login_method' => 'pin', 'pin' => '5823', 'role' => 'cashier',
    ]);
    $rina = User::query()->where('name', 'Rina')->firstOrFail();

    $this->actingAs($owner)->patch("/stores/{$a->slug}/members/{$rina->id}", [
        'name' => 'Rina', 'phone' => '0811111111', 'pin' => '7391', 'role' => 'warehouse',
    ])->assertSessionHasNoErrors();

    $rina->refresh();
    expect($rina->phone)->toBe('0811111111')
        ->and(Hash::check('7391', $rina->cashier_pin_hash))->toBeTrue()
        ->and($a->members()->whereKey($rina->id)->first()->pivot->role)->toBe('warehouse');

    $this->actingAs($owner)->patch("/stores/{$a->slug}/members/{$rina->id}", [
        'name' => 'Rina', 'role' => 'admin',
    ])->assertSessionHasErrors('role');
});

// ---------- 7. Izin ke semua outlet & langkah setelah outlet dibuat ----------

test('hak akses menu bisa diterapkan ke semua outlet grup yang dikelola', function () {
    [, $a, $b, $owner] = easeGroup();

    $this->actingAs($owner)->postJson('/menu-permissions', [
        'role' => 'cashier',
        'menus' => ['dashboard', 'pos'],
        'apply_to_all' => true,
    ])->assertOk()->assertJson(['store_count' => 2]);

    foreach ([$a, $b] as $store) {
        expect(RoleMenuPermission::storeOverride('cashier', $store->fresh()))->toContain('dashboard', 'pos')
            ->not->toContain('stock_overview');
    }
});

test('tanpa opsi semua outlet, hanya toko aktif yang berubah', function () {
    [, $a, $b, $owner] = easeGroup();

    $this->actingAs($owner)->postJson('/menu-permissions', [
        'role' => 'cashier',
        'menus' => ['dashboard'],
    ])->assertOk()->assertJson(['store_count' => 1]);

    expect(RoleMenuPermission::storeOverride('cashier', $b->fresh()))->toBeNull();
});

test('outlet baru di grup memunculkan daftar langkah berikutnya', function () {
    [$org, $a, , $owner] = easeGroup();
    $owner->stores()->updateExistingPivot($a->id, ['role' => 'admin']);

    $this->actingAs($owner)->post(route('stores.store'), [
        'name' => 'Outlet C',
        'currency' => 'IDR',
        'business_type' => 'general',
        'location_type' => StoreLocationType::OUTLET,
        'timezone' => 'Asia/Jakarta',
        'organization_mode' => 'join',
        'organization_id' => $org->id,
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas('new_store_checklist', fn ($c) => $c['name'] === 'Outlet C' && $c['has_products'] === false)
        ->assertSessionMissing('show_product_modal');
});
