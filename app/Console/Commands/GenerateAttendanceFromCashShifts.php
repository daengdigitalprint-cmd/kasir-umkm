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
                            {--date= : Tanggal shift (YYYY-MM-DD). Default: kemarin dan hari ini menurut zona waktu toko}';

    protected $description = 'Konversi shift kasir menjadi absensi harian (idempoten)';

    public function __construct(private readonly AttendanceService $attendance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : null;

        $storeId = $this->option('store');

        $stores = $storeId
            ? Store::query()->whereKey($storeId)->get()
            : Store::query()->get()->filter(fn (Store $store) => AttendanceSettings::enabled($store)
                && AttendanceSettings::autoGenerateFromCashShift($store));

        if ($stores->isEmpty()) {
            $this->info('Tidak ada toko dengan modul absensi aktif.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($stores as $store) {
            try {
                // Tanpa --date: proses kemarin & hari ini (shift malam yang ditutup lewat tengah malam ikut tercatat).
                $now = CarbonImmutable::now($store->timezone ?: 'UTC');
                $dates = $date ? [$date] : [$now->subDay(), $now];
                $processed = 0;
                foreach ($dates as $day) {
                    $processed += $this->attendance->generateFromCashShifts($store, $day);
                }
                $total += $processed;
                $this->info("  ✓ {$store->name}: {$processed} absensi diproses.");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$store->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Total {$total} absensi diproses.");

        return self::SUCCESS;
    }
}
