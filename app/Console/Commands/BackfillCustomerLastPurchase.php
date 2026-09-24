<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCustomerLastPurchase extends Command
{
    protected $signature = 'customers:backfill-last-purchase {--store= : Batasi ke store ID tertentu} {--dry-run : Hanya tampilkan jumlah, tidak mengubah data}';

    protected $description = 'Isi kolom last_purchase_at & last_purchase_amount pada customer dari transaksi completed terakhir mereka.';

    public function handle(): int
    {
        $storeId = $this->option('store') ? (int) $this->option('store') : null;
        $dryRun = (bool) $this->option('dry-run');

        $transactions = Transaction::query()
            ->select('id', 'customer_id', 'total_amount', DB::raw('COALESCE(completed_at, created_at) as happened_at'))
            ->where('status', 'completed')
            ->where('is_void', false)
            ->whereNotNull('customer_id')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('happened_at')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($items) => $items->last());

        $count = 0;

        foreach ($transactions as $customerId => $transaction) {
            $customer = Customer::query()->find($customerId);

            if (! $customer) {
                continue;
            }

            $count++;

            if ($dryRun) {
                continue;
            }

            $customer->update([
                'last_purchase_at' => $transaction->happened_at,
                'last_purchase_amount' => $transaction->total_amount,
            ]);
        }

        $this->info($dryRun
            ? "Dry-run: ada {$count} customer yang akan di-backfill."
            : "Selesai: {$count} customer berhasil di-backfill.");

        return self::SUCCESS;
    }
}
