<?php

namespace App\Tools\Reports;

use App\Models\OperationalExpense;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class ProfitReportTool extends AiTool
{
    public function getName(): string
    {
        return 'get_profit_report';
    }

    public function getDescription(): string
    {
        return 'Membuat laporan laba rugi: pendapatan bersih, HPP, laba kotor (margin), beban operasional (sewa, gaji, listrik, dll), dan laba bersih untuk periode tertentu, lengkap dengan catatan bila harga modal/beban belum tercatat. Gunakan saat user bertanya "berapa laba/untung/rugi", "laba bersih", "laba rugi", "beban operasional", atau "laporan keuangan".';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode laporan (default: this_month). Periode berjalan dihitung sampai hari ini.',
                'enum' => AiPeriod::PERIODS,
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

        $range = AiPeriod::resolve($args, $store, 'this_month');
        $summary = AiMetrics::summary($storeIds, $range['from'], $range['to']);

        $expenseRows = OperationalExpense::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('expense_date', [$range['local_from']->toDateString(), $range['local_to']->toDateString()])
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

        $revenue = (float) $summary['revenue'];
        $grossProfit = (float) $summary['gross_profit'];
        $netProfit = $grossProfit - $totalExpense;

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'period' => [
                'label' => $range['label'],
                'from' => $range['display_from'],
                'to' => $range['display_to'],
            ],
            'revenue' => round($revenue),
            'returns' => $summary['returns'],
            'transactions' => $summary['transactions'],
            'hpp' => $summary['hpp'],
            'gross_profit' => round($grossProfit),
            'gross_margin_pct' => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0,
            'cost_coverage_pct' => $summary['cost_coverage_pct'],
            'covered_margin_pct' => $summary['covered_margin_pct'],
            'margin_reliable' => $summary['margin_reliable'],
            'operational_expenses' => round($totalExpense),
            'expense_breakdown' => $expenseBreakdown,
            'net_profit' => round($netProfit),
            'net_margin_pct' => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0,
            'data_notes' => array_values(array_filter([
                AiMetrics::coverageNote($summary),
                $revenue > 0 && $totalExpense <= 0
                    ? 'Belum ada beban operasional yang dicatat pada periode ini, jadi laba bersih di sini sama dengan laba kotor. Catat sewa, gaji, listrik, dll agar laba bersih akurat.'
                    : null,
            ])),
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data, $allBranches, count($storeIds))
        );
    }

    protected function summaryMessage(array $data, bool $allBranches, int $storeCount): string
    {
        $lines = [
            'Laporan laba rugi **'.$data['period']['from'].' - '.$data['period']['to'].'**'
                .($allBranches ? " (semua cabang — {$storeCount} outlet)" : '').':',
            '',
            '- Pendapatan bersih: '.$this->formatCurrency((float) $data['revenue'])
                .($data['returns'] > 0 ? ' (setelah retur '.$this->formatCurrency((float) $data['returns']).')' : ''),
            '- HPP: '.$this->formatCurrency((float) $data['hpp']),
            '- **Laba kotor: '.$this->formatCurrency((float) $data['gross_profit']).' (margin '.AiMetrics::number($data['gross_margin_pct']).'%)**',
            '- Beban operasional: '.$this->formatCurrency((float) $data['operational_expenses']),
        ];

        if ($data['expense_breakdown'] !== []) {
            $parts = array_map(
                fn (array $row) => $row['label'].' '.$this->formatCurrency((float) $row['total']),
                $data['expense_breakdown']
            );
            $lines[] = '  Rincian: '.implode(', ', $parts);
        }

        $lines[] = '- **Laba bersih: '.$this->formatCurrency((float) $data['net_profit']).' (margin '.AiMetrics::number($data['net_margin_pct']).'%)**';

        if ($data['revenue'] <= 0) {
            $lines[] = '';
            $lines[] = 'Belum ada penjualan selesai pada periode ini.';
        }

        foreach ($data['data_notes'] as $note) {
            $lines[] = '';
            $lines[] = $note;
        }

        if ($data['net_profit'] < 0 && $data['revenue'] > 0) {
            $largest = $data['expense_breakdown'][0] ?? null;
            $lines[] = '';
            $lines[] = 'Perhatian: periode ini **rugi bersih** '.$this->formatCurrency(abs((float) $data['net_profit'])).'.'
                .($largest
                    ? ' Beban terbesar: '.$largest['label'].' '.$this->formatCurrency((float) $largest['total'])
                        .' ('.AiMetrics::number($largest['total'] / $data['revenue'] * 100).'% dari pendapatan).'
                    : '')
                .($data['gross_profit'] > 0
                    ? ' Untuk impas, laba kotor perlu naik '.$this->formatCurrency(abs((float) $data['net_profit'])).' atau beban turun sebesar itu.'
                    : ' Laba kotor sudah negatif — periksa harga jual dan harga modal produk.');
        }

        return implode("\n", $lines);
    }
}
