<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    protected $fillable = [
        'transaction_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'product_sku',
        'variant_label',
        'quantity',
        'free_quantity',
        'unit_price',
        'total_price',
        'discount_amount',
        'tax_amount',
        'product_snapshot',
        'modifiers',
        'unit_cost_snapshot',
        'total_cost_snapshot',
        'gross_profit_snapshot',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'free_quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'unit_cost_snapshot' => 'decimal:6',
        'total_cost_snapshot' => 'decimal:2',
        'gross_profit_snapshot' => 'decimal:2',
        'product_snapshot' => 'array',
        'modifiers' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function allocations()
    {
        return $this->hasMany(SaleConsumptionAllocation::class);
    }
}
