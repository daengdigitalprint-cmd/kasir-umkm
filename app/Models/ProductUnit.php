<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satuan beli produk: `factor` = isi satu satuan ini dalam satuan dasar produk.
 */
class ProductUnit extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'factor',
        'is_purchase_default',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'is_purchase_default' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
