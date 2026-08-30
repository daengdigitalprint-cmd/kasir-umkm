<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export Excel generik untuk semua laporan.
 *
 * Menerima struktur "payload" yang sama dengan template PDF generik:
 *  - title, storeName, meta[], sections[], generatedAt
 *
 * Section bertipe keyvalue / table / note dirender berurutan menjadi baris-baris sheet.
 * Metadata gaya (baris judul, header tabel, baris footer) dikumpulkan lalu diterapkan
 * pada event AfterSheet agar tampil rapi (bold, border, fill).
 */
class GenericReportExport implements FromArray, WithEvents, WithTitle
{
    /** @var array<int, array{row:int, kind:string, cols:int}> */
    protected array $styleMap = [];

    protected int $maxCols = 1;

    public function __construct(protected array $payload) {}

    public function title(): string
    {
        return mb_substr($this->payload['title'] ?? 'Laporan', 0, 31);
    }

    public function array(): array
    {
        $rows = [];
        $cursor = 0; // index baris pada $rows (0-based); Excel row = cursor+1

        $push = function (array $cells, ?string $kind = null) use (&$rows, &$cursor) {
            $rows[] = $cells;
            $this->maxCols = max($this->maxCols, count($cells));
            if ($kind !== null) {
                $this->styleMap[] = ['row' => $cursor + 1, 'kind' => $kind, 'cols' => count($cells)];
            }
            $cursor++;
        };

        // Kop laporan
        $push([$this->payload['storeName'] ?? ''], 'store');
        $push([$this->payload['title'] ?? 'Laporan'], 'doctitle');
        foreach (($this->payload['meta'] ?? []) as $m) {
            $push([$m['label'].': '.$m['value']], 'meta');
        }
        $push([]); // spacer

        foreach (($this->payload['sections'] ?? []) as $section) {
            $type = $section['type'] ?? 'table';

            if (! empty($section['title'])) {
                $push([$section['title']], 'section');
            }

            if ($type === 'keyvalue') {
                foreach ($section['rows'] as $row) {
                    $push([$row['label'], $row['value']], ! empty($row['strong']) ? 'kvstrong' : 'kv');
                }
            } elseif ($type === 'note') {
                $push([$section['text'] ?? ''], 'note');
            } else {
                $push($section['headings'], 'thead');
                foreach ($section['rows'] as $row) {
                    $push(array_values($row));
                }
                if (! empty($section['footer'])) {
                    $push(array_values($section['footer']), 'tfoot');
                }
            }

            $push([]); // spacer antar section
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = $this->columnLetter($this->maxCols);

                foreach ($this->styleMap as $entry) {
                    $r = $entry['row'];
                    $span = 'A'.$r.':'.$lastCol.$r;

                    switch ($entry['kind']) {
                        case 'store':
                            $sheet->mergeCells($span);
                            $sheet->getStyle('A'.$r)->getFont()->setBold(true)->setSize(15);
                            break;
                        case 'doctitle':
                            $sheet->mergeCells($span);
                            $sheet->getStyle('A'.$r)->getFont()->setBold(true)->setSize(12)
                                ->getColor()->setRGB('7C3AED');
                            break;
                        case 'meta':
                            $sheet->mergeCells($span);
                            $sheet->getStyle('A'.$r)->getFont()->setSize(10)->getColor()->setRGB('4B5563');
                            break;
                        case 'section':
                            $sheet->mergeCells($span);
                            $sheet->getStyle('A'.$r)->getFont()->setBold(true)->setSize(12);
                            $sheet->getStyle('A'.$r)->getFill()->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setRGB('EEF2FF');
                            break;
                        case 'thead':
                            $cellSpan = 'A'.$r.':'.$this->columnLetter($entry['cols']).$r;
                            $sheet->getStyle($cellSpan)->getFont()->setBold(true);
                            $sheet->getStyle($cellSpan)->getFill()->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setRGB('F1F5F9');
                            $sheet->getStyle($cellSpan)->getBorders()->getAllBorders()
                                ->setBorderStyle(Border::BORDER_THIN);
                            break;
                        case 'tfoot':
                            $cellSpan = 'A'.$r.':'.$this->columnLetter($entry['cols']).$r;
                            $sheet->getStyle($cellSpan)->getFont()->setBold(true);
                            $sheet->getStyle($cellSpan)->getFill()->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setRGB('EEF2FF');
                            break;
                        case 'kvstrong':
                            $sheet->getStyle('A'.$r.':B'.$r)->getFont()->setBold(true);
                            $sheet->getStyle('B'.$r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                            break;
                        case 'kv':
                            $sheet->getStyle('A'.$r)->getFont()->setBold(true);
                            $sheet->getStyle('B'.$r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                            break;
                        case 'note':
                            $sheet->mergeCells($span);
                            $sheet->getStyle('A'.$r)->getAlignment()->setWrapText(true);
                            break;
                    }
                }

                foreach (range(1, $this->maxCols) as $i) {
                    $sheet->getColumnDimension($this->columnLetter($i))->setAutoSize(true);
                }
            },
        ];
    }

    protected function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, $index));
    }
}
