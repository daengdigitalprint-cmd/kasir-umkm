<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferCorrectionLine extends Model
{
    protected $fillable = [
        'stock_transfer_correction_id',
        'stock_transfer_line_id',
        'quantity_before',
        'quantity_after',
        'discrepancy_type',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(StockTransferCorrection::class, 'stock_transfer_correction_id');
    }

    public function transferLine(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id');
    }
}
