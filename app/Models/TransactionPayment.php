<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris pembayaran pada sebuah transaksi.
 *
 * Transaksi dengan satu metode tetap menyimpan satu baris di sini supaya
 * laporan rincian metode punya satu sumber data saja.
 */
class TransactionPayment extends Model
{
    /** Metode yang boleh dipakai kasir saat membagi pembayaran. */
    public const METHODS = ['cash', 'card', 'ewallet', 'transfer', 'qris', 'bank_transfer', 'other'];

    protected $fillable = [
        'transaction_id',
        'store_id',
        'method',
        'amount',
        'reference',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isCash(): bool
    {
        return $this->method === 'cash';
    }
}
