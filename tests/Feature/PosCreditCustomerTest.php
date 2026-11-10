<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat outlet aktif dengan user kasir yang sudah login.
 */
function makePosCreditStore(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Uji Kredit',
        'slug' => 'pos-credit-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    return [$store, $cashier];
}

// ═══════════════════════════════════════════════════════════════
// Skenario 1: Endpoint quick-store berhasil mendaftarkan pelanggan baru
// ═══════════════════════════════════════════════════════════════

test('POST /customers/quick-store mendaftarkan pelanggan baru dari POS', function () {
    [$store, $cashier] = makePosCreditStore();

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/customers/quick-store', [
            '_token' => 'csrf-token',
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('customer.name', 'Budi Santoso');
    $response->assertJsonPath('customer.phone', '081234567890');

    $this->assertDatabaseHas('customers', [
        'store_id' => $store->id,
        'name' => 'Budi Santoso',
        'phone' => '081234567890',
        'customer_type' => 'ecer',
    ]);
});

// ═══════════════════════════════════════════════════════════════
// Skenario 2: Quick-store tanpa phone tetap berhasil (phone opsional)
// ═══════════════════════════════════════════════════════════════

test('POST /customers/quick-store tanpa nomor telepon tetap berhasil', function () {
    [$store, $cashier] = makePosCreditStore();

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/customers/quick-store', [
            '_token' => 'csrf-token',
            'name' => 'Ani Rahayu',
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('customer.name', 'Ani Rahayu');
    $response->assertJsonPath('customer.phone', null);

    $this->assertDatabaseHas('customers', [
        'store_id' => $store->id,
        'name' => 'Ani Rahayu',
        'customer_type' => 'ecer',
    ]);
});

// ═══════════════════════════════════════════════════════════════
// Skenario 3: Validasi — nama wajib diisi
// ═══════════════════════════════════════════════════════════════

test('POST /customers/quick-store gagal jika nama kosong', function () {
    [$store, $cashier] = makePosCreditStore();

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/customers/quick-store', [
            '_token' => 'csrf-token',
            'name' => '',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['name']);
});

// ═══════════════════════════════════════════════════════════════
// Skenario 4: Pelanggan baru yang didaftarkan dari quick-store
//             langsung muncul di hasil pencarian
// ═══════════════════════════════════════════════════════════════

test('pelanggan baru dari quick-store muncul di hasil pencarian', function () {
    [$store, $cashier] = makePosCreditStore();

    // Daftarkan pelanggan via quick-store
    $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/customers/quick-store', [
            '_token' => 'csrf-token',
            'name' => 'Cindy Permata',
            'phone' => '089876543210',
        ]);

    // Cari pelanggan yang baru didaftarkan
    $searchResponse = $this->actingAs($cashier)
        ->getJson('/customers/search?q=Cindy');

    $searchResponse->assertStatus(200);
    $customers = $searchResponse->json('customers');
    expect($customers)->toHaveCount(1);
    expect($customers[0]['name'])->toBe('Cindy Permata');
});

// ═══════════════════════════════════════════════════════════════
// Skenario 5: Quick-store tidak terpengaruh role backoffice —
//             kasir biasa bisa mendaftarkan pelanggan dari POS
// ═══════════════════════════════════════════════════════════════

test('kasir biasa dapat mengakses quick-store tanpa role backoffice', function () {
    [$store, $cashier] = makePosCreditStore();

    // Pastikan user hanya role cashier, bukan admin/backoffice
    expect($cashier->role)->toBe('cashier');

    $response = $this->actingAs($cashier)
        ->withSession(['_token' => 'csrf-token'])
        ->postJson('/customers/quick-store', [
            '_token' => 'csrf-token',
            'name' => 'Dedi Kurniawan',
        ]);

    $response->assertStatus(201);
});
