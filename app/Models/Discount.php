<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'code',
        'type',
        'value',
        'buy_x',
        'get_y',
        'minimum_amount',
        'maximum_uses',
        'used_count',
        'starts_at',
        'expires_at',
        'applicable_products',
        'applicable_categories',
        'is_active',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'buy_x' => 'integer',
        'get_y' => 'integer',
        'minimum_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'applicable_products' => 'array',
        'applicable_categories' => 'array',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getIsValidAttribute()
    {
        $now = Carbon::now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && $now->gt($this->expires_at)) {
            return false;
        }

        if ($this->maximum_uses && $this->used_count >= $this->maximum_uses) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('maximum_uses')
                    ->orWhereRaw('used_count < maximum_uses');
            });
    }
}
