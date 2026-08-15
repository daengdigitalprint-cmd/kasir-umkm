<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    public const STATUSES = ['waiting', 'serving', 'ready', 'completed', 'cancelled'];
    public const ORDER_TYPES = ['dine_in', 'takeaway', 'online'];

    protected $fillable = [
        'queue_id',
        'store_id',
        'number',
        'status',
        'order_type',
        'table_number',
        'customer_name',
        'customer_phone',
        'transaction_id',
        'priority',
        'notes',
        'items',
        'called_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'items' => 'array',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
