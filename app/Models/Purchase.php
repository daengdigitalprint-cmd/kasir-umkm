<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Purchase extends Model
{
    protected $fillable = [
        'store_id',
        'supplier_id',
        'user_id',
        'purchase_number',
        'purchased_at',
        'notes',
        'is_paid',
        'payment_method',
        'amount_paid',
        'due_date',
        'dpp_amount',
        'charges_amount',
        'ppn_amount',
        'total_amount',
        'supplier_tax_invoice_no',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
            'due_date' => 'date',
            'is_paid' => 'boolean',
            'amount_paid' => 'decimal:2',
            'dpp_amount' => 'decimal:2',
            'charges_amount' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase) {
            if (empty($purchase->purchase_number)) {
                $purchase->purchase_number = 'PUR-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseCharge::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }
}
