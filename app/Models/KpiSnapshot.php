<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiSnapshot extends Model
{
    protected $fillable = [
        'store_id',
        'snapshot_date',
        'metrics',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'metrics' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
