<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkAbsentAttendance extends Command
{
    protected $signature = 'attendance:mark-absent
                            {--store= : ID toko tertentu (opsional)}
                            {--date= : Tanggal kerja (YYYY-MM-DD, default kemarin per timezone toko)}';

    protected $description = 'Tandai karyawan berjadwal yang tidak hadir sebagai absent (idempoten)';

    public function __construct(private readonly AttendanceService $attendance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : null;

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
                $marked = $this->attendance->markAbsent($store, $date);
                $total += $marked;
                $this->info("  ✓ {$store->name}: {$marked} absensi absent dibuat.");
            } catch (\Throwable $e) {
                $this->error("  ✗ {$store->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Selesai. Total {$total} absensi absent dibuat.");

        return self::SUCCESS;
    }
}
