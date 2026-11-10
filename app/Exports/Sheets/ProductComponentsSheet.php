<?php

namespace App\Exports\Sheets;

use App\Models\Product;
use App\Models\ProductBundleItem;
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
 * Sheet kedua untuk ekspor produk: komponen produk.
 * Struktur:
 * - Tipe komponen: 'recipe' (resep produksi), 'consumption' (komponen menu),
 *   atau 'bundle' (komponen produk paket)
 * - ID Produk (yang punya komponen)
 * - Nama Produk
 * - SKU Produk
 * - SKU/Nama Varian Produk (komponen menu per varian)
 * - ID Komponen (produk yang dipakai)
 * - Nama Komponen
 * - SKU Komponen
 * - SKU/Nama Varian Komponen (komponen paket yang menunjuk varian tertentu)
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
                    'component_variant_sku' => null,
                    'component_variant_name' => null,
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
                'component_variant_sku' => null,
                'component_variant_name' => null,
                'quantity' => $cons->quantity,
                'base_unit' => $cons->base_unit,
                'sort_order' => $cons->sort_order,
                'recipe_code' => null,
            ];
        }

        // 3. Komponen produk paket (bundle)
        $bundleItems = ProductBundleItem::with(['bundleProduct', 'componentProduct', 'componentVariant'])
            ->whereIn('bundle_product_id', Product::query()->where('store_id', $storeId)->select('id'))
            ->orderBy('bundle_product_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($bundleItems as $item) {
            $rows[] = [
                'type' => 'bundle',
                'product_id' => $item->bundle_product_id,
                'product_name' => $item->bundleProduct?->name,
                'product_sku' => $item->bundleProduct?->sku,
                'product_variant_sku' => null,
                'product_variant_name' => null,
                'component_id' => $item->component_product_id,
                'component_name' => $item->componentProduct?->name,
                'component_sku' => $item->componentProduct?->sku,
                'component_variant_sku' => $item->componentVariant?->sku,
                'component_variant_name' => $item->componentVariant?->name,
                'quantity' => $item->quantity,
                'base_unit' => $item->componentProduct?->base_unit,
                'sort_order' => $item->sort_order,
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
            'SKU Varian Komponen',
            'Nama Varian Komponen',
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
            $row['component_variant_sku'],
            $row['component_variant_name'],
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
            'J' => 20, // SKU Varian Komponen
            'K' => 30, // Nama Varian Komponen
            'L' => 12, // Kuantitas
            'M' => 14, // Satuan Dasar
            'N' => 10, // Urutan
            'O' => 16, // Kode Resep
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = 'O';
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
