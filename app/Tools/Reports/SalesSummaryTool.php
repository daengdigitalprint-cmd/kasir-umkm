<?php

namespace App\Tools\Reports;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class SalesSummaryTool extends AiTool
{
    public function getName(): string
    {
        return 'get_sales_summary';
    }

    public function getDescription(): string
    {
        return 'Mendapatkan ringkasan penjualan untuk periode tertentu. Gunakan tool ini saat user ingin melihat laporan penjualan.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode laporan: "today", "yesterday", "this_week", "last_week", "this_month", "last_month", "this_year"',
                'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year'],
            ],
            'date_from' => [
                'type' => 'string',
                'description' => 'Tanggal mulai (format: Y-m-d), opsional jika period diisi',
            ],
            'date_to' => [
                'type' => 'string',
                'description' => 'Tanggal akhir (format: Y-m-d), opsional jika period diisi',
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

        // Tentukan rentang tanggal
        $dateRange = $this->getDateRange($args);
        $dateFrom = $dateRange['from'];
        $dateTo = $dateRange['to'];

        $query = Transaction::whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $transactions = $query->get();

        $totalSales = $transactions->sum('total_amount');
        $totalTransactions = $transactions->count();
        $averageTransaction = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        // Metode pembayaran
        $paymentMethods = $transactions->groupBy('payment_method')
            ->map(fn ($group) => [
                'method' => $group->first()->payment_method,
                'count' => $group->count(),
                'total' => $group->sum('total'),
            ])
            ->values()
            ->toArray();

        // Produk terlaris
        $topProducts = $transactions->flatMap(fn ($t) => $t->items ?? [])
            ->groupBy('product_id')
            ->map(fn ($group) => [
                'product_name' => $group->first()->product_name ?? 'Unknown',
                'quantity' => $group->sum('quantity'),
                'revenue' => $group->sum('total_price'),
            ])
            ->sortByDesc('quantity')
            ->take(5)
            ->values()
            ->toArray();

        return ToolResult::success(
            data: [
                'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
                'stores_analyzed' => count($storeIds),
                'period' => [
                    'from' => $dateFrom->format('d/m/Y'),
                    'to' => $dateTo->format('d/m/Y'),
                ],
                'total_sales' => $this->formatCurrency($totalSales),
                'total_transactions' => $totalTransactions,
                'average_transaction' => $this->formatCurrency($averageTransaction),
                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts,
            ],
            message: ($allBranches ? 'Ringkasan penjualan semua cabang ('.count($storeIds).' outlet) ' : 'Ringkasan penjualan ')
                     .$dateFrom->format('d/m/Y').' - '.$dateTo->format('d/m/Y').': '.
                     "Total penjualan {$this->formatCurrency($totalSales)} dari {$totalTransactions} transaksi"
        );
    }

    protected function getDateRange(array $args): array
    {
        if (! empty($args['date_from']) && ! empty($args['date_to'])) {
            return [
                'from' => Carbon::parse($args['date_from'])->startOfDay(),
                'to' => Carbon::parse($args['date_to'])->endOfDay(),
            ];
        }

        $period = $args['period'] ?? 'today';
        $now = Carbon::now();

        return match ($period) {
            'today' => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
            'yesterday' => ['from' => $now->copy()->subDay()->startOfDay(), 'to' => $now->copy()->subDay()->endOfDay()],
            'this_week' => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
            'last_week' => ['from' => $now->copy()->subWeek()->startOfWeek(), 'to' => $now->copy()->subWeek()->endOfWeek()],
            'this_month' => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
            'last_month' => ['from' => $now->copy()->subMonth()->startOfMonth(), 'to' => $now->copy()->subMonth()->endOfMonth()],
            'this_year' => ['from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear()],
            default => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
        };
    }
}
