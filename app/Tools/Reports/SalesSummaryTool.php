<?php

namespace App\Tools\Reports;

use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\DB;

class SalesSummaryTool extends AiTool
{
    public function getName(): string
    {
        return 'get_sales_summary';
    }

    public function getDescription(): string
    {
        return 'Ringkasan penjualan (pendapatan bersih = subtotal − diskon − retur, sama dengan laporan) untuk suatu periode: total, jumlah transaksi, rata-rata, metode pembayaran, dan 5 produk terlaris. Gunakan untuk pertanyaan omzet/penjualan/laporan penjualan.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode laporan (default: today). Periode berjalan dihitung sampai hari ini.',
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

        $range = AiPeriod::resolve($args, $store, 'today');
        $summary = AiMetrics::summary($storeIds, $range['from'], $range['to']);

        $paymentMethods = DB::table('transactions')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->groupBy('payment_method')
            ->selectRaw('payment_method as method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method ?: 'lainnya',
                'count' => (int) $row->count,
                'total' => round((float) $row->total),
            ])
            ->toArray();

        $topProducts = AiMetrics::byProduct($storeIds, $range['from'], $range['to'])
            ->sortByDesc('quantity')
            ->take(5)
            ->map(fn (array $row) => [
                'product_name' => $row['product_name'],
                'quantity' => $row['quantity'],
                'revenue' => $row['revenue'],
            ])
            ->values()
            ->toArray();

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'period' => [
                'label' => $range['label'],
                'from' => $range['display_from'],
                'to' => $range['display_to'],
            ],
            'total_sales' => $this->formatCurrency($summary['revenue']),
            'total_sales_value' => $summary['revenue'],
            'collected_value' => $summary['collected'],
            'returns_value' => $summary['returns'],
            'total_transactions' => $summary['transactions'],
            'items_sold' => $summary['items_sold'],
            'average_transaction' => $this->formatCurrency($summary['average_transaction']),
            'payment_methods' => $paymentMethods,
            'top_products' => $topProducts,
        ];

        return ToolResult::success(data: $data, message: $this->summaryMessage($data, $summary, $allBranches, count($storeIds)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $summary
     */
    protected function summaryMessage(array $data, array $summary, bool $allBranches, int $storeCount): string
    {
        $period = $data['period']['from'] === $data['period']['to']
            ? $data['period']['from']
            : $data['period']['from'].' - '.$data['period']['to'];

        $head = ($allBranches ? "Ringkasan penjualan semua cabang ({$storeCount} outlet) " : 'Ringkasan penjualan ')
            .$period.': Total penjualan '.$data['total_sales']." dari {$summary['transactions']} transaksi";

        if ($summary['transactions'] === 0) {
            return $head.'. Belum ada transaksi selesai pada periode ini.';
        }

        $lines = [
            $head.'.',
            '',
            '- Rata-rata per transaksi: '.$data['average_transaction'],
            '- Item terjual: '.AiMetrics::number($summary['items_sold'], 2),
        ];

        if ($summary['returns'] > 0) {
            $lines[] = '- Retur: '.$this->formatCurrency($summary['returns']).' (sudah dikurangkan dari total penjualan)';
        }

        if (abs($summary['collected'] - $summary['gross_sales']) >= 1) {
            $lines[] = '- Uang diterima termasuk pajak/servis/tip: '.$this->formatCurrency($summary['collected']);
        }

        if ($data['payment_methods'] !== []) {
            $lines[] = '';
            $lines[] = '**Metode pembayaran:**';
            foreach ($data['payment_methods'] as $method) {
                $lines[] = '- '.ucfirst(str_replace('_', ' ', (string) $method['method'])).": {$method['count']} transaksi, ".$this->formatCurrency($method['total']);
            }
        }

        if ($data['top_products'] !== []) {
            $lines[] = '';
            $lines[] = '**Produk terlaris periode ini:**';
            foreach ($data['top_products'] as $index => $product) {
                $lines[] = ($index + 1).'. '.$product['product_name'].' — '.AiMetrics::number($product['quantity'], 2).' terjual ('.$this->formatCurrency($product['revenue']).')';
            }
        }

        return implode("\n", $lines);
    }
}
