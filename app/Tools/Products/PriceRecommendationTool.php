<?php

namespace App\Tools\Products;

use App\Models\Product;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

/**
 * Rekomendasi harga jual berdasarkan HPP + margin target + harga pasar
 * (rata-rata harga historis penjualan dan harga produk sejenis).
 */
class PriceRecommendationTool extends AiTool
{
    public const DEFAULT_MARGIN_PCT = 30.0;

    public const MARKET_PREMIUM_CAP_PCT = 15.0;

    public function getName(): string
    {
        return 'recommend_price';
    }

    public function getDescription(): string
    {
        return 'Merekomendasikan harga jual berdasarkan HPP + margin target + harga pasar (rata-rata harga historis penjualan & produk sejenis). Gunakan saat user bertanya "saran/rekomendasi harga", "harga jual yang ideal/tepat", "berapa sebaiknya dijual", "cek margin harga", atau "harga sesuai HPP belum".';
    }

    public function getParameters(): array
    {
        return [
            'product_name' => [
                'type' => 'string',
                'description' => 'Nama atau SKU produk yang mau dianalisa. Opsional — kosongkan untuk menganalisa semua produk aktif.',
            ],
            'target_margin_pct' => [
                'type' => 'number',
                'description' => 'Target margin terhadap HPP dalam persen. Default 30.',
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

        $marginPct = (float) ($args['target_margin_pct'] ?? self::DEFAULT_MARGIN_PCT);

        $products = Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->when(! empty($args['product_name']), function ($query) use ($args) {
                $query->where(function ($q) use ($args) {
                    $q->where('name', 'like', "%{$args['product_name']}%")
                        ->orWhere('sku', 'like', "%{$args['product_name']}%")
                        ->orWhere('barcode', 'like', "%{$args['product_name']}%");
                });
            })
            ->orderBy('name')
            ->limit(15)
            ->get();

        if ($products->isEmpty()) {
            return ToolResult::success(
                data: ['products' => [], 'total' => 0, 'target_margin_pct' => $marginPct],
                message: 'Tidak ada produk yang cocok untuk dianalisa harga.'
            );
        }

        $marketPrices = $this->resolveHistoricPrices($products, $storeIds);
        $peerPrices = $this->resolvePeerCategoryPrices($products, $storeIds);

        $recommendations = $products
            ->map(fn (Product $product) => $this->buildRecommendation($product, $marginPct, $marketPrices, $peerPrices))
            ->values()
            ->toArray();

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'target_margin_pct' => $marginPct,
            'products' => $recommendations,
            'total' => count($recommendations),
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data, $allBranches, count($storeIds))
        );
    }

    /**
     * Rata-rata harga jual historis per produk (90 hari terakhir, transaksi completed & tidak void).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array<int, int>  $storeIds
     * @return array<int, float>
     */
    protected function resolveHistoricPrices($products, array $storeIds): array
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transaction_items.product_id', $products->pluck('id'))
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->where('transactions.is_void', false)
            ->where('transactions.created_at', '>=', Carbon::now()->subDays(90))
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id, AVG(transaction_items.unit_price) as avg_price')
            ->pluck('avg_price', 'product_id')
            ->map(fn ($price) => round((float) $price))
            ->all();
    }

    /**
     * Rata-rata harga jual produk sejenis (satu kategori) sebagai pembanding harga pasar.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array<int, int>  $storeIds
     * @return array<int, float>
     */
    protected function resolvePeerCategoryPrices($products, array $storeIds): array
    {
        $peers = Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->get(['id', 'product_category_id', 'category', 'price']);

        $result = [];
        foreach ($products as $product) {
            if ($product->product_category_id === null && ! $product->category) {
                continue;
            }

            $similar = $peers->filter(function (Product $peer) use ($product) {
                if ($peer->id === $product->id) {
                    return false;
                }

                return $product->product_category_id !== null
                    ? $peer->product_category_id === $product->product_category_id
                    : $peer->category === $product->category;
            });

            if ($similar->isNotEmpty()) {
                $result[$product->id] = round((float) $similar->avg('price'));
            }
        }

        return $result;
    }

    /** HPP per satuan: estimasi biaya komponen, fallback rata-rata biaya, lalu harga modal. */
    protected function resolveHpp(Product $product): ?float
    {
        foreach ([(float) $product->estimatedUnitCost(), (float) $product->currentAverageCost(), (float) $product->cost_price] as $cost) {
            if ($cost > 0) {
                return $cost;
            }
        }

        return null;
    }

    /** Pembulatan harga psikologis: kelipatan 500 (< Rp100rb) atau 1000 (>= Rp100rb). */
    protected function roundPrice(float $price): float
    {
        $price = round($price);
        $step = $price < 100000 ? 500 : 1000;

        return (float) (ceil($price / $step) * $step);
    }

    /**
     * @param  array<int, float>  $marketPrices
     * @param  array<int, float>  $peerPrices
     * @return array<string, mixed>
     */
    protected function buildRecommendation(Product $product, float $marginPct, array $marketPrices, array $peerPrices): array
    {
        $hpp = $this->resolveHpp($product);
        $currentPrice = (float) $product->price;

        $marketPrice = $marketPrices[$product->id] ?? null;
        $marketSource = $marketPrice !== null ? 'historis' : null;
        if ($marketPrice === null && isset($peerPrices[$product->id])) {
            $marketPrice = $peerPrices[$product->id];
            $marketSource = 'produk sejenis';
        }

        $row = [
            'name' => $product->name,
            'sku' => $product->sku,
            'hpp' => $hpp !== null ? round($hpp) : null,
            'current_price' => round($currentPrice),
            'current_margin_pct' => $hpp !== null && $hpp > 0 ? round(($currentPrice - $hpp) / $hpp * 100, 1) : null,
            'market_price' => $marketPrice,
            'market_source' => $marketSource,
            'price_from_margin' => null,
            'suggested_price' => null,
            'price_gap' => null,
            'advice' => '',
        ];

        if ($hpp === null) {
            $row['advice'] = 'HPP belum tercatat — isi harga modal/average cost dulu agar rekomendasi akurat.';

            return $row;
        }

        $ideal = $this->roundPrice($hpp * (1 + $marginPct / 100));
        $suggested = $ideal;

        if ($marketPrice !== null && $ideal > $marketPrice * (1 + self::MARKET_PREMIUM_CAP_PCT / 100)) {
            $suggested = $this->roundPrice($marketPrice * (1 + self::MARKET_PREMIUM_CAP_PCT / 100));
        }

        $row['price_from_margin'] = round($ideal);
        $row['suggested_price'] = round($suggested);
        $row['price_gap'] = round($suggested - $currentPrice);
        $row['advice'] = $this->buildAdvice($row, $marginPct, $hpp);

        return $row;
    }

    protected function buildAdvice(array $row, float $marginPct, float $hpp): string
    {
        $currentPrice = (float) $row['current_price'];

        if ($row['market_price'] !== null && $row['market_price'] < $hpp) {
            return 'Harga pasar di bawah HPP — naikkan harga atau tekan biaya modal.';
        }

        if ($currentPrice <= 0) {
            return 'Harga jual belum diisi — pasang sesuai saran di atas.';
        }

        $gapPct = ($row['suggested_price'] - $currentPrice) / $currentPrice * 100;

        if (abs($gapPct) <= 2) {
            return 'Harga sudah sesuai — margin di sekitar target '.$marginPct.'%.';
        }

        return $gapPct > 0
            ? 'Harga kini di bawah saran — pertimbangkan naik agar margin ke target '.$marginPct.'%.'
            : 'Harga kini di atas saran — turunkan agar tetap kompetitif dengan harga pasar.';
    }

    protected function summaryMessage(array $data, bool $allBranches, int $storeCount): string
    {
        $lines = [
            '💡 Rekomendasi harga jual — margin target **'.$data['target_margin_pct'].'%** dari HPP'
                .($allBranches ? " (semua cabang — {$storeCount} outlet)" : '').':',
        ];

        foreach ($data['products'] as $row) {
            $lines[] = '';
            $lines[] = '**'.$row['name'].'**'.($row['sku'] ? ' ('.$row['sku'].')' : '');

            if ($row['suggested_price'] === null) {
                $lines[] = '- '.$row['advice'];

                continue;
            }

            $market = $row['market_price'] !== null
                ? 'harga pasar ±'.$this->formatCurrency((float) $row['market_price']).' ('.$row['market_source'].')'
                : 'harga pasar belum ada data';

            $lines[] = '- HPP '.$this->formatCurrency((float) $row['hpp'])
                .' · harga kini '.$this->formatCurrency((float) $row['current_price'])
                .' (margin '.$row['current_margin_pct'].'%) · '.$market;
            $lines[] = '- Saran jual: **'.$this->formatCurrency((float) $row['suggested_price']).'**'
                .' (dari margin target: '.$this->formatCurrency((float) $row['price_from_margin']).')'
                .($row['price_gap'] != 0
                    ? ' → '.($row['price_gap'] > 0 ? 'naik ' : 'turun ').$this->formatCurrency(abs((float) $row['price_gap']))
                    : ' → harga sudah pas');
            $lines[] = '- '.$row['advice'];
        }

        return implode("\n", $lines);
    }
}
