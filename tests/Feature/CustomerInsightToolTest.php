<?php

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Tools\Customers\CustomerInsightTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCustomerInsightStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Customer',
        'slug' => 'toko-customer-'.uniqid(),
        'is_active' => true,
    ]);
}

function createCustomerInsightCustomer(Store $store, string $name, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'store_id' => $store->id,
        'name' => $name,
        'is_active' => true,
    ], $overrides));
}

it('menampilkan pelanggan paling berharga', function () {
    $store = createCustomerInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createCustomerInsightCustomer($store, 'Budi', ['total_spent' => 500000, 'total_orders' => 5, 'points_balance' => 120]);
    createCustomerInsightCustomer($store, 'Ani', ['total_spent' => 1500000, 'total_orders' => 10, 'points_balance' => 300]);
    createCustomerInsightCustomer($store, 'Citra', ['total_spent' => 200000, 'total_orders' => 2, 'points_balance' => 0]);

    $result = (new CustomerInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_customers'])->toBe(3)
        ->and($result->data['top_customers'][0]['name'])->toBe('Ani')
        ->and($result->data['top_customers'][0]['total_spent_formatted'])->toBe('Rp 1.500.000')
        ->and($result->data['top_customers'][0]['average_order_value'])->toBe(150000);
});

it('mendeteksi pelanggan yang mulai jarang belanja', function () {
    $store = createCustomerInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createCustomerInsightCustomer($store, 'Rina Rajin', [
        'total_spent' => 900000,
        'total_orders' => 9,
        'last_purchase_at' => now()->subDay(),
    ]);
    createCustomerInsightCustomer($store, 'Dedi Jarang', [
        'total_spent' => 400000,
        'total_orders' => 4,
        'last_purchase_at' => now()->subDays(45),
    ]);

    $result = (new CustomerInsightTool)->execute(['inactive_days' => 30], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['at_risk_customers'])->toHaveCount(1)
        ->and($result->data['at_risk_customers'][0]['name'])->toBe('Dedi Jarang')
        ->and($result->data['at_risk_customers'][0]['days_inactive'])->toBe(45)
        ->and($result->data['inactive_days_threshold'])->toBe(30);
});

it('merangkum saldo poin loyalty pelanggan', function () {
    $store = createCustomerInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    createCustomerInsightCustomer($store, 'Poin Tinggi', ['points_balance' => 500, 'points_earned_total' => 700, 'points_redeemed_total' => 200]);
    createCustomerInsightCustomer($store, 'Poin Sedikit', ['points_balance' => 50]);
    createCustomerInsightCustomer($store, 'Tanpa Poin', ['points_balance' => 0]);

    $result = (new CustomerInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['loyalty']['points_in_circulation'])->toBe(550)
        ->and($result->data['loyalty']['customers_with_points'])->toBe(2)
        ->and($result->data['loyalty']['top_by_points'][0]['name'])->toBe('Poin Tinggi')
        ->and($result->data['loyalty']['top_by_points'][0]['points_redeemed_total'])->toBe(200);
});

it('mengembalikan pesan ramah saat belum ada pelanggan', function () {
    $store = createCustomerInsightStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $result = (new CustomerInsightTool)->execute([], $user);

    expect($result->success)->toBeTrue()
        ->and($result->data['total_customers'])->toBe(0)
        ->and($result->message)->toContain('Belum ada data pelanggan');
});
