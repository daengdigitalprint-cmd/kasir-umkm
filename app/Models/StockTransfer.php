<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StockTransfer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'transfer_number',
        'from_store_id',
        'to_store_id',
        'user_id',
        'notes',
        'status',
        'sent_at',
        'received_at',
        'cancelled_at',
        'sent_by',
        'received_by',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockTransfer $t) {
            if (empty($t->transfer_number)) {
                $t->transfer_number = 'TRF-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
            if (empty($t->status)) {
                $t->status = self::STATUS_DRAFT;
            }
        });
    }

    // ── Relasi ──

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    // ── Status helpers ──

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_RECEIVED || $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Daftar status transisi yang valid.
     */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CANCELLED],
            self::STATUS_SENT => [self::STATUS_RECEIVED, self::STATUS_CANCELLED],
            default => [],
        };
    }

    /**
     * Throw jika transisi tidak valid.
     *
     * @throws \RuntimeException
     */
    public function assertCanTransitionTo(string $targetStatus): void
    {
        if (! in_array($targetStatus, $this->allowedTransitions(), true)) {
            $label = match ($targetStatus) {
                self::STATUS_SENT => 'Kirim',
                self::STATUS_RECEIVED => 'Terima',
                self::STATUS_CANCELLED => 'Batalkan',
                default => $targetStatus,
            };
            throw new \RuntimeException(
                "Transfer \"{$this->transfer_number}\" (status: {$this->status}) tidak dapat diproses menjadi \"{$label}\"."
            );
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SENT => 'Dikirim',
            self::STATUS_RECEIVED => 'Diterima',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => $status,
        };
    }
}
