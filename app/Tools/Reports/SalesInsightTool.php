<?php

namespace App\Tools\Reports;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class SalesInsightTool extends AiTool
{
    public function getName(): string
    {
        return 'get_sales_insight';
    }

    public function getDescription(): string
    {
        return 'Menganalisis performa penjualan: membandingkan periode berjalan dengan periode sebelumnya (naik/turun berapa persen), produk mana yang paling mendorong perubahan, dan margin kotor per produk. Gunakan saat user bertanya "kenapa penjualan naik/turun", "analisa toko saya", "performa toko bagaimana", atau analisis bisnis lainnya.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode analisis: "today", "this_week", "this_month", "this_year" (default: this_week)',
                'enum' => ['today', 'this_week', 'this_month', 'this_year'],
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

        $range = $this->getCurrentRange($args['period'] ?? 'this_week');
        $previousRange = $this->getPreviousRange($range['from'], $range['to']);

        $current = $this->summarize($storeIds, $range['from'], $range['to']);
        $previous = $this->summarize($storeIds, $previousRange['from'], $previousRange['to']);

        $revenueChangePct = $this->percentChange($previous['revenue'], $current['revenue']);
        $transactionsChangePct = $this->percentChange($previous['transactions'], $current['transactions']);

        $direction = 'stabil';
        if ($revenueChangePct !== null && $revenueChangePct >= 5) {
            $direction = 'naik';
        } elseif ($revenueChangePct !== null && $revenueChangePct <= -5) {
            $direction = 'turun';
        }

        $trends = $this->productTrends($storeIds, $range, $previousRange);
        $recommendations = $this->recommendations($direction, $current, $trends);

        // Ringkasan dihitung dari data lengkap (termasuk margin) sebelum
        // margin dihapus dari `current`, agar angka margin tidak hilang.
        $message = $this->summaryMessage($direction, $revenueChangePct, $current, $previous, $trends, $recommendations);

        if ($allBranches) {
            $message = 'Analisa **semua cabang** ('.count($storeIds)." outlet):\n\n".$message;
        }

        $margin = $current['margin'];

        unset($current['margin']);

        $insight = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'period' => [
                'from' => $range['from']->format('d/m/Y'),
                'to' => $range['to']->format('d/m/Y'),
                'previous_from' => $previousRange['from']->format('d/m/Y'),
                'previous_to' => $previousRange['to']->format('d/m/Y'),
            ],
            'current' => $current,
            'previous' => $previous,
            'revenue_change_pct' => $revenueChangePct,
            'transactions_change_pct' => $transactionsChangePct,
            'direction' => $direction,
            'biggest_increases' => $trends['increases'],
            'biggest_decreases' => $trends['decreases'],
            'margin' => $margin,
            'recommendations' => $recommendations,
        ];

        return ToolResult::success(
            data: $insight,
            message: $message
        );
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    protected function summarize(array $storeIds, Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::with('items')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $revenue = (float) $transactions->sum('total_amount');
        $transactionCount = $transactions->count();
        $itemsSold = $transactions->flatMap(fn ($t) => $t->items ?? [])->sum('quantity');

        $items = $transactions->flatMap(fn ($t) => $t->items ?? []);

        $marginByProduct = $items->groupBy('product_id')->map(function ($group) {
            $grossProfit = $group->sum(fn ($item) => (float) ($item->gross_profit_snapshot ?? 0));
            $lineRevenue = (float) $group->sum('total_price');

            return [
                'product_name' => $group->first()->product_name ?? 'Unknown',
                'revenue' => round($lineRevenue),
                'gross_profit' => round($grossProfit),
                'margin_pct' => $lineRevenue > 0 ? round($grossProfit / $lineRevenue * 100, 1) : 0,
            ];
        })->sortByDesc('gross_profit')->take(5)->values();

        $totalGrossProfit = (float) $items->sum(fn ($item) => (float) ($item->gross_profit_snapshot ?? 0));

        return [
            'revenue' => round($revenue),
            'transactions' => $transactionCount,
            'items_sold' => (float) $itemsSold,
            'average_transaction' => $transactionCount > 0 ? round($revenue / $transactionCount) : 0,
            'margin' => [
                'total_gross_profit' => round($totalGrossProfit),
                'gross_margin_pct' => $revenue > 0 ? round($totalGrossProfit / $revenue * 100, 1) : 0,
                'top_products' => $marginByProduct,
            ],
        ];
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    protected function productTrends(array $storeIds, array $range, array $previousRange): array
    {
        $current = $this->salesByProduct($storeIds, $range['from'], $range['to']);
        $previous = $this->salesByProduct($storeIds, $previousRange['from'], $previousRange['to']);

        $currentQty = $current['quantities'];
        $previousQty = $previous['quantities'];
        $names = $current['names'] + $previous['names'];

        $revenueByProduct = Transaction::with('items')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$range['from'], $range['to']])
            ->get()
            ->flatMap(fn ($t) => $t->items ?? [])
            ->groupBy('product_id')
            ->map(fn ($group) => round((float) $group->sum('total_price')));

        $productIds = $currentQty->keys()->merge($previousQty->keys())->unique();

        $changes = $productIds->map(function ($productId) use ($currentQty, $previousQty, $revenueByProduct, $names) {
            $now = (float) ($currentQty[$productId] ?? 0);
            $before = (float) ($previousQty[$productId] ?? 0);
            $delta = $now - $before;

            return [
                'product_name' => $names[$productId] ?? 'Unknown',
                'qty_previous' => $before,
                'qty_current' => $now,
                'qty_change' => $delta,
                'change_pct' => $before > 0
                    ? round($delta / $before * 100, 1)
                    : ($now > 0 ? null : 0),
                'is_new' => $before == 0 && $now > 0,
                'revenue_current' => $revenueByProduct[$productId] ?? 0,
            ];
        })->filter(fn ($row) => $row['qty_change'] != 0);

        return [
            'increases' => $changes->filter(fn ($row) => $row['qty_change'] > 0)->sortByDesc('qty_change')->take(3)->values()->toArray(),
            'decreases' => $changes->filter(fn ($row) => $row['qty_change'] < 0)->sortBy('qty_change')->take(3)->values()->toArray(),
        ];
    }

    /**
     * @param  array<int, int>  $storeIds
     */
    protected function salesByProduct(array $storeIds, Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::with('items')
            ->whereIn('store_id', $storeIds)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $quantities = [];
        $names = [];

        foreach ($transactions->flatMap(fn ($t) => $t->items ?? []) as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $quantities[$item->product_id] = ($quantities[$item->product_id] ?? 0) + (float) $item->quantity;
            $names[$item->product_id] = $item->product_name ?? 'Unknown';
        }

        return [
            'quantities' => collect($quantities),
            'names' => $names,
        ];
    }

    protected function getCurrentRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => ['from' => $now->copy()->startOfDay(), 'to' => $now->copy()->endOfDay()],
            'this_month' => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
            'this_year' => ['from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear()],
            default => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
        };
    }

    protected function getPreviousRange(Carbon $from, Carbon $to): array
    {
        $durationSeconds = $to->getTimestamp() - $from->getTimestamp();
        $previousTo = Carbon::createFromTimestamp($from->getTimestamp() - 1);
        $previousFrom = Carbon::createFromTimestamp($previousTo->getTimestamp() - $durationSeconds);

        return ['from' => $previousFrom, 'to' => $previousTo];
    }

    protected function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? null : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Menyusun saran aksi konkret dari data insight secara deterministik,
     * tanpa memanggil provider AI.
     *
     * @return array<int, string>
     */
    protected function recommendations(string $direction, array $current, array $trends): array
    {
        $tips = [];

        $decreasing = collect($trends['decreases'])->pluck('product_name')->take(2)->implode(', ');
        $increasing = collect($trends['increases'])->pluck('product_name')->take(2)->implode(', ');
        $marginPct = $current['margin']['gross_margin_pct'] ?? 0;

        if ($current['revenue'] <= 0) {
            $tips[] = 'Belum ada penjualan di periode ini — jalankan promo sederhana (bundling/diskon) atau tawarkan langsung ke pelanggan lama untuk memicu transaksi pertama.';
        } elseif ($direction === 'turun') {
            $tips[] = $decreasing !== ''
                ? "Penurunan terpusat di produk {$decreasing} — beri promo atau paket hemat di produk tersebut, dan pastikan stok serta tampilannya di kasir selalu baik."
                : 'Penjualan turun — coba promo mingguan atau paket hemat untuk menarik pembeli kembali.';
        } elseif ($direction === 'naik') {
            $tips[] = $increasing !== ''
                ? "Pertahankan pola yang berhasil: jaga stok produk {$increasing} agar tidak kosong, dan terapkan cara jual yang sama ke produk lain."
                : 'Pertahankan pola penjualan yang berhasil dan pastikan stok selalu tersedia.';
        } else {
            $tips[] = 'Penjualan stabil — coba promo mingguan atau bundling untuk mendorong kenaikan transaksi.';
        }

        if ($current['revenue'] > 0 && $marginPct > 0 && $marginPct < 20) {
            $tips[] = "Margin kotor tipis ({$marginPct}%) — tinjau harga jual atau cari supplier lebih hemat agar laba per transaksi naik.";
        }

        return $tips;
    }

    protected function summaryMessage(string $direction, ?float $revenueChangePct, array $current, array $previous, array $trends, array $recommendations = []): string
    {
        $revenueNow = $this->formatCurrency((float) $current['revenue']);
        $revenueBefore = $this->formatCurrency((float) $previous['revenue']);

        $changeLabel = $revenueChangePct === null
            ? 'dari nol (periode sebelumnya tidak ada penjualan)'
            : (($revenueChangePct >= 0 ? 'naik ' : 'turun ').abs($revenueChangePct).'%');

        $message = "Penjualan ({$changeLabel}): {$revenueNow} vs periode sebelumnya {$revenueBefore} "
            ."dari {$current['transactions']} transaksi (sebelumnya {$previous['transactions']}).";

        $topIncrease = $trends['increases'][0] ?? null;
        if ($topIncrease) {
            $message .= $topIncrease['is_new']
                ? " Produk baru yang mulai terjual: {$topIncrease['product_name']}."
                : " Pendorong utama: {$topIncrease['product_name']} (+{$topIncrease['qty_change']} unit).";
        }

        $topDecrease = $trends['decreases'][0] ?? null;
        if ($topDecrease) {
            $message .= " Penurunan terbesar: {$topDecrease['product_name']} ({$topDecrease['qty_change']} unit).";
        }

        $marginPct = $current['margin']['gross_margin_pct'] ?? 0;
        $message .= " Margin kotor periode ini: {$marginPct}%.";

        if ($recommendations !== []) {
            $message .= "\n\n**Rekomendasi:**\n".implode("\n", array_map(fn (string $tip) => "- {$tip}", $recommendations));
        }

        return $message;
    }
}
