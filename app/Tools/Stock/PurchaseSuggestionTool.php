<?php

declare(strict_types=1);

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\AiStoreScope;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

/**
 * Daftar belanja siap kirim ke supplier: kelompokkan produk yang perlu restock per supplier
 * beserta harga beli terakhir (untuk negosiasi) dan total per supplier.
 */
class PurchaseSuggestionTool extends AiTool
{
    public function getName(): string
    {
        return 'purchase_suggestion';
    }

    public function getDescription(): string
    {
        return 'Buat daftar belanja siap kirim ke supplier: kelompokkan produk perlu restock berdasarkan supplier, sertakan harga beli terakhir untuk negosiasi, dan hitung total per supplier. Gunakan saat user ingin "daftar belanja supplier", "belanja ke supplier apa", "siapkan order ke supplier", atau "total belanja per supplier".';
    }

    public function getParameters(): array
    {
        return [
            'days' => [
                'type' => 'integer',
                'description' => 'Periode analisis laju penjualan dalam hari (default: 30)',
            ],
            'coverage_days' => [
                'type' => 'integer',
                'description' => 'Target stok harus cukup untuk berapa hari ke depan (default: 14)',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Jumlah maksimal produk yang direkomendasikan (default: 20)',
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

        $days = max(1, min(365, (int) ($args['days'] ?? 30)));
        $coverageDays = max(1, min(365, (int) ($args['coverage_days'] ?? 14)));
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $since = Carbon::now()->subDays($days);

        $soldPerProduct = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->whereIn('transactions.store_id', $storeIds)
            ->where('transactions.status', 'completed')
            ->where('transactions.created_at', '>=', $since)
            ->whereNotNull('transaction_items.product_id')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id as product_id, COALESCE(SUM(transaction_items.quantity), 0) as sold_total')
            ->pluck('sold_total', 'product_id');

        $products = Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->with(['productCategory', 'store'])
            ->get();

        $productIds = $products->pluck('id');

        // Ambil PurchaseLine terakhir per produk (untuk supplier + harga beli).
        $lastPurchaseLines = PurchaseLine::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchase', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->with('purchase.supplier')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($lines) => $lines->sortBy('id')->last());

        $items = [];

        foreach ($products as $product) {
            $sold = (float) ($soldPerProduct[$product->id] ?? 0);
            $avgPerDay = $sold / $days;
            $stock = (float) $product->stock_quantity;
            $minStock = (float) $product->min_stock_level;

            $daysLeft = $avgPerDay > 0 ? (int) floor($stock / $avgPerDay) : null;
            $belowMin = $stock <= $minStock;

            if (! $belowMin && ($daysLeft === null || $daysLeft > $coverageDays)) {
                continue;
            }

            $suggested = (int) ceil(max($avgPerDay * $coverageDays - $stock, $belowMin ? $minStock - $stock : 0));

            if ($suggested <= 0) {
                continue;
            }

            $unitCost = (float) ($product->average_cost ?: $product->cost_price);
            $lastLine = $lastPurchaseLines[$product->id] ?? null;
            $supplierName = $lastLine?->purchase?->supplier?->name;
            $lastPurchasePrice = $lastLine ? (float) $lastLine->unit_cost : null;

            $items[] = [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->productCategory?->name ?? $product->category ?? '-',
                'stock' => round($stock, 2),
                'min_stock_level' => round($minStock, 2),
                'unit' => $product->unit ?? 'pcs',
                'avg_sales_per_day' => round($avgPerDay, 2),
                'days_left' => $daysLeft,
                'suggested_quantity' => $suggested,
                'estimated_cost' => round($suggested * $unitCost),
                'last_purchase_price' => $lastPurchasePrice !== null ? round($lastPurchasePrice) : null,
                'supplier' => $supplierName,
                'reason' => $belowMin ? 'stok di bawah minimum' : "diprediksi habis dalam {$daysLeft} hari",
            ];
        }

        // Urutkan: paling mendesak dulu, lalu ambil limit.
        usort($items, fn (array $a, array $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));
        $items = array_slice($items, 0, $limit);

        if ($items === []) {
            return ToolResult::success(
                data: ['suppliers' => [], 'items' => [], 'total_items' => 0, 'grand_total' => 0],
                message: 'Semua stok masih aman — tidak ada produk yang perlu dibeli saat ini.'
            );
        }

        // Kelompokkan per supplier.
        $grouped = [];
        $supplierTotals = [];

        foreach ($items as $item) {
            $supplierKey = $item['supplier'] ?? 'Tanpa Supplier';
            $grouped[$supplierKey][] = $item;
            $supplierTotals[$supplierKey] = ($supplierTotals[$supplierKey] ?? 0) + $item['estimated_cost'];
        }

        // Urutkan supplier: total terbesar dulu.
        arsort($supplierTotals);

        $suppliers = [];
        foreach ($supplierTotals as $supplierName => $total) {
            $suppliers[] = [
                'name' => $supplierName,
                'items' => $grouped[$supplierName],
                'item_count' => count($grouped[$supplierName]),
                'total_estimated_cost' => round($total),
            ];
        }

        $grandTotal = (float) array_sum($supplierTotals);

        return ToolResult::success(
            data: [
                'suppliers' => $suppliers,
                'items' => $items,
                'total_items' => count($items),
                'grand_total' => round($grandTotal),
                'period_days' => $days,
                'coverage_days' => $coverageDays,
            ],
            message: $this->buildSummary($suppliers, $grandTotal, $coverageDays)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $suppliers
     */
    private function buildSummary(array $suppliers, float $grandTotal, int $coverageDays): string
    {
        $lines = [
            'Daftar belanja supplier (stok cukup **'.$coverageDays.' hari ke depan**):',
            '',
        ];

        $displayedItems = 0;
        $maxItems = 10;

        foreach ($suppliers as $group) {
            $lines[] = '**'.$group['name'].'** — '.$group['item_count'].' item, total '.$this->formatCurrency((float) $group['total_estimated_cost']).':';

            foreach ($group['items'] as $item) {
                if ($displayedItems >= $maxItems) {
                    break;
                }

                $lastPrice = $item['last_purchase_price'] !== null
                    ? ' (harga beli terakhir '.$this->formatCurrency((float) $item['last_purchase_price']).')'
                    : '';

                $lines[] = '- '.$item['name']
                    .' — beli **'.$item['suggested_quantity'].' '.$item['unit']
                    .'** sekitar '.$this->formatCurrency((float) $item['estimated_cost'])
                    .$lastPrice;

                $displayedItems++;
            }

            $lines[] = '';
        }

        $lines[] = '**Total semua supplier: '.$this->formatCurrency($grandTotal).'**';
        $lines[] = 'Buatkan pesanan pembelian langsung dengan perintah "beli [produk] [jumlah] dari supplier [nama]".';

        return implode("\n", $lines);
    }
}
