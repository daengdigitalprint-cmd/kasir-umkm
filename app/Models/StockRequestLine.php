<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestLine extends Model
{
    protected $fillable = [
        'stock_request_id',
        'requester_product_id',
        'supplier_product_id',
        'quantity_requested',
        'quantity_approved',
        'unit_price',
        'unit_cost',
        'quantity_billed',
        'line_total',
        'stock_at_request',
        'min_stock_at_request',
        'base_unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'quantity_approved' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:6',
            'quantity_billed' => 'decimal:4',
            'line_total' => 'decimal:2',
            'stock_at_request' => 'decimal:4',
            'min_stock_at_request' => 'decimal:4',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function requesterProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'requester_product_id');
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'supplier_product_id');
    }
}
