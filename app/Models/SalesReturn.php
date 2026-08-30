<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SalesReturn extends Model
{
    protected $fillable = [
        'store_id',
        'transaction_id',
        'customer_id',
        'user_id',
        'cash_shift_id',
        'return_number',
        'return_type',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'refund_method',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'returned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = 'SRET-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }
}
