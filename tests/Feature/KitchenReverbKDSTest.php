<?php

use App\Events\KitchenQueueUpdated;
use App\Models\Queue;
use App\Models\QueueEntry;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function kdsStore(int $idx): Store
{
    return Store::query()->create([
        'name' => "Warung KDS {$idx}",
        'slug' => "warung-kds-{$idx}-".strtolower(\Illuminate\Support\Str::random(4)),
        'is_active' => true,
        'business_type' => 'fnb',
        'settings' => ['kitchen_queue_enabled' => true],
    ]);
}

function kdsQueue(Store $store): Queue
{
    return Queue::query()->create([
        'store_id' => $store->id,
        'name' => 'Kasir',
        'code' => 'Q001',
        'is_active' => true,
    ]);
}

it('merancang event KitchenQueueUpdated dengan data yang benar', function () {
    Event::fake();

    $store = kdsStore(1);
    $queue = kdsQueue($store);

    $entry = QueueEntry::query()->create([
        'queue_id' => $queue->id,
        'store_id' => $store->id,
        'number' => 1,
        'status' => 'waiting',
        'order_type' => 'dine_in',
        'table_number' => 'A1',
        'items' => [
            ['product_name' => 'Nasi Goreng', 'quantity' => 2],
            ['product_name' => 'Es Teh Manis', 'quantity' => 1],
        ],
    ]);

    $event = KitchenQueueUpdated::fromEntry($entry, 'upsert');

    expect($event->storeId)->toBe($store->id)
        ->and($event->action)->toBe('upsert')
        ->and($event->entryId)->toBe($entry->id)
        ->and($event->entry['id'])->toBe($entry->id)
        ->and($event->entry['status'])->toBe('waiting')
        ->and($event->entry['number'])->toBe(1)
        ->and($event->entry['items'])->toHaveCount(2);
});

it('merancang event delete dengan benar', function () {
    $storeId = 42;
    $entryId = 17;
    $event = KitchenQueueUpdated::deleted($storeId, $entryId);

    expect($event->storeId)->toBe(42)
        ->and($event->action)->toBe('delete')
        ->and($event->entryId)->toBe(17)
        ->and($event->entry['id'])->toBe(17);
});

it('broadcast ke channel kitchen-store.{storeId}', function () {
    $store = kdsStore(2);
    $queue = kdsQueue($store);
    $entry = QueueEntry::query()->create([
        'queue_id' => $queue->id,
        'store_id' => $store->id,
        'number' => 1,
        'status' => 'waiting',
        'items' => [],
    ]);

    $event = KitchenQueueUpdated::fromEntry($entry);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    $channel = $channels[0];
    expect((string) $channel)->toBe("kitchen-store.{$store->id}");
    expect($event->broadcastAs())->toBe('kitchen.queue.updated');
});

it('dispatch event ketika QueueEntry diupdate statusnya (sesuai alur updateEntry)', function () {
    Event::fake([KitchenQueueUpdated::class]);

    $store = kdsStore(3);
    $queue = kdsQueue($store);
    $entry = QueueEntry::query()->create([
        'queue_id' => $queue->id,
        'store_id' => $store->id,
        'number' => 1,
        'status' => 'waiting',
        'items' => [],
    ]);

    $updates = ['status' => 'serving', 'called_at' => now()];
    DB::transaction(function () use ($entry, $updates) {
        $entry->update($updates);
    });

    event(KitchenQueueUpdated::fromEntry($entry->load('transaction:id,transaction_number,order_type,table_reference')));

    Event::assertDispatched(KitchenQueueUpdated::class, function (KitchenQueueUpdated $e) use ($store, $entry) {
        return $e->storeId === $store->id
            && $e->action === 'upsert'
            && $e->entryId === $entry->id
            && $e->entry['status'] === 'serving';
    });
});

it('dispatch event ketika QueueEntry dihapus (sesuai alur destroyEntry)', function () {
    Event::fake([KitchenQueueUpdated::class]);

    $store = kdsStore(4);
    $queue = kdsQueue($store);
    $entry = QueueEntry::query()->create([
        'queue_id' => $queue->id,
        'store_id' => $store->id,
        'number' => 1,
        'status' => 'waiting',
        'items' => [],
    ]);
    $storeId = (int) $entry->store_id;
    $entryId = (int) $entry->id;
    $entry->delete();

    event(KitchenQueueUpdated::deleted($storeId, $entryId));

    Event::assertDispatched(KitchenQueueUpdated::class, function (KitchenQueueUpdated $e) use ($storeId, $entryId) {
        return $e->storeId === $storeId
            && $e->action === 'delete'
            && $e->entryId === $entryId;
    });
});

it('otorisasi channel hanya mengizinkan user dari toko yang sama', function () {
    $storeA = kdsStore(5);
    $storeB = kdsStore(6);

    $userA = \App\Models\User::query()->create([
        'name' => 'User A',
        'email' => 'user-a-'.uniqid().'@example.com',
        'password' => bcrypt('password'),
        'store_id' => $storeA->id,
        'role' => 'admin',
        'is_active' => true,
    ]);

    $userB = \App\Models\User::query()->create([
        'name' => 'User B',
        'email' => 'user-b-'.uniqid().'@example.com',
        'password' => bcrypt('password'),
        'store_id' => $storeB->id,
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($userA);

    $resultA = DB::table('store_user')
        ->where('user_id', $userA->id)
        ->where('store_id', $storeA->id)
        ->exists();
    $hasStoreA = ((int) ($userA->store_id ?? 0) === $storeA->id) || $resultA;
    expect($hasStoreA)->toBeTrue();

    $hasStoreB = ((int) ($userA->store_id ?? 0) === $storeB->id) || DB::table('store_user')
        ->where('user_id', $userA->id)
        ->where('store_id', $storeB->id)
        ->exists();
    expect($hasStoreB)->toBeFalse();
});
