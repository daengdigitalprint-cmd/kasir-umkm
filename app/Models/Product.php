<?php

namespace App\Models;

use App\Support\BaseUnit;
use App\Support\InventoryCosting;
use App\Support\InventoryType;
use App\Support\ProductQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'product_category_id',
        'name',
        'sku',
        'barcode',
        'description',
        'price',
        'wholesale_price',
        'cost_price',
        'average_cost',
        'stock_quantity',
        'min_stock_level',
        'category',
        'inventory_type',
        'brand',
        'unit',
        'service_duration_minutes',
        'barber_commission_mode',
        'barber_commission_value',
        'base_unit',
        'images',
        'tax_rate',
        'modifier_options',
        'is_active',
        'track_stock',
        'unlimited_stock',
        'allow_negative_stock',
        'allows_decimal',
        'has_variants',
        'is_bundle',
        'pharmacy_sale_class',
        'pharmacy_registration_no',
    ];

    protected $attributes = [
        'inventory_type' => InventoryType::SELLABLE,
        'base_unit' => BaseUnit::PIECE,
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'average_cost' => 'decimal:6',
        'tax_rate' => 'decimal:2',
        'stock_quantity' => 'decimal:4',
        'min_stock_level' => 'decimal:4',
        'service_duration_minutes' => 'integer',
        'barber_commission_value' => 'decimal:2',
        'modifier_options' => 'array',
        'images' => 'array',
        'is_active' => 'boolean',
        'track_stock' => 'boolean',
        'unlimited_stock' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'allows_decimal' => 'boolean',
        'has_variants' => 'boolean',
        'is_bundle' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_product_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function saleConsumptions(): HasMany
    {
        return $this->hasMany(ProductSaleConsumption::class);
    }

    public function saleConsumptionsForVariant(?int $variantId = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = ProductSaleConsumption::query()
            ->where('product_id', $this->id);

        if ($variantId !== null) {
            return $query->where('product_variant_id', $variantId);
        }

        return $query->whereNull('product_variant_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->images || count($this->images) === 0) {
            return null;
        }

        return Storage::disk('public')->url($this->images[0]);
    }

    public function getIsLowStockAttribute()
    {
        if (! $this->track_stock) {
            return false;
        }

        if ($this->has_variants) {
            if (! $this->relationLoaded('variants')) {
                $this->load(['variants' => fn ($q) => $q->where('is_active', true)]);
            }
            foreach ($this->variants->where('is_active', true) as $v) {
                if (ProductQuantity::lte((string) $v->stock_quantity, (string) $v->min_stock_level)) {
                    return true;
                }
            }

            return false;
        }

        return ProductQuantity::lte(
            (string) $this->stock_quantity,
            (string) $this->min_stock_level,
        );
    }

    public function getTotalValueAttribute()
    {
        return $this->stock_quantity * $this->cost_price;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->where('track_stock', true);
    }

    public function scopeInventoryType($query, string $type)
    {
        return $query->where('inventory_type', $type);
    }

    public function baseUnit(): string
    {
        return BaseUnit::normalizeBaseUnit($this->base_unit);
    }

    public function baseUnitDimension(): string
    {
        return BaseUnit::dimensionOf($this->baseUnit());
    }

    public function inventoryType(): string
    {
        return InventoryType::of($this);
    }

    public function isRawMaterial(): bool
    {
        return $this->inventoryType() === InventoryType::RAW_MATERIAL;
    }

    public function isPackaging(): bool
    {
        return $this->inventoryType() === InventoryType::PACKAGING;
    }

    public function isProductionOutput(): bool
    {
        return InventoryType::isProductionOutput($this);
    }

    public function isSellable(): bool
    {
        return InventoryType::isSellable($this);
    }

    /** Biaya persediaan berjalan per satuan dasar. */
    public function currentAverageCost(): string
    {
        return InventoryCosting::currentAverageCost($this);
    }

    /** Estimasi HPP satu produk terjual berdasarkan biaya komponen terkini. */
    public function estimatedUnitCost(?ProductVariant $variant = null): string
    {
        $query = $this->saleConsumptions()->with('component');
        if ($this->has_variants) {
            $query->where('product_variant_id', $variant?->id ?? 0);
        } else {
            $query->whereNull('product_variant_id');
        }

        $total = '0.00';
        foreach ($query->get() as $consumption) {
            $componentCost = $consumption->component?->currentAverageCost() ?? '0';
            $lineCost = InventoryCosting::extendedCost($consumption->quantity, $componentCost, 2);
            $total = bcadd($total, $lineCost, 2);
        }

        return $total;
    }
}
