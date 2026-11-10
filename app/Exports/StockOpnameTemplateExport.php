<?php

namespace App\Exports;

use App\Models\StockAdjustmentLine;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar hitung stock opname untuk satu sesi.
 *
 * Kolom `Stok Fisik` dan `Catatan` sengaja dibiarkan kosong untuk diisi
 * petugas saat menghitung di gudang. Nilai `Stok Sistem` adalah snapshot sesi
 * (bukan stok berjalan) supaya selisih tetap konsisten walau ada transaksi
 * selama proses hitung berlangsung.
 *
 * Pencocokan saat unggah memakai SKU/Barcode/Kode Batch; bila kolom tersebut
 * kosong, baris dicocokkan lewat Nama Produk + Varian + Satuan. Kolom nama
 * dan varian tetap ditampilkan agar mudah dibandingkan dengan hasil hitung.
 */
class StockOpnameTemplateExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly int $stockAdjustmentId,
        private readonly bool $withBatch = false,
    ) {}

    public function query(): Builder
    {
        return StockAdjustmentLine::query()
            ->where('stock_adjustment_id', $this->stockAdjustmentId)
            ->with(['product', 'productVariant', 'productBatch'])
            ->orderBy('id');
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = ['SKU', 'Barcode', 'Nama Produk', 'Varian', 'Satuan', 'Stok Sistem', 'Stok Fisik', 'Catatan'];

        return $this->withBatch ? array_merge($headings, ['Kode Batch']) : $headings;
    }

    /** @return list<string> */
    public function map($line): array
    {
        $product = $line->product;
        $variant = $line->productVariant;

        $row = [
            $variant?->sku ?? $product?->sku ?? '',
            $product?->barcode ?? '',
            $product?->name ?? '',
            $variant?->name ?? '',
            $product?->unit ?? '',
            (string) $line->system_quantity,
            '',
            '',
        ];

        if ($this->withBatch) {
            $row[] = $line->batch_code ?? $line->productBatch?->batch_code ?? '';
        }

        return $row;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function styles(Worksheet $sheet): array
    {
        // Lebar kolom tetap: autosize lambat untuk lembar dengan ribuan baris.
        $widths = ['A' => 22, 'B' => 20, 'C' => 42, 'D' => 22, 'E' => 12, 'F' => 14, 'G' => 14, 'H' => 28, 'I' => 20];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return [1 => ['font' => ['bold' => true]]];
    }
}
