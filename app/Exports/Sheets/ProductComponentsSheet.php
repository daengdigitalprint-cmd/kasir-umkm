<?php

namespace App\Exports\Sheets;

use App\Models\Product;
use App\Models\ProductSaleConsumption;
use App\Models\Recipe;
use App\Models\Store;
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
 * Sheet kedua untuk ekspor produk: komponen (recipe & product sale consumption).
 * Struktur:
 * - Tipe komponen: 'recipe' (resep produksi) atau 'consumption' (komponen penjualan)
 * - ID Produk (yang punya komponen)
 * - Nama Produk
 * - SKU Produk
 * - ID Komponen (produk yang dipakai)
 * - Nama Komponen
 * - SKU Komponen
 * - Kuantitas
 * - Satuan Dasar
 * - Urutan (sort_order)
 * - Kode Resep (hanya untuk tipe recipe)
 */
class ProductComponentsSheet implements FromCollection, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected Store $store
    ) {}

    public function collection(): Collection
    {
        $storeId = $this->store->id;
        $rows = [];

        // 1. Recipes (resep produksi)
        $recipes = Recipe::with(['product', 'ingredients.product'])
            ->where('store_id', $storeId)
            ->get();

        foreach ($recipes as $recipe) {
            foreach ($recipe->ingredients as $ing) {
                $rows[] = [
                    'type' => 'recipe',
                    'product_id' => $recipe->product_id,
                    'product_name' => $recipe->product->name,
                    'product_sku' => $recipe->product->sku,
                    'product_variant_sku' => null,
                    'product_variant_name' => null,
                    'component_id' => $ing->product_id,
                    'component_name' => $ing->product->name,
                    'component_sku' => $ing->product->sku,
                    'quantity' => $ing->quantity,
                    'base_unit' => $ing->base_unit,
                    'sort_order' => $ing->sort_order,
                    'recipe_code' => $recipe->code,
                ];
            }
        }

        // 2. Product sale consumptions (komponen penjualan)
        $consumptions = ProductSaleConsumption::with(['product', 'variant', 'component'])
            ->where('store_id', $storeId)
            ->get();

        foreach ($consumptions as $cons) {
            $rows[] = [
                'type' => 'consumption',
                'product_id' => $cons->product_id,
                'product_name' => $cons->product->name,
                'product_sku' => $cons->product->sku,
                'product_variant_sku' => $cons->variant?->sku,
                'product_variant_name' => $cons->variant?->name,
                'component_id' => $cons->component_product_id,
                'component_name' => $cons->component->name,
                'component_sku' => $cons->component->sku,
                'quantity' => $cons->quantity,
                'base_unit' => $cons->base_unit,
                'sort_order' => $cons->sort_order,
                'recipe_code' => null,
            ];
        }

        return new Collection($rows);
    }

    public function headings(): array
    {
        return [
            'Tipe Komponen',
            'ID Produk',
            'Nama Produk',
            'SKU Produk',
            'SKU Varian Produk',
            'Nama Varian Produk',
            'ID Komponen',
            'Nama Komponen',
            'SKU Komponen',
            'Kuantitas',
            'Satuan Dasar',
            'Urutan',
            'Kode Resep',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<mixed>
     */
    public function map($row): array
    {
        return [
            $row['type'],
            $row['product_id'],
            $row['product_name'],
            $row['product_sku'],
            $row['product_variant_sku'],
            $row['product_variant_name'],
            $row['component_id'],
            $row['component_name'],
            $row['component_sku'],
            $row['quantity'],
            $row['base_unit'],
            $row['sort_order'],
            $row['recipe_code'],
        ];
    }

    public function title(): string
    {
        return 'Komponen Produk';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, // Tipe Komponen
            'B' => 10, // ID Produk
            'C' => 30, // Nama Produk
            'D' => 18, // SKU Produk
            'E' => 18, // SKU Varian Produk
            'F' => 30, // Nama Varian Produk
            'G' => 10, // ID Komponen
            'H' => 30, // Nama Komponen
            'I' => 18, // SKU Komponen
            'J' => 12, // Kuantitas
            'K' => 14, // Satuan Dasar
            'L' => 10, // Urutan
            'M' => 16, // Kode Resep
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = 'M';
        $sheet->freezePane('A2');

        return [
            "A1:{$lastColumn}1" => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '059669'],
                ],
            ],
        ];
    }
}
