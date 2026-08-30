<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyNotification extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'type',
        'channel',
        'title',
        'body',
        'read_at',
        'delivery_status',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
