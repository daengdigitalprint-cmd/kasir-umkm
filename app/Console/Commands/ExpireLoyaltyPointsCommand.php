<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\LoyaltyService;
use App\Support\LoyaltySettings;
use Illuminate\Console\Command;

class ExpireLoyaltyPointsCommand extends Command
{
    protected $signature = 'loyalty:expire-points {--dry-run : Hanya tampilkan jumlah, tidak mengubah saldo}';

    protected $description = 'Kadaluarsakan poin loyalitas yang melewati masa berlaku & kirim peringatan poin yang hampir kadaluarsa.';

    public function handle(LoyaltyService $loyalty): int
    {
        $dry = (bool) $this->option('dry-run');
        $expiredPoints = 0;
        $warnedCustomers = 0;
        $processedStores = 0;

        Store::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($loyalty, $dry, &$expiredPoints, &$warnedCustomers, &$processedStores) {
                foreach ($stores as $store) {
                    if (! LoyaltySettings::enabled($store) || LoyaltySettings::expiryMonths($store) <= 0) {
                        continue;
                    }

                    $processedStores++;

                    if ($dry) {
                        $due = $store->loyaltyPointLedgers()
                            ->whereIn('type', ['earn', 'adjust', 'bonus_birthday', 'bonus_referral'])
                            ->where('points_remaining', '>', 0)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now())
                            ->sum('points_remaining');
                        $this->line("[dry-run] {$store->name}: {$due} poin akan kadaluarsa.");
                        $expiredPoints += (int) $due;

                        continue;
                    }

                    $expiredPoints += $loyalty->expirePointsForStore($store);
                    $warnedCustomers += $loyalty->warnExpiringForStore($store);
                }
            });

        $this->info($dry
            ? "Selesai (dry-run): {$processedStores} toko, {$expiredPoints} poin akan kadaluarsa."
            : "Selesai: {$processedStores} toko, {$expiredPoints} poin dikadaluarsakan, {$warnedCustomers} pelanggan diperingatkan.");

        return self::SUCCESS;
    }
}
