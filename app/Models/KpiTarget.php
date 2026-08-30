<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiTarget extends Model
{
    protected $fillable = [
        'store_id',
        'period',
        'growth_percentage',
        'target_revenue',
        'target_transactions',
        'target_margin',
        'target_customers',
        'baseline_data',
        'auto_generated',
    ];

    protected $casts = [
        'growth_percentage' => 'decimal:2',
        'target_revenue' => 'decimal:2',
        'target_margin' => 'decimal:2',
        'baseline_data' => 'array',
        'auto_generated' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
