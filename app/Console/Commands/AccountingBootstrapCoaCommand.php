<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use Illuminate\Console\Command;

class AccountingBootstrapCoaCommand extends Command
{
    protected $signature = 'accounting:bootstrap-coa {--store= : ID toko (kosongkan untuk semua toko)}';

    protected $description = 'Buat bagan akun standar per toko (jika belum ada) untuk jurnal & laporan keuangan';

    public function handle(): int
    {
        $storeId = $this->option('store');

        $query = Store::query()->orderBy('id');
        if ($storeId !== null && $storeId !== '') {
            $query->whereKey((int) $storeId);
        }

        $count = 0;
        foreach ($query->cursor() as $store) {
            ChartOfAccountsBootstrap::ensureForStore($store);
            $count++;
            $this->line("OK store #{$store->id} {$store->name}");
        }

        $this->info("Selesai: {$count} toko.");

        return self::SUCCESS;
    }
}
