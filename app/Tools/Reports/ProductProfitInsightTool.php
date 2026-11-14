<?php

declare(strict_types=1);

namespace App\Tools\Reports;

use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Collection;

/**
 * Analisa gabungan: produk bocor (margin tipis / sering diskon),
 * rekomendasi bundling/cross-sell (produk A sering bareng B), dan
 * produk slow-moving dengan margin tinggi (target promosi).
 *
 * Margin hanya dinilai untuk produk yang harga modalnya tercatat. Produk
 * tanpa harga modal dilaporkan terpisah — tidak dianggap bermargin 0% atau 100%.
 */
class ProductProfitInsightTool extends AiTool
{
    public function getName(): string
    {
        return 'product_profit_insight';
    }

    public function getDescription(): string
    {
        return 'Analisa mendalam profit per produk: mendeteksi "produk bocor" (margin tipis, dijual di bawah modal, atau sering diskon) lengkap dengan potensi tambahan laba, menemukan pasangan produk yang sering dibeli bersama untuk bundling/cross-sell, dan mengidentifikasi produk slow-moving dengan margin tinggi yang cocok untuk dipromosikan. Gunakan saat user bertanya "produk mana yang boros", "produk apa yang bisa di-bundle", "cross-sell", "margin tipis", "produk bocor", atau "insight laba per produk".';
    }

    public function getParameters(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Periode analisis (default: last_30_days)',
                'enum' => AiPeriod::PERIODS,
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

        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        $marginThreshold = max(0, min(100, (float) ($args['margin_threshold_pct'] ?? 15)));

        $range = AiPeriod::resolve(['period' => $args['period'] ?? 'last_30_days'], $store, 'last_30_days');
        $period = ['label' => $range['label'], 'from' => $range['display_from'], 'to' => $range['display_to']];

        $products = AiMetrics::byProduct($storeIds, $range['from'], $range['to']);

        if ($products->isEmpty()) {
            return ToolResult::success(
                data: [
                    'leaking_products' => [],
                    'bundling_suggestions' => [],
                    'slow_movers_high_margin' => [],
                    'summary' => [
                        'total_products_analyzed' => 0,
                        'period' => $period,
                    ],
                ],
                message: "Belum ada data transaksi di periode {$period['from']} - {$period['to']} — tidak bisa melakukan analisa."
            );
        }

        $withoutCost = $products->filter(fn (array $row) => ! $row['has_cost'] && $row['revenue'] > 0)->sortByDesc('revenue')->values();

        $leaking = $this->detectLeakingProducts($products, $marginThreshold, $limit);
        $bundling = $this->analyzeBundling($storeIds, $range, $limit);
        $slowMovers = $this->detectSlowMoversHighMargin($products, $limit);

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'leaking_products' => $leaking,
            'bundling_suggestions' => $bundling,
            'slow_movers_high_margin' => $slowMovers,
            'products_without_cost' => $withoutCost->take(5)->map(fn (array $row) => [
                'product_name' => $row['product_name'],
                'revenue' => $row['revenue'],
            ])->toArray(),
            'summary' => [
                'total_products_analyzed' => $products->count(),
                'products_with_cost' => $products->count() - $withoutCost->count(),
                'products_without_cost' => $withoutCost->count(),
                'leaking_count' => count($leaking),
                'bundling_pairs_count' => count($bundling),
                'slow_movers_count' => count($slowMovers),
                'margin_threshold_pct' => $marginThreshold,
                'period' => $period,
            ],
        ];

        return ToolResult::success(
            data: $data,
            message: $this->buildSummary($data)
        );
    }

    /**
     * Produk bocor: margin di bawah ambang (hanya bila harga modal tercatat)
     * atau porsi diskon tinggi. Diurutkan dari potensi tambahan laba terbesar.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function detectLeakingProducts(Collection $products, float $marginThreshold, int $limit): array
    {
        $candidates = [];

        foreach ($products as $row) {
            $revenue = (float) $row['revenue'];

            if ($revenue <= 0) {
                continue;
            }

            $discount = (float) $row['discount'];
            $discountRatio = round($discount / ($revenue + $discount) * 100, 1);
            $marginPct = $row['margin_pct'];

            $isLowMargin = $row['has_cost'] && $marginPct < $marginThreshold;
            $isHighDiscount = $discountRatio > 20;

            if (! $isLowMargin && ! $isHighDiscount) {
                continue;
            }

            $reasons = [];
            $potentialGain = 0.0;

            if ($isLowMargin) {
                $reasons[] = $marginPct < 0
                    ? 'dijual DI BAWAH harga modal (margin '.AiMetrics::number($marginPct).'%)'
                    : 'margin hanya '.AiMetrics::number($marginPct).'%';
                // Tambahan laba bila margin dinaikkan ke ambang, pada volume yang sama.
                $potentialGain = round($revenue * ($marginThreshold - $marginPct) / 100);
            }

            if ($isHighDiscount) {
                $reasons[] = 'diskon rata-rata '.AiMetrics::number($discountRatio).'% dari harga jual';
            }

            $candidates[] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'total_revenue' => round($revenue),
                'total_profit' => $row['gross_profit'],
                'margin_pct' => $marginPct,
                'total_discount' => round($discount),
                'discount_ratio_pct' => $discountRatio,
                'quantity_sold' => $row['quantity'],
                'potential_gain_at_threshold' => $potentialGain,
                'reasons' => $reasons,
            ];
        }

        // Margin terendah dulu; tanpa data margin (hanya diskon tinggi) di belakang.
        usort($candidates, function (array $a, array $b) {
            $marginCmp = ($a['margin_pct'] ?? PHP_FLOAT_MAX) <=> ($b['margin_pct'] ?? PHP_FLOAT_MAX);

            return $marginCmp !== 0 ? $marginCmp : $b['discount_ratio_pct'] <=> $a['discount_ratio_pct'];
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Market basket analysis: pasangan produk yang sering dibeli bersama.
     *
     * @param  array<int, int>  $storeIds
     * @param  array<string, mixed>  $range
     * @return array<int, array<string, mixed>>
     */
    private function analyzeBundling(array $storeIds, array $range, int $limit): array
    {
        $items = AiMetrics::itemsQuery($storeIds, $range['from'], $range['to'])
            ->whereNotNull('transaction_items.product_id')
            ->get(['transaction_items.transaction_id', 'transaction_items.product_id', 'transaction_items.product_name']);

        $multiProductTransactions = $items->groupBy('transaction_id')
            ->filter(fn ($group) => $group->pluck('product_id')->unique()->count() > 1);

        if ($multiProductTransactions->isEmpty()) {
            return [];
        }

        $pairCounts = [];
        $productNameMap = [];
        $productTransactionCounts = [];

        foreach ($items->groupBy('transaction_id') as $group) {
            foreach ($group->pluck('product_id')->unique() as $productId) {
                $productTransactionCounts[$productId] = ($productTransactionCounts[$productId] ?? 0) + 1;
            }
        }

        foreach ($multiProductTransactions as $group) {
            $productIds = $group->pluck('product_id')->unique()->sort()->values()->toArray();

            foreach ($group as $item) {
                $productNameMap[$item->product_id] = $item->product_name ?? 'Tanpa nama';
            }

            $count = count($productIds);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $productIds[$i].'-'.$productIds[$j];
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        if ($pairCounts === []) {
            return [];
        }

        arsort($pairCounts);

        $totalMultiProductTx = $multiProductTransactions->count();
        $pairs = [];

        foreach (array_slice($pairCounts, 0, $limit * 3, true) as $key => $count) {
            [$idA, $idB] = explode('-', (string) $key);
            $nameA = $productNameMap[$idA] ?? 'Tanpa nama';
            $nameB = $productNameMap[$idB] ?? 'Tanpa nama';
            $confidence = round($count / $totalMultiProductTx * 100, 1);

            // Minimal 2x muncul bersama dan ≥5% dari transaksi multi-produk.
            if ($count < 2 || $confidence < 5) {
                continue;
            }

            $shareOfA = round($count / max(1, $productTransactionCounts[$idA] ?? 1) * 100);
            $shareOfB = round($count / max(1, $productTransactionCounts[$idB] ?? 1) * 100);

            $pairs[] = [
                'product_a' => ['id' => (int) $idA, 'name' => $nameA],
                'product_b' => ['id' => (int) $idB, 'name' => $nameB],
                'co_occurrence' => $count,
                'confidence_pct' => $confidence,
                'buyers_of_a_also_buy_b_pct' => $shareOfA,
                'buyers_of_b_also_buy_a_pct' => $shareOfB,
                'suggestion' => "**{$nameA}** + **{$nameB}** dibeli bareng {$count}x ({$shareOfA}% pembeli {$nameA} juga membeli {$nameB}) — cocok untuk paket/bundling.",
            ];

            if (count($pairs) >= $limit) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Produk jarang terjual tetapi margin tinggi (target promosi / bundling).
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function detectSlowMoversHighMargin(Collection $products, int $limit): array
    {
        $allQtySold = $products->pluck('quantity')->map(fn ($qty) => (float) $qty)->sort()->values();
        $percentile25 = $allQtySold->count() > 0
            ? $allQtySold[(int) floor($allQtySold->count() * 0.25)]
            : 0;

        // Ambang "jarang terjual": persentil ke-25 atau minimal 2 unit.
        $slowThreshold = max(2, $percentile25);

        $candidates = [];

        foreach ($products as $row) {
            if (! $row['has_cost'] || $row['revenue'] <= 0 || $row['quantity'] > $slowThreshold || $row['margin_pct'] <= 40) {
                continue;
            }

            $candidates[] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'total_revenue' => $row['revenue'],
                'total_profit' => $row['gross_profit'],
                'margin_pct' => $row['margin_pct'],
                'quantity_sold' => $row['quantity'],
                'suggestion' => 'Produk ini jarang terjual ('.AiMetrics::number($row['quantity'], 2).' unit) tapi margin tinggi ('.AiMetrics::number($row['margin_pct']).'%) — cocok untuk dipromosikan atau di-bundle dengan produk laris.',
            ];
        }

        usort($candidates, fn (array $a, array $b) => $b['margin_pct'] <=> $a['margin_pct']);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSummary(array $data): string
    {
        $summary = $data['summary'];
        $lines = [
            'Insight profit per produk **'.$summary['period']['from'].' - '.$summary['period']['to'].'**: '
            ."{$summary['total_products_analyzed']} produk dianalisis (margin threshold ".AiMetrics::number($summary['margin_threshold_pct']).'%).',
        ];

        $leaking = $data['leaking_products'];

        if ($leaking !== []) {
            $lines[] = '';
            $lines[] = '**Produk Bocor** (margin tipis / sering diskon):';

            foreach (array_slice($leaking, 0, 5) as $item) {
                $line = '- **'.$item['product_name'].'** — '.implode(', ', $item['reasons'])
                    .'; omzet '.AiMetrics::rupiah($item['total_revenue']);

                if ($item['potential_gain_at_threshold'] > 0) {
                    $line .= '. Bila margin dinaikkan ke '.AiMetrics::number($summary['margin_threshold_pct']).'%: laba +'
                        .AiMetrics::rupiah($item['potential_gain_at_threshold']).' pada volume yang sama';
                }

                $lines[] = $line;
            }
        }

        $bundling = $data['bundling_suggestions'];

        if ($bundling !== []) {
            $lines[] = '';
            $lines[] = '**Rekomendasi Bundling** (sering dibeli bersama):';

            foreach (array_slice($bundling, 0, 5) as $pair) {
                $lines[] = '- '.$pair['product_a']['name'].' + '.$pair['product_b']['name']
                    .' — '.$pair['co_occurrence'].'x dibeli bareng ('.$pair['buyers_of_a_also_buy_b_pct'].'% pembeli '
                    .$pair['product_a']['name'].' juga membeli '.$pair['product_b']['name'].')';
            }
        }

        $slow = $data['slow_movers_high_margin'];

        if ($slow !== []) {
            $lines[] = '';
            $lines[] = '**Produk Slow-Moving Margin Tinggi** (target promosi):';

            foreach (array_slice($slow, 0, 5) as $item) {
                $lines[] = '- **'.$item['product_name'].'** — '.AiMetrics::number($item['quantity_sold'], 2)
                    .' unit terjual, margin '.AiMetrics::number($item['margin_pct']).'%';
            }
        }

        if ($summary['products_without_cost'] > 0) {
            $names = implode(', ', array_column($data['products_without_cost'], 'product_name'));
            $lines[] = '';
            $lines[] = "Catatan: **{$summary['products_without_cost']} dari {$summary['total_products_analyzed']} produk** belum punya harga modal, jadi marginnya tidak dinilai"
                .($names !== '' ? " (omzet terbesar: {$names})" : '').'. Isi harga modalnya agar analisa laba lengkap.';
        }

        if ($leaking === [] && $bundling === [] && $slow === []) {
            $lines[] = '';
            $lines[] = $summary['products_with_cost'] === 0
                ? 'Belum ada kesimpulan margin yang bisa ditarik karena harga modal belum tercatat.'
                : 'Tidak ada produk bocor, pasangan bundling, atau slow-mover margin tinggi yang memenuhi ambang pada periode ini.';
        }

        return implode("\n", $lines);
    }
}
