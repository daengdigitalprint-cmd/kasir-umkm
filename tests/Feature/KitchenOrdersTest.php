<?php

use App\Events\KitchenQueueUpdated;
use App\Models\Product;
use App\Models\QueueEntry;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\KitchenOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Papan Dapur hanya untuk toko yang menyalakan "Antrian Dapur";
 * toko lain (termasuk pemakai "Antrian Pelanggan") tidak berubah perilakunya.
 */
function kitchenStore(array $settings): Store
{
    return Store::query()->create([
        'name' => 'Kafe Uji',
        'slug' => 'kafe-uji-'.uniqid(),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => $settings,
        'is_subscription_active' => true,
        'subscription_plan_id' => \App\Models\SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'bisnis'],
            ['name' => 'GrowUp', 'price' => 99000, 'billing_cycle' => 'monthly', 'is_active' => true],
        )->id,
        'subscription_end' => now()->addMonth(),
    ]);
}

function kitchenTransaction(Store $store, array $items = []): Transaction
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin']);
    $product = Product::query()->create(['store_id' => $store->id, 'name' => 'Es Kopi Susu', 'price' => 20000]);

    $trx = Transaction::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'transaction_number' => 'TRX-'.uniqid(),
        'status' => 'completed',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal' => 20000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 20000,
        'amount_paid' => 20000,
        'change_amount' => 0,
    ]);

    foreach ($items ?: [['Es Kopi Susu', 2, 'Large', [['key' => 'gula', 'label' => 'Less sugar', 'price' => 0], ['key' => '__note', 'label' => 'Catatan: tanpa es', 'price' => 0]]]] as [$name, $qty, $variant, $mods]) {
        TransactionItem::query()->create([
            'transaction_id' => $trx->id,
            'product_id' => $product->id,
            'product_name' => $name,
            'variant_label' => $variant,
            'modifiers' => $mods,
            'quantity' => $qty,
            'unit_price' => 20000,
            'total_price' => 20000 * $qty,
        ]);
    }

    return $trx;
}

beforeEach(fn () => Event::fake([KitchenQueueUpdated::class]));

it('meneruskan pesanan ke dapur lengkap dengan varian dan pilihan bila Antrian Dapur aktif', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $trx = kitchenTransaction($store);

    $entry = KitchenOrders::enqueue($store, $trx, ['order_type' => 'takeaway']);

    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe('waiting')
        ->and($entry->number)->toBe(1)
        ->and($entry->queue->code)->toBe(KitchenOrders::KITCHEN_QUEUE_CODE)
        ->and($entry->items[0]['variant'])->toBe('Large')
        ->and(collect($entry->items[0]['modifiers'])->pluck('label')->all())->toBe(['Less sugar', 'Catatan: tanpa es']);

    Event::assertDispatched(KitchenQueueUpdated::class);
});

it('tidak membuat apa pun untuk toko F&B yang tidak menyalakan Antrian Dapur', function () {
    $store = kitchenStore([]);
    $trx = kitchenTransaction($store);

    expect(KitchenOrders::enqueue($store, $trx))->toBeNull()
        ->and(QueueEntry::query()->count())->toBe(0);
});

it('tetap memakai perilaku lama untuk Antrian Pelanggan: nomor berlanjut di antrean Kasir', function () {
    $store = kitchenStore(['queue_module_enabled' => true]);

    $first = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $first->forceFill(['created_at' => now()->subDay()])->saveQuietly();
    $second = KitchenOrders::enqueue($store, kitchenTransaction($store));

    expect($first->queue->code)->toBe('Q001')
        ->and($second->number)->toBe(2);
});

it('memulai nomor dapur dari 1 setiap hari', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);

    $yesterday = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $yesterday->forceFill(['created_at' => now()->subDay()])->saveQuietly();
    $today = KitchenOrders::enqueue($store, kitchenTransaction($store));

    expect($today->number)->toBe(1);
});

it('tidak membuat entri ganda untuk transaksi yang sama', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $trx = kitchenTransaction($store);

    KitchenOrders::enqueue($store, $trx);
    KitchenOrders::enqueue($store, $trx);

    expect(QueueEntry::query()->where('transaction_id', $trx->id)->count())->toBe(1);
});

it('menghapus pesanan yang dibatalkan dari papan dapur', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $trx = kitchenTransaction($store);
    $entry = KitchenOrders::enqueue($store, $trx);

    KitchenOrders::cancelForTransaction($store, $trx);

    expect($entry->fresh()->status)->toBe('cancelled');
});

it('tidak menyentuh antrean toko tanpa Antrian Dapur saat transaksi dibatalkan', function () {
    $store = kitchenStore(['queue_module_enabled' => true]);
    $trx = kitchenTransaction($store);
    $entry = KitchenOrders::enqueue($store, $trx);

    KitchenOrders::cancelForTransaction($store, $trx);

    expect($entry->fresh()->status)->toBe('waiting');
});

it('tidak menggagalkan proses saat siaran ke layar dapur error', function () {
    Event::swap(new Illuminate\Events\Dispatcher(app()));
    Event::listen(KitchenQueueUpdated::class, fn () => throw new RuntimeException('Pusher error'));

    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $entry = KitchenOrders::enqueue($store, kitchenTransaction($store));

    expect($entry)->not->toBeNull()
        ->and($entry->exists)->toBeTrue();
});

function kitchenAdmin(Store $store): User
{
    $user = User::factory()->create(['store_id' => $store->id, 'role' => 'admin', 'email_verified_at' => now()]);
    $store->members()->attach($user->id, ['role' => 'admin']);

    return $user;
}

it('mencatat waktu siap dan bisa mengembalikan salah tekan dalam 10 menit', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $entry = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $user = kitchenAdmin($store);

    $this->actingAs($user)->patchJson("/queue-entries/{$entry->id}", ['status' => 'serving'])->assertOk();
    $this->actingAs($user)->patchJson("/queue-entries/{$entry->id}", ['status' => 'ready'])->assertOk();
    expect($entry->fresh()->ready_at)->not->toBeNull();

    $this->actingAs($user)->patchJson("/queue-entries/{$entry->id}", ['status' => 'completed'])->assertOk();
    $this->actingAs($user)->patchJson("/queue-entries/{$entry->id}", ['status' => 'ready'])->assertOk();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('ready')
        ->and($fresh->completed_at)->toBeNull();
});

it('menolak mengembalikan status setelah lewat 10 menit', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $entry = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $entry->update(['status' => 'completed', 'completed_at' => now()->subMinutes(15)]);

    $this->actingAs(kitchenAdmin($store))
        ->patchJson("/queue-entries/{$entry->id}", ['status' => 'ready'])
        ->assertStatus(422);

    expect($entry->fresh()->status)->toBe('completed');
});

it('tidak mengizinkan status mundur di toko tanpa Antrian Dapur', function () {
    $store = kitchenStore(['queue_module_enabled' => true]);
    $entry = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $entry->update(['status' => 'serving', 'called_at' => now()]);

    $this->actingAs(kitchenAdmin($store))
        ->patchJson("/queue-entries/{$entry->id}", ['status' => 'waiting'])
        ->assertStatus(422);
});

it('menampilkan riwayat selesai hari ini dan rata-rata lama masak di papan dapur', function () {
    $store = kitchenStore(['kitchen_queue_enabled' => true]);
    $entry = KitchenOrders::enqueue($store, kitchenTransaction($store));
    $entry->forceFill([
        'status' => 'completed',
        'created_at' => now()->subMinutes(12),
        'ready_at' => now()->subMinutes(4),
        'completed_at' => now()->subMinutes(2),
    ])->saveQuietly();

    // Akses menu diatur paket & peran (diuji terpisah); di sini yang diuji isi papan dapur.
    $this->withoutMiddleware(\App\Http\Middleware\EnsureMenuAccess::class);

    $this->actingAs(kitchenAdmin($store))->get('/kitchen')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Kitchen/Index')
            ->has('history', 1)
            ->where('summary.completed_today', 1)
            ->where('summary.avg_prep_minutes', fn ($v) => abs((float) $v - 8.0) < 0.2));
});
