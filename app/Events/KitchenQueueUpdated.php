<?php

namespace App\Events;

use App\Models\QueueEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KitchenQueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $storeId,
        public readonly string $action,
        public readonly array $entry,
        public readonly ?int $entryId = null,
    ) {}

    /**
     * @return Channel[]
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("kitchen-store.{$this->storeId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'kitchen.queue.updated';
    }

    public static function fromEntry(QueueEntry $entry, string $action = 'upsert'): self
    {
        $transaction = $entry->relationLoaded('transaction')
            ? $entry->transaction
            : $entry->transaction()->first(['id', 'transaction_number', 'order_type', 'table_reference']);

        $barber = $entry->relationLoaded('barber')
            ? $entry->barber
            : $entry->barber()->first(['id', 'name']);

        return new self(
            storeId: (int) $entry->store_id,
            action: $action,
            entry: [
                'id' => $entry->id,
                'number' => $entry->number,
                'status' => $entry->status,
                'order_type' => $transaction?->order_type ?? $entry->order_type,
                'table_reference' => $transaction?->table_reference ?? $entry->table_number,
                'transaction_number' => $transaction?->transaction_number,
                'created_at' => $entry->created_at?->toIso8601String(),
                'called_at' => $entry->called_at?->toIso8601String(),
                'notes' => $entry->notes,
                'items' => $entry->items ?? [],
                'barber_user_id' => $entry->barber_user_id,
                'barber_name' => $barber?->name,
            ],
            entryId: $entry->id,
        );
    }

    public static function deleted(int $storeId, int $entryId): self
    {
        return new self(
            storeId: $storeId,
            action: 'delete',
            entry: ['id' => $entryId],
            entryId: $entryId,
        );
    }
}
