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
        // Zona UTC agar "kemarin" pada tes sama dengan kemarin menurut jam server tes.
        'timezone' => 'UTC',
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

    // Produk sudah lama ada: laju harian dibagi seluruh jendela 14 hari.
    $product->forceFill(['created_at' => now()->subDays(60)])->save();

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

it('memakai pendapatan bersih tanpa pajak pada digest', function () {
    $store = createDigestStore();
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = Product::query()->create([
        'store_id' => $store->id, 'name' => 'Nasi Goreng', 'price' => 100000,
        'stock_quantity' => 50, 'min_stock_level' => 0, 'track_stock' => true, 'is_active' => true,
    ]);

    recordDigestSale($store, $user, $product, 1, now()->subDay()->setTime(12, 0));
    Transaction::query()->where('store_id', $store->id)->update(['tax_amount' => 11000, 'total_amount' => 111000]);

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    $content = AiMessage::query()->where('role', 'assistant')->value('content');

    expect($content)->toContain('Pendapatan: Rp 100.000')
        ->and($content)->not->toContain('111.000');
});

it('tidak melaporkan produk "sisa 0 (min 0)" yang tidak bergerak sebagai stok menipis', function () {
    $store = createDigestStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    Product::query()->create([
        'store_id' => $store->id, 'name' => 'Menu Musiman', 'price' => 10000,
        'stock_quantity' => 0, 'min_stock_level' => 0, 'track_stock' => true, 'is_active' => true,
    ]);

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    expect(AiMessage::query()->count())->toBe(0);
});

it('tidak mengulang digest stok yang sama untuk toko tanpa penjualan', function () {
    $store = createDigestStore();
    User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    Product::query()->create([
        'store_id' => $store->id, 'name' => 'Plastik Cup', 'price' => 500,
        'stock_quantity' => 100, 'min_stock_level' => 350, 'track_stock' => true, 'is_active' => true,
    ]);

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    $this->travel(1)->days();
    $this->artisan('ai:daily-digest')->assertExitCode(0);

    expect(AiMessage::query()->where('role', 'assistant')->count())->toBe(1);
});

it('menentukan "kemarin" menurut zona waktu toko', function () {
    // 14 Nov 18:00 UTC = 15 Nov 02:00 WITA → kemarin (toko) = 14 Nov.
    $this->travelTo(\Carbon\Carbon::parse('2026-11-14 18:00:00', 'UTC'));

    $store = createDigestStore();
    $store->update(['timezone' => 'Asia/Makassar']);
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = Product::query()->create([
        'store_id' => $store->id, 'name' => 'Es Teh', 'price' => 5000,
        'stock_quantity' => 50, 'min_stock_level' => 0, 'track_stock' => true, 'is_active' => true,
    ]);

    // 14 Nov 10:00 WITA (= 02:00 UTC).
    recordDigestSale($store, $user, $product, 2, \Carbon\Carbon::parse('2026-11-14 02:00:00', 'UTC'));

    $this->artisan('ai:daily-digest')->assertExitCode(0);

    $content = AiMessage::query()->where('role', 'assistant')->value('content');

    expect($content)->toContain('Digest Harian 2026-11-14')
        ->and($content)->toContain('Pendapatan: Rp 10.000');
});
