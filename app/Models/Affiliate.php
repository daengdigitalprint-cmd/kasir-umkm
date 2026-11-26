<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Afiliator: pengguna yang membagikan link daftar dan mendapat komisi.
 * Boleh pemilik toko maupun orang luar yang tidak punya toko.
 */
class Affiliate extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'code',
        'status',
        'commission_percent',
        'payout_provider',
        'payout_account_number',
        'payout_account_holder',
        'agreed_terms_at',
    ];

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'agreed_terms_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AffiliateNotification::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Tarif yang berlaku untuk afiliator ini. */
    public function effectivePercent(): float
    {
        return $this->commission_percent !== null
            ? (float) $this->commission_percent
            : (float) AffiliateSetting::value('commission_percent');
    }

    public function hasPayoutAccount(): bool
    {
        return filled($this->payout_provider) && filled($this->payout_account_number) && filled($this->payout_account_holder);
    }

    /** Link pendek yang dibagikan afiliator. */
    public function shareUrl(): string
    {
        return url('/r/'.$this->code);
    }
}
