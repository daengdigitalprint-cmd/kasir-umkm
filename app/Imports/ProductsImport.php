<?php

namespace App\Imports;

use App\Support\ProductImportSchema;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Pembaca file impor produk menjadi baris mentah.
 *
 * Header dibaca manual (bukan WithHeadingRow) agar toleran terhadap variasi penulisan
 * judul kolom, lihat App\Support\ProductImportSchema::normalizeHeader(). Sheet yang
 * dipakai adalah sheet pertama yang memiliki kolom "Nama Produk" sehingga file hasil
 * ekspor maupun template (yang memiliki sheet panduan) sama-sama bisa diunggah.
 */
class ProductsImport implements ToArray
{
    use Importable;

    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        // Tidak dipakai: pembacaan memakai toArray() lalu diolah oleh ProductImportService.
    }

    /**
     * Baris mentah sheet produk, termasuk baris header pada indeks 0.
     *
     * @return list<list<mixed>>
     */
    public function rowsFrom(UploadedFile $file): array
    {
        $sheets = $this->toArray($file);

        foreach ($sheets as $rows) {
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $rows = array_values($rows);
            $header = is_array($rows[0]) ? $rows[0] : [];

            foreach ($header as $cell) {
                if (ProductImportSchema::normalizeHeader($cell) === ProductImportSchema::COL_NAME) {
                    return array_map(
                        static fn ($row) => is_array($row) ? array_values($row) : [],
                        $rows,
                    );
                }
            }
        }

        return [];
    }

    /**
     * Baris mentah sheet komponen (hasil ekspor "with-components"), termasuk header pada indeks 0.
     * Sheet dikenali dari kolom "Tipe Komponen".
     *
     * @return list<list<mixed>>
     */
    public function componentRowsFrom(UploadedFile $file): array
    {
        return $this->sheetWithHeader($file, 'tipe_komponen');
    }

    /**
     * Baris mentah sheet varian (hasil ekspor "with-components"), termasuk header pada indeks 0.
     * Sheet dikenali dari kolom "Nama Varian".
     *
     * @return list<list<mixed>>
     */
    public function variantRowsFrom(UploadedFile $file): array
    {
        return $this->sheetWithHeader($file, 'nama_varian');
    }

    /**
     * Sheet pertama yang memiliki kolom header tertentu.
     *
     * @return list<list<mixed>>
     */
    private function sheetWithHeader(UploadedFile $file, string $headerKey): array
    {
        $sheets = $this->toArray($file);

        foreach ($sheets as $rows) {
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $rows = array_values($rows);
            $header = is_array($rows[0]) ? $rows[0] : [];

            foreach ($header as $cell) {
                if (ProductImportSchema::normalizeHeader($cell) === $headerKey) {
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
