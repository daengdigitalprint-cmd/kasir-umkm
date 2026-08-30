<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class LoyaltyPointLedger extends Model
{
    protected $fillable = [
        'store_id',
        'customer_id',
        'type',
        'points',
        'balance_after',
        'points_remaining',
        'expires_at',
        'description',
        'source_type',
        'source_id',
        'created_by',
        'integrity_hash',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'points_remaining' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Payload kanonik yang di-hash untuk mendeteksi manipulasi baris ledger.
     */
    public function integrityPayload(): string
    {
        return implode('|', [
            $this->store_id,
            $this->customer_id,
            $this->type,
            $this->points,
            $this->balance_after,
            $this->created_at?->toIso8601String(),
        ]);
    }

    public function generateIntegrityHash(): string
    {
        return hash_hmac('sha256', $this->integrityPayload(), config('app.key'));
    }

    /** Verifikasi baris ledger belum dimanipulasi. */
    public function hasValidIntegrity(): bool
    {
        if ($this->integrity_hash === null) {
            return false;
        }

        return hash_equals($this->integrity_hash, $this->generateIntegrityHash());
    }
}
