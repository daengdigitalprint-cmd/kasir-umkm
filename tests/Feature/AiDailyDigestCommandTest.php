<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Notifications\AiAlertPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function createDigestStore(): Store
{
    return Store::query()->create([
        'name' => 'Toko Digest',
        'slug' => 'toko-digest-'.uniqid(),
        'is_active' => true,
    ]);
}

function recordDigestSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt): void
{
    $total = $product->price * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-DIG-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();
}

function recordDigestItemSale(Store $store, User $user, Product $product, int $quantity, \Carbon\Carbon $createdAt): void
{
    $total = $product->price * $quantity;

    $transaction = Transaction::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-DGI-'.uniqid(),
        'subtotal' => $total,
        'total_amount' => $total,
        'amount_paid' => $total,
        'change_amount' => 0,
        'payment_method' => 'cash',
        'status' => 'completed',
    ]);

    $transaction->forceFill(['created_at' => $createdAt])->save();

    TransactionItem::create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'unit_price' => $product->price,
        'total_price' => $total,
    ]);
}

it('membuat digest proaktif berisi rekap kemarin dan stok menipis', function () {
    $store = createDigestStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Bubuk Red Velvet',
        'price' => 20000,
        'stock_quantity' => 3,
        'min_stock_level' => 10,
        'track_stock' => true,
        'is_active' => true,
    ]);

    recordDigestSale($store, $user, $product, 4, now()->subDay()->setTime(12, 0));

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    $conversation = AiConversation::query()
        ->where('user_id', $user->id)
        ->where('store_id', $store->id)
        ->where('title', 'Digest Harian')
        ->first();

    expect($conversation)->not->toBeNull();

    $message = AiMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('Digest Harian '.now()->subDay()->format('Y-m-d'))
        ->and($message->content)->toContain('Rp 80.000')
        ->and($message->content)->toContain('Bubuk Red Velvet')
        ->and($message->content)->toContain('sisa 3');
});

it('tidak membuat digest duplikat saat dijalankan dua kali', function () {
    $store = createDigestStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Produk Menipis',
        'price' => 5000,
        'stock_quantity' => 1,
        'min_stock_level' => 5,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $this->artisan('ai:daily-digest')->assertExitCode(0);
    $this->artisan('ai:daily-digest')->assertExitCode(0);

    expect(AiMessage::query()->where('role', 'assistant')->count())->toBe(1);
});

it('melewati toko tanpa penjualan kemarin dan tanpa stok menipis', function () {
    $store = createDigestStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    expect(AiConversation::query()->count())->toBe(0)
        ->and(AiMessage::query()->count())->toBe(0);
});

it('mode dry-run tidak menyimpan pesan', function () {
    $store = createDigestStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Stok Tipis',
        'price' => 5000,
        'stock_quantity' => 2,
        'min_stock_level' => 10,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $this->artisan('ai:daily-digest --dry-run')->assertExitCode(0);

    expect(AiConversation::query()->count())->toBe(0)
        ->and(AiMessage::query()->count())->toBe(0);
});

it('memberi peringatan produk yang akan segera habis meski stok masih di atas minimum', function () {
    $store = createDigestStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    $product = Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Kopi Susu Instan',
        'price' => 15000,
        'stock_quantity' => 50,
        'min_stock_level' => 5,
        'unit' => 'pcs',
        'track_stock' => true,
        'is_active' => true,
    ]);

    recordDigestItemSale($store, $user, $product, 140, now()->subDays(2)->setTime(12, 0));

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    $conversation = AiConversation::query()
        ->where('user_id', $user->id)
        ->where('store_id', $store->id)
        ->where('title', 'Digest Harian')
        ->first();

    expect($conversation)->not->toBeNull();

    $message = AiMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', 'assistant')
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('🚨 Perkiraan segera habis (laju penjualan 14 hari)')
        ->and($message->content)->toContain('Kopi Susu Instan — sisa 50 pcs')
        ->and($message->content)->toContain('±10 unit/hari')
        ->and($message->content)->toContain('perkiraan habis ±5 hari lagi')
        ->and($message->content)->toContain('Siapkan restock.');
});

it('mengirim push email digest ke penerima', function () {
    Notification::fake();

    $store = createDigestStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);

    Product::query()->create([
        'store_id' => $store->id,
        'name' => 'Stok Tipis',
        'price' => 5000,
        'stock_quantity' => 2,
        'min_stock_level' => 10,
        'track_stock' => true,
        'is_active' => true,
    ]);

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    Notification::assertSentTo($user, AiAlertPushNotification::class);

    // Idempoten: run kedua tidak mengirim push duplikat.
    $this->artisan('ai:daily-digest')->assertExitCode(0);

    Notification::assertSentToTimes($user, AiAlertPushNotification::class, 1);
});
