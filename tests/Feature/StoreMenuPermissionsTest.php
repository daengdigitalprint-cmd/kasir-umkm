<?php

use App\Models\RoleMenuPermission;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: Store, 1: User, 2: User} toko, admin, kasir */
function makeMenuPermissionStore(string $name): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'menu-perm-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $admin, $cashier];
}

beforeEach(function () {
    // Default sistem untuk kasir (dipakai bersama semua toko).
    foreach (['dashboard', 'pos', 'pos_transactions', 'settings'] as $key) {
        RoleMenuPermission::query()->create(['role' => 'cashier', 'menu_key' => $key]);
    }
    RoleMenuPermission::flushMenuCache();
});

test('hak akses yang disimpan satu toko tidak mengubah toko lain maupun default', function () {
    [$storeA, $adminA, $cashierA] = makeMenuPermissionStore('Toko A');
    [, , $cashierB] = makeMenuPermissionStore('Toko B');

    $this->actingAs($adminA)
        ->postJson('/menu-permissions', ['role' => 'cashier', 'menus' => ['pos', 'customers', 'reports']])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($cashierA->fresh()->allowedMenus())->toContain('customers', 'reports', 'pos')
        ->not->toContain('pos_transactions');

    // Toko B dan tabel default tidak berubah.
    expect($cashierB->fresh()->allowedMenus())->toEqualCanonicalizing(['dashboard', 'pos', 'pos_transactions', 'settings'])
        ->and(RoleMenuPermission::query()->where('role', 'cashier')->pluck('menu_key')->all())
        ->toEqualCanonicalizing(['dashboard', 'pos', 'pos_transactions', 'settings']);

    expect($storeA->fresh()->settings['role_menu_permissions']['cashier'])->toContain('customers');
});

test('menu di luar daftar izin tetap dipertahankan saat disimpan', function () {
    [, $admin, $cashier] = makeMenuPermissionStore('Toko C');

    // "settings" bukan menu yang bisa diatur di halaman, jadi tidak boleh hilang.
    $this->actingAs($admin)
        ->postJson('/menu-permissions', ['role' => 'cashier', 'menus' => ['pos', 'menu_palsu']])
        ->assertOk();

    expect($cashier->fresh()->allowedMenus())->toEqualCanonicalizing(['pos', 'settings']);
});

test('daftar izin memakai menu_key sidebar dan menandai toko yang belum diatur', function () {
    [, $admin] = makeMenuPermissionStore('Toko D');

    $response = $this->actingAs($admin)->getJson('/menu-permissions')->assertOk();

    expect($response->json('all_menus'))->toContain('pos', 'cash_shifts', 'pos_transactions', 'discounts', 'attendance')
        ->not->toContain('cashier', 'shift', 'transactions', 'queue_module')
        ->and($response->json('customized.cashier'))->toBeFalse()
        ->and($response->json('menus.0'))->toHaveKeys(['key', 'label', 'group']);
});

test('kasir yang diberi akses menu back-office bisa membuka halamannya', function () {
    [, $admin, $cashier] = makeMenuPermissionStore('Toko E');

    $this->actingAs($cashier)->get('/customers')->assertRedirect(route('dashboard'));

    $this->actingAs($admin)->postJson('/menu-permissions', ['role' => 'cashier', 'menus' => ['pos', 'customers']])->assertOk();

    $this->actingAs($cashier->fresh())->get('/customers')->assertOk();
    // Menu yang tidak diberikan tetap ditolak.
    $this->actingAs($cashier->fresh())->get('/expenses')->assertRedirect(route('dashboard'));
});

test('kasir tidak bisa mengubah hak akses', function () {
    [$store, , $cashier] = makeMenuPermissionStore('Toko F');

    $status = $this->actingAs($cashier)
        ->postJson('/menu-permissions', ['role' => 'cashier', 'menus' => ['reports']])
        ->status();

    // Ditolak oleh guard rute (redirect) atau controller (403); yang penting tidak tersimpan.
    expect($status)->toBeIn([302, 403])
        ->and($store->fresh()->settings['role_menu_permissions'] ?? null)->toBeNull();
});
