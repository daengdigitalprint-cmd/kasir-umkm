<?php

namespace App\Console\Commands;

use App\Services\KPIService;
use Illuminate\Console\Command;

class GenerateKPISnapshot extends Command
{
    protected $signature = 'kpi:snapshot';

    protected $description = 'Generate snapshot KPI harian untuk semua outlet aktif';

    public function __construct(private KPIService $kpiService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stores = $this->kpiService->activeStores();

        if ($stores->isEmpty()) {
            $this->info('Tidak ada outlet aktif. Snapshot dilewati.');

            return self::SUCCESS;
        }

        $this->info('Membuat snapshot KPI untuk '.$stores->count().' outlet...');
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($stores as $store) {
            try {
                $snapshot = $this->kpiService->createDailySnapshot($store);
                $success++;
                $this->info("  ✓ {$store->name} — revenue: Rp ".number_format($snapshot->metrics['revenue'] ?? 0, 0, ',', '.'));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ {$store->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$success}, Gagal: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
