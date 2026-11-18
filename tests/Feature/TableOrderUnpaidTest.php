<?php

use App\Models\CashShift;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\KitchenOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Store, 1: User, 2: Transaction}
 */
function unpaidTableOrder(string $orderStatus = 'ready', ?string $kitchenStatus = 'completed'): array
{
    $store = Store::query()->create([
        'name' => 'Kedai Tagihan',
        'slug' => 'kedai-tagihan-'.uniqid(),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => ['scan_meja_enabled' => true, 'kitchen_queue_enabled' => true],
    ]);
    $cashier = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);

    $trx = Transaction::query()->create([
        'store_id' => $store->id,
        'status_token' => 'tok-'.uniqid(),
        'status' => 'pending',
        'order_status' => $orderStatus,
        'payment_method' => 'cash',
        'payment_status' => 'unpaid',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'amount_paid' => 0,
        'order_type' => 'dine_in',
        'table_reference' => 'Meja 5',
        'payment_details' => ['source' => 'scan_meja', 'customer_name' => 'Andi'],
    ]);

    if ($kitchenStatus) {
        $queue = $store->queues()->create(['name' => 'Dapur', 'code' => KitchenOrders::KITCHEN_QUEUE_CODE, 'is_active' => true]);
        $queue->entries()->create([
            'store_id' => $store->id,
            'number' => 1,
            'status' => $kitchenStatus,
            'transaction_id' => $trx->id,
            'items' => [],
        ]);
    }

    return [$store, $cashier, $trx];
}

test('pesanan meja yang sudah diantar tapi belum bayar tampil di tab belum bayar dengan status dapur', function () {
    [, $cashier, $trx] = unpaidTableOrder();

    $this->actingAs($cashier)->get('/table-orders?status=accepted')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.accepted', 1)
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $trx->id)
            ->where('orders.data.0.kitchen_status', 'completed')
            ->where('unpaid_table_order_count', 1));
});

test('panel open bill pos mendapat daftar pesanan meja belum bayar', function () {
    [, $cashier, $trx] = unpaidTableOrder('accepted', 'serving');

    $this->actingAs($cashier)->getJson('/table-orders/unpaid')
        ->assertOk()
        ->assertJsonCount(1, 'orders')
        ->assertJsonPath('orders.0.id', $trx->id)
        ->assertJsonPath('orders.0.table_reference', 'Meja 5')
        ->assertJsonPath('orders.0.customer_name', 'Andi')
        ->assertJsonPath('orders.0.kitchen_status', 'serving');
});

test('kasir bisa membayar pesanan meja dari pos lewat json', function () {
    [$store, $cashier, $trx] = unpaidTableOrder();

    // Tanpa shift terbuka → ditolak dengan pesan jelas.
    $this->actingAs($cashier)->postJson("/table-orders/{$trx->id}/pay", ['payment_method' => 'cash'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Buka shift kasir terlebih dahulu.');

    CashShift::query()->create(['store_id' => $store->id, 'user_id' => $cashier->id, 'opening_cash' => 0, 'opened_at' => now()]);

    $this->actingAs($cashier)->postJson("/table-orders/{$trx->id}/pay", ['payment_method' => 'qris'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $trx->refresh();
    expect($trx->payment_status)->toBe('paid')
        ->and($trx->order_status)->toBe('completed')
        ->and($trx->payment_method)->toBe('qris');

    $this->actingAs($cashier)->getJson('/table-orders/unpaid')->assertJsonCount(0, 'orders');
    expect(QueueEntry::query()->where('transaction_id', $trx->id)->value('status'))->toBe('completed');
});

test('pesanan yang sudah lunas atau belum diterima tidak dihitung sebagai tagihan meja', function () {
    [$store, $cashier] = unpaidTableOrder('pending', null);

    Transaction::query()->create([
        'store_id' => $store->id,
        'status_token' => 'tok-'.uniqid(),
        'status' => 'completed',
        'order_status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 5000,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'order_type' => 'dine_in',
        'table_reference' => 'Meja 2',
        'payment_details' => ['source' => 'scan_meja'],
    ]);

    $this->actingAs($cashier)->getJson('/table-orders/unpaid')->assertJsonCount(0, 'orders');
});
