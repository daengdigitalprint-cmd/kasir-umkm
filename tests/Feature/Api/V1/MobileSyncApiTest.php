<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\SyncOutbox;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\Sync\MobileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('endpoint login API mengembalikan token Sanctum', function () {
    $user = User::factory()->create([
        'email' => 'api.user@toko.com',
        'password' => bcrypt('password123'),
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'api.user@toko.com',
        'password' => 'password123',
        'device_name' => 'Test Mobile POS',
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'status',
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'role'],
        ]);
});

test('endpoint me API membutuhkan autentikasi token', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $user = User::factory()->create(['is_active' => true]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('user.email', $user->email);
});

test('endpoint sync products mengembalikan produk milik toko', function () {
    $store = Store::query()->create([
        'name' => 'Toko Uji POS',
        'slug' => 'toko-uji-pos-'.Str::random(5),
        'is_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'is_active' => true]);

    for ($i = 1; $i <= 3; $i++) {
        Product::query()->create([
            'store_id' => $store->id,
            'name' => 'Produk Sync '.$i,
            'price' => 10000 * $i,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/sync/products');

    $response->assertSuccessful()
        ->assertJsonPath('count', 3);
});

test('endpoint sync transactions idempoten menggunakan client_uuid', function () {
    $store = Store::query()->create([
        'name' => 'Toko Kasir POS',
        'slug' => 'toko-kasir-pos-'.Str::random(5),
        'is_active' => true,
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'is_active' => true]);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Robusta',
        'price' => 15000,
        'stock_quantity' => 10,
        'is_active' => true,
    ]);

    $token = $user->createToken('test')->plainTextToken;
    $clientUuid = (string) Str::uuid();

    $payload = [
        'client_uuid' => $clientUuid,
        'transaction_number' => 'TRX-MOB-001',
        'payment_method' => 'cash',
        'subtotal' => 30000,
        'total_amount' => 30000,
        'amount_paid' => 50000,
        'change_amount' => 20000,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 15000,
            ],
        ],
    ];

    // Kirim pertama: sukses create
    $firstResponse = $this->withToken($token)->postJson('/api/v1/sync/transactions', $payload);
    $firstResponse->assertCreated()
        ->assertJsonPath('idempotent', false);

    expect((int) $product->fresh()->stock_quantity)->toBe(8);

    // Kirim kedua (retry karena jaringan delay): idempoten sukses tanpa double pengurangan stok
    $secondResponse = $this->withToken($token)->postJson('/api/v1/sync/transactions', $payload);
    $secondResponse->assertOk()
        ->assertJsonPath('idempotent', true);

    expect((int) $product->fresh()->stock_quantity)->toBe(8)
        ->and(Transaction::query()->where('offline_sale_id', $clientUuid)->count())->toBe(1);
});

test('MobileSyncService dapat menyimpan transaksi ke outbox queue', function () {
    $store = Store::query()->create([
        'name' => 'Toko Outbox',
        'slug' => 'toko-outbox-'.Str::random(5),
        'is_active' => true,
    ]);

    $service = new MobileSyncService;
    $clientUuid = (string) Str::uuid();

    $outbox = $service->queue('transaction', $clientUuid, ['test' => 'data'], $store->id);

    expect($outbox)->not->toBeNull()
        ->and($outbox->client_uuid)->toBe($clientUuid)
        ->and($outbox->status)->toBe('pending');

    expect(SyncOutbox::query()->where('client_uuid', $clientUuid)->count())->toBe(1);
});
