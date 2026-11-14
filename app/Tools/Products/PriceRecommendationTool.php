<?php

namespace App\Tools\Products;

use App\Models\Product;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

/**
 * Rekomendasi harga jual berdasarkan HPP + target untung.
 *
 * Target untung dipakai sebagai harga MINIMUM, bukan harga wajib: produk yang
 * sudah dijual di atasnya tidak pernah disarankan turun harga. Harga acuan
 * (rata-rata harga jual sendiri 90 hari / produk sekategori di toko ini)
 * hanya pembanding internal — bukan harga pasar pesaing.
 */
class PriceRecommendationTool extends AiTool
{
    public const DEFAULT_MARGIN_PCT = 30.0;

    /** Kenaikan sekali jalan dibatasi sekian persen di atas harga jual rata-rata. */
    public const MARKET_PREMIUM_CAP_PCT = 15.0;

    protected const MAX_ROWS = 15;

    public function getName(): string
    {
        return 'recommend_price';
    }

    public function getDescription(): string
    {
        return 'Mengecek harga jual terhadap HPP: menghitung harga minimum agar untung sesuai target (persen dari modal), menandai produk yang dijual di bawah modal atau di bawah target, dan menyarankan kenaikan bertahap. TIDAK menyarankan turun harga hanya karena untung melebihi target. Gunakan saat user bertanya "saran/rekomendasi harga", "harga jual yang ideal/tepat", "berapa sebaiknya dijual", "cek margin harga", atau "harga sesuai HPP belum".';
    }

    public function getParameters(): array
    {
        return [
            'product_name' => [
                'type' => 'string',
                'description' => 'Nama atau SKU produk yang mau dianalisa. Opsional — kosongkan untuk memeriksa produk terlaris dan menampilkan yang paling perlu ditindak.',
            ],
            'target_margin_pct' => [
                'type' => 'number',
                'description' => 'Target untung terhadap HPP/modal dalam persen (markup). Default 30.',
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

        $marginPct = max(0.0, min(1000.0, (float) ($args['target_margin_pct'] ?? self::DEFAULT_MARGIN_PCT)));
        $targetIsDefault = ! isset($args['target_margin_pct']);
        $filtered = ! empty($args['product_name']);

        $products = $filtered
            ? $this->findByName((string) $args['product_name'], $storeIds)
            : $this->candidateProducts($storeIds);

        if ($products->isEmpty()) {
            return ToolResult::success(
                data: ['products' => [], 'total' => 0, 'target_margin_pct' => $marginPct],
                message: $filtered
                    ? "Tidak ditemukan produk aktif dengan nama/SKU \"{$args['product_name']}\"."
                    : 'Tidak ada produk aktif yang bisa dianalisa harganya.'
            );
        }

        $marketPrices = $this->resolveHistoricPrices($products, $storeIds);
        $peerPrices = $this->resolvePeerCategoryPrices($products, $storeIds);

        $rows = $products
            ->map(fn (Product $product) => $this->buildRecommendation($product, $marginPct, $marketPrices, $peerPrices))
            ->values();

        $checked = $rows->count();
        $counts = [
            'below_cost' => $rows->where('status', 'below_cost')->count(),
            'below_target' => $rows->where('status', 'below_target')->count(),
            'ok' => $rows->whereIn('status', ['ok', 'above_target'])->count(),
            'no_cost' => $rows->where('status', 'no_cost')->count(),
        ];

        // Tanpa nama produk: tampilkan yang paling perlu ditindak lebih dulu.
        if (! $filtered) {
            $priority = ['below_cost' => 0, 'below_target' => 1, 'no_cost' => 2, 'ok' => 3, 'above_target' => 3];
            $rows = $rows
                ->sort(fn (array $a, array $b) => [$priority[$a['status']], -abs((float) $a['price_gap'])] <=> [$priority[$b['status']], -abs((float) $b['price_gap'])])
                ->values();
        }

        $recommendations = $rows->take(self::MAX_ROWS)->values()->toArray();

        $data = [
            'scope' => $allBranches ? AiStoreScope::ALL : AiStoreScope::CURRENT,
            'stores_analyzed' => count($storeIds),
            'target_margin_pct' => $marginPct,
            'target_basis' => 'persen untung dari HPP/modal (markup)',
            'target_is_default' => $targetIsDefault,
            'products' => $recommendations,
            'total' => count($recommendations),
            'checked' => $checked,
            'counts' => $counts,
        ];

        return ToolResult::success(
            data: $data,
            message: $this->summaryMessage($data, $allBranches, count($storeIds), $filtered)
        );
    }

    /**
     * @param  array<int, int>  $storeIds
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function findByName(string $name, array $storeIds)
    {
        return Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhere('sku', 'like', "%{$name}%")
                    ->orWhere('barcode', 'like', "%{$name}%");
            })
            ->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get();
    }

    /**
     * Tanpa nama produk: periksa produk dengan omzet terbesar 90 hari terakhir
     * (harga merekalah yang paling berpengaruh), dilengkapi produk lain bila
     * riwayat penjualan belum banyak.
     *
     * @param  array<int, int>  $storeIds
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function candidateProducts(array $storeIds)
    {
        $limit = 60;

        $topIds = AiMetrics::itemsQuery($storeIds, Carbon::now()->subDays(90), Carbon::now())
            ->whereNotNull('transaction_items.product_id')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id as product_id, SUM(transaction_items.total_price) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->pluck('product_id');

        $products = Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->whereIn('id', $topIds)
            ->get();

        if ($products->count() < $limit) {
            $products = $products->concat(
                Product::query()
                    ->whereIn('store_id', $storeIds)
                    ->where('is_active', true)
                    ->whereNotIn('id', $products->pluck('id'))
                    ->orderBy('name')
                    ->limit($limit - $products->count())
                    ->get()
            );
        }

        return $products->values();
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
     * Rata-rata harga jual produk sekategori di toko ini (pembanding internal).
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

    /** Pembulatan harga ke atas: kelipatan 500 (< Rp100rb) atau 1000 (>= Rp100rb). */
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
            // Untung terhadap modal (markup) — dasar target tool ini.
            'current_markup_pct' => $hpp !== null && $hpp > 0 ? round(($currentPrice - $hpp) / $hpp * 100, 1) : null,
            // Margin terhadap harga jual — definisi yang dipakai laporan laba.
            'current_margin_pct' => $hpp !== null && $currentPrice > 0 ? round(($currentPrice - $hpp) / $currentPrice * 100, 1) : null,
            'market_price' => $marketPrice,
            'market_source' => $marketSource,
            'price_from_margin' => null,
            'suggested_price' => null,
            'price_gap' => null,
            'status' => 'no_cost',
            'advice' => '',
        ];

        if ($hpp === null) {
            $row['advice'] = 'HPP belum tercatat — isi harga modal/average cost dulu agar rekomendasi akurat.';

            return $row;
        }

        // Harga minimum agar untung sesuai target.
        $minimum = $this->roundPrice($hpp * (1 + $marginPct / 100));
        $row['price_from_margin'] = round($minimum);

        if ($currentPrice >= $minimum) {
            // Sudah memenuhi target: pertahankan. Menurunkan harga hanya karena
            // untung melebihi target akan langsung memotong laba.
            $row['suggested_price'] = round($currentPrice);
            $row['price_gap'] = 0.0;
            $row['status'] = $currentPrice <= $minimum * 1.02 ? 'ok' : 'above_target';
            $row['advice'] = $row['status'] === 'ok'
                ? 'Harga sudah sesuai — untung di sekitar target '.AiMetrics::number($marginPct).'% dari modal.'
                : 'Harga sudah di atas target (untung '.AiMetrics::number($row['current_markup_pct']).'% dari modal) — pertahankan, tidak perlu diturunkan.';

            return $row;
        }

        $suggested = $minimum;
        $capped = false;

        // Kenaikan bertahap: jangan melompat jauh di atas harga yang biasa
        // dibayar pelanggan, tapi tidak pernah menyarankan harga di bawah modal.
        if ($marketSource === 'historis' && $minimum > $marketPrice * (1 + self::MARKET_PREMIUM_CAP_PCT / 100)) {
            $suggested = max(
                $this->roundPrice($marketPrice * (1 + self::MARKET_PREMIUM_CAP_PCT / 100)),
                $this->roundPrice($hpp)
            );
            $capped = $suggested < $minimum;
        }

        $suggested = max($suggested, $currentPrice);

        $row['suggested_price'] = round($suggested);
        $row['price_gap'] = round($suggested - $currentPrice);
        $row['status'] = $currentPrice > 0 && $currentPrice < $hpp ? 'below_cost' : 'below_target';

        if ($currentPrice <= 0) {
            $row['advice'] = 'Harga jual belum diisi — pasang minimal sesuai saran di atas.';
        } elseif ($row['status'] === 'below_cost') {
            $row['advice'] = 'Dijual DI BAWAH modal (rugi '.$this->formatCurrency($hpp - $currentPrice).' per unit) — naikkan harga atau tekan harga beli.';
        } else {
            $row['advice'] = 'Harga kini di bawah target untung '.AiMetrics::number($marginPct).'% dari modal — pertimbangkan naik.';
        }

        if ($capped) {
            $row['advice'] .= ' Kenaikan dibatasi bertahap (maks. '.AiMetrics::number(self::MARKET_PREMIUM_CAP_PCT)
                .'% di atas harga jual rata-rata 90 hari); target penuhnya '.$this->formatCurrency($minimum).'.';
        }

        return $row;
    }

    protected function summaryMessage(array $data, bool $allBranches, int $storeCount, bool $filtered): string
    {
        $lines = [
            '💡 Rekomendasi harga jual — target untung/margin target **'.AiMetrics::number($data['target_margin_pct']).'%** dari HPP (modal)'
                .($allBranches ? " (semua cabang — {$storeCount} outlet)" : '').':',
        ];

        if (! $filtered) {
            $counts = $data['counts'];
            $lines[] = '';
            $lines[] = "Dari {$data['checked']} produk yang diperiksa (omzet terbesar 90 hari terakhir): "
                ."**{$counts['below_cost']}** dijual di bawah modal, **{$counts['below_target']}** di bawah target, "
                ."{$counts['ok']} sudah memenuhi target, {$counts['no_cost']} belum punya HPP."
                .($data['checked'] > $data['total'] ? " Ditampilkan {$data['total']} yang paling perlu ditindak." : '');
        }

        if ($data['target_is_default']) {
            $lines[] = '';
            $lines[] = 'Catatan: target '.AiMetrics::number($data['target_margin_pct']).'% adalah angka bawaan, bukan patokan pasar untuk jenis usaha Anda. Sebutkan target sendiri bila berbeda, mis. "saran harga dengan untung 15%".';
        }

        foreach ($data['products'] as $row) {
            $lines[] = '';
            $lines[] = '**'.$row['name'].'**'.($row['sku'] ? ' ('.$row['sku'].')' : '');

            if ($row['suggested_price'] === null) {
                $lines[] = '- '.$row['advice'];

                continue;
            }

            $reference = $row['market_price'] !== null
                ? 'harga acuan ±'.$this->formatCurrency((float) $row['market_price']).' ('.$row['market_source'].')'
                : 'harga acuan belum ada data';

            $lines[] = '- HPP '.$this->formatCurrency((float) $row['hpp'])
                .' · harga kini '.$this->formatCurrency((float) $row['current_price'])
                .' (untung '.AiMetrics::number($row['current_markup_pct']).'% dari modal, margin '.AiMetrics::number($row['current_margin_pct']).'% dari harga jual) · '.$reference;
            $lines[] = '- Harga minimum sesuai target: '.$this->formatCurrency((float) $row['price_from_margin'])
                .' · Saran jual: **'.$this->formatCurrency((float) $row['suggested_price']).'**'
                .($row['price_gap'] > 0
                    ? ' → naik '.$this->formatCurrency((float) $row['price_gap'])
                    : ($row['status'] === 'ok' ? ' → harga sudah pas' : ' → pertahankan'));
            $lines[] = '- '.$row['advice'];
        }

        return implode("\n", $lines);
    }
}
