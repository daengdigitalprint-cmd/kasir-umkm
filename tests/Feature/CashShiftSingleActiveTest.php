<?php

use App\Models\CashShift;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat outlet (store) aktif dengan dua kasir terdaftar di outlet yang sama.
 */
function makeShiftOrg(string $prefix): array
{
    $store = Store::query()->create([
        'name' => "{$prefix} Store",
        'slug' => 'shift-'.strtolower($prefix).'-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $cashierA = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashierA->id, ['role' => 'cashier']);

    $cashierB = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashierB->id, ['role' => 'cashier']);

    return [$store, $cashierA, $cashierB];
}

/** Helper POST dengan token CSRF seperti pola test lain di repo ini. */
function openShiftRequest(User $user, array $data = [])
{
    return test()
        ->actingAs($user)
        ->withSession(['_token' => 'csrf-token'])
        ->post('/cash-shifts/open', array_merge(['_token' => 'csrf-token'], $data));
}

// ═══════════════════════════════════════════════════════
// Skenario 1: Kasir pertama membuka shift
// ═══════════════════════════════════════════════════════

test('kasir pertama dapat membuka shift di outlet', function () {
    [$store, $cashierA] = makeShiftOrg('S1');

    $response = openShiftRequest($cashierA, ['opening_cash' => 100000]);

    $response->assertRedirect();
    $this->assertDatabaseHas('cash_shifts', [
        'store_id' => $store->id,
        'user_id' => $cashierA->id,
        'opening_cash' => 100000,
    ]);

    $shift = CashShift::query()->where('store_id', $store->id)->where('user_id', $cashierA->id)->first();
    expect($shift)->not->toBeNull();
    expect($shift->isOpen())->toBeTrue();
});

// ═══════════════════════════════════════════════════════
// Skenario 2: Kasir kedua diblokir saat shift kasir lain masih aktif
// ═══════════════════════════════════════════════════════

test('kasir kedua tidak dapat membuka shift saat shift kasir lain masih aktif di outlet yang sama', function () {
    [$store, $cashierA, $cashierB] = makeShiftOrg('S2');

    // Kasir A membuka shift lebih dulu
    openShiftRequest($cashierA, ['opening_cash' => 100000]);

    // Kasir B mencoba membuka shift di outlet yang sama
    $response = openShiftRequest($cashierB, ['opening_cash' => 50000]);

    // Tidak ada shift baru yang dibuat untuk kasir B
    $this->assertDatabaseMissing('cash_shifts', [
        'store_id' => $store->id,
        'user_id' => $cashierB->id,
    ]);

    // Flash berisi detail kasir yang mengoper shift aktif (untuk popup konfirmasi)
    $response->assertSessionHas('shift_blocked', function (array $data) use ($cashierA) {
        return isset($data['shift_number'])
            && $data['user_name'] === $cashierA->name
            && isset($data['opened_at']);
    });

    // Shift kasir A tetap aktif (tidak tertutup)
    $shiftA = CashShift::query()->where('user_id', $cashierA->id)->first();
    expect($shiftA->isOpen())->toBeTrue();
});

test('admin dapat menutup shift yang dibuka oleh kasir lain', function () {
    [$store, $cashierA] = makeShiftOrg('S4');
    $admin = User::factory()->create(['store_id' => $store->id]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    openShiftRequest($cashierA, ['opening_cash' => 100000]);
    $shift = CashShift::query()->where('user_id', $cashierA->id)->first();

    $response = $this->actingAs($admin)
        ->withSession(['_token' => 'csrf-token'])
        ->post("/cash-shifts/{$shift->id}/close", [
            '_token' => 'csrf-token',
            'closing_cash_counted' => 100000,
            'closing_notes' => 'Tutup paksa oleh admin',
        ]);
    
    $response->assertRedirect();
    $shift->refresh();
    expect($shift->isOpen())->toBeFalse();
    expect($shift->closing_notes)->toBe('Tutup paksa oleh admin');
});

test('owner dapat menutup shift yang dibuka oleh kasir lain', function () {
    [$store, $cashierA] = makeShiftOrg('S5');
    $owner = User::factory()->create(['store_id' => $store->id]);
    $store->members()->attach($owner->id, ['role' => 'owner']);

    openShiftRequest($cashierA, ['opening_cash' => 100000]);
    $shift = CashShift::query()->where('user_id', $cashierA->id)->first();

    $response = $this->actingAs($owner)
        ->withSession(['_token' => 'csrf-token'])
        ->post("/cash-shifts/{$shift->id}/close", [
            '_token' => 'csrf-token',
            'closing_cash_counted' => 100000,
            'closing_notes' => 'Tutup paksa oleh owner',
        ]);
    
    $response->assertRedirect();
    $shift->refresh();
    expect($shift->isOpen())->toBeFalse();
    expect($shift->closing_notes)->toBe('Tutup paksa oleh owner');
});

// ═══════════════════════════════════════════════════════
// Skenario 3: Kasir kedua dapat membuka shift setelah shift pertama ditutup
// ═══════════════════════════════════════════════════════

test('kasir kedua dapat membuka shift baru setelah shift aktif sebelumnya ditutup', function () {
    [$store, $cashierA, $cashierB] = makeShiftOrg('S3');

    // Kasir A membuka shift
    openShiftRequest($cashierA, ['opening_cash' => 100000]);
    $shiftA = CashShift::query()->where('user_id', $cashierA->id)->first();

    // Kasir A menutup shift-nya
    $closeResponse = $this->actingAs($cashierA)
        ->withSession(['_token' => 'csrf-token'])
        ->post("/cash-shifts/{$shiftA->id}/close", [
            '_token' => 'csrf-token',
            'closing_cash_counted' => 100000,
            'closing_notes' => 'Tutup shift sore',
        ]);
    $closeResponse->assertRedirect();

    // Kasir B kini bisa membuka shift baru
    $response = openShiftRequest($cashierB, ['opening_cash' => 75000]);

    $this->assertDatabaseHas('cash_shifts', [
        'store_id' => $store->id,
        'user_id' => $cashierB->id,
        'opening_cash' => 75000,
    ]);
    $response->assertSessionMissing('shift_blocked');

    $shiftB = CashShift::query()->where('user_id', $cashierB->id)->first();
    expect($shiftB->isOpen())->toBeTrue();
});

test('menutup shift via inertia mengembalikan redirect bukan json', function () {
    [$store, $cashierA] = makeShiftOrg('S3B');

    openShiftRequest($cashierA, ['opening_cash' => 100000]);
    $shift = CashShift::query()->where('user_id', $cashierA->id)->first();

    $response = $this->actingAs($cashierA)
        ->withSession(['_token' => 'csrf-token'])
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post("/cash-shifts/{$shift->id}/close", [
            '_token' => 'csrf-token',
            'closing_cash_counted' => 100000,
        ]);

    $response->assertRedirect(route('cash-shifts.index'));
    $response->assertSessionHas('success');
});

// ═══════════════════════════════════════════════════════
// Skenario 4 (isolasi): Shift aktif di outlet A tidak memblokir outlet B
// ═══════════════════════════════════════════════════════

test('shift aktif di satu outlet tidak memblokir pembukaan shift di outlet lain', function () {
    [$storeA, $cashierA] = makeShiftOrg('S4A');
    [$storeB, $cashierB] = makeShiftOrg('S4B');

    openShiftRequest($cashierA, ['opening_cash' => 100000]);

    $response = openShiftRequest($cashierB, ['opening_cash' => 200000]);

    $this->assertDatabaseHas('cash_shifts', [
        'store_id' => $storeB->id,
        'user_id' => $cashierB->id,
        'opening_cash' => 200000,
    ]);
    $response->assertSessionMissing('shift_blocked');
});
