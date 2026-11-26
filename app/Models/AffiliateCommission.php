<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Komisi dari satu pembayaran langganan yang sudah dikonfirmasi. */
class AffiliateCommission extends Model
{
    public const STATUS_EARNED = 'earned';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'affiliate_id',
        'affiliate_referral_id',
        'subscription_payment_id',
        'invoice_number',
        'payment_type',
        'base_amount',
        'percent',
        'amount',
        'status',
        'available_at',
        'paid_at',
        'paid_note',
        'cancel_reason',
        'processed_by',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'percent' => 'decimal:2',
        'amount' => 'decimal:2',
        'available_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferral::class, 'affiliate_referral_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    /** Masih dalam masa tahan. */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EARNED)->where('available_at', '>', now());
    }

    /** Masa tahan lewat, menunggu ditransfer. */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EARNED)->where('available_at', '<=', now());
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /** Keadaan untuk ditampilkan: held | ready | paid | cancelled. */
    public function state(): string
    {
        if ($this->status === self::STATUS_EARNED) {
            return $this->available_at && $this->available_at->isFuture() ? 'held' : 'ready';
        }

        return $this->status;
    }
}
