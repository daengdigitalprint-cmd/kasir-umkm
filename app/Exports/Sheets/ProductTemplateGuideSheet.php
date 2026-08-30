<?php

namespace App\Exports\Sheets;

use App\Models\Store;
use App\Support\ProductImportSchema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet panduan pengisian template impor produk: aturan umum, penjelasan setiap kolom,
 * dan contoh nilai yang benar.
 */
class ProductTemplateGuideSheet implements FromArray, WithColumnWidths, WithStyles, WithTitle
{
    public function __construct(
        protected Store $store
    ) {}

    public function array(): array
    {
        $rows = [];
        $rows[] = ['PANDUAN IMPOR PRODUK — '.$this->store->name];
        $rows[] = [];
        $rows[] = ['ATURAN UMUM'];

        foreach (ProductImportSchema::generalNotesFor($this->store) as $i => $note) {
            $rows[] = [($i + 1).'.', $note];
        }

        $rows[] = [];
        $rows[] = ['PENJELASAN & CONTOH KOLOM'];
        $rows[] = ['Kolom', 'Cara pengisian', 'Contoh 1', 'Contoh 2'];

        foreach (ProductImportSchema::columnsFor($this->store) as $col) {
            $rows[] = [$col['label'], $col['guide'], $col['examples'][0], $col['examples'][1]];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Panduan Pengisian';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 26,
            'B' => 95,
            'C' => 22,
            'D' => 22,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = count($this->array());

        $sheet->getStyle("B1:B{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A1:D{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $tableHeaderRow = $this->tableHeaderRow();

        return [
            'A1' => [
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0F172A']],
            ],
            'A3' => [
                'font' => ['bold' => true, 'color' => ['rgb' => '0891B2']],
            ],
            'A'.($tableHeaderRow - 1) => [
                'font' => ['bold' => true, 'color' => ['rgb' => '0891B2']],
            ],
            'A'.$tableHeaderRow.':D'.$tableHeaderRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0891B2'],
                ],
            ],
        ];
    }

    /** Baris header tabel kolom: judul + kosong + "ATURAN UMUM" + catatan + kosong + judul tabel. */
    private function tableHeaderRow(): int
    {
        return 3 + count(ProductImportSchema::generalNotesFor($this->store)) + 3;
    }
}
