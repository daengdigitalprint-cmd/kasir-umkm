<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'from_product_id',
        'to_product_id',
        'quantity',
        'base_unit',
        'unit_cost_snapshot',
        'total_cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:6',
            'total_cost_snapshot' => 'decimal:2',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function batchAllocations(): HasMany
    {
        return $this->hasMany(StockTransferLineBatch::class);
    }
}
