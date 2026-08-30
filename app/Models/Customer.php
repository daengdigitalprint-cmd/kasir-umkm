<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'email',
        'phone',
        'customer_type',
        'address',
        'date_of_birth',
        'gender',
        'total_spent',
        'total_orders',
        'points_balance',
        'points_earned_total',
        'points_redeemed_total',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'total_spent' => 'decimal:2',
        'points_balance' => 'integer',
        'points_earned_total' => 'integer',
        'points_redeemed_total' => 'integer',
        'notes' => 'array',
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

    public function pointLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    public function getAverageOrderValueAttribute()
    {
        if ($this->total_orders > 0) {
            return $this->total_spent / $this->total_orders;
        }

        return 0;
    }

    public function getTotalPurchasesAttribute()
    {
        return $this->total_spent ?? 0;
    }

    public function getLastPurchaseAttribute()
    {
        $lastTransaction = $this->transactions()->latest()->first();

        return $lastTransaction ? $lastTransaction->created_at->format('Y-m-d') : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTopCustomers($query, $limit = 10)
    {
        return $query->orderBy('total_spent', 'desc')->limit($limit);
    }
}
