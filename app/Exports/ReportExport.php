<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        $rows = [];

        // Summary section
        $rows[] = ['RINGKASAN PENJUALAN'];
        $rows[] = ['Total Penjualan', 'Rp '.number_format($this->data['summary']['total_sales'], 0, ',', '.')];
        $rows[] = ['Total Transaksi', number_format($this->data['summary']['total_transactions']).' transaksi'];
        $rows[] = ['Rata-rata Nilai Transaksi', 'Rp '.number_format($this->data['summary']['average_order_value'], 0, ',', '.')];
        $rows[] = []; // Empty row

        // Top Products section
        if (count($this->data['topProducts']) > 0) {
            $rows[] = ['PRODUK TERLARIS'];
            $rows[] = ['No', 'Nama Produk', 'Terjual', 'Harga', 'Total Penjualan'];

            foreach ($this->data['topProducts'] as $index => $product) {
                $sold = (float) ($product->total_sold ?? 0);
                $rows[] = [
                    $index + 1,
                    $product->name,
                    $sold,
                    'Rp '.number_format($product->price, 0, ',', '.'),
                    'Rp '.number_format($product->price * $sold, 0, ',', '.'),
                ];
            }
            $rows[] = []; // Empty row
        }

        // Recent Transactions section
        if (count($this->data['recentTransactions']) > 0) {
            $rows[] = ['TRANSAKSI TERBARU'];
            $rows[] = ['No', 'Tanggal', 'Pelanggan', 'Total', 'Status'];

            foreach ($this->data['recentTransactions'] as $index => $transaction) {
                $rows[] = [
                    $index + 1,
                    \Carbon\Carbon::parse($transaction->created_at)->format('d/m/Y H:i'),
                    $transaction->customer ? $transaction->customer->name : 'Pelanggan Umum',
                    'Rp '.number_format($transaction->total_amount, 0, ',', '.'),
                    $transaction->status === 'completed' ? 'Selesai' : ucfirst($transaction->status),
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'LAPORAN PENJUALAN',
            $this->data['store']->name,
            'Periode: '.\Carbon\Carbon::parse($this->data['dateRange']['start_date'])->format('d F Y').' - '.\Carbon\Carbon::parse($this->data['dateRange']['end_date'])->format('d F Y'),
            'Dibuat pada: '.\Carbon\Carbon::now()->format('d F Y H:i'),
        ];
    }

    public function title(): string
    {
        return 'Laporan Penjualan';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 8,
            'B' => 25,
            'C' => 15,
            'D' => 15,
            'E' => 20,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Header styles
        $sheet->getStyle('A1:E4')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'E3F2FD',
                ],
            ],
        ]);

        // Section headers
        $sheet->getStyle('A5')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 14,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '2563EB',
                ],
            ],
            'font' => [
                'color' => [
                    'rgb' => 'FFFFFF',
                ],
            ],
        ]);

        // Table headers
        $sheet->getStyle('A7:E7')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'F8F9FA',
                ],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Data rows
        $sheet->getStyle('A8:E'.(8 + count($this->data['topProducts']) - 1))->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Auto-fit columns
        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
