<?php

namespace App\Exports\Sheets;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\ProductImportSchema;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet varian produk pada ekspor "produk + komponen".
 *
 * Varian dicocokkan kembali saat impor memakai SKU Varian, lalu Nama Varian,
 * dalam lingkup produk induk yang ditunjuk kolom SKU/Nama Produk.
 */
class ProductVariantsSheet implements FromCollection, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected Store $store
    ) {}

    public function collection(): Collection
    {
        return ProductVariant::query()
            ->with('product')
            ->whereIn('product_id', Product::query()->where('store_id', $this->store->id)->select('id'))
            ->orderBy('product_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID Produk',
            'Nama Produk',
            'SKU Produk',
            'Nama Varian',
            'SKU Varian',
            'Barcode Varian',
            'Harga',
            'Harga Grosir',
            'Harga Beli',
            'Stok',
            'Stok Minimum',
            'Izinkan Penjualan Stok Habis',
            'Status Aktif',
            'Urutan',
        ];
    }

    /**
     * @param  ProductVariant  $variant
     * @return array<mixed>
     */
    public function map($variant): array
    {
        return [
            $variant->product_id,
            $variant->product?->name,
            $variant->product?->sku,
            $variant->name,
            $variant->sku,
            $variant->barcode,
            $variant->price !== null ? (float) $variant->price : null,
            $variant->wholesale_price !== null ? (float) $variant->wholesale_price : null,
            $variant->cost_price !== null ? (float) $variant->cost_price : null,
            (float) $variant->stock_quantity,
            (float) $variant->min_stock_level,
            ProductImportSchema::booleanLabel($variant->allow_negative_stock),
            ProductImportSchema::booleanLabel($variant->is_active),
            $variant->sort_order,
        ];
    }

    public function title(): string
    {
        return 'Varian Produk';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10, // ID Produk
            'B' => 30, // Nama Produk
            'C' => 18, // SKU Produk
            'D' => 24, // Nama Varian
            'E' => 20, // SKU Varian
            'F' => 20, // Barcode Varian
            'G' => 14, // Harga
            'H' => 14, // Harga Grosir
            'I' => 14, // Harga Beli
            'J' => 12, // Stok
            'K' => 14, // Stok Minimum
            'L' => 24, // Izinkan Penjualan Stok Habis
            'M' => 14, // Status Aktif
            'N' => 10, // Urutan
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [
            'A1:N1' => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '7C3AED'],
                ],
            ],
        ];
    }
}
