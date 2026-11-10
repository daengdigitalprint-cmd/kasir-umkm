<?php

use App\Models\CashShift;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('makeBarbershopPosStore')) {
    /**
     * Membuat toko barbershop aktif lengkap dengan kasir, teknisi, dan produk.
     *
     * @return array{0: Store, 1: User, 2: User, 3: Product}
     */
    function makeBarbershopPosStore(array $settings = []): array
    {
        $store = Store::query()->create([
            'name' => 'Barbershop(Pos)',
            'slug' => 'barbershop-pos-'.uniqid(),
            'business_type' => 'barbershop',
            'settings' => $settings,
            'is_active' => true,
            'is_subscription_active' => true,
        ]);

        $cashier = User::factory()->create([
            'store_id' => $store->id,
            'role' => 'cashier',
            'email_verified_at' => now(),
        ]);
        $store->members()->attach($cashier->id, ['role' => 'cashier']);

        $barber = User::factory()->create([
            'store_id' => $store->id,
            'role' => 'staff',
            'email_verified_at' => now(),
        ]);
        $store->members()->attach($barber->id, ['role' => 'barber']);

        CashShift::query()->create([
            'store_id' => $store->id,
            'user_id' => $cashier->id,
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $product = Product::query()->create([
            'store_id' => $store->id,
            'name' => 'Potong Rambut',
            'price' => 50000,
            'stock_quantity' => 0,
            'track_stock' => false,
            'is_active' => true,
        ]);

        return [$store, $cashier, $barber, $product];
    }
}

if (! function_exists('barbershopPaymentPayload')) {
    function barbershopPaymentPayload(Product $product, ?int $barberUserId = null): array
    {
        $payload = [
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 50000,
                'subtotal' => 50000,
            ]],
            'payment_method' => 'cash',
            'amount_paid' => 50000,
            'total_amount' => 50000,
        ];

        if ($barberUserId !== null) {
            $payload['barber_user_id'] = $barberUserId;
        }

        return $payload;
    }
}

/**
 * Toko barbershop + owner untuk pengaturan & laporan.
 *
 * @return array{0: Store, 1: User, 2: User, 3: Product, 4: User}
 */
function makeCommissionBarbershop(array $settings = []): array
{
    [$store, $cashier, $barber, $product] = makeBarbershopPosStore($settings);

    $owner = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'owner',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($owner->id, ['role' => 'owner']);

    return [$store, $cashier, $barber, $product, $owner];
}

it('menghitung komisi persen global saat pembayaran dengan teknisi', function () {
    [, $cashier, $barber, $product] = makeCommissionBarbershop([
        'barber_commission' => ['mode' => 'percent', 'value' => 40],
    ]);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id));

    $response->assertOk();

    $transaction = Transaction::query()->latest('id')->first();
    expect($transaction)->not->toBeNull()
        ->and((float) $transaction->barber_commission_percent)->toBe(40.0)
        ->and((float) $transaction->barber_commission_amount)->toBe(20000.0);
});

it('menimpa tarif global dengan komisi tetap per produk', function () {
    [, $cashier, $barber, $product] = makeCommissionBarbershop([
        'barber_commission' => ['mode' => 'percent', 'value' => 40],
    ]);
    $product->update([
        'barber_commission_mode' => 'fixed',
        'barber_commission_value' => 10000,
    ]);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id));

    $response->assertOk();

    $transaction = Transaction::query()->latest('id')->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->barber_commission_percent)->toBeNull()
        ->and((float) $transaction->barber_commission_amount)->toBe(10000.0);
});

it('tidak menghitung komisi tanpa teknisi', function () {
    [, $cashier, , $product] = makeCommissionBarbershop([
        'barber_commission' => ['mode' => 'percent', 'value' => 40],
    ]);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product));

    $response->assertOk();

    $transaction = Transaction::query()->latest('id')->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->barber_commission_percent)->toBeNull()
        ->and($transaction->barber_commission_amount)->toBeNull();
});

it('menyimpan tarif komisi global dari pengaturan toko', function () {
    [$store, , , , $owner] = makeCommissionBarbershop();
    // Ubah pivot role menjadi admin agar lolos middleware role.store_admin
    $store->members()->updateExistingPivot($owner->id, ['role' => 'admin']);

    $response = $this->actingAs($owner)->put('/settings/store', [
        'name' => $store->name,
        'business_type' => 'barbershop',
        'barber_commission_mode' => 'fixed',
        'barber_commission_value' => 15000,
    ]);

    $response->assertSessionHasNoErrors();

    $store->refresh();
    expect($store->settings['barber_commission']['mode'])->toBe('fixed')
        ->and((float) $store->settings['barber_commission']['value'])->toBe(15000.0);
});

it('menampilkan laporan komisi per teknisi', function () {
    [, $cashier, $barber, $product, $owner] = makeCommissionBarbershop([
        'barber_commission' => ['mode' => 'percent', 'value' => 40],
    ]);

    $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id))
        ->assertOk();

    // Transaksi kedua lalu di-void agar tidak ikut dihitung.
    $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id))
        ->assertOk();
    Transaction::query()->latest('id')->first()->update(['status' => 'void']);

    $response = $this->actingAs($owner)->get('/reports/commissions');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Reports/Commissions')
        ->where('summary.total_commission', 20000)
        ->where('summary.transaction_count', 1)
        ->where('summary.barber_count', 1)
        ->has('rows', 1)
        ->where('rows.0.barber_name', $barber->name)
        ->where('rows.0.commission_amount', 20000));
});

it('menolak laporan komisi pada toko non-barbershop', function () {
    $store = Store::query()->create([
        'name' => 'Toko Normal',
        'slug' => 'toko-normal-'.uniqid(),
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $owner = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'owner',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner)->get('/reports/commissions')->assertNotFound();
});

it('menolak laporan komisi pada paket Starter', function () {
    [, , , , $owner] = makeCommissionBarbershop();

    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true]
    );
    $owner->store->update([
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addMonth(),
    ]);

    $this->actingAs($owner)
        ->get('/reports/commissions')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});
