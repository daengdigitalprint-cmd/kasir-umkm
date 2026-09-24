<?php

namespace App\Tools\Reports;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class BranchComparisonTool extends AiTool
{
    public function getName(): string
    {
        return 'compare_branches';
    }

    public function getDescription(): string
    {
        return 'Membandingkan performa antar cabang/outlet dalam satu grup usaha: pendapatan, jumlah transaksi, laba kotor, ranking, dan cabang terbaik/terburuk. Gunakan saat user bertanya "bandingkan cabang", "cabang mana yang paling laris/produktif", "performa antar outlet", atau "ranking cabang". Hanya untuk usaha multi-outlet.';
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
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        if (! $user->canAccessBackOffice()) {
            return ToolResult::error('Perbandingan antar cabang hanya boleh dilihat oleh owner/admin/manager.');
        }

        if (! $store->organization_id) {
            return ToolResult::error('Usaha ini belum memiliki grup usaha (multi-cabang) — tidak ada cabang lain untuk dibandingkan.');
        }

        $scope = AiStoreScope::resolve($user, AiStoreScope::ALL);
        $storeIds = $scope['store_ids'];

        if (count($storeIds) < 2) {
            return ToolResult::error('Hanya ada satu outlet aktif — perbandingan cabang butuh minimal 2 outlet.');
        }

        $branches = Store::query()
            ->whereIn('id', $storeIds)
            ->get()
            ->keyBy('id');

        $dateRange = $this->getDateRange($args);
        $from = $dateRange['from'];
        $to = $dateRange['to'];

        $salesRows = Transaction::query()
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('store_id, COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as transactions')
            ->groupBy('store_id')
            ->get()
            ->keyBy('store_id');

        $profitRows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->groupBy('transactions.store_id')
            ->selectRaw('transactions.store_id as store_id, COALESCE(SUM(transaction_items.gross_profit_snapshot), 0) as gross_profit')
            ->get()
            ->keyBy('store_id');

        $rows = collect($storeIds)->map(function ($storeId) use ($branches, $salesRows, $profitRows) {
            $revenue = (float) ($salesRows[$storeId]->revenue ?? 0);
            $transactions = (int) ($salesRows[$storeId]->transactions ?? 0);

            return [
                'store_id' => $storeId,
                'store_name' => $branches[$storeId]->name ?? 'Outlet #'.$storeId,
                'revenue' => round($revenue),
                'transactions' => $transactions,
                'average_transaction' => $transactions > 0 ? round($revenue / $transactions) : 0,
                'gross_profit' => round((float) ($profitRows[$storeId]->gross_profit ?? 0)),
            ];
        })->sortByDesc('revenue')->values();

        $totalRevenue = (float) $rows->sum('revenue');

        $ranked = $rows->map(function (array $row, int $index) use ($totalRevenue) {
            $row['rank'] = $index + 1;
            $row['revenue_share_pct'] = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 1) : 0;

            return $row;
        })->values()->toArray();

        $data = [
            'period' => [
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
            ],
            'branches' => $ranked,
            'total_revenue' => round($totalRevenue),
            'best_branch' => $ranked[0]['store_name'] ?? null,
            'worst_branch' => $ranked[count($ranked) - 1]['store_name'] ?? null,
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data)
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

        $period = $args['period'] ?? 'this_month';
        $now = Carbon::now();

        return match ($period) {
            'today' => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
            'yesterday' => ['from' => $now->copy()->subDay()->startOfDay(), 'to' => $now->copy()->subDay()->endOfDay()],
            'this_week' => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
            'last_week' => ['from' => $now->copy()->subWeek()->startOfWeek(), 'to' => $now->copy()->subWeek()->endOfWeek()],
            'this_month' => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
            'last_month' => ['from' => $now->copy()->subMonth()->startOfMonth(), 'to' => $now->copy()->subMonth()->endOfMonth()],
            'this_year' => ['from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear()],
            default => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
        };
    }

    protected function summaryMessage(array $data): string
    {
        $lines = [
            'Perbandingan **'.$data['period']['from'].' - '.$data['period']['to'].'**:',
            '',
            '| # | Cabang | Pendapatan | Transaksi | Laba Kotor | Porsi |',
            '|---|--------|------------|-----------|------------|-------|',
        ];

        foreach ($data['branches'] as $row) {
            $lines[] = '| '.$row['rank']
                .' | '.$row['store_name']
                .' | '.$this->formatCurrency((float) $row['revenue'])
                .' | '.$row['transactions']
                .' | '.$this->formatCurrency((float) $row['gross_profit'])
                .' | '.$row['revenue_share_pct'].'% |';
        }

        $lines[] = '';
        $lines[] = '**Total semua cabang: '.$this->formatCurrency((float) $data['total_revenue']).'**';
        $lines[] = 'Cabang terbaik: **'.$data['best_branch'].'** — terendah: **'.$data['worst_branch'].'**';

        return implode("\n", $lines);
    }
}
