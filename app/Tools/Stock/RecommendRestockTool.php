<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiStockVelocity;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class RecommendRestockTool extends AiTool
{
    public function getName(): string
    {
        return 'recommend_restock';
    }

    public function getDescription(): string
    {
        return 'Membuat rekomendasi belanja/restock cerdas: produk apa yang perlu dibeli, berapa jumlahnya (berdasarkan laju penjualan + stok minimal), estimasi modal, dan supplier terakhir. Gunakan saat user bertanya "rekomendasi restock", "daftar belanja", "perlu beli apa", "saran pembelian", atau "siapkan belanjaan".';
    }

    public function getParameters(): array
    {
        return [
            'days' => [
                'type' => 'integer',
                'description' => 'Periode analisis laju penjualan dalam hari (default: 30)',
            ],
            'coverage_days' => [
                'type' => 'integer',
                'description' => 'Target stok harus cukup untuk berapa hari ke depan (default: 14)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah maksimal produk yang direkomendasikan (default: 10)',
            ],
            'scope' => [
                'type' => 'string',
                'description' => 'Cakupan data: "current" hanya outlet aktif user (default), "all" menggabungkan SEMUA outlet/cabang dalam grup usaha yang sama. Hanya berlaku untuk owner/admin/manager.',
                'enum' => ['current', 'all'],
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $scope = AiStoreScope::resolve($user, $args['scope'] ?? AiStoreScope::CURRENT);
        $storeIds = $scope['store_ids'];
        $allBranches = $scope['all_branches'];

        $days = max(1, min(365, (int) ($args['days'] ?? 30)));
        $coverageDays = max(1, min(365, (int) ($args['coverage_days'] ?? 14)));
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        $since = Carbon::now()->subDays($days);

        // Termasuk bahan baku/kemasan yang terpakai lewat resep, bukan hanya penjualan langsung.
        $soldPerProduct = AiStockVelocity::usage($storeIds, $since);

        $products = AiStockVelocity::trackedProducts($storeIds)
            ->with(['productCategory', 'store'])
            ->get();

        $productIds = $products->pluck('id');

        $suppliers = PurchaseLine::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchase', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->with('purchase.supplier')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines) => $lines->sortBy('id')->last());

        $items = $products->map(function (Product $product) use ($soldPerProduct, $days, $coverageDays, $suppliers, $allBranches) {
            $sold = (float) ($soldPerProduct[$product->id] ?? 0);
            $avgPerDay = $sold / AiStockVelocity::activeDays($product, $days);
            $stock = (float) $product->stock_quantity;
            $minStock = (float) $product->min_stock_level;

            $daysLeft = $avgPerDay > 0 ? (int) floor($stock / $avgPerDay) : null;
            $belowMin = $stock <= $minStock;

            if (! $belowMin && ($daysLeft === null || $daysLeft > $coverageDays)) {
                return null;
            }

            $suggested = (int) ceil(max($avgPerDay * $coverageDays - $stock, $belowMin ? $minStock - $stock : 0));

            if ($suggested <= 0) {
                return null;
            }

            $unitCost = (float) ($product->average_cost ?: $product->cost_price);
            $lastLine = $suppliers[$product->id] ?? null;

            $item = [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->productCategory?->name ?? $product->category ?? '-',
                'stock' => round($stock, 2),
                'min_stock_level' => round($minStock, 2),
                'unit' => $product->unit ?? 'pcs',
                'avg_sales_per_day' => round($avgPerDay, 2),
                'days_left' => $daysLeft,
                'suggested_quantity' => $suggested,
                'estimated_cost' => round($suggested * $unitCost),
                'supplier' => $lastLine?->purchase?->supplier?->name,
                'reason' => $belowMin
                    ? 'stok di bawah minimum'
                    : "diprediksi habis dalam {$daysLeft} hari",
                // Di bawah minimum tetapi tidak bergerak: jangan dibeli membabi buta.
                'no_recent_sales' => $sold <= 0,
                'cost_known' => $unitCost > 0,
            ];

            if ($allBranches) {
                $item['store_name'] = $product->store?->name;
            }

            return $item;
        })->filter()
            ->sortBy([
                fn (array $a, array $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX),
            ])
            ->take($limit)
            ->values();

        if ($items->isEmpty()) {
            return ToolResult::success(
                data: ['items' => [], 'total_estimated_cost' => 0, 'period_days' => $days, 'coverage_days' => $coverageDays],
                message: 'Semua stok masih aman — tidak ada yang perlu direstock saat ini.'
            );
        }

        $totalCost = (float) $items->sum('estimated_cost');

        return ToolResult::success(
            data: [
                'items' => $items,
                'total' => $items->count(),
                'total_estimated_cost' => round($totalCost),
                'period_days' => $days,
                'coverage_days' => $coverageDays,
            ],
            message: $this->summaryMessage($items->toArray(), $totalCost, $coverageDays, $allBranches, count($storeIds))
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function summaryMessage(array $items, float $totalCost, int $coverageDays, bool $allBranches, int $storeCount): string
    {
        $lines = [
            'Daftar belanja untuk stok cukup **'.$coverageDays.' hari ke depan**'
                .($allBranches ? " (semua cabang — {$storeCount} outlet)" : '').'. '
                .count($items).' produk perlu direstock:',
            '',
        ];

        foreach (array_slice($items, 0, 8) as $item) {
            $branch = isset($item['store_name']) ? " [{$item['store_name']}]" : '';
            $supplier = $item['supplier'] ? ' (supplier: '.$item['supplier'].')' : '';

            $lines[] = '- **'.$item['name'].'**'.$branch
                .' — beli **'.$item['suggested_quantity'].' '.$item['unit'].'**'
                .' (sisa stok '.$item['stock'].', '.$item['reason']
                .($item['avg_sales_per_day'] > 0 ? ', laku ±'.AiMetrics::number($item['avg_sales_per_day'], 2).'/hari' : '').')'
                .($item['cost_known'] ? ', estimasi '.$this->formatCurrency((float) $item['estimated_cost']) : ', harga modal belum tercatat')
                .$supplier
                .($item['no_recent_sales'] ? ' — tidak ada penjualan/pemakaian pada periode analisis, pastikan masih perlu distok' : '');
        }

        $unknownCost = count(array_filter($items, fn (array $item) => ! $item['cost_known']));

        if (count($items) > 8) {
            $lines[] = '- …dan '.(count($items) - 8).' produk lainnya.';
        }

        $lines[] = '';
        $lines[] = '**Estimasi total modal: '.$this->formatCurrency($totalCost).'**'
            .($unknownCost > 0 ? " (belum termasuk {$unknownCost} produk tanpa harga modal)" : '');
        $lines[] = 'Buatkan pesanan pembelian langsung dengan perintah "beli [produk] [jumlah] dari supplier [nama]".';

        return implode("\n", $lines);
    }
}
