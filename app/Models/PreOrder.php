<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PreOrder extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'user_id',
        'order_number',
        'status',
        'expected_pickup_at',
        'deposit_required',
        'deposit_received',
        'estimated_total',
        'notes',
        'fulfilled_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_pickup_at' => 'date',
            'deposit_required' => 'decimal:2',
            'deposit_received' => 'decimal:2',
            'estimated_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PreOrder $order) {
            if (empty($order->order_number)) {
                $order->order_number = 'PRE-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PreOrderLine::class);
    }

    public function fulfilledTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'fulfilled_transaction_id');
    }
}
