<?php

namespace App\Exports;

use App\Models\StockAdjustmentLine;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lembar hitung stock opname untuk satu sesi.
 *
 * - Kolom pertama "ID Baris" disembunyikan dan dipakai untuk mencocokkan saat unggah,
 *   sehingga nama produk boleh berubah/terketik ulang tanpa membuat baris gagal cocok.
 * - Urut per kategori lalu nama, supaya penghitung tidak bolak-balik antar rak.
 * - Kolom "Stok Sistem" tidak dicetak bila sesi memakai hitung buta.
 * - "Stok Fisik" yang dibiarkan kosong = belum dihitung (bukan error).
 */
class StockOpnameTemplateExport implements FromQuery, WithChunkReading, WithEvents, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly int $stockAdjustmentId,
        private readonly bool $withBatch = false,
        private readonly bool $blindCount = false,
    ) {}

    public function query(): Builder
    {
        return StockAdjustmentLine::query()
            ->select('stock_adjustment_lines.*')
            ->leftJoin('products', 'products.id', '=', 'stock_adjustment_lines.product_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->where('stock_adjustment_lines.stock_adjustment_id', $this->stockAdjustmentId)
            ->with(['product.productCategory', 'productVariant', 'productBatch'])
            // Produk tanpa kategori di akhir.
            ->orderByRaw('product_categories.name IS NULL')
            ->orderBy('product_categories.name')
            ->orderBy('products.name')
            ->orderBy('stock_adjustment_lines.id');
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = ['ID Baris', 'Kategori', 'SKU', 'Barcode', 'Nama Produk', 'Varian', 'Satuan'];
        if (! $this->blindCount) {
            $headings[] = 'Stok Sistem';
        }
        $headings[] = 'Stok Fisik';
        $headings[] = 'Catatan';

        return $this->withBatch ? array_merge($headings, ['Kode Batch']) : $headings;
    }

    /** @return list<string|int> */
    public function map($line): array
    {
        $product = $line->product;
        $variant = $line->productVariant;

        $row = [
            $line->id,
            $product?->productCategory?->name ?? '',
            $variant?->sku ?? $product?->sku ?? '',
            $product?->barcode ?? '',
            $product?->name ?? '',
            $variant?->name ?? '',
            $product?->unit ?? '',
        ];
        if (! $this->blindCount) {
            $row[] = (string) (float) $line->system_quantity;
        }
        // Yang sudah dihitung ikut dicetak, supaya lembar bisa dilanjutkan.
        $row[] = $line->counted_quantity === null ? '' : (string) (float) $line->counted_quantity;
        $row[] = $line->line_notes ?? '';

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
        $widths = [8, 20, 18, 18, 42, 20, 10];
        if (! $this->blindCount) {
            $widths[] = 13;
        }
        $widths[] = 13;
        $widths[] = 28;
        if ($this->withBatch) {
            $widths[] = 20;
        }

        foreach ($widths as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
        }

        return [1 => ['font' => ['bold' => true]]];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                $sheet->getColumnDimension('A')->setVisible(false);
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

                // Sorot kolom yang perlu diisi.
                $countedColumn = Coordinate::stringFromColumnIndex($this->blindCount ? 8 : 9);
                $sheet->getStyle("{$countedColumn}1:{$countedColumn}{$lastRow}")
                    ->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF7D6');
            },
        ];
    }
}
