<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'code',
        'product_id',
        'output_quantity',
        'output_unit',
        'is_active',
        'version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:4',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Recipe $r) {
            if (empty($r->version)) {
                $r->version = 1;
            }
        });
    }

    // ── Relasi ──

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    // ── Helpers ──

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Total biaya bahan dari snapshot harga terkini.
     */
    public function totalIngredientCost(): string
    {
        $total = '0';
        foreach ($this->ingredients()->with('product')->get() as $ing) {
            $avgCost = $ing->product
                ? \App\Support\InventoryCosting::currentAverageCost($ing->product)
                : '0';
            $lineCost = \App\Support\InventoryCosting::extendedCost((string) $ing->quantity, $avgCost, 2);
            $total = bcadd($total, $lineCost, 2);
        }

        return $total;
    }

    /**
     * HPP per satuan output dari total biaya bahan.
     */
    public function unitCostPerOutput(): string
    {
        $total = $this->totalIngredientCost();
        $outputQty = (string) $this->output_quantity;
        if (bccomp($outputQty, '0', 4) <= 0) {
            return '0';
        }

        return \App\Support\InventoryCosting::unitCostFromTotal($total, $outputQty);
    }
}
