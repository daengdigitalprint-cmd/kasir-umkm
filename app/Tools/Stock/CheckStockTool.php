<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiStockVelocity;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class CheckStockTool extends AiTool
{
    public const DEFAULT_THRESHOLD = 10;

    protected const MAX_ROWS = 30;

    public function getName(): string
    {
        return 'check_stock';
    }

    public function getDescription(): string
    {
        return 'Mengecek stok saat ini: satu produk (nama/ID), satu kategori, daftar stok rendah (stok ≤ stok minimum produk, TERMASUK yang sudah habis), atau hanya yang habis. Hanya produk yang stoknya dilacak yang dinilai rendah/habis. Gunakan tool ini saat user ingin mengetahui kondisi stok.';
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
                'description' => 'Nama produk yang ingin dicek (opsional, pencarian sebagian nama)',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Batasi ke satu kategori, mis. "Bahan Baku" (opsional, pencarian sebagian nama kategori)',
            ],
            'low_stock_threshold' => [
                'type' => 'integer',
                'description' => 'Batas stok rendah untuk produk yang belum punya stok minimum (default: 10). Produk yang punya stok minimum memakai nilainya sendiri.',
            ],
            'show_all' => [
                'type' => 'boolean',
                'description' => 'Jika true, tampilkan semua produk (sesuai filter) beserta stoknya, bukan hanya yang rendah',
            ],
            'out_of_stock' => [
                'type' => 'boolean',
                'description' => 'Jika true, tampilkan hanya produk dengan stok habis (≤ 0)',
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $threshold = max(0, (float) ($args['low_stock_threshold'] ?? self::DEFAULT_THRESHOLD));
        $category = trim((string) ($args['category'] ?? ''));
        $productName = trim((string) ($args['product_name'] ?? ''));
        $outOfStock = ! empty($args['out_of_stock']);
        $specific = ! empty($args['product_id']) || $productName !== '';
        $showAll = ! empty($args['show_all']) || $specific;

        // Stok rendah/habis hanya bermakna untuk produk yang stoknya dilacak.
        // Produk yang disebut langsung tetap ditampilkan walau stoknya tidak dilacak.
        $query = ($specific && ! $outOfStock)
            ? Product::query()->where('store_id', $store->id)->where('is_active', true)
            : AiStockVelocity::trackedProducts([$store->id]);

        if (! empty($args['product_id'])) {
            $query->where('id', $args['product_id']);
        }

        if ($productName !== '') {
            $query->where('name', 'like', "%{$productName}%");
        }

        if ($category !== '') {
            $query->where(function ($q) use ($category) {
                $q->where('category', 'like', "%{$category}%")
                    ->orWhereHas('productCategory', fn ($c) => $c->where('name', 'like', "%{$category}%"));
            });
        }

        // Batas rendah per produk: stok minimum-nya sendiri, atau ambang umum bila belum diisi.
        $effectiveMinimum = 'CASE WHEN COALESCE(min_stock_level, 0) > 0 THEN min_stock_level ELSE ? END';

        if ($outOfStock) {
            $query->where('stock_quantity', '<=', 0);
        } elseif (! $showAll) {
            $query->whereRaw("stock_quantity <= {$effectiveMinimum}", [$threshold]);
        }

        $total = (clone $query)->count();

        $products = $query->with('productCategory')
            ->orderBy('stock_quantity', 'asc')
            ->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get();

        $filterLabel = $category !== '' ? " pada kategori \"{$category}\"" : '';

        if ($products->isEmpty()) {
            $message = match (true) {
                $productName !== '' => "Tidak ditemukan produk dengan nama \"{$productName}\"{$filterLabel}",
                $outOfStock => "Tidak ada produk (yang stoknya dilacak) dengan stok habis{$filterLabel}",
                $showAll => "Tidak ditemukan produk{$filterLabel}",
                default => "Tidak ada produk (yang stoknya dilacak) dengan stok rendah atau habis{$filterLabel} — semuanya masih di atas stok minimum (atau di atas ".AiMetrics::number($threshold).' bila stok minimum belum diisi)',
            };

            return ToolResult::success(
                data: ['products' => [], 'total' => 0, 'threshold' => $threshold, 'category' => $category ?: null],
                message: $message
            );
        }

        $productList = $products->map(function (Product $p) use ($threshold) {
            $stock = (float) $p->stock_quantity;
            $minimum = (float) $p->min_stock_level > 0 ? (float) $p->min_stock_level : $threshold;
            $tracked = (bool) $p->track_stock && ! $p->unlimited_stock;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => $this->formatStock((string) $p->stock_quantity),
                'unit' => $p->unit ?? 'pcs',
                'category' => $p->productCategory?->name ?? $p->category ?? '-',
                'min_stock_level' => (float) $p->min_stock_level > 0 ? $this->formatStock((string) $p->min_stock_level) : null,
                'stock_tracked' => $tracked,
                'is_out_of_stock' => $tracked && $stock <= 0,
                'is_low_stock' => $tracked && $stock <= $minimum,
            ];
        })->toArray();

        $outCount = count(array_filter($productList, fn (array $p) => $p['is_out_of_stock']));
        $lowCount = count(array_filter($productList, fn (array $p) => $p['is_low_stock']));
        $shown = count($productList);
        $more = $total > $shown ? " (ditampilkan {$shown} dari {$total}, urut dari stok terkecil)" : '';

        $message = match (true) {
            $outOfStock => "Ditemukan {$total} produk dengan stok habis{$filterLabel}{$more}",
            $showAll => "Menampilkan {$shown} produk{$filterLabel}".($total > $shown ? " dari {$total}" : '')
                .($lowCount > 0 ? " — {$lowCount} stok rendah".($outCount > 0 ? ", {$outCount} di antaranya habis" : '') : ''),
            default => "Ditemukan {$total} produk dengan stok rendah{$filterLabel}"
                .($outCount > 0 ? ($outCount === $shown && $total === $shown ? ' — semuanya sudah habis' : " — {$outCount} di antaranya sudah habis") : '')
                .$more,
        };

        return ToolResult::success(
            data: [
                'products' => $productList,
                'total' => $total,
                'shown' => $shown,
                'low_stock_count' => $lowCount,
                'out_of_stock_count' => $outCount,
                'threshold' => $threshold,
                'category' => $category ?: null,
            ],
            message: $message
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
