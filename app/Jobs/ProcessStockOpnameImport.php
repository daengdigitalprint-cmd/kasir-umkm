<?php

namespace App\Jobs;

use App\Models\StockOpnameImport;
use App\Services\StockOpnameImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pemrosesan berkas hasil hitung stock opname di latar belakang.
 *
 * Pekerjaan hanya memvalidasi berkas dan menyusun preview selisih; penulisan
 * ke lembar hitung dilakukan terpisah saat user mengonfirmasi hasil.
 */
class ProcessStockOpnameImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(StockOpnameImportService $service): void
    {
        $import = StockOpnameImport::query()->find($this->importId);
        if (! $import) {
            return;
        }

        try {
            $service->process($import);
        } catch (\Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                report($e);
            }

            $import->update([
                'status' => StockOpnameImport::STATUS_FAILED,
                'error_message' => $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Terjadi kesalahan saat memproses berkas. Unggah ulang berkas atau hubungi dukungan.',
            ]);
        }
    }
}
