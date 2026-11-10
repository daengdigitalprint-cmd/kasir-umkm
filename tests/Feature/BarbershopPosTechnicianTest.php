<?php

use App\Models\CashShift;
use App\Models\Product;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat toko barbershop aktif lengkap dengan kasir, teknisi, dan produk.
 *
 * @return array{0: Store, 1: User, 2: User, 3: Product}
 */
if (! function_exists('makeBarbershopPosStore')) {
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

it('menyimpan teknisi pada transaksi dan menampilkan namanya di payload struk', function () {
    [, $cashier, $barber, $product] = makeBarbershopPosStore();

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id));

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('transaction.barber.id', $barber->id);
    $response->assertJsonPath('transaction.barber.name', $barber->name);
    $response->assertJsonPath('transaction.barbershop_enabled', true);

    $transaction = Transaction::query()->whereNotNull('barber_user_id')->first();
    expect($transaction)->not->toBeNull()
        ->and((int) $transaction->barber_user_id)->toBe($barber->id);
});

it('menolak teknisi yang bukan anggota toko', function () {
    [, $cashier, , $product] = makeBarbershopPosStore();

    $outsiderStore = Store::query()->create([
        'name' => 'Barbershop Lain',
        'slug' => 'barbershop-lain-'.uniqid(),
        'business_type' => 'barbershop',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $outsider = User::factory()->create([
        'store_id' => $outsiderStore->id,
        'role' => 'barber',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $outsider->id));

    $response->assertStatus(422);
    $response->assertJsonPath('success', false);
    expect(Transaction::query()->count())->toBe(0);
});

it('menyimpan transaksi tanpa teknisi sebagai null', function () {
    [, $cashier, , $product] = makeBarbershopPosStore();

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product));

    $response->assertOk();
    $transaction = Transaction::query()->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->barber_user_id)->toBeNull();
});

it('mewarisi teknisi ke entri antrian otomatis', function () {
    [, $cashier, $barber, $product] = makeBarbershopPosStore([
        'queue_module_enabled' => true,
    ]);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $barber->id));

    $response->assertOk();

    $entry = QueueEntry::query()->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->barber_user_id)->toBe($barber->id);
});

it('mengabaikan barber_user_id pada toko non-barbershop', function () {
    $store = Store::query()->create([
        'name' => 'Toko Normal',
        'slug' => 'toko-normal-'.uniqid(),
        'business_type' => 'retail',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);
    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);
    CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'opening_cash' => 100000,
        'opened_at' => now(),
    ]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Sabun',
        'price' => 10000,
        'stock_quantity' => 0,
        'track_stock' => false,
        'is_active' => true,
    ]);
    $someone = User::factory()->create(['role' => 'barber']);

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/pos/process-payment', barbershopPaymentPayload($product, $someone->id));

    $response->assertOk();
    $transaction = Transaction::query()->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->barber_user_id)->toBeNull();
});
