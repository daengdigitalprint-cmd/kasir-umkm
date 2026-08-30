<?php

use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{store: Store, user: User}
 */
function loyaltyStore(array $loyalty = []): array
{
    $store = Store::query()->create([
        'name' => 'Toko Loyalitas',
        'slug' => 'toko-loyalitas-'.uniqid(),
        'is_active' => true,
        'currency' => 'IDR',
        'settings' => ['loyalty' => array_merge([
            'enabled' => true,
            'rupiah_per_point' => 10000,
            'expiry_months' => 12,
            'expiry_warn_days' => 30,
        ], $loyalty)],
    ]);

    $user = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    return ['store' => $store, 'user' => $user];
}

function loyaltyCustomer(Store $store): Customer
{
    return $store->customers()->create(['name' => 'Budi']);
}

test('poin diperoleh dari nilai transaksi sesuai aturan', function () {
    ['store' => $store, 'user' => $user] = loyaltyStore();
    $customer = loyaltyCustomer($store);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 55000,
        'total_amount' => 55000,
        'amount_paid' => 55000,
        'change_amount' => 0,
    ]);

    $earned = app(LoyaltyService::class)->earnFromTransaction($transaction);

    // 55.000 / 10.000 = 5 poin (dibulatkan ke bawah)
    expect($earned)->toBe(5);
    expect($customer->fresh()->points_balance)->toBe(5);
    expect($customer->pointLedgers()->where('type', 'earn')->count())->toBe(1);
});

test('poin tidak diperoleh saat loyalitas nonaktif', function () {
    ['store' => $store, 'user' => $user] = loyaltyStore(['enabled' => false]);
    $customer = loyaltyCustomer($store);

    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'payment_method' => 'cash',
        'subtotal' => 55000,
        'total_amount' => 55000,
        'amount_paid' => 55000,
        'change_amount' => 0,
    ]);

    expect(app(LoyaltyService::class)->earnFromTransaction($transaction))->toBe(0);
    expect($customer->fresh()->points_balance)->toBe(0);
});

test('penukaran hadiah berhasil saat saldo cukup', function () {
    ['store' => $store, 'user' => $user] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    $service = app(LoyaltyService::class);
    $service->earn($customer, 500, 'earn', 'seed');

    $reward = LoyaltyReward::query()->create([
        'store_id' => $store->id,
        'name' => 'Voucher 10rb',
        'type' => 'voucher',
        'points_cost' => 300,
        'voucher_value' => 10000,
        'stock' => 5,
        'is_active' => true,
    ]);

    $redemption = $service->redeem($customer->fresh(), $reward, $user->id);

    expect($redemption->points_spent)->toBe(300);
    expect($redemption->voucher_code)->not->toBeNull();
    expect($customer->fresh()->points_balance)->toBe(200);
    expect($reward->fresh()->stock)->toBe(4);
    expect($reward->fresh()->redeemed_count)->toBe(1);
});

test('penukaran ditolak saat saldo tidak cukup', function () {
    ['store' => $store] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    app(LoyaltyService::class)->earn($customer, 100, 'earn', 'seed');

    $reward = LoyaltyReward::query()->create([
        'store_id' => $store->id,
        'name' => 'Voucher mahal',
        'type' => 'voucher',
        'points_cost' => 300,
        'voucher_value' => 10000,
        'stock' => 5,
        'is_active' => true,
    ]);

    expect(fn () => app(LoyaltyService::class)->redeem($customer->fresh(), $reward))
        ->toThrow(RuntimeException::class);

    expect($customer->fresh()->points_balance)->toBe(100);
    expect($reward->fresh()->stock)->toBe(5);
});

test('penukaran ditolak saat stok hadiah habis', function () {
    ['store' => $store] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    app(LoyaltyService::class)->earn($customer, 500, 'earn', 'seed');

    $reward = LoyaltyReward::query()->create([
        'store_id' => $store->id,
        'name' => 'Voucher habis',
        'type' => 'voucher',
        'points_cost' => 100,
        'voucher_value' => 10000,
        'stock' => 0,
        'is_active' => true,
    ]);

    expect(fn () => app(LoyaltyService::class)->redeem($customer->fresh(), $reward))
        ->toThrow(RuntimeException::class);
});

test('poin kadaluarsa dihitung otomatis', function () {
    ['store' => $store] = loyaltyStore(['expiry_months' => 12]);
    $customer = loyaltyCustomer($store);
    $service = app(LoyaltyService::class);
    $ledger = $service->earn($customer, 200, 'earn', 'seed');

    // Paksa baris ledger sudah kadaluarsa.
    $ledger->forceFill(['expires_at' => now()->subDay()])->save();

    $expired = $service->expirePointsForStore($store);

    expect($expired)->toBe(200);
    expect($customer->fresh()->points_balance)->toBe(0);
    expect($customer->pointLedgers()->where('type', 'expire')->count())->toBe(1);
});

test('penyesuaian negatif tidak boleh membuat saldo negatif', function () {
    ['store' => $store] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    app(LoyaltyService::class)->earn($customer, 50, 'earn', 'seed');

    expect(fn () => app(LoyaltyService::class)->adjust($customer->fresh(), -100, 'koreksi'))
        ->toThrow(RuntimeException::class);

    expect($customer->fresh()->points_balance)->toBe(50);
});

test('baris ledger memiliki hash integritas yang valid', function () {
    ['store' => $store] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    $ledger = app(LoyaltyService::class)->earn($customer, 100, 'earn', 'seed');

    expect($ledger->fresh()->hasValidIntegrity())->toBeTrue();
});

test('panel admin loyalitas menampilkan statistik akurat', function () {
    ['store' => $store, 'user' => $user] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    app(LoyaltyService::class)->earn($customer, 300, 'earn', 'seed');

    $this->actingAs($user)
        ->get('/loyalty')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Loyalty/Index')
            ->where('stats.total_circulating', 300)
            ->where('stats.total_earned', 300)
            ->where('stats.members_with_points', 1)
        );
});

test('endpoint redeem admin memotong saldo poin pelanggan', function () {
    ['store' => $store, 'user' => $user] = loyaltyStore();
    $customer = loyaltyCustomer($store);
    app(LoyaltyService::class)->earn($customer, 500, 'earn', 'seed');

    $reward = LoyaltyReward::query()->create([
        'store_id' => $store->id,
        'name' => 'Voucher 20rb',
        'type' => 'voucher',
        'points_cost' => 200,
        'voucher_value' => 20000,
        'stock' => null,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post('/loyalty/redeem', [
            'customer_id' => $customer->id,
            'reward_id' => $reward->id,
        ])
        ->assertRedirect();

    expect($customer->fresh()->points_balance)->toBe(300);
    expect($store->loyaltyRedemptions()->count())->toBe(1);
});
