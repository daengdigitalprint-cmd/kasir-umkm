<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function pharmacyPrescriptionPayload(Product $product, int $quantity = 1): array
{
    return [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'modifiers' => [],
        ]],
        'payment_method' => 'cash',
        'amount_paid' => 10000 * $quantity,
    ];
}

function pharmacyPrescriptionContext(): array
{
    $store = Store::query()->create([
        'name' => 'Apotek Test',
        'slug' => 'apotek-test-'.uniqid(),
        'business_type' => 'pharmacy',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => now()->addDay(),
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $user->stores()->attach($store->id, ['role' => 'cashier']);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Obat Resep',
        'price' => 10000,
        'stock_quantity' => 10,
        'track_stock' => true,
        'is_active' => true,
        'pharmacy_sale_class' => 'prescription',
    ]);

    return compact('store', 'user', 'product');
}

test('POS apotek menyimpan snapshot item resep canonical dari cart', function () {
    ['user' => $user, 'product' => $product] = pharmacyPrescriptionContext();

    $response = $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product),
        [
            'pharmacy_sale_reference' => 'LEGACY-RX-1',
            'prescription' => [
                'number' => 'RX-2026-001',
                'patient_name' => 'Siti',
                'doctor_name' => 'dr. Budi',
                'issued_at' => '2026-08-23',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ],
        ],
    ));

    $response->assertOk()->assertJsonPath('success', true);

    expect(Transaction::query()->sole()->payment_details)->toMatchArray([
        'pharmacy' => [
            'sale_reference' => 'LEGACY-RX-1',
            'prescription' => [
                'number' => 'RX-2026-001',
                'patient_name' => 'Siti',
                'doctor_name' => 'dr. Budi',
                'issued_at' => '2026-08-23',
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
            ],
            'has_prescription_lines' => true,
            'has_limited_lines' => false,
        ],
    ]);
});

test('POS apotek menolak daftar item resep kosong', function () {
    ['user' => $user, 'product' => $product] = pharmacyPrescriptionContext();

    $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product),
        ['prescription' => ['number' => 'RX-1', 'items' => []]],
    ))->assertUnprocessable()->assertJsonValidationErrors(['prescription.items']);
});

test('POS apotek menolak daftar item resep tidak cocok dengan cart', function () {
    ['store' => $store, 'user' => $user, 'product' => $product] = pharmacyPrescriptionContext();
    $otherProduct = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Obat Resep Lain',
        'price' => 10000,
        'stock_quantity' => 10,
        'track_stock' => true,
        'is_active' => true,
        'pharmacy_sale_class' => 'prescription',
    ]);

    $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product),
        ['prescription' => ['number' => 'RX-1', 'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]]]],
    ))->assertUnprocessable()->assertJsonValidationErrors(['prescription.items']);
});

test('POS apotek menolak quantity daftar item resep kurang dari cart', function () {
    ['user' => $user, 'product' => $product] = pharmacyPrescriptionContext();

    $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product, 2),
        ['prescription' => ['number' => 'RX-1', 'items' => [['product_id' => $product->id, 'quantity' => 1]]]],
    ))->assertUnprocessable()->assertJsonValidationErrors(['prescription.items']);
});

test('POS apotek menerima payload legacy tanpa items lalu menyimpan snapshot cart', function () {
    ['user' => $user, 'product' => $product] = pharmacyPrescriptionContext();

    $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product),
        ['pharmacy_sale_reference' => 'LEGACY-RX-1', 'prescription' => ['number' => 'RX-2026-001']],
    ))->assertOk()->assertJsonPath('success', true);

    expect(Transaction::query()->sole()->payment_details['pharmacy']['prescription']['items'])
        ->toBe([['quantity' => '1', 'product_id' => $product->id]]);
});

test('POS non-apotek mengabaikan payload resep', function () {
    $store = Store::query()->create([
        'name' => 'Toko Umum',
        'slug' => 'toko-umum-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_end' => now()->addDay(),
    ]);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'cashier']);
    $user->stores()->attach($store->id, ['role' => 'cashier']);
    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Umum',
        'price' => 10000,
        'stock_quantity' => 10,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)->postJson('/pos/process-payment', array_merge(
        pharmacyPrescriptionPayload($product),
        ['prescription' => ['number' => 'RX-IGNORED']],
    ))->assertOk()->assertJsonPath('success', true);

    expect(Transaction::query()->sole()->payment_details)->toBeNull();
});
