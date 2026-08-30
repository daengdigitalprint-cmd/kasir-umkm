<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyReward extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'description',
        'type',
        'points_cost',
        'voucher_value',
        'stock',
        'redeemed_count',
        'is_active',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'voucher_value' => 'decimal:2',
        'stock' => 'integer',
        'redeemed_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Hadiah masih tersedia untuk ditukar (aktif + stok). */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->stock === null || $this->stock > 0;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
