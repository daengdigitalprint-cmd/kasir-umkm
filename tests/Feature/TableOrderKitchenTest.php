<?php

use App\Models\Product;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Transaction}
 */
function scanOrderForKitchen(bool $kitchenOn): array
{
    $store = Store::query()->create([
        'name' => 'Kedai Dapur',
        'slug' => 'kedai-dapur-'.uniqid(),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => ['scan_meja_enabled' => true, 'kitchen_queue_enabled' => $kitchenOn],
    ]);
    $admin = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);

    $trx = Transaction::query()->create([
        'store_id' => $store->id,
        'status_token' => 'tok-'.uniqid(),
        'status' => 'pending',
        'order_status' => 'pending',
        'payment_method' => 'cash',
        'payment_status' => 'unpaid',
        'subtotal' => 15000,
        'total_amount' => 15000,
        'amount_paid' => 0,
        'order_type' => 'dine_in',
        'table_reference' => 'Meja 3',
        'payment_details' => ['source' => 'scan_meja', 'customer_name' => 'Rina'],
    ]);
    $product = Product::query()->create(['store_id' => $store->id, 'name' => 'Nasi Goreng', 'price' => 15000, 'inventory_type' => 'sellable', 'is_active' => true]);
    TransactionItem::query()->create([
        'transaction_id' => $trx->id,
        'product_id' => $product->id,
        'product_name' => 'Nasi Goreng',
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
    ]);

    return [$admin, $trx];
}

test('menerima pesanan scan meja mengirimnya ke papan dapur bila antrian dapur aktif', function () {
    [$admin, $trx] = scanOrderForKitchen(true);

    $this->actingAs($admin)->post("/table-orders/{$trx->id}/accept")
        ->assertRedirect()
        ->assertSessionHas('success', 'Pesanan diterima dan diteruskan ke dapur.');

    $entry = QueueEntry::query()->where('transaction_id', $trx->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe('waiting')
        ->and($entry->table_number)->toBe('Meja 3')
        ->and($entry->customer_name)->toBe('Rina')
        ->and($entry->queue->code)->toBe(\App\Support\KitchenOrders::KITCHEN_QUEUE_CODE)
        ->and($entry->items[0]['product_name'])->toBe('Nasi Goreng')
        ->and($trx->fresh()->order_status)->toBe('accepted');

    // Diterima dua kali tidak membuat entri dapur ganda.
    \App\Support\KitchenOrders::enqueue($trx->store, $trx->fresh());
    expect(QueueEntry::query()->where('transaction_id', $trx->id)->count())->toBe(1);
});

test('bila antrian dapur mati pesanan tetap diterima tetapi kasir diberi tahu tidak masuk dapur', function () {
    [$admin, $trx] = scanOrderForKitchen(false);

    $this->actingAs($admin)->post("/table-orders/{$trx->id}/accept")
        ->assertRedirect()
        ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Papan dapur belum aktif'));

    expect(QueueEntry::query()->where('transaction_id', $trx->id)->exists())->toBeFalse()
        ->and($trx->fresh()->order_status)->toBe('accepted');
});
