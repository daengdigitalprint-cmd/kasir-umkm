<?php

namespace App\Models;

use App\Support\ProductQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'name',
        'sku',
        'barcode',
        'price',
        'wholesale_price',
        'cost_price',
        'stock_quantity',
        'min_stock_level',
        'is_active',
        'sort_order',
        'allow_negative_stock',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'decimal:4',
            'min_stock_level' => 'decimal:4',
            'is_active' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleConsumptions(): HasMany
    {
        return $this->hasMany(ProductSaleConsumption::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function effectiveUnitPrice(): float
    {
        if ($this->price !== null && $this->product) {
            return (float) $this->price;
        }

        return $this->product ? (float) $this->product->price : 0.0;
    }

    public function getIsLowStockAttribute(): bool
    {
        if (! $this->product?->track_stock || ! $this->is_active) {
            return false;
        }

        return ProductQuantity::lte(
            (string) $this->stock_quantity,
            (string) $this->min_stock_level,
        );
    }
}
