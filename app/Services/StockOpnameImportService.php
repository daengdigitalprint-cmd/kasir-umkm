<?php

namespace App\Services;

use App\Imports\StockOpnameSheetImport;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockOpnameImport;
use App\Support\AdjustmentStockOperations;
use App\Support\InventoryCosting;
use App\Support\ProductImportSchema;
use App\Support\ProductQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pencocokan dan validasi berkas hasil hitung fisik stock opname.
 *
 * Baris berkas dicocokkan ke baris lembar hitung lewat SKU/Barcode/Kode Batch.
 * Untuk produk yang tidak memiliki SKU/Barcode, pencocokan mundur ke
 * Nama Produk + Varian + Satuan (persis seperti label di lembar hitung sesi),
 * sehingga toko yang belum melengkapi SKU tetap bisa mengunggah hasil hitung.
 * Seluruh baris divalidasi lebih dulu; penulisan ke lembar hitung baru terjadi
 * pada proses apply setelah user melihat preview selisih. Mutasi stok tetap
 * menunggu posting sesi.
 */
class StockOpnameImportService
{
    /** Batas baris data per berkas supaya proses tetap terkendali. */
    public const MAX_ROWS = 20000;

    /** Batas pesan error yang disimpan agar laporan tetap terbaca. */
    public const MAX_REPORTED_ERRORS = 200;

    /** Interval pembaruan progres saat memvalidasi baris. */
    private const PROGRESS_STEP = 250;

    private const COL_VARIANT = 'varian';

    private const COL_SYSTEM = 'stok_sistem';

    private const COL_NOTE = 'catatan';

    /**
     * Validasi berkas lalu susun laporan preview selisih tanpa menulis data apa pun.
     *
     * @throws \RuntimeException bila berkas tidak dapat dibaca atau formatnya salah.
     */
    public function process(StockOpnameImport $import): void
    {
        $import->update([
            'status' => StockOpnameImport::STATUS_PROCESSING,
            'processed_rows' => 0,
        ]);

        try {
            $rows = (new StockOpnameSheetImport)->rowsFrom($import->file_path, 'local');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Berkas tidak dapat dibaca. Unggah berkas CSV atau Excel (.xlsx) hasil unduhan lembar hitung.',
                0,
                $e,
            );
        }

        if ($rows === []) {
            throw new \RuntimeException('Sheet dengan kolom "Stok Fisik" tidak ditemukan. Unduh lembar hitung dari sesi ini lalu isi kolom "Stok Fisik".');
        }

        $headerMap = $this->buildHeaderMap(array_shift($rows));

        if (! isset($headerMap[StockOpnameSheetImport::COL_COUNTED])) {
            throw new \RuntimeException('Kolom "Stok Fisik" tidak ditemukan pada baris header. Gunakan lembar hitung yang diunduh dari sesi ini.');
        }

        if (! isset($headerMap[ProductImportSchema::COL_SKU]) && ! isset($headerMap[ProductImportSchema::COL_BARCODE])) {
            throw new \RuntimeException('Kolom "SKU" dan "Barcode" tidak ditemukan pada baris header. Gunakan lembar hitung yang diunduh dari sesi ini.');
        }

        $dataRows = [];
        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            // +2 karena baris 1 adalah header dan indeks array dimulai dari 0.
            $dataRows[$index + 2] = $row;
        }

        if ($dataRows === []) {
            throw new \RuntimeException('Tidak ada baris data yang bisa diproses. Isi kolom "Stok Fisik" mulai baris ke-2 pada lembar hitung.');
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw new \RuntimeException('Jumlah baris data ('.count($dataRows).') melebihi batas '.self::MAX_ROWS.' baris per berkas. Pecah lembar hitung menjadi beberapa bagian.');
        }

        $import->update(['total_rows' => count($dataRows)]);

        $lines = $import->stockAdjustment->lines()
            ->with([
                'product:id,name,sku,barcode,unit,allows_decimal',
                'productVariant:id,product_id,name,sku',
                'productBatch:id,batch_code',
            ])
            ->orderBy('id')
            ->get();

        $index = $this->buildLineIndex($lines);

        $errors = [];
        $preview = [];
        $matchedLineIds = [];
        $matchedRows = 0;
        $errorRows = 0;
        $processed = 0;

        foreach ($dataRows as $rowNumber => $row) {
            $rowErrors = [];
            $item = $this->prepareRow($index, $matchedLineIds, $headerMap, $row, (int) $rowNumber, $rowErrors);

            if ($rowErrors !== []) {
                $errorRows++;
                foreach ($rowErrors as $message) {
                    $errors[] = "Baris {$rowNumber}: {$message}";
                }
            } elseif ($item !== null) {
                $matchedRows++;
                $preview[] = $item;
            }

            $processed++;
            if ($processed % self::PROGRESS_STEP === 0) {
                $import->update(['processed_rows' => $processed]);
            }
        }

        // Produk yang tidak ada di berkas: abaikan (selisih 0) atau dianggap stok 0.
        $missingLines = 0;
        if ($import->missing_mode === StockOpnameImport::MODE_ZERO) {
            foreach ($lines as $line) {
                if (! $line->product || isset($matchedLineIds[$line->id])) {
                    continue;
                }
                $missingLines++;
                $preview[] = $this->previewRow($line, '0', null, null, true);
            }
        }

        ['positive' => $positive, 'negative' => $negative, 'total' => $total] = $this->summarize($preview);

        $import->update([
            'status' => StockOpnameImport::STATUS_COMPLETED,
            'processed_rows' => count($dataRows),
            'matched_rows' => $matchedRows,
            'error_rows' => $errorRows,
            'report' => [
                'summary' => [
                    'data_rows' => count($dataRows),
                    'matched_rows' => $matchedRows,
                    'error_rows' => $errorRows,
                    'missing_lines' => $missingLines,
                    'counted_lines' => count($preview),
                    'positive_value' => $positive,
                    'negative_value' => $negative,
                    'total_variance_value' => $total,
                ],
                'preview' => $preview,
                'errors' => $this->capErrors($errors),
            ],
        ]);
    }

    /**
     * Tulis hasil yang sudah dikonfirmasi user ke lembar hitung sesi.
     *
     * Sesi harus masih draft; mutasi stok tetap terjadi saat posting.
     *
     * @throws \RuntimeException
     */
    public function apply(StockOpnameImport $import): void
    {
        if (! $import->isCompleted()) {
            throw new \RuntimeException('Berkas ini belum selesai diperiksa sehingga belum dapat diterapkan.');
        }
        if ($import->isApplied()) {
            throw new \RuntimeException('Hasil berkas ini sudah pernah diterapkan ke lembar hitung.');
        }

        $report = $import->report ?? [];
        if (($report['errors'] ?? []) !== []) {
            $errorRows = (int) ($report['summary']['error_rows'] ?? count($report['errors']));
            throw new \RuntimeException("Masih ada {$errorRows} baris bermasalah pada berkas ini. Perbaiki lalu unggah ulang sebelum menerapkan hasil.");
        }

        $preview = $report['preview'] ?? [];
        if ($preview === []) {
            throw new \RuntimeException('Tidak ada hasil hitung yang dapat diterapkan dari berkas ini.');
        }

        DB::transaction(function () use ($import, $preview) {
            $document = StockAdjustment::query()
                ->whereKey($import->stock_adjustment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $document->isDraft()) {
                throw new \RuntimeException('Sesi ini sudah final dan tidak dapat diubah.');
            }

            foreach (array_chunk($preview, 500) as $chunk) {
                $lines = StockAdjustmentLine::query()
                    ->whereIn('id', array_column($chunk, 'line_id'))
                    ->with('product')
                    ->get()
                    ->keyBy('id');

                foreach ($chunk as $row) {
                    $line = $lines->get((int) $row['line_id']);
                    if (! $line || ! $line->product) {
                        continue;
                    }

                    $counted = ProductQuantity::normalizeStorageAmount($line->product, $row['counted_quantity']);
                    $variance = AdjustmentStockOperations::variance($counted, (string) $line->system_quantity);

                    $line->update([
                        'counted_quantity' => $counted,
                        'variance_quantity' => $variance,
                        'variance_value' => InventoryCosting::extendedCost($variance, (string) $line->unit_cost),
                        'line_notes' => $row['note'] ?? $line->line_notes,
                    ]);
                }
            }

            $document->update([
                'total_variance_value' => (string) $document->lines()->sum('variance_value'),
            ]);

            $import->update(['applied_at' => now()]);
        });
    }

    /**
     * @param  Collection<int, StockAdjustmentLine>  $lines
     * @return array{bySku: array<string, list<StockAdjustmentLine>>, byBarcode: array<string, list<StockAdjustmentLine>>, byBatch: array<string, list<StockAdjustmentLine>>, byName: array<string, list<StockAdjustmentLine>>}
     */
    private function buildLineIndex(Collection $lines): array
    {
        $index = ['bySku' => [], 'byBarcode' => [], 'byBatch' => [], 'byName' => []];

        foreach ($lines as $line) {
            if (! $line->product) {
                continue;
            }

            $this->pushIndex($index['bySku'], $line->productVariant?->sku ?: $line->product->sku, $line);
            $this->pushIndex($index['byBarcode'], $line->product->barcode, $line);
            $this->pushIndex($index['byBatch'], $line->batch_code ?: $line->productBatch?->batch_code, $line);
            $this->pushIndex($index['byName'], $line->product->name, $line);
        }

        return $index;
    }

    /**
     * @param  array<string, list<StockAdjustmentLine>>  $bucket
     */
    private function pushIndex(array &$bucket, mixed $value, StockAdjustmentLine $line): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        $bucket[mb_strtolower(trim((string) $value))][] = $line;
    }

    /**
     * @param  array{bySku: array<string, list<StockAdjustmentLine>>, byBarcode: array<string, list<StockAdjustmentLine>>, byBatch: array<string, list<StockAdjustmentLine>>, byName: array<string, list<StockAdjustmentLine>>}  $index
     * @param  array<int, true>  $matchedLineIds
     * @param  array<string, int>  $headerMap
     * @param  list<mixed>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function prepareRow(array $index, array &$matchedLineIds, array $headerMap, array $row, int $rowNumber, array &$errors): ?array
    {
        $line = $this->matchLine($index, $matchedLineIds, $headerMap, $row, $errors);
        if ($line === null) {
            return null;
        }

        $product = $line->product;

        $raw = $this->rawValue($row, $headerMap, StockOpnameSheetImport::COL_COUNTED);
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            $errors[] = 'kolom Stok Fisik wajib diisi.';

            return null;
        }

        $value = ProductImportSchema::parseNumber($raw);
        if ($value === null) {
            $errors[] = 'kolom Stok Fisik harus berupa angka.';

            return null;
        }
        if ($value < 0) {
            $errors[] = 'kolom Stok Fisik tidak boleh negatif.';

            return null;
        }
        if (! $product->allows_decimal && abs($value - round($value)) > 0.00001) {
            $errors[] = 'kolom Stok Fisik harus bilangan bulat untuk produk ini.';

            return null;
        }

        $note = $this->stringValue($row, $headerMap, self::COL_NOTE);
        if ($note !== null && mb_strlen($note) > 255) {
            $errors[] = 'kolom Catatan melebihi 255 karakter.';

            return null;
        }

        $counted = ProductQuantity::normalizeStorageAmount($product, $value);

        return $this->previewRow($line, $counted, $note, $rowNumber, false);
    }

    /**
     * Cocokkan satu baris berkas ke baris lembar hitung.
     *
     * Prioritas: Kode Batch/SKU/Barcode. Bila ketiganya kosong, pencocokan
     * mundur ke Nama Produk (+ Varian/Satuan bila perlu mempersempit pilihan).
     *
     * @param  array{bySku: array<string, list<StockAdjustmentLine>>, byBarcode: array<string, list<StockAdjustmentLine>>, byBatch: array<string, list<StockAdjustmentLine>>, byName: array<string, list<StockAdjustmentLine>>}  $index
     * @param  array<int, true>  $matchedLineIds
     * @param  array<string, int>  $headerMap
     * @param  list<mixed>  $row
     * @param  list<string>  $errors
     */
    private function matchLine(array $index, array &$matchedLineIds, array $headerMap, array $row, array &$errors): ?StockAdjustmentLine
    {
        $sku = $this->stringValue($row, $headerMap, ProductImportSchema::COL_SKU);
        $barcode = $this->stringValue($row, $headerMap, ProductImportSchema::COL_BARCODE);
        $batch = $this->stringValue($row, $headerMap, ProductImportSchema::COL_BATCH_CODE);
        $variantName = $this->stringValue($row, $headerMap, self::COL_VARIANT);
        $byName = false;

        if ($batch !== null) {
            $label = "Kode Batch «{$batch}»";
            $candidates = $index['byBatch'][mb_strtolower($batch)] ?? [];
            if ($sku !== null) {
                $candidates = array_values(array_filter(
                    $candidates,
                    fn (StockAdjustmentLine $line): bool => $this->lineSku($line) === mb_strtolower($sku),
                ));
            }
        } elseif ($sku !== null) {
            $label = "SKU «{$sku}»";
            $candidates = $index['bySku'][mb_strtolower($sku)] ?? [];
        } elseif ($barcode !== null) {
            $label = "Barcode «{$barcode}»";
            $candidates = $index['byBarcode'][mb_strtolower($barcode)] ?? [];
        } else {
            $name = $this->stringValue($row, $headerMap, ProductImportSchema::COL_NAME);
            if ($name === null) {
                $errors[] = 'kolom SKU atau Barcode wajib diisi untuk mencocokkan baris ini. Untuk produk tanpa SKU, isi kolom Nama Produk sesuai lembar hitung.';

                return null;
            }

            $byName = true;
            $label = "Nama Produk «{$name}»";
            $candidates = $index['byName'][mb_strtolower($name)] ?? [];
        }

        if ($candidates === []) {
            $errors[] = "{$label} tidak ditemukan pada sesi opname ini.";

            return null;
        }

        if (count($candidates) > 1 && $variantName !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (StockAdjustmentLine $line): bool => mb_strtolower((string) ($line->productVariant?->name ?? '')) === mb_strtolower($variantName),
            ));
        }

        if (count($candidates) > 1 && $byName) {
            $unit = $this->stringValue($row, $headerMap, ProductImportSchema::COL_UNIT);
            if ($unit !== null) {
                $candidates = array_values(array_filter(
                    $candidates,
                    fn (StockAdjustmentLine $line): bool => mb_strtolower((string) ($line->product?->unit ?? '')) === mb_strtolower($unit),
                ));
            }
        }

        if (count($candidates) > 1) {
            $unmatched = array_values(array_filter(
                $candidates,
                fn (StockAdjustmentLine $line): bool => ! isset($matchedLineIds[$line->id]),
            ));
            if ($unmatched !== []) {
                $candidates = $unmatched;
            }
        }

        if (count($candidates) > 1) {
            if (! $byName) {
                $errors[] = "{$label} cocok dengan beberapa baris pada sesi ini. Lengkapi kolom Kode Batch atau Varian agar barisnya unik.";

                return null;
            }

            // Produk kembar identik tanpa SKU: pasangkan mengikuti urutan baris berkas.
            $candidates = [$candidates[0]];
        }

        $line = $candidates[0];

        if (isset($matchedLineIds[$line->id])) {
            $errors[] = "{$label} sudah diisi pada baris lain. Gabungkan menjadi satu baris.";

            return null;
        }

        $matchedLineIds[$line->id] = true;

        return $line;
    }

    private function lineSku(StockAdjustmentLine $line): string
    {
        return mb_strtolower((string) ($line->productVariant?->sku ?: $line->product?->sku ?? ''));
    }

    /**
     * @return array{line_id: int, file_row: int|null, product_id: int, product_name: string, sku: string, variant: string|null, batch_code: string|null, unit: string, system_quantity: string, counted_quantity: string, variance_quantity: string, unit_cost: string, variance_value: string, note: string|null, missing: bool}
     */
    private function previewRow(StockAdjustmentLine $line, string $counted, ?string $note, ?int $rowNumber, bool $missing): array
    {
        $system = bcadd((string) $line->system_quantity, '0', ProductQuantity::SCALE);
        $variance = AdjustmentStockOperations::variance($counted, $system);
        $unitCost = (string) $line->unit_cost;

        return [
            'line_id' => $line->id,
            'file_row' => $rowNumber,
            'product_id' => $line->product_id,
            'product_name' => $line->product?->name ?? '',
            'sku' => $line->productVariant?->sku ?: ($line->product?->sku ?? ''),
            'variant' => $line->productVariant?->name,
            'batch_code' => $line->batch_code ?: $line->productBatch?->batch_code,
            'unit' => $line->product?->unit ?? '',
            'system_quantity' => $system,
            'counted_quantity' => $counted,
            'variance_quantity' => $variance,
            'unit_cost' => $unitCost,
            'variance_value' => InventoryCosting::extendedCost($variance, $unitCost),
            'note' => $note,
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $preview
     * @return array{positive: string, negative: string, total: string}
     */
    private function summarize(array $preview): array
    {
        $positive = '0.00';
        $negative = '0.00';
        $total = '0.00';

        foreach ($preview as $row) {
            $value = (string) $row['variance_value'];
            $total = bcadd($total, $value, 2);

            if (bccomp($value, '0', 2) > 0) {
                $positive = bcadd($positive, $value, 2);
            } elseif (bccomp($value, '0', 2) < 0) {
                $negative = bcadd($negative, $value, 2);
            }
        }

        return ['positive' => $positive, 'negative' => $negative, 'total' => $total];
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int>
     */
    private function buildHeaderMap(mixed $header): array
    {
        if (! is_array($header)) {
            return [];
        }

        $map = [];
        foreach (array_values($header) as $index => $cell) {
            $key = ProductImportSchema::normalizeHeader($cell);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     */
    private function rawValue(array $row, array $headerMap, string $key): mixed
    {
        if (! isset($headerMap[$key])) {
            return null;
        }

        return $row[$headerMap[$key]] ?? null;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $headerMap
     */
    private function stringValue(array $row, array $headerMap, string $key): ?string
    {
        $raw = $this->rawValue($row, $headerMap, $key);

        if ($raw === null || is_array($raw)) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function isEmptyRow(mixed $row): bool
    {
        if (! is_array($row)) {
            return true;
        }

        foreach ($row as $cell) {
            if ($cell === null) {
                continue;
            }
            if (is_string($cell) && trim($cell) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function capErrors(array $errors): array
    {
        if (count($errors) <= self::MAX_REPORTED_ERRORS) {
            return $errors;
        }

        $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
        $shown[] = 'dan '.(count($errors) - self::MAX_REPORTED_ERRORS).' kesalahan lain.';

        return $shown;
    }
}
