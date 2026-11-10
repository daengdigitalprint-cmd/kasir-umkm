<?php

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\LoyaltyRedemption;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: Store, 1: User, 2: Product, 3: Transaction} */
function makeVoidableSale(): array
{
    $plan = SubscriptionPlan::query()->firstOrCreate(
        ['slug' => 'starter'],
        ['name' => 'Paket Starter', 'price' => 0, 'billing_cycle' => 'monthly', 'is_active' => true],
    );

    $store = Store::query()->create([
        'name' => 'Toko Uji Void POS',
        'slug' => 'pos-void-'.uniqid(),
        'business_type' => 'general',
        'is_active' => true,
        'is_subscription_active' => true,
        'subscription_plan_id' => $plan->id,
        'subscription_end' => now()->addDay(),
    ]);

    $cashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($cashier->id, ['role' => 'cashier']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Teh Manis',
        'sku' => 'PV-'.uniqid(),
        'price' => 10000,
        'cost_price' => 4000,
        'stock_quantity' => 20,
        'unit' => 'cup',
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
        'track_stock' => true,
        'is_active' => true,
    ]);

    test()->actingAs($cashier)
        ->postJson('/pos/process-payment', [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 10000,
                'subtotal' => 20000,
                'product_name' => 'Teh Manis',
            ]],
            'payment_method' => 'cash',
            'amount_paid' => 20000,
        ])
        ->assertOk();

    $transaction = Transaction::query()->where('store_id', $store->id)->firstOrFail();

    return [$store, $cashier, $product, $transaction];
}

test('kasir dapat void transaksinya sendiri dari POS dan stok dikembalikan', function () {
    [, $cashier, $product, $transaction] = makeVoidableSale();

    expect((float) $product->fresh()->stock_quantity)->toBe(18.0);

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Salah input'])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->status)->toBe('void')
        ->and((bool) $transaction->is_void)->toBeTrue()
        ->and($transaction->void_reason)->toBe('Salah input')
        ->and((int) $transaction->void_by)->toBe($cashier->id)
        ->and((float) $product->fresh()->stock_quantity)->toBe(20.0);
});

test('void dari POS wajib menyertakan alasan', function () {
    [, $cashier, , $transaction] = makeVoidableSale();

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('void_reason');

    expect($transaction->fresh()->status)->toBe('completed');
});

test('kasir tidak dapat void transaksi milik kasir lain', function () {
    [$store, , , $transaction] = makeVoidableSale();

    $otherCashier = User::factory()->create([
        'store_id' => $store->id,
        'role' => 'cashier',
        'email_verified_at' => now(),
    ]);
    $store->members()->attach($otherCashier->id, ['role' => 'cashier']);

    $this->actingAs($otherCashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Coba'])
        ->assertForbidden();

    expect($transaction->fresh()->status)->toBe('completed');
});

test('transaksi yang sudah void tidak dapat di-void ulang', function () {
    [, $cashier, $product, $transaction] = makeVoidableSale();

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Pertama'])
        ->assertOk();

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Kedua'])
        ->assertUnprocessable();

    expect((float) $product->fresh()->stock_quantity)->toBe(20.0);
});

test('void ditolak bila shift kasir sudah ditutup', function () {
    [$store, $cashier, , $transaction] = makeVoidableSale();

    $shift = CashShift::query()->forceCreate([
        'store_id' => $store->id,
        'user_id' => $cashier->id,
        'shift_number' => 'SH-TEST-1',
        'opening_cash' => 0,
        'opened_at' => now()->subHour(),
        'closed_at' => now(),
    ]);
    $transaction->forceFill(['cash_shift_id' => $shift->id])->save();

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Terlambat'])
        ->assertUnprocessable();

    expect($transaction->fresh()->status)->toBe('completed');
});

test('void melepas voucher loyalitas yang dipakai sehingga bisa digunakan lagi', function () {
    [$store, $cashier, , $transaction] = makeVoidableSale();

    $customer = Customer::query()->forceCreate([
        'store_id' => $store->id,
        'name' => 'Pelanggan Voucher',
    ]);

    $redemption = LoyaltyRedemption::query()->forceCreate([
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'reward_name' => 'Voucher 5rb',
        'reward_type' => 'voucher',
        'points_spent' => 50,
        'voucher_value' => 5000,
        'status' => 'fulfilled',
        'voucher_code' => 'VCVOID01',
        'used_at' => now(),
        'used_transaction_id' => $transaction->id,
    ]);

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Batal'])
        ->assertOk();

    $redemption->refresh();

    expect($redemption->used_at)->toBeNull()
        ->and($redemption->used_transaction_id)->toBeNull();
});

test('riwayat transaksi POS menampilkan transaksi void', function () {
    [, $cashier, , $transaction] = makeVoidableSale();

    $this->actingAs($cashier)
        ->postJson("/pos/transactions/{$transaction->id}/void", ['void_reason' => 'Batal'])
        ->assertOk();

    $this->actingAs($cashier)
        ->get('/pos/transactions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Sales')
            ->where('transactions.data.0.id', $transaction->id)
            ->where('transactions.data.0.is_void', true));
});
