<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionDeletionLog extends Model
{
    protected $fillable = [
        'store_id',
        'transaction_id',
        'transaction_number',
        'deleted_by',
        'total_amount',
        'items_count',
        'restoration_details',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'restoration_details' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
