<?php

namespace App\Exports;

use App\Exports\Sheets\ProductComponentsSheet;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Ekspor produk PLUS sheet komponen (recipe & product sale consumption).
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
            new ProductComponentsSheet($this->store),
        ];
    }
}
