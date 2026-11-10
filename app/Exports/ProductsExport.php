<?php

namespace App\Exports;

use App\Models\Product;
use App\Models\Store;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use App\Support\ModifierOptions;
use App\Support\ProductImportSchema;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor seluruh produk toko dengan susunan kolom yang sama seperti template impor,
 * sehingga file hasil ekspor bisa diedit lalu diunggah kembali lewat fitur impor.
 */
class ProductsExport implements FromQuery, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        protected Store $store
    ) {}

    /**
     * @return Builder<Product>
     */
    public function query(): Builder
    {
        return Product::query()
            ->where('store_id', $this->store->id)
            ->orderBy('name')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return ProductImportSchema::headingsFor($this->store, withExportInfo: true);
    }

    /**
     * @param  Product  $product
     * @return list<mixed>
     */
    public function map($product): array
    {
        $values = [
            ProductImportSchema::COL_NAME => $product->name,
            ProductImportSchema::COL_SKU => $product->sku,
            ProductImportSchema::COL_BARCODE => $product->barcode,
            ProductImportSchema::COL_CATEGORY => $product->category,
            ProductImportSchema::COL_BRAND => $product->brand,
            ProductImportSchema::COL_UNIT => $product->unit,
            ProductImportSchema::COL_PRICE => (float) $product->price,
            ProductImportSchema::COL_WHOLESALE_PRICE => $product->wholesale_price !== null ? (float) $product->wholesale_price : null,
            ProductImportSchema::COL_COST => $product->cost_price !== null ? (float) $product->cost_price : null,
            ProductImportSchema::COL_STOCK => (float) $product->stock_quantity,
            ProductImportSchema::COL_MIN_STOCK => (float) $product->min_stock_level,
            ProductImportSchema::COL_TAX => (float) $product->tax_rate,
            ProductImportSchema::COL_INVENTORY_TYPE => InventoryType::of($product),
            ProductImportSchema::COL_BASE_UNIT => BaseUnit::normalizeBaseUnit($product->base_unit),
            ProductImportSchema::COL_TRACK_STOCK => ProductImportSchema::booleanLabel($product->track_stock),
            ProductImportSchema::COL_UNLIMITED_STOCK => ProductImportSchema::booleanLabel($product->unlimited_stock),
            ProductImportSchema::COL_ALLOWS_DECIMAL => ProductImportSchema::booleanLabel($product->allows_decimal),
            ProductImportSchema::COL_IS_ACTIVE => ProductImportSchema::booleanLabel($product->is_active),
            ProductImportSchema::COL_ALLOW_NEGATIVE_STOCK => ProductImportSchema::booleanLabel($product->allow_negative_stock),
            ProductImportSchema::COL_DESCRIPTION => $product->description,
            ProductImportSchema::COL_IMAGE_URL => $product->image_url,
            ProductImportSchema::COL_BATCH_CODE => null,
            ProductImportSchema::COL_EXPIRY_DATE => null,
            ProductImportSchema::COL_PHARMACY_CLASS => $product->pharmacy_sale_class,
            ProductImportSchema::COL_PHARMACY_REG_NO => $product->pharmacy_registration_no,
            ProductImportSchema::COL_MODIFIER_OPTIONS => ModifierOptions::toJson($product->modifier_options),
            ProductImportSchema::COL_SERVICE_DURATION => $product->service_duration_minutes,
            ProductImportSchema::COL_BARBER_COMMISSION_MODE => $product->barber_commission_mode,
            ProductImportSchema::COL_BARBER_COMMISSION_VALUE => $product->barber_commission_value !== null
                ? (float) $product->barber_commission_value
                : null,
        ];

        $row = [];
        foreach (ProductImportSchema::keysFor($this->store) as $key) {
            $row[] = $values[$key] ?? null;
        }

        $row[] = $product->id;
        $row[] = ProductImportSchema::booleanLabel($product->has_variants);
        $row[] = ProductImportSchema::booleanLabel($product->is_bundle);
        $row[] = $product->average_cost !== null ? (float) $product->average_cost : null;

        return $row;
    }

    public function title(): string
    {
        return 'Produk';
    }

    public function columnWidths(): array
    {
        $widths = [];
        $index = 0;

        foreach (ProductImportSchema::columnsFor($this->store) as $col) {
            $widths[$this->columnLetter($index++)] = $col['width'];
        }

        foreach (ProductImportSchema::EXPORT_INFO_COLUMNS as $label) {
            $widths[$this->columnLetter($index++)] = max(14, mb_strlen($label) + 2);
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $this->columnLetter(
            count(ProductImportSchema::keysFor($this->store)) + count(ProductImportSchema::EXPORT_INFO_COLUMNS) - 1
        );

        $sheet->freezePane('A2');

        return [
            "A1:{$lastColumn}1" => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0891B2'],
                ],
            ],
        ];
    }

    private function columnLetter(int $zeroBasedIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
