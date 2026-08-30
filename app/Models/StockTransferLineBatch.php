<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLineBatch extends Model
{
    protected $fillable = [
        'stock_transfer_line_id',
        'batch_code',
        'expiry_date',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'decimal:4',
        ];
    }

    public function transferLine(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id');
    }
}
