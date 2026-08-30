<?php

use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Akses peran ke menu Penyesuaian stok.
 *
 * Kasir kini dapat membuka menu Penyesuaian stok (melihat daftar, membuat draft),
 * tetapi persetujuan & pembatalan tetap khusus admin/manajer.
 */
function makeAdjustmentRoleStore(string $role): array
{
    $store = Store::query()->create([
        'name' => 'Toko '.$role,
        'slug' => 'akses-'.$role.'-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => null,
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => $role,
        'email_verified_at' => now(),
    ]);

    return [$store, $user];
}

test('kasir dapat membuka halaman penyesuaian stok', function () {
    [$store, $user] = makeAdjustmentRoleStore('cashier');

    $this->actingAs($user)->get(route('stock-adjustments.index'))->assertOk();
    $this->actingAs($user)->get(route('stock-adjustments.create'))->assertOk();
});

test('admin tetap dapat membuka halaman penyesuaian stok', function () {
    [$store, $user] = makeAdjustmentRoleStore('admin');

    $this->actingAs($user)->get(route('stock-adjustments.index'))->assertOk();
});

test('kasir tidak dapat membuka menu produk (tetap khusus peran inventory)', function () {
    [$store, $user] = makeAdjustmentRoleStore('cashier');

    $this->actingAs($user)->get(route('products.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});

test('kasir tidak dapat menyetujui penyesuaian stok', function () {
    [$store, $user] = makeAdjustmentRoleStore('cashier');

    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'status' => StockAdjustment::STATUS_PENDING_APPROVAL,
    ]);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->from(route('stock-adjustments.show', $doc))
        ->post(route('stock-adjustments.approve', $doc), ['_token' => 'csrf-token'])
        ->assertSessionHasErrors('approval');

    expect($doc->refresh()->status)->toBe(StockAdjustment::STATUS_PENDING_APPROVAL);
});

test('manajer dapat menyetujui penyesuaian stok', function () {
    [$store, $user] = makeAdjustmentRoleStore('manager');

    $doc = StockAdjustment::create([
        'store_id' => $store->id,
        'reason_code' => StockAdjustment::REASON_DAMAGE,
        'status' => StockAdjustment::STATUS_PENDING_APPROVAL,
    ]);

    $this->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post(route('stock-adjustments.approve', $doc), ['_token' => 'csrf-token']);

    expect($doc->refresh()->status)->toBe(StockAdjustment::STATUS_POSTED);
});
