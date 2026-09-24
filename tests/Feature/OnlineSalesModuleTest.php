<?php

use App\Models\CashShift;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat toko F&B dengan modul online sales aktif/nonaktif.
 *
 * @return array{0: Store, 1: User, 2: Product}
 */
function makeOnlineSalesStore(bool $enabled): array
{
    $store = Store::query()->create([
        'name' => 'Toko F&B Online',
        'slug' => 'online-sales-'.uniqid(),
        'business_type' => 'fnb',
        'settings' => ['online_sales_enabled' => $enabled],
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    // Buka shift kasir agar processPayment dapat menyimpan transaksi.
    CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'opening_cash' => 100000,
        'opened_at' => now(),
    ]);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi',
        'price' => 10000,
        'stock_quantity' => '100.0000',
        'track_stock' => true,
        'is_active' => true,
    ]);

    return [$store, $cashier, $product];
}

function posOrderPayload(Product $product, string $channel, ?float $commission = null): array
{
    $payload = [
        'items' => [[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
        'total_amount' => 10000,
        'sales_channel' => $channel,
    ];
    if ($commission !== null) {
        $payload['commission_percent'] = $commission;
    }

    return $payload;
}

it('menolak channel aggregator saat modul online nonaktif', function () {
    [, $cashier, $product] = makeOnlineSalesStore(false);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', posOrderPayload($product, 'gofood'));

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('message', 'Modul penjualan online belum diaktifkan. Aktifkan di Pengaturan > Toko.');
});

it('menerima channel aggregator saat modul online aktif', function () {
    [, $cashier, $product] = makeOnlineSalesStore(true);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', posOrderPayload($product, 'gofood', 20));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

it('menerima channel offline terlepas dari status modul online', function () {
    [, $cashier, $product] = makeOnlineSalesStore(false);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', posOrderPayload($product, 'walk_in'));

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);
});

it('menghitung komisi saat channel aggregator dan modul online aktif', function () {
    [$store, $cashier, $product] = makeOnlineSalesStore(true);

    $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', posOrderPayload($product, 'gofood', 20));

    $txn = \App\Models\Transaction::where('store_id', $store->id)->first();
    expect($txn)->not->toBeNull()
        ->and($txn->sales_channel->value)->toBe('gofood')
        ->and((float) $txn->commission_amount)->toBe(2000.0)
        ->and((float) $txn->commission_percent)->toBe(20.0);
});

it('tidak menghitung komisi untuk channel offline', function () {
    [$store, $cashier, $product] = makeOnlineSalesStore(true);

    $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', posOrderPayload($product, 'walk_in'));

    $txn = \App\Models\Transaction::where('store_id', $store->id)->first();
    expect($txn)->not->toBeNull()
        ->and($txn->sales_channel->value)->toBe('walk_in')
        ->and((float) $txn->commission_amount)->toBe(0.0);
});

it('menyimpan persentase komisi per aggregator dari pengaturan toko', function () {
    [$store, $admin] = makeOnlineSalesStore(true);
    // Ubah pivot role menjadi admin agar lolos middleware role.store_admin
    $store->members()->updateExistingPivot($admin->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->put('/settings/store', [
            'name' => $store->name,
            'business_type' => $store->business_type,
            'online_commission_gofood' => 25,
            'online_commission_grabfood' => 18,
            'online_commission_shopeefood' => 12,
        ])->assertRedirect();

    $store->refresh();
    expect((float) ($store->settings['online_commission_gofood'] ?? null))->toBe(25.0)
        ->and((float) ($store->settings['online_commission_grabfood'] ?? null))->toBe(18.0)
        ->and((float) ($store->settings['online_commission_shopeefood'] ?? null))->toBe(12.0);
});

it('menolak persentase komisi di luar rentang 0-100', function () {
    [$store, $admin] = makeOnlineSalesStore(true);
    // Ubah pivot role menjadi admin agar lolos middleware role.store_admin
    $store->members()->updateExistingPivot($admin->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->putJson('/settings/store', [
            'name' => $store->name,
            'business_type' => $store->business_type,
            'online_commission_gofood' => 150,
        ])->assertUnprocessable();
});
