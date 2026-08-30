<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSaleConsumption extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'product_variant_id',
        'component_product_id',
        'quantity',
        'base_unit',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'sort_order' => 'integer',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /** Konsumsi berasal dari hasil produksi, bukan stok retail biasa. */
    public function consumesProductionOutput(): bool
    {
        return $this->component?->isProductionOutput() === true;
    }

    /** Estimasi biaya konsumsi per satu unit jual. */
    public function estimatedUnitCost(): string
    {
        $component = $this->component;
        if (! $component) {
            return '0.00';
        }

        return \App\Support\InventoryCosting::extendedCost(
            (string) $this->quantity,
            \App\Support\InventoryCosting::currentAverageCost($component),
            2,
        );
    }
}
