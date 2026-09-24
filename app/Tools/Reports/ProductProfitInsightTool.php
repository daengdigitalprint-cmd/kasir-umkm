<?php

declare(strict_types=1);

namespace App\Tools\Reports;

use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Analisa gabungan: produk bocor (terlaris tapi margin tipis / sering diskon),
 * rekomendasi bundling/cross-sell (produk A sering bareng B), dan
 * produk slow-moving dengan margin tinggi (target promosi).
 */
class ProductProfitInsightTool extends AiTool
{
    public function getName(): string
    {
        return 'product_profit_insight';
    }

    public function getDescription(): string
    {
        return 'Analisa mendalam profit per produk: mendeteksi "produk bocor" (terlaris tapi margin tipis atau sering diskon), menemukan pasangan produk yang sering dibeli bersama untuk bundling/cross-sell, dan mengidentifikasi produk slow-moving dengan margin tinggi yang cocok untuk dipromosikan. Gunakan saat user bertanya "produk mana yang boros", "produk apa yang bisa di-bundle", "cross-sell", "margin tipis", "produk bocor", atau "insight laba per produk".';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode analisis: "this_week", "this_month", "last_month", "this_year" (default: this_month)',
                'enum' => ['this_week', 'this_month', 'last_month', 'this_year'],
            ],
            'margin_threshold_pct' => [
                'type' => 'number',
                'description' => 'Ambang batas margin (%) untuk mengklasifikasikan produk bocor. Default: 15',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah maksimal item per kategori (default: 10)',
            ],
            'scope' => [
                'type' => 'string',
                'description' => 'Cakupan data: "current" hanya outlet aktif user (default), "all" menggabungkan SEMUA outlet/cabang.',
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

        $period = $args['period'] ?? 'this_month';
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        $marginThreshold = max(0, min(100, (float) ($args['margin_threshold_pct'] ?? 15)));

        $range = $this->getPeriodRange($period);
        $from = $range['from'];
        $to = $range['to'];

        // Ambil semua item transaksi completed dalam periode.
        $items = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->whereNotNull('transaction_items.product_id')
            ->select([
                'transaction_items.transaction_id',
                'transaction_items.product_id',
                'transaction_items.product_name',
                'transaction_items.quantity',
                'transaction_items.unit_price',
                'transaction_items.total_price',
                'transaction_items.discount_amount',
                'transaction_items.unit_cost_snapshot',
                'transaction_items.total_cost_snapshot',
                'transaction_items.gross_profit_snapshot',
            ])
            ->get();

        if ($items->isEmpty()) {
            return ToolResult::success(
                data: [
                    'leaking_products' => [],
                    'bundling_suggestions' => [],
                    'slow_movers_high_margin' => [],
                    'summary' => [
                        'total_products_analyzed' => 0,
                        'period' => ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')],
                    ],
                ],
                message: 'Belum ada data transaksi di periode ini — tidak bisa melakukan analisa.'
            );
        }

        $leaking = $this->detectLeakingProducts($items, $marginThreshold, $limit);
        $bundling = $this->analyzeBundling($items, $limit);
        $slowMovers = $this->detectSlowMoversHighMargin($items, $storeIds, $from, $to, $limit);

        $totalProductsAnalyzed = $items->pluck('product_id')->unique()->count();

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'leaking_products' => $leaking,
            'bundling_suggestions' => $bundling,
            'slow_movers_high_margin' => $slowMovers,
            'summary' => [
                'total_products_analyzed' => $totalProductsAnalyzed,
                'leaking_count' => count($leaking),
                'bundling_pairs_count' => count($bundling),
                'slow_movers_count' => count($slowMovers),
                'margin_threshold_pct' => $marginThreshold,
                'period' => ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')],
            ],
        ];

        return ToolResult::success(
            data: $data,
            message: $this->buildSummary($data)
        );
    }

    /**
     * Deteksi produk bocor: terlaris tapi margin tipis atau sering didiskon.
     *
     * @param  Collection<int, TransactionItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function detectLeakingProducts(Collection $items, float $marginThreshold, int $limit): array
    {
        $byProduct = $items->groupBy('product_id');

        $candidates = [];

        foreach ($byProduct as $productId => $group) {
            $totalRevenue = (float) $group->sum('total_price');
            $totalProfit = (float) $group->sum('gross_profit_snapshot');
            $totalDiscount = (float) $group->sum('discount_amount');
            $totalQty = (float) $group->sum('quantity');

            if ($totalRevenue <= 0) {
                continue;
            }

            $marginPct = round($totalProfit / $totalRevenue * 100, 1);
            $discountRatio = round($totalDiscount / ($totalRevenue + $totalDiscount) * 100, 1);

            // Produk "bocor": terlaris dengan margin rendah ATAU diskon tinggi.
            $isLowMargin = $marginPct < $marginThreshold;
            $isHighDiscount = $discountRatio > 20;

            if (! $isLowMargin && ! $isHighDiscount) {
                continue;
            }

            $reasons = [];

            if ($isLowMargin) {
                $reasons[] = "margin hanya {$marginPct}%";
            }

            if ($isHighDiscount) {
                $reasons[] = "diskon rata-rata {$discountRatio}% dari harga jual";
            }

            $candidates[] = [
                'product_id' => $productId,
                'product_name' => $group->first()->product_name ?? 'Unknown',
                'total_revenue' => round($totalRevenue),
                'total_profit' => round($totalProfit),
                'margin_pct' => $marginPct,
                'total_discount' => round($totalDiscount),
                'discount_ratio_pct' => $discountRatio,
                'quantity_sold' => round($totalQty, 2),
                'reasons' => $reasons,
            ];
        }

        // Urutkan: margin paling rendah dulu, lalu discount_ratio tertinggi.
        usort($candidates, function (array $a, array $b) {
            $marginCmp = $a['margin_pct'] <=> $b['margin_pct'];

            return $marginCmp !== 0 ? $marginCmp : $b['discount_ratio_pct'] <=> $a['discount_ratio_pct'];
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Market basket analysis: cari pasangan produk yang sering dibeli bersama.
     *
     * @param  Collection<int, TransactionItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function analyzeBundling(Collection $items, int $limit): array
    {
        // Kelompokkan item per transaksi.
        $byTransaction = $items->groupBy('transaction_id');

        // Hanya ambil transaksi dengan >1 produk unik (cross-sell = beli bareng).
        $multiProductTransactions = $byTransaction->filter(function ($group) {
            return $group->pluck('product_id')->unique()->count() > 1;
        });

        if ($multiProductTransactions->isEmpty()) {
            return [];
        }

        // Hitung frekuensi co-occurrence per pasangan produk.
        $pairCounts = [];
        $productNameMap = [];

        foreach ($multiProductTransactions as $group) {
            $productIds = $group->pluck('product_id')->unique()->sort()->values()->toArray();

            // Simpan nama produk.
            foreach ($group as $item) {
                $productNameMap[$item->product_id] = $item->product_name ?? 'Unknown';
            }

            // Buat semua pasangan unik (combinations of 2).
            $count = count($productIds);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $productIds[$i].'-'.$productIds[$j];
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        if (empty($pairCounts)) {
            return [];
        }

        // Urutkan berdasarkan frekuensi, ambil top N.
        arsort($pairCounts);

        $totalMultiProductTx = $multiProductTransactions->count();
        $pairs = [];

        foreach (array_slice($pairCounts, 0, $limit * 3) as $key => $count) {
            [$idA, $idB] = explode('-', $key);
            $nameA = $productNameMap[$idA] ?? 'Unknown';
            $nameB = $productNameMap[$idB] ?? 'Unknown';
            $confidence = round($count / $totalMultiProductTx * 100, 1);

            // Filter: minimal co-occurrence 2x dan confidence >= 5%.
            if ($count < 2 || $confidence < 5) {
                continue;
            }

            $pairs[] = [
                'product_a' => ['id' => (int) $idA, 'name' => $nameA],
                'product_b' => ['id' => (int) $idB, 'name' => $nameB],
                'co_occurrence' => $count,
                'confidence_pct' => $confidence,
                'suggestion' => "Beli **{$nameA}** + **{$nameB}** sering bareng ({$count}x transaksi) — cocok untuk bundling.",
            ];

            if (count($pairs) >= $limit) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Deteksi produk slow-moving tapi margin tinggi (target promosi / bundling).
     *
     * @param  Collection<int, TransactionItem>  $items
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     */
    private function detectSlowMoversHighMargin(Collection $items, array $storeIds, Carbon $from, Carbon $to, int $limit): array
    {
        $byProduct = $items->groupBy('product_id');

        // Hitung median qty sold untuk threshold "slow-moving".
        $allQtySold = $byProduct->map(fn ($g) => (float) $g->sum('quantity'))->sort()->values();
        $medianQty = $allQtySold->count() > 0
            ? $allQtySold[(int) floor($allQtySold->count() * 0.25)]
            : 0;

        // Threshold: 25th percentile atau minimal 2 unit.
        $slowThreshold = max(2, $medianQty);

        $candidates = [];

        foreach ($byProduct as $productId => $group) {
            $totalRevenue = (float) $group->sum('total_price');
            $totalProfit = (float) $group->sum('gross_profit_snapshot');
            $totalQty = (float) $group->sum('quantity');

            if ($totalRevenue <= 0 || $totalQty > $slowThreshold) {
                continue;
            }

            $marginPct = round($totalProfit / $totalRevenue * 100, 1);

            // Hanya yang margin-nya di atas 40% (tergolong tinggi).
            if ($marginPct <= 40) {
                continue;
            }

            $candidates[] = [
                'product_id' => $productId,
                'product_name' => $group->first()->product_name ?? 'Unknown',
                'total_revenue' => round($totalRevenue),
                'total_profit' => round($totalProfit),
                'margin_pct' => $marginPct,
                'quantity_sold' => round($totalQty, 2),
                'suggestion' => "Produk ini jarang terjual ({$totalQty} unit) tapi margin tinggi ({$marginPct}%) — cocok untuk dipromosikan atau di-bundle dengan produk laris.",
            ];
        }

        // Urutkan: margin tertinggi dulu.
        usort($candidates, fn (array $a, array $b) => $b['margin_pct'] <=> $a['margin_pct']);

        return array_slice($candidates, 0, $limit);
    }

    private function getPeriodRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'this_week' => ['from' => $now->copy()->startOfWeek(), 'to' => $now->copy()->endOfWeek()],
            'last_month' => ['from' => $now->copy()->subMonth()->startOfMonth(), 'to' => $now->copy()->subMonth()->endOfMonth()],
            'this_year' => ['from' => $now->copy()->startOfYear(), 'to' => $now->copy()->endOfYear()],
            default => ['from' => $now->copy()->startOfMonth(), 'to' => $now->copy()->endOfMonth()],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSummary(array $data): string
    {
        $summary = $data['summary'];
        $lines = [
            'Insight profit per produk **'.$summary['period']['from'].' - '.$summary['period']['to'].'**: '
            ."{$summary['total_products_analyzed']} produk dianalisis (margin threshold {$summary['margin_threshold_pct']}%).",
        ];

        // Produk bocor.
        $leaking = $data['leaking_products'];

        if ($leaking !== []) {
            $lines[] = '';
            $lines[] = '**Produk Bocor** (terlaris tapi margin tipis / sering diskon):';

            foreach (array_slice($leaking, 0, 5) as $item) {
                $reasons = implode(', ', $item['reasons']);
                $lines[] = '- **'.$item['product_name'].'** — margin '.$item['margin_pct']
                    .'%, diskon '.$item['discount_ratio_pct']
                    .'% — '.$reasons;
            }
        }

        // Bundling.
        $bundling = $data['bundling_suggestions'];

        if ($bundling !== []) {
            $lines[] = '';
            $lines[] = '**Rekomendasi Bundling** (sering dibeli bersama):';

            foreach (array_slice($bundling, 0, 5) as $pair) {
                $lines[] = '- '.$pair['product_a']['name'].' + '.$pair['product_b']['name']
                    .' — '.$pair['co_occurrence'].'x dibeli bareng (confidence '.$pair['confidence_pct'].'%)';
            }
        }

        // Slow movers.
        $slow = $data['slow_movers_high_margin'];

        if ($slow !== []) {
            $lines[] = '';
            $lines[] = '**Produk Slow-Moving Margin Tinggi** (target promosi):';

            foreach (array_slice($slow, 0, 5) as $item) {
                $lines[] = '- **'.$item['product_name'].'** — '.$item['quantity_sold']
                    .' unit terjual, margin '.$item['margin_pct'].'%';
            }
        }

        if ($leaking === [] && $bundling === [] && $slow === []) {
            $lines[] = '';
            $lines[] = 'Tidak ada insight signifikan ditemukan — data penjualan masih terlalu sedikit atau semua produk sudah sehat.';
        }

        return implode("\n", $lines);
    }
}
