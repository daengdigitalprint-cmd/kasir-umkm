<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_PURCHASE = 'purchase';

    /** Retur barang ke pemasok (Fase E3). */
    public const TYPE_SUPPLIER_RETURN = 'supplier_return';

    public const TYPE_RETURN = 'return';

    public const TYPE_DAMAGE = 'damage';

    /** Keluar ke outlet lain (Fase F2). */
    public const TYPE_TRANSFER_OUT = 'transfer_out';

    /** Masuk dari outlet lain (Fase F2). */
    public const TYPE_TRANSFER_IN = 'transfer_in';

    /** Pembalik transfer_out saat pembatalan. */
    public const TYPE_TRANSFER_REVERSAL = 'transfer_reversal';

    /** Konsumsi bahan saat produksi. */
    public const TYPE_PRODUCTION_CONSUMPTION = 'production_consumption';

    /** Penambahan output hasil produksi. */
    public const TYPE_PRODUCTION_OUTPUT = 'production_output';

    /** Pembalik production_consumption saat pembatalan produksi. */
    public const TYPE_PRODUCTION_REVERSAL = 'production_reversal';

    /** Hasil stock opname (hitung fisik berkala). */
    public const TYPE_OPNAME = 'opname';

    /** Pembalik adjustment / opname saat dokumen koreksi dibatalkan. */
    public const TYPE_ADJUSTMENT_REVERSAL = 'adjustment_reversal';

    protected $fillable = [
        'store_id',
        'product_id',
        'product_variant_id',
        'user_id',
        'quantity_change',
        'type',
        'reference_type',
        'reference_id',
        'balance_after',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
