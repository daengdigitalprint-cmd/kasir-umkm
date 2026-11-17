<?php

/*
 * Pesanan Antar dinyalakan / dimatikan per toko dari halaman Pengaturan Toko
 * (bukan otomatis untuk semua toko F&B).
 */

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\DeliveryFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deliveryToggleStore(string $planSlug = 'bisnis'): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => $planSlug],
        ['name' => $planSlug, 'price' => $planSlug === 'bisnis' ? 99000 : 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Warung Mie',
        'slug' => 'mie-'.uniqid(),
        'business_type' => 'fnb',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $admin->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $admin];
}

it('toko F&B baru: Pesanan Antar mati secara bawaan', function () {
    [$store, $admin] = deliveryToggleStore();

    expect(DeliveryFeatures::enabled($store))->toBeFalse();
    $this->actingAs($admin)->get('/settings/profile')->assertOk()
        ->assertInertia(fn ($page) => $page->where('store.delivery_enabled', false));
});

it('admin menyalakan Pesanan Antar + ongkir bawaan dari Pengaturan Toko, lalu mematikannya', function () {
    [$store, $admin] = deliveryToggleStore();

    $this->actingAs($admin)->put('/settings/store', [
        'name' => $store->name,
        'business_type' => 'fnb',
        'delivery_enabled' => true,
        'delivery_default_fee' => 9000,
    ])->assertSessionHasNoErrors();

    $store->refresh();
    expect(DeliveryFeatures::enabled($store))->toBeTrue()
        ->and(DeliveryFeatures::defaultFee($store))->toBe(9000.0);

    $this->actingAs($admin)->get('/settings/profile')
        ->assertInertia(fn ($page) => $page->where('store.delivery_enabled', true)->where('store.delivery_default_fee', 9000));

    $this->actingAs($admin)->put('/settings/store', [
        'name' => $store->name,
        'business_type' => 'fnb',
        'delivery_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect(DeliveryFeatures::enabled($store->fresh()))->toBeFalse();
});

it('menyimpan pengaturan lain tidak ikut menyalakan Pesanan Antar', function () {
    [$store, $admin] = deliveryToggleStore();

    $this->actingAs($admin)->put('/settings/store', [
        'name' => 'Warung Mie Baru',
        'business_type' => 'fnb',
    ])->assertSessionHasNoErrors();

    expect(DeliveryFeatures::enabled($store->fresh()))->toBeFalse();
});

it('paket Gratis tidak bisa menyalakan Pesanan Antar', function () {
    [$store, $admin] = deliveryToggleStore('starter');

    $this->actingAs($admin)->put('/settings/store', [
        'name' => $store->name,
        'business_type' => 'fnb',
        'delivery_enabled' => true,
    ])->assertSessionHas('warning');

    expect(DeliveryFeatures::enabled($store->fresh()))->toBeFalse();
});

it('tidak bisa dimatikan dari Pengaturan Toko selama ada pengantaran berjalan', function () {
    [$store, $admin] = deliveryToggleStore();
    $store->update(['settings' => ['delivery_enabled' => true]]);
    $transaction = \App\Models\Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $admin->id,
        'transaction_number' => 'TRX-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 10000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 18000,
        'amount_paid' => 18000,
        'change_amount' => 0,
    ]);
    \App\Models\DeliveryOrder::query()->create([
        'store_id' => $store->id,
        'transaction_id' => $transaction->id,
        'address' => 'Jl. Uji 2',
        'delivery_fee' => 8000,
        'status' => \App\Models\DeliveryOrder::STATUS_PREPARING,
    ]);

    $this->actingAs($admin)->put('/settings/store', [
        'name' => $store->name,
        'business_type' => 'fnb',
        'delivery_enabled' => false,
    ])->assertSessionHas('warning');

    expect(DeliveryFeatures::enabled($store->fresh()))->toBeTrue();
});
