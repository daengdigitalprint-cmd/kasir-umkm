<?php

namespace App\Tools\Reports;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class TopProductsTool extends AiTool
{
    public function getName(): string
    {
        return 'get_top_products';
    }

    public function getDescription(): string
    {
        return 'Mendapatkan daftar produk terlaris berdasarkan jumlah penjualan atau pendapatan. Gunakan tool ini saat user ingin melihat produk paling laku.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode: "today", "this_week", "this_month", "last_month"',
                'enum' => ['today', 'this_week', 'this_month', 'last_month'],
            ],
            'sort_by' => [
                'type' => 'string',
                'description' => 'Urutkan berdasarkan: "quantity" (jumlah terjual) atau "revenue" (pendapatan)',
                'enum' => ['quantity', 'revenue'],
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah produk yang ditampilkan (default: 10)',
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

        $sortBy = $args['sort_by'] ?? 'quantity';
        $limit = $args['limit'] ?? 10;

        $scope = AiStoreScope::resolve($user, $args['scope'] ?? AiStoreScope::CURRENT);
        $storeIds = $scope['store_ids'];
        $allBranches = $scope['all_branches'];

        $dateRange = $this->getDateRange($args);

        $query = Transaction::whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']]);

        $transactions = $query->get();

        // Hitung produk terlaris
        $productStats = $transactions->flatMap(fn ($t) => $t->items ?? [])
            ->groupBy('product_id')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'product_id' => $first->product_id,
                    'product_name' => $first->product_name ?? 'Unknown',
                    'quantity_sold' => $group->sum('quantity'),
                    'revenue' => $group->sum('total_price'),
                    'average_price' => $group->avg('unit_price'),
                ];
            });

        // Sort
        if ($sortBy === 'revenue') {
            $productStats = $productStats->sortByDesc('revenue');
        } else {
            $productStats = $productStats->sortByDesc('quantity_sold');
        }

        $topProducts = $productStats->take($limit)->values()->toArray();

        // Format currency
        foreach ($topProducts as &$product) {
            $product['revenue_formatted'] = $this->formatCurrency($product['revenue']);
            $product['average_price_formatted'] = $this->formatCurrency($product['average_price']);
        }

        $sortLabel = $sortBy === 'revenue' ? 'pendapatan' : 'jumlah terjual';

        return ToolResult::success(
            data: [
                'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
                'stores_analyzed' => count($storeIds),
                'period' => [
                    'from' => $dateRange['from']->format('d/m/Y'),
                    'to' => $dateRange['to']->format('d/m/Y'),
                ],
                'sort_by' => $sortBy,
                'products' => $topProducts,
                'total_products_sold' => $productStats->count(),
            ],
            message: count($topProducts) > 0
                ? ('Top '.count($topProducts)." produk terlaris (berdasarkan {$sortLabel})"
                    .($allBranches ? ' — semua cabang ('.count($storeIds).' outlet)' : ''))
                : 'Tidak ada data penjualan pada periode ini'
        );
    }

    protected function getDateRange(array $args): array
    {
        $now = Carbon::now();

        if (! empty($args['date_from']) && ! empty($args['date_to'])) {
            return [
                'from' => Carbon::parse($args['date_from'])->startOfDay(),
                'to' => Carbon::parse($args['date_to'])->endOfDay(),
            ];
        }

        $period = $args['period'] ?? 'this_month';

        return match ($period) {
            'today' => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
            'yesterday' => ['from' => $now->copy()->subDay()->startOfDay(), 'to' => $now->copy()->subDay()->endOfDay()],
            'this_week' => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
            'last_week' => ['from' => $now->copy()->subWeek()->startOfWeek(), 'to' => $now->copy()->subWeek()->endOfWeek()],
            'this_month' => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
            'last_month' => ['from' => $now->copy()->subMonth()->startOfMonth(), 'to' => $now->copy()->subMonth()->endOfMonth()],
            default => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
        };
    }
}
