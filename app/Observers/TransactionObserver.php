<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Support\CacheKeys;

/**
 * Invalidate cache laporan (dashboard/KPI) setiap kali transaksi dibuat,
 * diubah, atau dihapus. Transaksi kasir TIDAK di-cache — hanya cache
 * agregat laporan yang di-flush supaya dashboard tetap realtime.
 */
class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        $this->flush($transaction);
    }

    public function updated(Transaction $transaction): void
    {
        // Hanya flush bila ada perubahan relevan terhadap laporan
        if ($transaction->wasChanged(['status', 'total_amount', 'is_void', 'customer_id'])) {
            $this->flush($transaction);
        }
    }

    public function deleted(Transaction $transaction): void
    {
        $this->flush($transaction);
    }

    private function flush(Transaction $transaction): void
    {
        if ($transaction->store_id) {
            CacheKeys::forgetStoreReports((int) $transaction->store_id);
        }
    }
}
