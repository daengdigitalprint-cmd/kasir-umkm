<?php

namespace App\Exports;

use App\Exports\Sheets\ProductTemplateGuideSheet;
use App\Exports\Sheets\ProductTemplateSheet;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Template impor produk: satu sheet isian dan satu sheet panduan pengisian.
 */
class ProductImportTemplateExport implements WithMultipleSheets
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
            new ProductTemplateSheet($this->store),
            new ProductTemplateGuideSheet($this->store),
        ];
    }
}
