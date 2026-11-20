<?php

use App\Models\Customer;
use App\Models\ReceivablePayment;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Mencatat pembayaran piutang (uang masuk + jurnal kas) hanya untuk back office
 * (admin/manajer/pemilik) atau peran yang diberi menu Piutang/Pelanggan di Tim & Akses.
 */
function arAccessStore(array $menuOverrides = []): Store
{
    $settings = ['receivables_enabled' => true];
    if ($menuOverrides !== []) {
        $settings['role_menu_permissions'] = $menuOverrides;
    }

    return Store::query()->create([
        'name' => 'Toko Akses Piutang',
        'slug' => 'akses-piutang-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
        'settings' => $settings,
    ]);
}

function arAccessUser(Store $store, string $role): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => $role, 'email_verified_at' => now()]);
    $user->stores()->attach($store->id, ['role' => $role]);

    return $user;
}

function arAccessBill(Store $store, User $user): array
{
    $customer = Customer::query()->create(['store_id' => $store->id, 'name' => 'Bu Rina']);
    $id = DB::table('transactions')->insertGetId([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'transaction_number' => 'TXN-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'credit',
        'payment_status' => 'unpaid',
        'subtotal' => 50000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 50000,
        'amount_paid' => 0,
        'change_amount' => 0,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    return [$customer, Transaction::query()->findOrFail($id)];
}

function arAccessPay($test, User $user, Customer $customer, Transaction $bill)
{
    $payload = ['amount' => 10000, 'payment_method' => 'cash', 'received_at' => now()->toDateString()];

    return [
        $test->actingAs($user)->post('/receivable-payments', $payload + ['transaction_id' => $bill->id]),
        $test->actingAs($user)->post("/receivables/customers/{$customer->id}/payments", $payload),
    ];
}

test('staf tanpa menu piutang tidak dapat mencatat pembayaran piutang', function (string $role) {
    $store = arAccessStore();
    [$customer, $bill] = arAccessBill($store, arAccessUser($store, 'admin'));

    foreach (arAccessPay($this, arAccessUser($store, $role), $customer, $bill) as $response) {
        $response->assertRedirect(route('dashboard'))->assertSessionHas('error');
    }

    expect(ReceivablePayment::query()->count())->toBe(0)
        ->and($bill->fresh()->payment_status)->toBe('unpaid');
})->with(['warehouse', 'cashier', 'kitchen']);

test('admin tetap dapat mencatat pembayaran piutang', function () {
    $store = arAccessStore();
    $admin = arAccessUser($store, 'admin');
    [$customer, $bill] = arAccessBill($store, $admin);

    foreach (arAccessPay($this, $admin, $customer, $bill) as $response) {
        $response->assertSessionHasNoErrors();
    }

    expect((float) ReceivablePayment::query()->sum('amount'))->toEqual(20000.0);
});

test('kasir yang diberi menu Piutang atau Pelanggan dapat mencatat pembayaran', function (string $menu) {
    $store = arAccessStore(['cashier' => ['dashboard', 'pos', $menu]]);
    $cashier = arAccessUser($store, 'cashier');
    [$customer, $bill] = arAccessBill($store, arAccessUser($store, 'admin'));

    foreach (arAccessPay($this, $cashier, $customer, $bill) as $response) {
        $response->assertSessionHasNoErrors();
    }

    expect((float) ReceivablePayment::query()->sum('amount'))->toEqual(20000.0);
})->with(['receivables', 'customers']);
