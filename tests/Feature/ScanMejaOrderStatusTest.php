<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function statusOrder(array $overrides = []): array
{
    $store = Store::query()->create([
        'name' => 'Kedai Status',
        'slug' => 'kedai-status-'.uniqid(),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => [
            'scan_meja_enabled' => true,
            'scan_meja_payment_methods' => [
                ['type' => 'cash', 'label' => 'Tunai', 'active' => true],
                ['type' => 'bank_transfer', 'label' => 'Transfer BCA', 'active' => true, 'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Kedai Status'],
            ],
        ],
    ]);
    $table = Table::query()->create(['store_id' => $store->id, 'name' => 'Meja 7', 'slug' => 'meja-status-'.uniqid(), 'is_active' => true]);

    $transaction = Transaction::query()->create(array_merge([
        'store_id' => $store->id,
        'status_token' => 'token-'.uniqid(),
        'status' => 'pending',
        'order_status' => 'pending',
        'payment_method' => 'bank_transfer',
        'payment_status' => 'unpaid',
        'subtotal' => 12000,
        'total_amount' => 12000,
        'amount_paid' => 0,
        'order_type' => 'dine_in',
        'table_reference' => $table->name,
        'notes' => 'Pesanan dari Meja 7 (Scan Meja)',
        'payment_details' => ['source' => 'scan_meja', 'table_slug' => $table->slug],
    ], $overrides));

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Es Teh',
        'price' => 6000,
        'inventory_type' => 'sellable',
        'is_active' => true,
    ]);

    TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => 'Es Teh',
        'quantity' => 2,
        'unit_price' => 6000,
        'total_price' => 12000,
        'modifiers' => [['name' => 'Catatan', 'value' => 'Kurang gula']],
    ]);

    return [$table, $transaction];
}

test('halaman status memuat detail rekening metode bayar dan catatan item', function () {
    [$table, $transaction] = statusOrder();

    $this->get("/m/{$table->slug}/status/{$transaction->status_token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ScanMeja/Guest/OrderStatus')
            ->where('paymentMethod.account_number', '1234567890')
            ->where('transaction.items.0.note', 'Kurang gula')
            ->where('transaction.reject_reason', null));
});

test('alasan penolakan kasir tampil di halaman dan api status', function () {
    [$table, $transaction] = statusOrder([
        'order_status' => 'cancelled',
        'status' => 'cancelled',
        'notes' => "Pesanan dari Meja 7 (Scan Meja)\n[Ditolak kasir: Menu habis]",
    ]);

    $this->get("/m/{$table->slug}/status/{$transaction->status_token}")
        ->assertInertia(fn ($page) => $page->where('transaction.reject_reason', 'Menu habis'));

    $this->getJson("/api/guest/order/{$transaction->status_token}/status")
        ->assertOk()
        ->assertJsonPath('reject_reason', 'Menu habis')
        ->assertJsonPath('items.0.note', 'Kurang gula');
});
