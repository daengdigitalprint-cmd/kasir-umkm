<?php

namespace App\Models;

use App\Support\AdLeadQualifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead dari landing page iklan (/mulai).
 */
class AdLead extends Model
{
    public const STATUSES = [
        'new' => 'Baru',
        'contacted' => 'Sudah dihubungi',
        'converted' => 'Jadi pengguna',
        'dropped' => 'Tidak lanjut',
    ];

    protected $fillable = [
        'name',
        'business_name',
        'whatsapp',
        'city',
        'business_type',
        'business_status',
        'daily_transactions',
        'start_timeline',
        'score',
        'tier',
        'status',
        'notes',
        'contacted_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'fbp',
        'fbc',
        'event_id',
        'ip',
        'user_agent',
        'submissions',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'submissions' => 'integer',
            'contacted_at' => 'datetime',
        ];
    }

    public function scopeQualified(Builder $query): Builder
    {
        return $query->whereIn('tier', [AdLeadQualifier::TIER_HOT, AdLeadQualifier::TIER_WARM]);
    }

    public function isQualified(): bool
    {
        return AdLeadQualifier::isQualified((string) $this->tier);
    }

    /** Nama panggilan untuk sapaan. */
    public function firstName(): string
    {
        return (string) strtok(trim((string) $this->name), ' ');
    }

    /** Nomor dalam format lokal (08xx) agar mudah dikenali pemiliknya. */
    public function whatsappLocal(): string
    {
        return '0'.substr((string) $this->whatsapp, 2);
    }
}
