<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleConsumptionAllocation extends Model
{
    protected $fillable = [
        'transaction_item_id',
        'production_batch_id',
        'component_product_id',
        'quantity_consumed',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:2',
    ];

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(TransactionItem::class);
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
