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
        'affiliate_payout_id',
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

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayout::class, 'affiliate_payout_id');
    }

    /** Masa tahan lewat dan belum masuk permintaan pencairan: bisa ditarik. */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EARNED)
            ->where('available_at', '<=', now())
            ->whereNull('affiliate_payout_id');
    }

    /** Sudah masuk permintaan pencairan yang belum diproses super admin. */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EARNED)->whereNotNull('affiliate_payout_id');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /** Keadaan untuk ditampilkan: held | ready | processing | paid | cancelled. */
    public function state(): string
    {
        if ($this->status === self::STATUS_EARNED) {
            if ($this->affiliate_payout_id) {
                return 'processing';
            }

            return $this->available_at && $this->available_at->isFuture() ? 'held' : 'ready';
        }

        return $this->status;
    }
}
