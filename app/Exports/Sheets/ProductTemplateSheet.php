<?php

namespace App\Exports\Sheets;

use App\Models\Store;
use App\Support\ProductImportSchema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet isian template impor: header plus baris contoh.
 *
 * Baris contoh pakai prefix "CONTOH_" supaya jelas harus dihapus/ditimpa.
 * Baris berwarna abu-abu supaya berbeda dari data asli pengguna.
 */
class ProductTemplateSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected Store $store
    ) {}

    public function array(): array
    {
        $keys = ProductImportSchema::keysFor($this->store);

        $exampleA = array_fill_keys($keys, null);
        $exampleB = array_fill_keys($keys, null);
        $exampleC = array_fill_keys($keys, null);

        $exampleA[ProductImportSchema::COL_NAME] = 'CONTOH_ Es Teh Manis';
        $exampleA[ProductImportSchema::COL_SKU] = 'MIN-001';
        $exampleA[ProductImportSchema::COL_CATEGORY] = 'Minuman';
        $exampleA[ProductImportSchema::COL_UNIT] = 'cup';
        $exampleA[ProductImportSchema::COL_PRICE] = '5000';
        $exampleA[ProductImportSchema::COL_COST] = '2500';
        $exampleA[ProductImportSchema::COL_STOCK] = '24';
        $exampleA[ProductImportSchema::COL_MIN_STOCK] = '5';
        $exampleA[ProductImportSchema::COL_TRACK_STOCK] = 'ya';
        $exampleA[ProductImportSchema::COL_IS_ACTIVE] = 'ya';

        $exampleB[ProductImportSchema::COL_NAME] = 'CONTOH_ Gula Pasir 1kg';
        $exampleB[ProductImportSchema::COL_SKU] = 'BAH-001';
        $exampleB[ProductImportSchema::COL_CATEGORY] = 'Bahan Baku';
        $exampleB[ProductImportSchema::COL_UNIT] = 'kg';
        $exampleB[ProductImportSchema::COL_PRICE] = '18000';
        $exampleB[ProductImportSchema::COL_COST] = '15000';
        $exampleB[ProductImportSchema::COL_STOCK] = '10';
        $exampleB[ProductImportSchema::COL_INVENTORY_TYPE] = 'raw_material';
        $exampleB[ProductImportSchema::COL_TRACK_STOCK] = 'ya';
        $exampleB[ProductImportSchema::COL_IS_ACTIVE] = 'ya';

        $exampleC[ProductImportSchema::COL_NAME] = 'CONTOH_ Jasa Ongkir';
        $exampleC[ProductImportSchema::COL_PRICE] = '0';
        $exampleC[ProductImportSchema::COL_STOCK] = '0';
        $exampleC[ProductImportSchema::COL_TRACK_STOCK] = 'tidak';
        $exampleC[ProductImportSchema::COL_IS_ACTIVE] = 'ya';
        $exampleC[ProductImportSchema::COL_DESCRIPTION] = 'Contoh produk jasa tanpa stok';

        return [
            array_values($exampleA),
            array_values($exampleB),
            array_values($exampleC),
        ];
    }

    public function headings(): array
    {
        return ProductImportSchema::headingsFor($this->store);
    }

    public function title(): string
    {
        return 'Template Produk';
    }

    public function columnWidths(): array
    {
        $widths = [];
        $index = 0;

        foreach (ProductImportSchema::columnsFor($this->store) as $col) {
            $widths[Coordinate::stringFromColumnIndex(++$index)] = $col['width'];
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count(ProductImportSchema::keysFor($this->store)));
        $lastRow = 4; // header + 3 baris contoh

        $sheet->freezePane('A2');
        $sheet->getComment('A1')->getText()->createTextRun(
            'Isi data mulai baris ke-2. Ganti atau hapus baris CONTOH_ di bawah ini dengan data produk Anda yang sebenarnya.'
        );

        return [
            "A1:{$lastColumn}1" => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0891B2'],
                ],
            ],
            "A2:{$lastColumn}{$lastRow}" => [
                'font' => ['italic' => true, 'color' => ['rgb' => '64748B']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F1F5F9'],
                ],
            ],
        ];
    }
}
