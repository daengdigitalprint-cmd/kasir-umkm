<?php

namespace App\Imports;

use App\Support\ProductImportSchema;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Pembaca berkas hasil hitung fisik stock opname menjadi baris mentah.
 *
 * Header dibaca manual agar toleran terhadap variasi penulisan judul kolom.
 * Sheet yang dipakai adalah sheet pertama yang memiliki kolom "Stok Fisik",
 * sehingga berkas lain (misalnya template impor produk) langsung dikenali
 * sebagai bukan lembar hitung opname.
 */
class StockOpnameSheetImport implements ToArray
{
    use Importable;

    public const COL_COUNTED = 'stok_fisik';

    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        // Tidak dipakai: pembacaan memakai toArray() lalu diolah oleh StockOpnameImportService.
    }

    /**
     * Baris mentah sheet hitung, termasuk baris header pada indeks 0.
     *
     * @return list<list<mixed>>
     */
    public function rowsFrom(string $path, ?string $disk = null): array
    {
        $sheets = $this->toArray($path, $disk);

        foreach ($sheets as $rows) {
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $rows = array_values($rows);
            $header = is_array($rows[0]) ? $rows[0] : [];

            foreach ($header as $cell) {
                if (ProductImportSchema::normalizeHeader($cell) === self::COL_COUNTED) {
                    return array_map(
                        static fn ($row) => is_array($row) ? array_values($row) : [],
                        $rows,
                    );
                }
            }
        }

        return [];
    }
}
