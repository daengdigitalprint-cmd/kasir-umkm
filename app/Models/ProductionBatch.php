<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductionBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_number',
        'store_id',
        'recipe_id',
        'product_id',
        'status',
        'multiplier',
        'output_quantity_initial',
        'output_quantity_remaining',
        'total_cost',
        'unit_cost',
        'produced_by',
        'produced_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'recipe_name',
        'recipe_code',
        'recipe_version',
        'recipe_output_quantity',
        'recipe_output_unit',
    ];

    protected function casts(): array
    {
        return [
            'multiplier' => 'decimal:4',
            'output_quantity_initial' => 'decimal:4',
            'output_quantity_remaining' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'unit_cost' => 'decimal:6',
            'produced_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'recipe_version' => 'integer',
            'recipe_output_quantity' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductionBatch $b) {
            if (empty($b->batch_number)) {
                $b->batch_number = 'BATCH-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            }
            if (empty($b->status)) {
                $b->status = self::STATUS_DRAFT;
            }
        });
    }

    // ── Relasi ──

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function producedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'produced_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionBatchLine::class);
    }

    // ── Status helpers ──

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELLED;
    }

    public function allowedTransitions(): array
    {
        return match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            default => [],
        };
    }

    /**
     * @throws \RuntimeException
     */
    public function assertCanTransitionTo(string $targetStatus): void
    {
        if (! in_array($targetStatus, $this->allowedTransitions(), true)) {
            throw new \RuntimeException(
                "Batch \"{$this->batch_number}\" (status: {$this->status}) tidak dapat diproses menjadi \"{$targetStatus}\"."
            );
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => $status,
        };
    }
}
