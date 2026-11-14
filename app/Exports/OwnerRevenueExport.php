<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OwnerRevenueExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /** @param list<array{Periode: string, Outlet: string, 'Penjualan Bersih': float}> */
    public function __construct(
        protected array $data
    ) {}

    public function array(): array
    {
        return $this->data;
    }

    public function headings(): array
    {
        return ['Periode', 'Outlet', 'Penjualan Bersih'];
    }

    public function title(): string
    {
        return 'Penjualan Bersih Outlet';
    }

    public function columnWidths(): array
    {
        return ['A' => 20, 'B' => 30, 'C' => 20];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            'A1:C1' => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0891B2'],
                ],
            ],
        ];
    }
}
