<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** F7 — Ekspor Pantau Stok Semua Lokasi: satu kolom per lokasi, sel diwarnai bila menipis/habis. */
class StockMonitorExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    private const FIXED_COLUMNS = 3; // Produk, SKU, Satuan

    public function __construct(
        private readonly Collection $stores,
        private readonly array $rows,
    ) {}

    public function headings(): array
    {
        return ['Produk', 'SKU', 'Satuan', ...$this->stores->pluck('name')->all(), 'Total'];
    }

    public function array(): array
    {
        return array_map(function ($row) {
            $cells = [];
            foreach ($this->stores as $store) {
                $cell = $row['locations'][$store->id] ?? null;
                $cells[] = $cell === null ? '-' : $cell['stock'];
            }

            return [$row['name'], $row['sku'] ?? '', $row['unit'], ...$cells, $row['total']];
        }, $this->rows);
    }

    public function title(): string
    {
        return 'Stok Semua Lokasi';
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + $this->stores->count() + 1);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0891B2']],
        ]);

        // Kuning = menipis (≤ Stok Minimum), merah = habis.
        foreach (array_values($this->rows) as $r => $row) {
            foreach ($this->stores->values() as $c => $store) {
                $cell = $row['locations'][$store->id] ?? null;
                if ($cell === null || $cell['status'] === 'ok') {
                    continue;
                }
                $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + $c + 1).($r + 2);
                $sheet->getStyle($coordinate)->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($cell['status'] === 'out' ? 'FECACA' : 'FEF08A');
            }
        }

        return [];
    }
}
