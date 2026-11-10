<?php

namespace App\Exports;

use App\Exports\Sheets\ProductComponentsSheet;
use App\Exports\Sheets\ProductVariantsSheet;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Ekspor produk PLUS sheet varian dan sheet komponen
 * (resep produksi, komponen menu, dan komponen produk paket).
 */
class ProductsWithComponentsExport implements WithMultipleSheets
{
    public function __construct(
        protected Store $store
    ) {}

    /**
     * @return list<object>
     */
    public function sheets(): array
    {
        return [
            new ProductsExport($this->store),
            new ProductVariantsSheet($this->store),
            new ProductComponentsSheet($this->store),
        ];
    }
}
