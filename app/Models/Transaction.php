<?php

namespace App\Models;

use App\Enums\SalesChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'discount_id',
        'user_id',
        'cash_shift_id',
        'transaction_number',
        'status_token',
        'status',
        'order_status',
        'payment_method',
        'payment_status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'service_charge_percent',
        'service_charge_amount',
        'total_amount',
        'amount_paid',
        'change_amount',
        'payment_details',
        'notes',
        'order_type',
        'table_reference',
        'tip_amount',
        'sales_channel',
        'commission_percent',
        'commission_amount',
        'completed_at',
        'offline_sale_id',
        'debt_due_at',
        'is_void',
        'void_reason',
        'void_at',
        'void_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'service_charge_percent' => 'decimal:2',
        'service_charge_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'payment_details' => 'array',
        'sales_channel' => SalesChannel::class,
        'commission_percent' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'completed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'debt_due_at' => 'datetime',
        'is_void' => 'boolean',
        'void_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = 'TXN-'.date('Ymd').'-'.Str::upper(Str::random(6));
            }
        });

        static::updating(function ($transaction) {
            $statusChanged = $transaction->isDirty('status') && $transaction->getOriginal('status') !== 'completed';
            $completedWithoutVoid = $transaction->status === 'completed' && ! $transaction->is_void;

            if ($statusChanged && $completedWithoutVoid && $transaction->customer_id) {
                Customer::query()
                    ->where('id', $transaction->customer_id)
                    ->update([
                        'last_purchase_at' => $transaction->completed_at ?? $transaction->freshTimestamp(),
                        'last_purchase_amount' => $transaction->total_amount,
                    ]);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function receivablePayments()
    {
        return $this->hasMany(ReceivablePayment::class);
    }

    /**
     * Outstanding amount (sisa piutang yang harus dibayar).
     * Nilai dihitung secara dinamis dari selisih total_amount dan amount_paid.
     */
    public function getOutstandingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeToday($query)
    {
        return $query->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    public function scopeNotVoided($query)
    {
        return $query->where('is_void', false);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }
}
