<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen koreksi stok. `type` membedakan penyesuaian insidental dari stock opname.
 */
class StockAdjustment extends Model
{
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_OPNAME = 'opname';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    public const REASON_DAMAGE = 'damage';

    public const REASON_EXPIRED = 'expired';

    public const REASON_LOST = 'lost';

    public const REASON_FOUND = 'found';

    public const REASON_INTERNAL_USE = 'internal_use';

    public const REASON_SAMPLE = 'sample';

    public const REASON_CORRECTION = 'correction';

    public const REASON_OPNAME = 'opname';

    protected $fillable = [
        'store_id',
        'adjustment_number',
        'type',
        'reason_code',
        'status',
        'notes',
        'counted_at',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'total_variance_value',
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_variance_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockAdjustment $doc) {
            if (empty($doc->status)) {
                $doc->status = self::STATUS_DRAFT;
            }
            if (empty($doc->type)) {
                $doc->type = self::TYPE_ADJUSTMENT;
            }
            if (empty($doc->adjustment_number)) {
                $doc->adjustment_number = self::nextNumber((int) $doc->store_id, (string) $doc->type);
            }
        });
    }

    /**
     * Nomor urut per toko per bulan, contoh `ADJ/2026/08/0001`.
     *
     * Dipanggil di dalam transaksi posting/pembuatan; `lockForUpdate` pada baris toko
     * mencegah dua dokumen memperoleh nomor yang sama saat dibuat bersamaan.
     */
    public static function nextNumber(int $storeId, string $type = self::TYPE_ADJUSTMENT): string
    {
        $prefix = $type === self::TYPE_OPNAME ? 'OPN' : 'ADJ';
        $year = now()->format('Y');
        $month = now()->format('m');
        $pattern = "{$prefix}/{$year}/{$month}/";

        $last = DB::table('stock_adjustments')
            ->where('store_id', $storeId)
            ->where('adjustment_number', 'like', $pattern.'%')
            ->orderByDesc('adjustment_number')
            ->value('adjustment_number');

        $sequence = $last === null
            ? 1
            : ((int) substr((string) $last, strlen($pattern))) + 1;

        return $pattern.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    // ── Relasi ──

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(StockOpnameImport::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ── Status ──

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Dokumen final tidak boleh diubah maupun dihapus. */
    public function isFinal(): bool
    {
        return $this->isPosted() || $this->isCancelled();
    }

    public function allowedTransitions(): array
    {
        return match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_PENDING_APPROVAL, self::STATUS_POSTED, self::STATUS_CANCELLED],
            self::STATUS_PENDING_APPROVAL => [self::STATUS_POSTED, self::STATUS_CANCELLED],
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
                "Dokumen \"{$this->adjustment_number}\" (status: ".self::statusLabel($this->status).') tidak dapat diproses menjadi "'.self::statusLabel($targetStatus).'".'
            );
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING_APPROVAL => 'Menunggu persetujuan',
            self::STATUS_POSTED => 'Diposting',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => $status,
        };
    }

    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_DAMAGE => 'Rusak atau pecah',
            self::REASON_EXPIRED => 'Kedaluwarsa',
            self::REASON_LOST => 'Hilang atau selisih',
            self::REASON_FOUND => 'Ditemukan',
            self::REASON_INTERNAL_USE => 'Pemakaian internal',
            self::REASON_SAMPLE => 'Sampel atau hadiah',
            self::REASON_CORRECTION => 'Koreksi salah input',
            self::REASON_OPNAME => 'Hasil stock opname',
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return self::reasonLabels()[$reason] ?? $reason;
    }
}
