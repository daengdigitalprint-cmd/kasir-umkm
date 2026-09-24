<?php

namespace App\Tools\Products;

use App\Models\Product;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class SearchProductTool extends AiTool
{
    public function getName(): string
    {
        return 'search_product';
    }

    public function getDescription(): string
    {
        return 'Mencari produk berdasarkan nama, SKU, atau kategori. Gunakan tool ini saat user ingin mencari produk.';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Kata kunci pencarian (nama produk, SKU, atau kategori)',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Filter berdasarkan kategori (opsional)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah maksimal hasil (default: 10)',
            ],
        ];
    }

    protected function getRequiredParameters(): array
    {
        return ['query'];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $this->validateArgs($args, $this->getRequiredParameters());

        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $limit = $args['limit'] ?? 10;

        $query = Product::where('store_id', $store->id)
            ->where(function ($q) use ($args) {
                $q->where('name', 'like', "%{$args['query']}%")
                    ->orWhere('sku', 'like', "%{$args['query']}%")
                    ->orWhere('barcode', 'like', "%{$args['query']}%");
            });

        if (! empty($args['category'])) {
            $query->whereHas('productCategory', function ($q) use ($args) {
                $q->where('name', 'like', "%{$args['category']}%");
            });
        }

        $products = $query->with('productCategory')
            ->limit($limit)
            ->get();

        if ($products->isEmpty()) {
            return ToolResult::success(
                data: ['products' => []],
                message: "Tidak ditemukan produk dengan kata kunci \"{$args['query']}\""
            );
        }

        $productList = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => $this->formatCurrency($p->price),
            'stock' => $p->stock_quantity,
            'category' => $p->productCategory?->name ?? $p->category ?? '-',
        ])->toArray();

        return ToolResult::success(
            data: ['products' => $productList, 'total' => $products->count()],
            message: "Ditemukan {$products->count()} produk"
        );
    }
}
