<?php

namespace App\Tools\Reports;

use App\Models\Product;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;

class SalesInsightTool extends AiTool
{
    public function getName(): string
    {
        return 'get_sales_insight';
    }

    public function getDescription(): string
    {
        return 'Menganalisis performa penjualan: membandingkan periode dengan periode sebelumnya yang SETARA (mis. 1–14 bulan ini vs 1–14 bulan lalu), produk mana yang paling mendorong perubahan (beserta sisa stoknya), jumlah transaksi vs nilai per transaksi, dan margin kotor. Gunakan saat user bertanya "kenapa penjualan naik/turun", "analisa toko saya", "performa toko bagaimana", atau analisis bisnis lainnya.';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode analisis (default: this_week). Periode berjalan dihitung sampai hari ini dan dibandingkan dengan rentang yang sama panjang sebelumnya.',
                'enum' => AiPeriod::PERIODS,
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

        $range = AiPeriod::resolve(['period' => $args['period'] ?? 'this_week'], $store, 'this_week');
        $previousRange = AiPeriod::previous($range);

        $currentSummary = AiMetrics::summary($storeIds, $range['from'], $range['to']);
        $previousSummary = AiMetrics::summary($storeIds, $previousRange['from'], $previousRange['to']);

        $currentProducts = AiMetrics::byProduct($storeIds, $range['from'], $range['to']);
        $previousProducts = AiMetrics::byProduct($storeIds, $previousRange['from'], $previousRange['to']);

        $revenueChangePct = $this->percentChange($previousSummary['revenue'], $currentSummary['revenue']);
        $transactionsChangePct = $this->percentChange($previousSummary['transactions'], $currentSummary['transactions']);
        $averageChangePct = $this->percentChange($previousSummary['average_transaction'], $currentSummary['average_transaction']);

        $direction = 'stabil';
        if ($revenueChangePct !== null && $revenueChangePct >= 5) {
            $direction = 'naik';
        } elseif ($revenueChangePct !== null && $revenueChangePct <= -5) {
            $direction = 'turun';
        }

        $trends = $this->productTrends($currentProducts, $previousProducts, $storeIds, $allBranches, $range['days']);

        $margin = [
            'total_gross_profit' => $currentSummary['gross_profit'],
            'gross_margin_pct' => $currentSummary['gross_margin_pct'] ?? 0,
            'cost_coverage_pct' => $currentSummary['cost_coverage_pct'],
            'covered_margin_pct' => $currentSummary['covered_margin_pct'],
            'reliable' => $currentSummary['margin_reliable'],
            'top_products' => $currentProducts
                ->filter(fn (array $row) => $row['has_cost'])
                ->sortByDesc('gross_profit')
                ->take(5)
                ->map(fn (array $row) => [
                    'product_name' => $row['product_name'],
                    'revenue' => $row['revenue'],
                    'gross_profit' => $row['gross_profit'],
                    'margin_pct' => $row['margin_pct'],
                ])
                ->values()
                ->toArray(),
        ];

        $current = $this->publicSummary($currentSummary);
        $previous = $this->publicSummary($previousSummary);

        $recommendations = $this->recommendations($direction, $currentSummary, $previousSummary, $trends, $transactionsChangePct, $averageChangePct);
        $coverageNote = AiMetrics::coverageNote($currentSummary);

        $message = $this->summaryMessage($direction, $revenueChangePct, $current, $previous, $trends, $margin, $recommendations, $range, $previousRange, $coverageNote);

        if ($allBranches) {
            $message = 'Analisa **semua cabang** ('.count($storeIds)." outlet):\n\n".$message;
        }

        return ToolResult::success(
            data: [
                'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
                'stores_analyzed' => count($storeIds),
                'period' => [
                    'label' => $range['label'],
                    'from' => $range['display_from'],
                    'to' => $range['display_to'],
                    'previous_from' => $previousRange['display_from'],
                    'previous_to' => $previousRange['display_to'],
                    'comparison' => $range['is_running']
                        ? 'periode berjalan dibandingkan dengan rentang yang sama panjang sebelumnya'
                        : 'periode penuh dibandingkan dengan periode penuh sebelumnya',
                ],
                'current' => $current,
                'previous' => $previous,
                'revenue_change_pct' => $revenueChangePct,
                'transactions_change_pct' => $transactionsChangePct,
                'average_transaction_change_pct' => $averageChangePct,
                'direction' => $direction,
                'biggest_increases' => $trends['increases'],
                'biggest_decreases' => $trends['decreases'],
                'margin' => $margin,
                'recommendations' => $recommendations,
                'data_notes' => array_values(array_filter([$coverageNote])),
            ],
            message: $message
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    protected function publicSummary(array $summary): array
    {
        return [
            'revenue' => $summary['revenue'],
            'transactions' => $summary['transactions'],
            'items_sold' => $summary['items_sold'],
            'average_transaction' => $summary['average_transaction'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $currentProducts
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $previousProducts
     * @param  array<int, int>  $storeIds
     * @return array{increases: array<int, array<string, mixed>>, decreases: array<int, array<string, mixed>>}
     */
    protected function productTrends($currentProducts, $previousProducts, array $storeIds, bool $allBranches, int $periodDays): array
    {
        $current = $currentProducts->keyBy('product_id');
        $previous = $previousProducts->keyBy('product_id');
        $productIds = $current->keys()->merge($previous->keys())->unique();

        $changes = $productIds->map(function ($productId) use ($current, $previous) {
            $now = (float) ($current[$productId]['quantity'] ?? 0);
            $before = (float) ($previous[$productId]['quantity'] ?? 0);
            $delta = $now - $before;

            return [
                'product_id' => (int) $productId,
                'product_name' => $current[$productId]['product_name'] ?? $previous[$productId]['product_name'] ?? 'Tanpa nama',
                'qty_previous' => $before,
                'qty_current' => $now,
                'qty_change' => $delta,
                'change_pct' => $before > 0
                    ? round($delta / $before * 100, 1)
                    : ($now > 0 ? null : 0),
                'is_new' => $before == 0 && $now > 0,
                'revenue_current' => $current[$productId]['revenue'] ?? 0,
                'revenue_previous' => $previous[$productId]['revenue'] ?? 0,
                'revenue_change' => ($current[$productId]['revenue'] ?? 0) - ($previous[$productId]['revenue'] ?? 0),
            ];
        })->filter(fn (array $row) => $row['qty_change'] != 0);

        $increases = $changes->filter(fn (array $row) => $row['qty_change'] > 0)->sortByDesc('qty_change')->take(3)->values();
        $decreases = $changes->filter(fn (array $row) => $row['qty_change'] < 0)->sortBy('qty_change')->take(3)->values();

        // Sisa stok memberi tahu apakah penurunan disebabkan barang kosong,
        // dan apakah produk yang sedang naik stoknya cukup. Hanya untuk satu
        // outlet: lintas cabang, stok tiap outlet berbeda.
        $stocks = $allBranches
            ? collect()
            : Product::query()
                ->whereIn('store_id', $storeIds)
                ->whereIn('id', $increases->pluck('product_id')->merge($decreases->pluck('product_id')))
                ->get(['id', 'stock_quantity', 'track_stock', 'unit'])
                ->keyBy('id');

        $withStock = function (array $row) use ($stocks, $periodDays) {
            $product = $stocks[$row['product_id']] ?? null;

            if ($product && $product->track_stock) {
                $stock = (float) $product->stock_quantity;
                $perDay = $periodDays > 0 ? $row['qty_current'] / $periodDays : 0;

                $row['stock'] = round($stock, 2);
                $row['unit'] = $product->unit ?? 'pcs';
                $row['stock_days_left'] = $perDay > 0 ? (int) floor(max(0, $stock) / $perDay) : null;
            }

            return $row;
        };

        return [
            'increases' => $increases->map($withStock)->toArray(),
            'decreases' => $decreases->map($withStock)->toArray(),
        ];
    }

    protected function percentChange(float $previous, float $current): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? null : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Saran aksi yang seluruhnya diturunkan dari angka toko (deterministik,
     * tanpa memanggil provider AI). Tidak ada saran generik tanpa dasar data.
     *
     * @return array<int, string>
     */
    protected function recommendations(string $direction, array $current, array $previous, array $trends, ?float $transactionsChangePct, ?float $averageChangePct): array
    {
        $tips = [];
        $number = fn ($value) => AiMetrics::number($value, 2);

        if ($current['revenue'] <= 0) {
            $tips[] = $previous['revenue'] > 0
                ? 'Belum ada penjualan pada periode ini, padahal periode pembanding mencatat '.AiMetrics::rupiah($previous['revenue']).' — pastikan transaksi sudah diinput/tersinkron dan toko memang buka.'
                : 'Belum ada penjualan pada periode ini maupun periode pembanding, jadi belum ada pola yang bisa dianalisa. Coba periode yang lebih panjang (mis. bulan ini).';

            return $tips;
        }

        foreach (array_slice($trends['decreases'], 0, 2) as $row) {
            $change = "{$number($row['qty_previous'])} → {$number($row['qty_current'])} unit";

            if (isset($row['stock']) && $row['stock'] <= 0) {
                $tips[] = "**{$row['product_name']}** turun ({$change}) dan stoknya sekarang **habis** — kemungkinan besar penjualan hilang karena barang kosong. Restock dulu sebelum memikirkan promo.";
            } elseif ($direction === 'turun') {
                $tips[] = "**{$row['product_name']}** turun paling banyak ({$change}"
                    .(isset($row['stock']) ? ", stok masih {$number($row['stock'])} {$row['unit']}" : '')
                    .') — cek harga, ketersediaan di etalase/kasir, dan apakah ada pesaing atau varian pengganti.';
            }
        }

        foreach (array_slice($trends['increases'], 0, 2) as $row) {
            if (! isset($row['stock_days_left']) || $row['stock_days_left'] > 7) {
                continue;
            }

            $tips[] = $row['stock'] <= 0
                ? "**{$row['product_name']}** sedang naik ({$number($row['qty_previous'])} → {$number($row['qty_current'])} unit) tetapi stoknya **habis** — segera restock agar momentumnya tidak hilang."
                : "**{$row['product_name']}** sedang naik ({$number($row['qty_previous'])} → {$number($row['qty_current'])} unit) dan stoknya tinggal {$number($row['stock'])} {$row['unit']} (±{$row['stock_days_left']} hari pada laju sekarang) — restock lebih awal.";
        }

        if ($transactionsChangePct !== null && $averageChangePct !== null && $previous['transactions'] >= 5) {
            if ($transactionsChangePct <= -10 && $averageChangePct > -5) {
                $tips[] = 'Yang turun adalah **jumlah transaksi** ('.AiMetrics::number($transactionsChangePct).'%), bukan nilai belanja per transaksi — fokus ke mendatangkan pembeli (jam buka, promosi ke pelanggan lama), bukan menaikkan harga.';
            } elseif ($averageChangePct <= -10 && $transactionsChangePct > -5) {
                $tips[] = 'Jumlah pembeli relatif tetap, tetapi **nilai per transaksi turun** '.AiMetrics::number(abs($averageChangePct)).'% ('.AiMetrics::rupiah($previous['average_transaction']).' → '.AiMetrics::rupiah($current['average_transaction']).') — dorong pembelian tambahan/paket di kasir.';
            }
        }

        if ($current['margin_reliable'] && $current['gross_margin_pct'] !== null && $current['gross_margin_pct'] < 20) {
            $tips[] = 'Margin kotor tipis ('.AiMetrics::number($current['gross_margin_pct']).'%) — tinjau harga jual atau harga beli produk dengan margin terendah (tanya "produk bocor" untuk daftarnya).';
        }

        if ($tips === []) {
            $tips[] = match ($direction) {
                'naik' => 'Penjualan naik tanpa masalah stok yang terdeteksi pada produk pendorongnya — pertahankan ketersediaan produk tersebut.',
                'turun' => 'Penjualan turun merata (tidak terpusat di produk tertentu) — bandingkan jumlah transaksi per hari untuk melihat hari mana yang sepi.',
                default => 'Penjualan stabil dibanding periode pembanding; tidak ada produk atau stok yang perlu tindakan segera.',
            };
        }

        return $tips;
    }

    /**
     * @param  array<int, string>  $recommendations
     */
    protected function summaryMessage(string $direction, ?float $revenueChangePct, array $current, array $previous, array $trends, array $margin, array $recommendations, array $range, array $previousRange, ?string $coverageNote): string
    {
        $revenueNow = $this->formatCurrency((float) $current['revenue']);
        $revenueBefore = $this->formatCurrency((float) $previous['revenue']);

        $changeLabel = $revenueChangePct === null
            ? 'dari nol (periode sebelumnya tidak ada penjualan)'
            : (($revenueChangePct >= 0 ? 'naik ' : 'turun ').AiMetrics::number(abs($revenueChangePct)).'%');

        $message = "Periode **{$range['display_from']} - {$range['display_to']}** dibanding **{$previousRange['display_from']} - {$previousRange['display_to']}**"
            .($range['is_running'] ? ' (rentang setara, periode masih berjalan)' : '').".\n\n"
            ."Penjualan ({$changeLabel}): {$revenueNow} vs periode sebelumnya {$revenueBefore} "
            ."dari {$current['transactions']} transaksi (sebelumnya {$previous['transactions']}).";

        $topIncrease = $trends['increases'][0] ?? null;
        if ($topIncrease) {
            $message .= $topIncrease['is_new']
                ? " Produk baru yang mulai terjual: {$topIncrease['product_name']}."
                : " Pendorong utama: {$topIncrease['product_name']} (+".AiMetrics::number($topIncrease['qty_change'], 2).' unit).';
        }

        $topDecrease = $trends['decreases'][0] ?? null;
        if ($topDecrease) {
            $message .= " Penurunan terbesar: {$topDecrease['product_name']} (".AiMetrics::number($topDecrease['qty_change'], 2).' unit).';
        }

        if ($current['revenue'] > 0 && $margin['cost_coverage_pct'] > 0) {
            $message .= ' Margin kotor periode ini: '.AiMetrics::number($margin['gross_margin_pct']).'%.';
        }

        if ($coverageNote !== null) {
            $message .= "\n\n".$coverageNote;
        }

        if ($recommendations !== []) {
            $message .= "\n\n**Rekomendasi:**\n".implode("\n", array_map(fn (string $tip) => "- {$tip}", $recommendations));
        }

        return $message;
    }
}
