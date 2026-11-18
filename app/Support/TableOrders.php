<?php

namespace App\Support;

use App\Models\QueueEntry;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pesanan dari Scan Meja (menu digital) yang ditangani kasir.
 *
 * "Belum bayar" = sudah diterima kasir (accepted/serving/ready) tetapi belum dibayar.
 * Pesanan ini tidak tercatat sebagai Open Bill POS, jadi kasir perlu ditunjukkan secara khusus.
 */
final class TableOrders
{
    /** Status pesanan meja yang sudah diterima kasir dan masih menunggu pembayaran. */
    public const UNPAID_STATUSES = ['accepted', 'serving', 'ready'];

    /** @return Builder<Transaction> */
    public static function query(int $storeId): Builder
    {
        return Transaction::query()
            ->where('store_id', $storeId)
            ->where('order_type', 'dine_in')
            ->where(function ($q) {
                $q->whereJsonContains('payment_details->source', 'scan_meja')
                    ->orWhere('payment_details->source', 'scan_meja');
            });
    }

    /** @return Builder<Transaction> */
    public static function unpaidQuery(int $storeId): Builder
    {
        return self::query($storeId)
            ->whereIn('order_status', self::UNPAID_STATUSES)
            ->where('payment_status', '!=', 'paid');
    }

    public static function unpaidCount(int $storeId): int
    {
        return self::unpaidQuery($storeId)->count();
    }

    /**
     * Status papan dapur per transaksi: waiting | serving | ready | completed | cancelled.
     *
     * @param  iterable<int>  $transactionIds
     * @return Collection<int, string>
     */
    public static function kitchenStatuses(iterable $transactionIds): Collection
    {
        $ids = collect($transactionIds)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return QueueEntry::query()
            ->whereIn('transaction_id', $ids)
            ->orderBy('id')
            ->get(['transaction_id', 'status'])
            ->pluck('status', 'transaction_id');
    }
}
