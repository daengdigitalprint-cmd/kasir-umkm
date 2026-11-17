<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Support\AutoStockRequest;
use App\Support\StoreLocationType;
use Illuminate\Console\Command;

/**
 * Order otomatis ke Gudang Pusat: dijalankan penjadwal tiap 15 menit, tiap outlet
 * diproses sekali sehari setelah jam yang diatur.
 */
class AutoStockRequestCommand extends Command
{
    protected $signature = 'stock-requests:auto';

    protected $description = 'Buat Permintaan Stok otomatis dari Daftar Belanja outlet ke Gudang Pusat';

    public function handle(): int
    {
        $created = 0;

        Store::query()
            ->whereNotNull('organization_id')
            ->where('is_active', true)
            ->where('location_type', '!=', StoreLocationType::CENTRAL_WAREHOUSE)
            ->each(function (Store $store) use (&$created) {
                if (! AutoStockRequest::isDue($store)) {
                    return;
                }
                try {
                    if ($request = AutoStockRequest::run($store)) {
                        $created++;
                        $this->line("{$store->name}: {$request->request_number}");
                    }
                } catch (\Throwable $e) {
                    // Satu outlet gagal tidak boleh menghentikan outlet lain.
                    report($e);
                    $this->warn("{$store->name}: gagal — {$e->getMessage()}");
                }
            });

        $this->info("Permintaan otomatis dibuat: {$created}");

        return self::SUCCESS;
    }
}
