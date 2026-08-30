<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatchLine extends Model
{
    protected $fillable = [
        'production_batch_id',
        'product_id',
        'quantity_required',
        'quantity_consumed',
        'base_unit',
        'unit_cost_snapshot',
        'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:4',
            'quantity_consumed' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:6',
            'total_cost' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
