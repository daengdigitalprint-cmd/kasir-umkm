<?php

namespace App\Tools\Reports;

use App\Models\OperationalExpense;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class ProfitReportTool extends AiTool
{
    public function getName(): string
    {
        return 'get_profit_report';
    }

    public function getDescription(): string
    {
        return 'Membuat laporan laba rugi: pendapatan, HPP, laba kotor (margin), beban operasional (sewa, gaji, listrik, dll), dan laba bersih untuk periode tertentu. Gunakan saat user bertanya "berapa laba/untung/rugi", "laba bersih", "laba rugi", "beban operasional", atau "laporan keuangan".';
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

        $dateRange = $this->getDateRange($args);
        $from = $dateRange['from'];
        $to = $dateRange['to'];

        $sales = Transaction::whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as transactions')
            ->first();

        $revenue = (float) $sales->revenue;
        $transactions = (int) $sales->transactions;

        $cogs = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(transaction_items.total_price), 0) as line_revenue,
                COALESCE(SUM(transaction_items.total_cost_snapshot), 0) as hpp,
                COALESCE(SUM(transaction_items.gross_profit_snapshot), 0) as gross_profit
            ')
            ->first();

        $hpp = (float) $cogs->hpp;
        $grossProfit = (float) $cogs->gross_profit;

        // Fallback: jika salah satu snapshot kosong, hitung dari yang tersedia.
        if ($grossProfit === 0.0 && $hpp > 0) {
            $grossProfit = (float) $cogs->line_revenue - $hpp;
        } elseif ($hpp === 0.0 && $grossProfit > 0) {
            $hpp = (float) $cogs->line_revenue - $grossProfit;
        }

        $expenseRows = OperationalExpense::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->get();

        $totalExpense = (float) $expenseRows->sum('total');
        $expenseBreakdown = $expenseRows
            ->map(fn ($row) => [
                'category' => $row->category,
                'label' => OperationalExpense::CATEGORIES[$row->category] ?? ucfirst((string) $row->category),
                'total' => round((float) $row->total),
            ])
            ->sortByDesc('total')
            ->values()
            ->toArray();

        $netProfit = $grossProfit - $totalExpense;

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'period' => [
                'from' => $from->format('d/m/Y'),
                'to' => $to->format('d/m/Y'),
            ],
            'revenue' => round($revenue),
            'hpp' => round($hpp),
            'gross_profit' => round($grossProfit),
            'gross_margin_pct' => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0,
            'operational_expenses' => round($totalExpense),
            'expense_breakdown' => $expenseBreakdown,
            'net_profit' => round($netProfit),
            'net_margin_pct' => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0,
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data, $allBranches, count($storeIds))
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

    protected function summaryMessage(array $data, bool $allBranches, int $storeCount): string
    {
        $lines = [
            'Laporan laba rugi **'.$data['period']['from'].' - '.$data['period']['to'].'**'
                .($allBranches ? " (semua cabang — {$storeCount} outlet)" : '').':',
            '',
            '- Pendapatan: '.$this->formatCurrency((float) $data['revenue']),
            '- HPP: '.$this->formatCurrency((float) $data['hpp']),
            '- **Laba kotor: '.$this->formatCurrency((float) $data['gross_profit'])." (margin {$data['gross_margin_pct']}%)**",
            '- Beban operasional: '.$this->formatCurrency((float) $data['operational_expenses']),
        ];

        if ($data['expense_breakdown'] !== []) {
            $parts = array_map(
                fn (array $row) => $row['label'].' '.$this->formatCurrency((float) $row['total']),
                $data['expense_breakdown']
            );
            $lines[] = '  Rincian: '.implode(', ', $parts);
        }

        $lines[] = '- **Laba bersih: '.$this->formatCurrency((float) $data['net_profit'])." (margin {$data['net_margin_pct']}%)**";

        if ($data['net_profit'] < 0) {
            $lines[] = '';
            $lines[] = 'Perhatian: periode ini **rugi bersih** — pertimbangkan menekan beban operasional atau menaikkan penjualan produk margin tinggi.';
        }

        return implode("\n", $lines);
    }
}
