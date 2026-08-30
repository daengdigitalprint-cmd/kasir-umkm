<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris dokumen koreksi stok.
 *
 * `system_quantity` adalah snapshot stok saat baris dibuat, bukan nilai berjalan.
 * Selisih dihitung terhadap snapshot ini agar penjualan yang terjadi selama
 * proses hitung tidak ikut terhapus saat posting.
 */
class StockAdjustmentLine extends Model
{
    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'product_variant_id',
        'product_batch_id',
        'batch_code',
        'expiry_date',
        'system_quantity',
        'counted_quantity',
        'variance_quantity',
        'unit_cost',
        'variance_value',
        'line_notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'system_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'variance_value' => 'decimal:2',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
