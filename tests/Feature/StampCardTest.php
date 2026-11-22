<?php

/*
 * Kartu Stempel Digital: modul bawaan nonaktif, stempel dari POS (pelanggan dipilih lewat ID),
 * kartu penuh jadi hadiah, tukar hadiah, batal transaksi menarik stempel, kartu publik /k/{token}.
 */

use App\Models\Customer;
use App\Models\Product;
use App\Models\StampCardLedger;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StampCardService;
use App\Support\StampCardSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Toko uji. Bawaan: paket berbayar (tanpa paket & tanpa masa berakhir = akses penuh) dengan shift terbuka.
 * $freePlan = true: paket Starter (Gratis), tempat kartu stempel dikunci.
 */
function stampStore(bool $enabled = true, array $rules = [], bool $freePlan = false): array
{
    $planFields = [];
    if ($freePlan) {
        $plan = \App\Models\SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'starter'],
            ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
        );
        $planFields = ['subscription_plan_id' => $plan->id, 'subscription_end' => now()->addDay()];
    }
    $store = Store::query()->create([
        'name' => 'Kedai Kopi Senja',
        'slug' => 'senja-'.uniqid(),
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => ['stamp_card_enabled' => $enabled, 'stamp_card' => array_merge(['target' => 3, 'reward' => '1 Es Kopi gratis'], $rules)],
    ] + $planFields);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => 'admin']);
    // Paket berbayar mewajibkan shift kasir terbuka untuk berjualan.
    \App\Models\CashShift::query()->create(['store_id' => $store->id, 'user_id' => $user->id, 'opening_cash' => 0, 'opened_at' => now()]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu',
        'sku' => 'KS-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 100,
        'track_stock' => false,
        'is_active' => true,
        'inventory_type' => 'sellable',
    ]);
    $customer = Customer::query()->create(['store_id' => $store->id, 'name' => 'Rina Wulandari', 'phone' => '0811-2223-333']);

    return [$store, $user, $product, $customer];
}

function stampSale($test, User $user, Product $product, ?Customer $customer, int $qty = 1)
{
    return $test->actingAs($user)->postJson('/pos/process-payment', array_filter([
        'items' => [['product_id' => $product->id, 'quantity' => $qty, 'modifiers' => []]],
        'payment_method' => 'cash',
        'amount_paid' => 10000 * $qty,
        'customer_id' => $customer?->id,
    ], fn ($v) => $v !== null));
}

it('bawaan nonaktif: toko baru tidak memberi stempel', function () {
    $store = Store::query()->create(['name' => 'Baru', 'slug' => 'baru-'.uniqid(), 'is_active' => true, 'settings' => []]);

    expect(StampCardSettings::enabled($store))->toBeFalse();
});

it('modul nonaktif: penjualan tetap jalan tanpa stempel', function () {
    [, $user, $product, $customer] = stampStore(false);

    stampSale($this, $user, $product, $customer)->assertOk()->assertJsonPath('transaction.stamp_card', null);

    expect($customer->fresh()->stamp_count)->toBe(0)
        ->and(StampCardLedger::query()->count())->toBe(0);
});

it('pelanggan dipilih lewat ID dapat 1 stempel per transaksi dan tercetak di struk', function () {
    [, $user, $product, $customer] = stampStore();

    $res = stampSale($this, $user, $product, $customer)->assertOk();

    $res->assertJsonPath('transaction.customer_id', $customer->id)
        ->assertJsonPath('transaction.stamp_card.added', 1)
        ->assertJsonPath('transaction.stamp_card.count', 1)
        ->assertJsonPath('transaction.payment_details.stamp_card.count', 1);
    expect($customer->fresh()->stamp_count)->toBe(1)
        ->and($customer->fresh()->stamp_card_token)->not->toBeNull();
});

it('kartu penuh menjadi hadiah siap tukar lalu bisa ditukar di kasir', function () {
    [, $user, $product, $customer] = stampStore();

    stampSale($this, $user, $product, $customer);
    stampSale($this, $user, $product, $customer);
    stampSale($this, $user, $product, $customer)->assertJsonPath('transaction.stamp_card.new_rewards', 1);

    $fresh = $customer->fresh();
    expect($fresh->stamp_count)->toBe(0)->and($fresh->stamp_rewards_ready)->toBe(1);

    $this->actingAs($user)->postJson("/pos/stamp-card/customers/{$customer->id}/redeem")
        ->assertOk()->assertJsonPath('customer.rewards_ready', 0);
    $this->actingAs($user)->postJson("/pos/stamp-card/customers/{$customer->id}/redeem")->assertStatus(422);

    expect($customer->fresh()->stamp_rewards_redeemed)->toBe(1);
});

it('mode per menu pilihan: stempel sesuai jumlah porsi', function () {
    [$store, $user, $product, $customer] = stampStore(true, ['mode' => 'item', 'target' => 10]);
    StampCardSettings::update($store, ['product_ids' => [$product->id]]);

    stampSale($this, $user, $product, $customer, 3)->assertJsonPath('transaction.stamp_card.added', 3);
});

it('belanja minimal tidak tercapai: tidak dapat stempel', function () {
    [, $user, $product, $customer] = stampStore(true, ['min_amount' => 50000]);

    stampSale($this, $user, $product, $customer)->assertOk()->assertJsonPath('transaction.stamp_card', null);
});

it('void transaksi menarik kembali stempelnya, termasuk hadiah yang belum ditukar', function () {
    [, $user, $product, $customer] = stampStore();

    stampSale($this, $user, $product, $customer);
    stampSale($this, $user, $product, $customer);
    $id = stampSale($this, $user, $product, $customer)->json('transaction.id');
    expect($customer->fresh()->stamp_rewards_ready)->toBe(1);

    app(StampCardService::class)->reverseForTransaction(Transaction::query()->find($id), $user->id);
    // Pemanggilan ulang tidak menarik dua kali.
    app(StampCardService::class)->reverseForTransaction(Transaction::query()->find($id), $user->id);

    $fresh = $customer->fresh();
    expect($fresh->stamp_rewards_ready)->toBe(0)->and($fresh->stamp_count)->toBe(2);
});

it('kasir menemukan kartu lewat no. WA berformat apa pun atau isi QR', function () {
    [$store, $user, , $customer] = stampStore();
    $token = app(StampCardService::class)->ensureToken($customer);

    $this->actingAs($user)->getJson('/pos/stamp-card/lookup?q='.urlencode('+62 811 2223 333'))
        ->assertOk()->assertJsonPath('customer.id', $customer->id);
    $this->actingAs($user)->getJson('/pos/stamp-card/lookup?q='.urlencode(url('/k/'.$token)))
        ->assertOk()->assertJsonPath('customer.id', $customer->id);
    $this->actingAs($user)->getJson('/pos/stamp-card/lookup?q=089999999999')
        ->assertOk()->assertJsonPath('customer', null);
});

it('kartu toko lain tidak bisa dipakai', function () {
    [, $user] = stampStore();
    [, , , $other] = stampStore();

    $this->actingAs($user)->postJson("/pos/stamp-card/customers/{$other->id}/redeem")->assertNotFound();
    $this->actingAs($user)->getJson('/pos/stamp-card/lookup?q='.urlencode('0811-2223-333'))
        ->assertJsonPath('customer.id', fn ($id) => $id !== $other->id);
});

it('halaman kartu publik tampil tanpa login dan tidak membocorkan nomor', function () {
    [, , , $customer] = stampStore();
    $token = app(StampCardService::class)->ensureToken($customer);

    $this->get('/k/'.$token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('StampCard/Public')
            ->where('card.first_name', 'Rina')
            ->where('card.target', 3)
            ->missing('card.phone'));

    $this->get('/k/tidakadatokenini12345')->assertNotFound();
});

it('pengelola mengatur aturan dan mengoreksi stempel', function () {
    [$store, $user, , $customer] = stampStore();

    $this->actingAs($user)->get('/stamp-card')->assertOk();
    $this->actingAs($user)->put('/stamp-card/settings', [
        'title' => 'Kartu Senja', 'target' => 8, 'mode' => 'transaction', 'min_amount' => 20000,
        'reward' => 'Gratis 1 kopi', 'expiry_months' => 6,
    ])->assertRedirect();
    expect(StampCardSettings::all($store->fresh())['target'])->toBe(8);

    $this->actingAs($user)->post("/stamp-card/customers/{$customer->id}/adjust", ['delta' => 5, 'note' => 'Pindahan kartu kertas'])->assertRedirect();
    expect($customer->fresh()->stamp_count)->toBe(5);
});

it('kasir tidak bisa membuka halaman pengaturan kartu stempel', function () {
    [$store] = stampStore();
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier', 'email_verified_at' => now()]);
    $cashier->stores()->attach($store->id, ['role' => 'cashier']);

    $this->actingAs($cashier)->get('/stamp-card')->assertRedirect();
    $this->actingAs($cashier)->post("/stamp-card/customers/{$store->customers()->first()->id}/adjust", ['delta' => 5, 'note' => 'x'])->assertRedirect();
    expect($store->customers()->first()->stamp_count)->toBe(0);
});

it('paket Gratis: modul terkunci walau sakelarnya tersimpan menyala', function () {
    [$store, $user, $product, $customer] = stampStore(true, [], freePlan: true);

    expect(StampCardSettings::enabled($store))->toBeFalse();

    // Penjualan tetap jalan, tanpa stempel.
    stampSale($this, $user, $product, $customer)->assertOk()->assertJsonPath('transaction.stamp_card', null);
    expect($customer->fresh()->stamp_count)->toBe(0);

    // Halaman & endpoint kasir ditolak dengan ajakan upgrade.
    $this->actingAs($user)->get('/stamp-card')->assertRedirect(route('dashboard'));
    $this->actingAs($user)->getJson('/pos/stamp-card/lookup?q=08112223333')
        ->assertForbidden()->assertJsonPath('upgrade_required', true);
});

it('paket Gratis: sakelar tidak bisa dinyalakan dari Pengaturan', function () {
    [$store, $user] = stampStore(false, [], freePlan: true);

    $this->actingAs($user)->put('/settings/store', ['stamp_card_enabled' => true]);

    expect((bool) ($store->fresh()->settings['stamp_card_enabled'] ?? false))->toBeFalse();
});
