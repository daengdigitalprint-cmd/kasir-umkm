<?php

use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeCashierPinStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko PIN',
        'slug' => 'toko-pin-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'cashier_login_mode' => 'pin',
    ]);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $admin, $cashier];
}

test('halaman pengaturan cashier pin menampilkan anggota tim dari manage staff', function () {
    [$store, $admin, $cashier] = makeCashierPinStore();

    $response = $this->actingAs($admin)->get('/settings/cashier-pin');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/CashierPin')
        ->has('cashiers', 2)
    );

    $names = collect($response->original->getData()['page']['props']['cashiers'])->pluck('name');
    expect($names)->toContain($admin->name, $cashier->name);
});

test('admin dapat mengatur pin untuk anggota tim', function () {
    [$store, $admin, $cashier] = makeCashierPinStore();

    $response = $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->post("/settings/cashier-pin/{$cashier->id}/pin", [
            '_token' => 'csrf-token',
            'pin' => '5678',
        ]);

    $response->assertRedirect();

    $cashier->refresh();
    expect($cashier->hasCashierPin())->toBeTrue();
    expect($cashier->can_login_pos)->toBeTrue();
    expect(Hash::check('5678', $cashier->cashier_pin_hash))->toBeTrue();
});
