<?php

namespace App\Tools\Reports;

use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class TopProductsTool extends AiTool
{
    public function getName(): string
    {
        return 'get_top_products';
    }

    public function getDescription(): string
    {
        return 'Daftar produk terjual pada suatu periode beserta jumlah, pendapatan, dan margin (bila harga modal tercatat). Urutkan menurut jumlah, pendapatan, atau laba; `order` "bottom" untuk produk paling tidak laku di antara yang terjual. Gunakan untuk "produk terlaris", "produk apa saja yang terjual", "produk paling menguntungkan".';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode (default: this_month). Periode berjalan dihitung sampai hari ini.',
                'enum' => AiPeriod::PERIODS,
            ],
            'date_from' => [
                'type' => 'string',
                'description' => 'Tanggal mulai (Y-m-d), dipakai bersama date_to untuk rentang khusus',
            ],
            'date_to' => [
                'type' => 'string',
                'description' => 'Tanggal akhir (Y-m-d)',
            ],
            'sort_by' => [
                'type' => 'string',
                'description' => 'Urutkan berdasarkan: "quantity" (jumlah terjual, default), "revenue" (pendapatan), atau "profit" (laba kotor)',
                'enum' => ['quantity', 'revenue', 'profit'],
            ],
            'order' => [
                'type' => 'string',
                'description' => '"top" = tertinggi dulu (default), "bottom" = terendah dulu',
                'enum' => ['top', 'bottom'],
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah produk yang ditampilkan (default: 10, maksimal 50)',
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

        $sortBy = in_array($args['sort_by'] ?? null, ['quantity', 'revenue', 'profit'], true) ? $args['sort_by'] : 'quantity';
        $bottom = ($args['order'] ?? 'top') === 'bottom';
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));

        $scope = AiStoreScope::resolve($user, $args['scope'] ?? AiStoreScope::CURRENT);
        $storeIds = $scope['store_ids'];
        $allBranches = $scope['all_branches'];

        $range = AiPeriod::resolve($args, $store, 'this_month');

        $stats = AiMetrics::byProduct($storeIds, $range['from'], $range['to']);

        $sortKey = match ($sortBy) {
            'revenue' => 'revenue',
            'profit' => 'gross_profit',
            default => 'quantity',
        };

        // Urutan laba hanya adil untuk produk yang harga modalnya tercatat.
        $pool = $sortBy === 'profit' ? $stats->filter(fn (array $row) => $row['has_cost']) : $stats;
        $sorted = $bottom ? $pool->sortBy($sortKey) : $pool->sortByDesc($sortKey);

        $topProducts = $sorted->take($limit)->values()->map(function (array $row) {
            $quantity = (float) $row['quantity'];
            $averagePrice = $quantity > 0 ? $row['revenue'] / $quantity : 0;

            return [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'quantity_sold' => $row['quantity'],
                'revenue' => $row['revenue'],
                'average_price' => round($averagePrice),
                'gross_profit' => $row['gross_profit'],
                'margin_pct' => $row['margin_pct'],
                'revenue_formatted' => $this->formatCurrency($row['revenue']),
                'average_price_formatted' => $this->formatCurrency($averagePrice),
            ];
        })->toArray();

        $sortLabel = match ($sortBy) {
            'revenue' => 'pendapatan',
            'profit' => 'laba kotor',
            default => 'jumlah terjual',
        };

        $period = $range['display_from'] === $range['display_to']
            ? $range['display_from']
            : $range['display_from'].' - '.$range['display_to'];

        $message = count($topProducts) > 0
            ? (($bottom ? 'Produk paling sedikit terjual' : 'Top '.count($topProducts).' produk terlaris')
                ." {$period} (berdasarkan {$sortLabel})"
                .($allBranches ? ' — semua cabang ('.count($storeIds).' outlet)' : '')
                .($stats->count() > count($topProducts) ? ', dari '.$stats->count().' produk yang terjual' : ''))
            : "Tidak ada data penjualan pada periode {$period}";

        return ToolResult::success(
            data: [
                'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
                'stores_analyzed' => count($storeIds),
                'period' => [
                    'label' => $range['label'],
                    'from' => $range['display_from'],
                    'to' => $range['display_to'],
                ],
                'sort_by' => $sortBy,
                'order' => $bottom ? 'bottom' : 'top',
                'products' => $topProducts,
                'total_products_sold' => $stats->count(),
            ],
            message: $message
        );
    }
}
