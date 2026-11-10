<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\LoyaltyService;
use App\Support\LoyaltySettings;
use Illuminate\Console\Command;

class LoyaltyBirthdayBonusCommand extends Command
{
    protected $signature = 'loyalty:bonus-daily {--dry-run : Hanya tampilkan jumlah, tidak memberikan poin}';

    protected $description = 'Berikan bonus ulang tahun ke pelanggan yang berulang tahun hari ini (idempoten per tahun).';

    public function handle(LoyaltyService $loyalty): int
    {
        $dry = (bool) $this->option('dry-run');
        $granted = 0;
        $processedStores = 0;

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($loyalty, $dry, &$granted, &$processedStores) {
                foreach ($stores as $store) {
                    if (! LoyaltySettings::enabled($store)) {
                        continue;
                    }

                    $processedStores++;

                    if ($dry) {
                        $due = $store->customers()
                            ->where('is_active', true)
                            ->whereNotNull('date_of_birth')
                            ->whereMonth('date_of_birth', now()->month)
                            ->whereDay('date_of_birth', now()->day)
                            ->count();
                        $this->line("[dry-run] {$store->name}: {$due} pelanggan berulang tahun hari ini.");

                        continue;
                    }

                    $granted += $loyalty->grantBirthdayBonusesForStore($store);
                }
            });

        $this->info($dry
            ? "Selesai (dry-run): {$processedStores} toko diperiksa."
            : "Selesai: {$processedStores} toko, {$granted} bonus ulang tahun diberikan.");

        return self::SUCCESS;
    }
}
