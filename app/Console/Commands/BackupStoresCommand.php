<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\StoreBackupService;
use App\Support\StorePlanLimits;
use Illuminate\Console\Command;

class BackupStoresCommand extends Command
{
    protected $signature = 'stores:backup {--store-id= : ID toko tertentu (opsional)}';

    protected $description = 'Backup otomatis data toko ke storage/app/backups + catat history';

    public function __construct(
        private StoreBackupService $backupService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $storeId = $this->option('store-id');

        if ($storeId) {
            $stores = Store::where('id', $storeId)->where('is_active', true)->get();
            if ($stores->isEmpty()) {
                $this->error("Store dengan ID {$storeId} tidak ditemukan atau tidak aktif.");

                return self::FAILURE;
            }
        } else {
            $stores = Store::where('is_active', true)->get();
        }

        $this->info("Memulai backup untuk {$stores->count()} toko...");

        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;

        foreach ($stores as $store) {
            // Backup otomatis khusus paket berbayar; paket gratis tetap bisa backup manual.
            if (! StorePlanLimits::allowsAutoBackup($store)) {
                $skippedCount++;
                $this->line("  - Lewati {$store->name} (ID: {$store->id}): paket tidak mencakup backup otomatis.");

                continue;
            }

            try {
                $this->info("  → Backup toko: {$store->name} (ID: {$store->id})");

                $history = $this->backupService->saveAndRecord(
                    $store,
                    triggerType: 'scheduled',
                );

                $successCount++;
                $this->info("    ✓ Berhasil: {$history->filename} ({$history->records_count} records, {$history->duration_ms}ms)");
            } catch (\Throwable $e) {
                $failCount++;
                $this->error("    ✗ Gagal backup {$store->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$successCount}, Gagal: {$failCount}, Dilewati (paket gratis): {$skippedCount}");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
