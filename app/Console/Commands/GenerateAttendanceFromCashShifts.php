<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateAttendanceFromCashShifts extends Command
{
    protected $signature = 'attendance:from-cash-shifts
                            {--store= : ID toko tertentu (opsional)}
                            {--date= : Tanggal shift (YYYY-MM-DD, default hari ini)}';

    protected $description = 'Konversi shift kasir menjadi absensi harian (idempoten)';

    public function __construct(private readonly AttendanceService $attendance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::now();

        $storeId = $this->option('store');

        $stores = $storeId
            ? Store::query()->whereKey($storeId)->get()
            : Store::query()->get()->filter(fn (Store $store) => AttendanceSettings::enabled($store));

        if ($stores->isEmpty()) {
            $this->info('Tidak ada toko dengan modul absensi aktif.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($stores as $store) {
            try {
                $processed = $this->attendance->generateFromCashShifts($store, $date);
                $total += $processed;
                $this->info("  ✓ {$store->name}: {$processed} absensi diproses.");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$store->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Total {$total} absensi diproses untuk {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
