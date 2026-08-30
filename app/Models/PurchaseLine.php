<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'sku_snapshot',
        'quantity',
        'unit_cost',
        'input_unit',
        'quantity_input',
        'base_unit',
        'qty_base',
        'unit_cost_input',
        'unit_cost_base',
        'subtotal',
        'batch_expiry_date',
        'batch_code',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'quantity_input' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost_input' => 'decimal:2',
            'unit_cost_base' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'batch_expiry_date' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
