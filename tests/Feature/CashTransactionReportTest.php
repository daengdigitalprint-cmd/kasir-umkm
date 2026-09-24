<?php

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreLocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCashTransactionContext(): array
{
    $store = Store::query()->create([
        'name' => 'Toko Kas',
        'slug' => 'toko-kas-'.uniqid(),
        'location_type' => StoreLocationType::OUTLET,
        'is_active' => true,
        'is_subscription_active' => true,
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $admin = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($admin->id, ['role' => 'admin']);

    $shift = CashShift::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'opening_cash' => 100000,
        'opened_at' => now(),
    ]);

    return [$store, $cashier, $admin, $shift];
}

test('halaman kas masuk keluar menampilkan ringkasan shift', function () {
    [$store, $cashier, , $shift] = makeCashTransactionContext();

    CashTransaction::create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'cash_shift_id' => $shift->id,
        'type' => CashTransaction::TYPE_IN,
        'category' => CashTransaction::CATEGORY_NON_REVENUE,
        'amount' => 50000,
        'notes' => 'Modal kembalian',
    ]);

    $response = $this->actingAs($cashier)->get('/cash-shifts/transactions');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('CashTransactions/Index')
        ->where('summary.opening_cash', 100000)
        ->where('summary.cash_in', 50000)
        ->where('summary.expected_cash', 150000)
        ->has('transactions', 1)
    );
});

test('laporan kas masuk keluar dapat diakses admin', function () {
    [$store, $cashier, $admin, $shift] = makeCashTransactionContext();

    CashTransaction::create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'cash_shift_id' => $shift->id,
        'type' => CashTransaction::TYPE_OUT,
        'category' => CashTransaction::CATEGORY_EXPENSE,
        'amount' => 25000,
        'notes' => 'Beli galon',
    ]);

    \App\Models\Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'cash_shift_id' => $shift->id,
        'transaction_number' => 'TRX-001',
        'payment_method' => 'cash',
        'status' => 'completed',
        'subtotal' => 75000,
        'total_amount' => 75000,
        'amount_paid' => 75000,
        'change_amount' => 0,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get('/reports/cash-transactions');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Reports/CashTransactions')
        ->where('summary.pos_cash_sales', 75000)
        ->where('summary.total_in', 75000)
        ->where('summary.total_out', 25000)
        ->where('summary.count', 2)
    );
});
