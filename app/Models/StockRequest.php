<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * F6 — Permintaan Stok. `from_store` = peminta (mis. outlet),
 * `to_store` = pemasok (mis. Gudang Pusat). Disetujui → Transfer Stok
 * dari pemasok ke peminta yang langsung dikirim.
 */
class StockRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const LABELS = [
        self::STATUS_REQUESTED => 'Diminta',
        self::STATUS_APPROVED => 'Dikirim',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'request_number',
        'organization_id',
        'from_store_id',
        'to_store_id',
        'status',
        'is_automatic',
        'notes',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejected_reason',
        'cancelled_at',
        'completed_at',
        'stock_transfer_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_automatic' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockRequest $request) {
            if (empty($request->request_number)) {
                $request->request_number = 'PST-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
            if (empty($request->status)) {
                $request->status = self::STATUS_REQUESTED;
            }
        });
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }
}
