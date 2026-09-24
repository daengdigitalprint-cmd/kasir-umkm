<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception dilempar ketika komponen produk memiliki stok tidak mencukupi.
 * Membawa daftar detail semua komponen bermasalah untuk ditampilkan ke UI.
 */
class ComponentStockInsufficientException extends RuntimeException
{
    /**
     * @var array<int, array{component_name: string, needed: string, available: string, unit: string}>
     */
    private array $issues;

    /**
     * @param  array<int, array{component_name: string, needed: string, available: string, unit: string}>  $issues
     */
    public function __construct(string $productName, array $issues)
    {
        $this->issues = $issues;

        $details = array_map(
            fn (array $i) => "{$i['component_name']} (tersedia {$i['available']} {$i['unit']}, dibutuhkan {$i['needed']} {$i['unit']})",
            $issues,
        );

        parent::__construct(
            "Stok komponen \"{$productName}\" tidak mencukupi: ".implode('; ', $details)
        );
    }

    /**
     * @return array<int, array{component_name: string, needed: string, available: string, unit: string}>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }
}
