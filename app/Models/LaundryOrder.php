<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LaundryOrder extends Model
{
    public const STATUSES = ['received', 'processing', 'ready', 'completed', 'cancelled'];

    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

    protected $fillable = [
        'store_id',
        'customer_id',
        'user_id',
        'order_number',
        'status',
        'payment_status',
        'customer_name',
        'customer_phone',
        'received_at',
        'estimated_ready_at',
        'ready_at',
        'completed_at',
        'total_amount',
        'amount_paid',
        'payment_method',
        'notes',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'estimated_ready_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LaundryOrder $order) {
            if (empty($order->order_number)) {
                $order->order_number = 'LDR-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
            if (empty($order->received_at)) {
                $order->received_at = now();
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
        return $this->hasMany(LaundryOrderLine::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
