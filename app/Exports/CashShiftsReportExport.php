<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CashShiftsReportExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array{store: object, dateRange: array{start_date: string, end_date: string}, summary: array<string, int|float>, rows: list<array<int|float|string>>}  $data
     */
    public function __construct(
        protected array $data
    ) {}

    public function array(): array
    {
        $s = $this->data['summary'];
        $rows = [];

        $rows[] = ['RINGKASAN'];
        $rows[] = ['Shift ditutup (periode filter)', $s['shifts_closed']];
        $rows[] = ['Shift dengan selisih kas ≠ 0', $s['shifts_with_difference']];
        $rows[] = ['Total penjualan tunai (dalam shift), Rp', $s['total_cash_sales_in_shifts']];
        $rows[] = ['Jumlah selisih (tutup), Rp', $s['total_difference_sum']];
        $rows[] = ['Shift masih terbuka saat ekspor', $s['open_shifts_now']];
        $rows[] = [];

        $rows[] = [
            'No',
            'Kode shift',
            'Kasir',
            'Waktu buka',
            'Waktu tutup',
            'Kas awal (Rp)',
            'Tunai terjual (Rp)',
            'Perkiraan kas (Rp)',
            'Kas dihitung (Rp)',
            'Selisih (Rp)',
            'Jumlah trx',
            'Catatan tutup',
        ];

        foreach ($this->data['rows'] as $r) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function headings(): array
    {
        $dr = $this->data['dateRange'];

        return [
            'Laporan shift kasir',
            $this->data['store']->name,
            'Periode tutup: '.Carbon::parse($dr['start_date'])->translatedFormat('d F Y').' — '.Carbon::parse($dr['end_date'])->translatedFormat('d F Y'),
            'Dibuat: '.Carbon::now()->translatedFormat('d F Y H:i'),
        ];
    }

    public function title(): string
    {
        return 'Shift kasir';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6,
            'B' => 18,
            'C' => 22,
            'D' => 18,
            'E' => 18,
            'F' => 14,
            'G' => 16,
            'H' => 16,
            'I' => 16,
            'J' => 12,
            'K' => 10,
            'L' => 28,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:D1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E7FF'],
            ],
        ]);

        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
        ]);

        /** Baris judul tabel (satu baris meta + 7 baris ringkasan) */
        $tableHeaderRow = 9;
        $sheet->getStyle('A'.$tableHeaderRow.':L'.$tableHeaderRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F1F5F9'],
            ],
        ]);
    }
}
