<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berkas hasil hitung fisik yang diunggah ke sesi stock opname.
 *
 * `report` berisi ringkasan, daftar baris preview, dan daftar error per baris
 * hasil validasi. Penulisan ke lembar hitung baru terjadi pada proses apply,
 * dan mutasi stok tetap menunggu posting sesi.
 */
class StockOpnameImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Produk yang tidak ada di berkas dibiarkan apa adanya (selisih 0). */
    public const MODE_IGNORE = 'ignore';

    /** Produk yang tidak ada di berkas dianggap tidak ditemukan (stok fisik 0). */
    public const MODE_ZERO = 'zero';

    protected $fillable = [
        'store_id',
        'stock_adjustment_id',
        'created_by',
        'file_path',
        'file_name',
        'missing_mode',
        'status',
        'total_rows',
        'processed_rows',
        'matched_rows',
        'error_rows',
        'error_message',
        'report',
        'applied_at',
    ];

    protected $casts = [
        'report' => 'array',
        'applied_at' => 'datetime',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'matched_rows' => 'integer',
        'error_rows' => 'integer',
    ];

    // ── Relasi ──

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Status ──

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_FAILED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** Hasil sudah ditulis ke lembar hitung. */
    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Menunggu antrean',
            self::STATUS_PROCESSING => 'Sedang diproses',
            self::STATUS_COMPLETED => 'Selesai diperiksa',
            self::STATUS_FAILED => 'Gagal diproses',
            default => $status,
        };
    }

    public static function missingModeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_ZERO => 'Anggap stok 0',
            default => 'Abaikan',
        };
    }
}
