<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu akun yang mendaftar lewat link/kode seorang afiliator. */
class AffiliateReferral extends Model
{
    protected $fillable = [
        'affiliate_id',
        'user_id',
        'organization_id',
        'referred_name',
        'source',
        'first_paid_at',
        'commission_until',
    ];

    protected $casts = [
        'first_paid_at' => 'datetime',
        'commission_until' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    /** Tahap rujukan: registered → store_created → paid. */
    public function stage(): string
    {
        if ($this->first_paid_at) {
            return 'paid';
        }

        return $this->organization_id ? 'store_created' : 'registered';
    }

    /** Nama yang aman ditampilkan ke afiliator: "Bu*** S." */
    public function maskedName(): string
    {
        $name = trim((string) ($this->referred_name ?? ''));
        if ($name === '') {
            return 'Pengguna';
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = $parts[0];
        $masked = mb_substr($first, 0, 2).str_repeat('*', max(2, min(4, mb_strlen($first) - 2)));
        if (count($parts) > 1) {
            $masked .= ' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
        }

        return $masked;
    }
}
