<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu pencairan komisi: diajukan afiliator atau dicatat langsung oleh super admin. */
class AffiliatePayout extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'affiliate_id',
        'amount',
        'status',
        'payout_provider',
        'payout_account_number',
        'payout_account_holder',
        'proof_path',
        'note',
        'reject_reason',
        'requested_at',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function scopeRequested(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REQUESTED);
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    /** Alamat bukti transfer untuk ditampilkan (null bila tidak diunggah). */
    public function proofUrl(): ?string
    {
        return $this->proof_path ? '/storage/'.$this->proof_path : null;
    }
}
