<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class CheckStockTool extends AiTool
{
    public function getName(): string
    {
        return 'check_stock';
    }

    public function getDescription(): string
    {
        return 'Mengecek stok produk tertentu atau daftar produk dengan stok rendah. Gunakan tool ini saat user ingin mengetahui kondisi stok.';
    }

    public function getParameters(): array
    {
        return [
            'product_id' => [
                'type' => 'integer',
                'description' => 'ID produk yang ingin dicek stoknya (opsional)',
            ],
            'product_name' => [
                'type' => 'string',
                'description' => 'Nama produk yang ingin dicek (opsional, akan dicari dengan LIKE)',
            ],
            'low_stock_threshold' => [
                'type' => 'integer',
                'description' => 'Ambang batas stok rendah (default: 10)',
            ],
            'show_all' => [
                'type' => 'boolean',
                'description' => 'Jika true, tampilkan semua produk beserta stoknya',
            ],
            'out_of_stock' => [
                'type' => 'boolean',
                'description' => 'Jika true, tampilkan produk dengan stok habis',
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $threshold = $args['low_stock_threshold'] ?? 10;

        $query = Product::where('store_id', $store->id)
            ->where('is_active', true);

        // Filter berdasarkan product_id
        if (! empty($args['product_id'])) {
            $query->where('id', $args['product_id']);
        }

        // Filter berdasarkan nama
        if (! empty($args['product_name'])) {
            $query->where('name', 'like', "%{$args['product_name']}%");
        }

        if (! empty($args['out_of_stock'])) {
            $query->where('stock_quantity', '<=', 0);
        } elseif (empty($args['product_id']) && empty($args['product_name']) && empty($args['show_all'])) {
            $query->where('stock_quantity', '>', 0)
                ->where('stock_quantity', '<=', $threshold);
        }

        $products = $query->with('productCategory')
            ->orderBy('stock_quantity', 'asc')
            ->limit(20)
            ->get();

        if ($products->isEmpty()) {
            if (! empty($args['product_name'])) {
                return ToolResult::success(
                    data: ['products' => []],
                    message: "Tidak ditemukan produk dengan nama \"{$args['product_name']}\""
                );
            }

            return ToolResult::success(
                data: ['products' => []],
                message: "Semua produk memiliki stok di atas {$threshold}"
            );
        }

        $productList = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'stock' => $this->formatStock($p->stock_quantity),
            'unit' => $p->unit ?? 'pcs',
            'category' => $p->productCategory?->name ?? $p->category ?? '-',
            'is_low_stock' => $p->stock_quantity <= $threshold,
        ])->toArray();

        $lowStockCount = $products->filter(fn ($p) => $p->stock_quantity <= $threshold)->count();

        return ToolResult::success(
            data: [
                'products' => $productList,
                'total' => $products->count(),
                'low_stock_count' => $lowStockCount,
                'threshold' => $threshold,
            ],
            message: ! empty($args['out_of_stock'])
                ? "Ditemukan {$products->count()} produk dengan stok habis"
                : ($lowStockCount > 0
                    ? "Ditemukan {$lowStockCount} produk dengan stok rendah (≤ {$threshold})"
                    : "Menampilkan {$products->count()} produk")
        );
    }

    protected function formatStock(string $stock): string
    {
        if (! str_contains($stock, '.')) {
            return $stock;
        }

        return rtrim(rtrim($stock, '0'), '.');
    }
}
