<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferLine extends Model
{
    /** Barang datang kurang dari yang dikirim (tidak ada di kiriman). */
    public const DISCREPANCY_SHORT = 'short';

    /** Barang datang tetapi rusak sehingga tidak masuk stok. */
    public const DISCREPANCY_DAMAGED = 'damaged';

    /** Barang datang lebih banyak dari yang dikirim. */
    public const DISCREPANCY_OVER = 'over';

    /** Kurang/rusak: barang kembali ke stok pengirim. */
    public const RESOLUTION_RETURNED = 'returned';

    /** Kurang/rusak: dicatat sebagai kerugian, stok tidak kembali. */
    public const RESOLUTION_LOSS = 'loss';

    /** Lebih: pengirim mengakui, stok pengirim berkurang dan stok penerima bertambah. */
    public const RESOLUTION_ACCEPTED = 'accepted';

    /** Lebih: barang dikembalikan fisik ke pengirim, stok tidak berubah. */
    public const RESOLUTION_REJECTED = 'rejected';

    public const DISCREPANCY_LABELS = [
        self::DISCREPANCY_SHORT => 'Kurang',
        self::DISCREPANCY_DAMAGED => 'Rusak',
        self::DISCREPANCY_OVER => 'Lebih',
    ];

    public const RESOLUTION_LABELS = [
        self::RESOLUTION_RETURNED => 'Dikembalikan ke stok pengirim',
        self::RESOLUTION_LOSS => 'Dicatat sebagai kerugian',
        self::RESOLUTION_ACCEPTED => 'Kelebihan diakui pengirim',
        self::RESOLUTION_REJECTED => 'Kelebihan dikembalikan ke pengirim',
    ];

    protected $fillable = [
        'stock_transfer_id',
        'from_product_id',
        'to_product_id',
        'quantity',
        'quantity_received',
        'discrepancy_type',
        'quantity_over',
        'discrepancy_reason',
        'resolution',
        'resolution_note',
        'resolved_by',
        'resolved_at',
        'base_unit',
        'unit_cost_snapshot',
        'total_cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_over' => 'decimal:4',
            'resolved_at' => 'datetime',
            'unit_cost_snapshot' => 'decimal:6',
            'total_cost_snapshot' => 'decimal:2',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function batchAllocations(): HasMany
    {
        return $this->hasMany(StockTransferLineBatch::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Selisih yang dicatat dengan jenis (alur baru) dan belum diselesaikan lokasi pengirim. */
    public function needsResolution(): bool
    {
        return $this->discrepancy_type !== null && $this->resolution === null;
    }

    /** Jumlah yang diselisihkan: kekurangan untuk kurang/rusak, kelebihan untuk lebih. */
    public function discrepancyQuantity(): string
    {
        if ($this->discrepancy_type === self::DISCREPANCY_OVER) {
            return (string) ($this->quantity_over ?? '0');
        }
        if ($this->quantity_received === null) {
            return '0.0000';
        }

        return bcsub((string) $this->quantity, (string) $this->quantity_received, 4);
    }
}
