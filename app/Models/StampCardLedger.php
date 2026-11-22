<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StampCardLedger extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'transaction_id',
        'type',
        'stamps',
        'rewards_delta',
        'count_after',
        'rewards_after',
        'note',
        'created_by',
    ];

    protected $casts = [
        'stamps' => 'integer',
        'rewards_delta' => 'integer',
        'count_after' => 'integer',
        'rewards_after' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
