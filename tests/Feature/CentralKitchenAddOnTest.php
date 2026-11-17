<?php

/*
 * R2 — Pembayaran Modul Central Kitchen: toko mengajukan pembayaran,
 * Super Admin mengonfirmasi, modul aktif untuk seluruh grup usaha.
 */

use App\Models\CentralKitchenPricing;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\CentralKitchenAddOnService;
use App\Support\CentralKitchenModule;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function addonStore(?Organization $org, string $name, string $type = StoreLocationType::OUTLET): array
{
    $store = Store::query()->create([
        'name' => $name,
        'slug' => 'addon-'.uniqid(),
        'organization_id' => $org?->id,
        'location_type' => $type,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);

    return [$store, $user];
}

function addonGroup(): array
{
    $org = Organization::query()->create(['name' => 'Grup Bakso', 'slug' => 'bakso-'.uniqid()]);
    [$ck, $ckAdmin] = addonStore($org, 'Dapur Pusat', StoreLocationType::CENTRAL_WAREHOUSE);
    [$outlet, $outletAdmin] = addonStore($org, 'Outlet 1');

    return compact('org', 'ck', 'ckAdmin', 'outlet', 'outletAdmin');
}

function superAdmin(): User
{
    return User::factory()->create(['is_super_admin' => true, 'email_verified_at' => now()]);
}

it('mengajukan pembayaran bulanan sesuai harga yang diatur', function () {
    CentralKitchenPricing::current()->update(['monthly_price' => 149000, 'yearly_price' => 1490000]);
    $g = addonGroup();

    $this->actingAs($g['outletAdmin'])
        ->postJson('/subscription/buy-central-kitchen', ['billing_cycle' => 'monthly', 'payment_method' => 'bank_transfer'])
        ->assertOk()
        ->assertJson(['success' => true]);

    $payment = SubscriptionPayment::query()->sole();
    expect($payment->type)->toBe(CentralKitchenAddOnService::PAYMENT_TYPE)
        ->and((int) $payment->amount)->toBe(149000)
        ->and((int) $payment->duration_days)->toBe(30)
        ->and($payment->status)->toBe('pending')
        ->and(CentralKitchenModule::enabled($g['outlet']))->toBeFalse();
});

it('konfirmasi Super Admin mengaktifkan modul untuk semua lokasi grup', function () {
    $g = addonGroup();
    $this->actingAs($g['outletAdmin'])
        ->postJson('/subscription/buy-central-kitchen', ['billing_cycle' => 'yearly', 'payment_method' => 'qris'])
        ->assertOk();
    $payment = SubscriptionPayment::query()->sole();

    $this->actingAs(superAdmin())->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();

    $g['outlet']->refresh();
    $g['ck']->refresh();
    expect($payment->fresh()->status)->toBe('confirmed')
        ->and((int) $payment->amount)->toBe(1990000)
        ->and(CentralKitchenModule::enabled($g['outlet']))->toBeTrue()
        ->and(CentralKitchenModule::enabled($g['ck']))->toBeTrue()
        ->and($g['org']->fresh()->central_kitchen_until->between(now()->addDays(364), now()->addDays(366)))->toBeTrue();
});

it('perpanjangan menambah dari masa aktif yang masih berjalan', function () {
    $g = addonGroup();
    $g['org']->update(['central_kitchen_until' => now()->addDays(10)]);

    $payment = CentralKitchenAddOnService::createPayment($g['outlet'], 'bank_transfer', null, $g['outletAdmin']);
    $this->actingAs(superAdmin())->postJson("/superadmin/payments/{$payment->id}/confirm")->assertOk();

    expect($g['org']->fresh()->central_kitchen_until->between(now()->addDays(39), now()->addDays(41)))->toBeTrue();
});

it('menolak pengajuan ganda dari lokasi lain dalam grup yang sama', function () {
    $g = addonGroup();
    $this->actingAs($g['outletAdmin'])
        ->postJson('/subscription/buy-central-kitchen', ['billing_cycle' => 'monthly', 'payment_method' => 'bank_transfer'])
        ->assertOk();

    $this->actingAs($g['ckAdmin'])
        ->postJson('/subscription/buy-central-kitchen', ['billing_cycle' => 'monthly', 'payment_method' => 'bank_transfer'])
        ->assertStatus(400);

    expect(SubscriptionPayment::query()->count())->toBe(1);
});

it('menolak toko tanpa grup usaha', function () {
    [, $solo] = addonStore(null, 'Warung Tunggal');

    $this->actingAs($solo)
        ->postJson('/subscription/buy-central-kitchen', ['billing_cycle' => 'monthly', 'payment_method' => 'bank_transfer'])
        ->assertStatus(400);

    expect(SubscriptionPayment::query()->count())->toBe(0);
});

it('halaman Langganan memuat info modul dan halaman checkout bisa dibuka', function () {
    $g = addonGroup();

    $this->actingAs($g['outletAdmin'])->get('/subscription')->assertOk()->assertInertia(
        fn ($page) => $page->where('centralKitchenInfo.is_purchased', false)
            ->where('centralKitchenInfo.has_organization', true)
            ->where('centralKitchenInfo.pricing.monthly_price', 199000)
    );

    $this->actingAs($g['outletAdmin'])->get('/subscription/checkout-central-kitchen')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Subscription/CentralKitchenCheckout')->where('storeName', 'Grup Bakso'));
});

it('Super Admin mengubah harga modul, toko biasa ditolak', function () {
    $this->actingAs(superAdmin())->post('/superadmin/central-kitchen-pricing', ['monthly_price' => 179000, 'yearly_price' => 1790000])
        ->assertSessionHasNoErrors();

    expect(CentralKitchenPricing::current()->monthly_price)->toBe(179000);

    $g = addonGroup();
    $this->actingAs($g['outletAdmin'])->post('/superadmin/central-kitchen-pricing', ['monthly_price' => 1, 'yearly_price' => 1]);
    expect(CentralKitchenPricing::current()->monthly_price)->toBe(179000);
});
