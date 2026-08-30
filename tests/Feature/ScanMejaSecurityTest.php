<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function scanMejaStore(string $suffix): Store
{
    return Store::query()->create([
        'name' => "Scan Meja {$suffix}",
        'slug' => "scan-meja-{$suffix}",
        'is_active' => true,
        'settings' => ['scan_meja_enabled' => true],
    ]);
}

function scanMejaTable(Store $store, string $slug): Table
{
    return Table::query()->create([
        'store_id' => $store->id,
        'name' => "Meja {$slug}",
        'slug' => $slug,
        'is_active' => true,
    ]);
}

test('status pesanan publik memerlukan token transaksi acak', function () {
    $store = scanMejaStore('status');
    $table = scanMejaTable($store, 'meja-asal');
    $transaction = Transaction::query()->create([
        'store_id' => $store->id,
        'status_token' => 'status-token-rahasia',
        'status' => 'pending',
        'order_status' => 'pending',
        'payment_method' => 'cash',
        'payment_status' => 'unpaid',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'amount_paid' => 0,
        'payment_details' => ['source' => 'scan_meja', 'table_slug' => $table->slug],
    ]);

    $this->get("/m/{$table->slug}/status/{$transaction->id}")->assertNotFound();
    $this->get("/m/{$table->slug}/status/{$transaction->status_token}")->assertOk();
    $this->getJson("/api/guest/order/{$transaction->id}/status")->assertNotFound();
    $this->getJson("/api/guest/order/{$transaction->status_token}/status")
        ->assertOk()
        ->assertJsonPath('id', $transaction->id);
});

test('order tamu menolak produk non-sellable dan menerima bank transfer', function () {
    $store = scanMejaStore('order');
    $table = scanMejaTable($store, 'meja-order');
    $rawMaterial = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Bahan Baku',
        'price' => 1000,
        'inventory_type' => 'raw_material',
        'is_active' => true,
    ]);
    $sellable = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Jual',
        'price' => 10000,
        'inventory_type' => 'sellable',
        'is_active' => true,
    ]);

    $this->postJson('/api/guest/order', [
        'table_slug' => $table->slug,
        'items' => [['product_id' => $rawMaterial->id, 'quantity' => 1]],
    ])->assertUnprocessable();

    $this->postJson('/api/guest/order', [
        'table_slug' => $table->slug,
        'payment_method' => 'bank_transfer',
        'items' => [['product_id' => $sellable->id, 'quantity' => 1]],
    ])->assertOk()
        ->assertJsonPath('transaction.status_token', fn ($token) => is_string($token) && strlen($token) === 64);

    $this->assertDatabaseHas('transactions', [
        'store_id' => $store->id,
        'payment_method' => 'bank_transfer',
    ]);
});
