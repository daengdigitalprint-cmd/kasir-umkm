<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\TransactionItem;
use App\Models\User;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class PredictStockRunwayTool extends AiTool
{
    public function getName(): string
    {
        return 'predict_stock_runway';
    }

    public function getDescription(): string
    {
        return 'Memprediksi kapan stok produk akan habis berdasarkan rata-rata penjualan harian (stock runway). Gunakan saat user bertanya "stok habis kapan", "kapan harus restock", "produk mana yang akan habis", atau prediksi/peringatan dini stok.';
    }

    public function getParameters(): array
    {
        return [
            'product_name' => [
                'type' => 'string',
                'description' => 'Nama produk spesifik yang ingin diprediksi (opsional, dicari dengan LIKE)',
            ],
            'days' => [
                'type' => 'integer',
                'description' => 'Periode analisis penjualan dalam hari (default: 30)',
            ],
            'warning_days' => [
                'type' => 'integer',
                'description' => 'Hanya tampilkan produk yang diprediksi habis dalam rentang ini (default: 14). Isi 9999 untuk menampilkan semua.',
            ],
        ];
    }

    public function execute(array $args, User $user): ToolResult
    {
        $store = $user->store;
        if (! $store) {
            return ToolResult::error('User tidak memiliki toko');
        }

        $days = max(1, min(365, (int) ($args['days'] ?? 30)));
        $warningDays = max(0, (int) ($args['warning_days'] ?? 14));
        $since = Carbon::now()->subDays($days);

        $soldPerProduct = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'completed')
            ->where('transactions.created_at', '>=', $since)
            ->whereNotNull('transaction_items.product_id')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id as product_id, COALESCE(SUM(transaction_items.quantity), 0) as sold_total')
            ->pluck('sold_total', 'product_id');

        $query = Product::where('store_id', $store->id)
            ->where('is_active', true)
            ->where('track_stock', true);

        if (! empty($args['product_name'])) {
            $query->where('name', 'like', "%{$args['product_name']}%");
        }

        $products = $query->with('productCategory')->get();

        $items = $products->map(function (Product $product) use ($soldPerProduct, $days) {
            $sold = (float) ($soldPerProduct[$product->id] ?? 0);
            $avgPerDay = $sold / $days;
            $stock = (float) $product->stock_quantity;

            $daysLeft = null;
            if ($avgPerDay > 0) {
                $daysLeft = $stock > 0 ? (int) floor($stock / $avgPerDay) : 0;
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->productCategory?->name ?? $product->category ?? '-',
                'stock' => $this->formatStock($product->stock_quantity),
                'unit' => $product->unit ?? 'pcs',
                'sold_last_'.$days.'_days' => $this->formatStock((string) $sold),
                'avg_per_day' => round($avgPerDay, 2),
                'days_left' => $daysLeft,
                'estimated_out_of_stock_date' => $daysLeft !== null
                    ? Carbon::now()->addDays($daysLeft)->format('d/m/Y')
                    : null,
                'urgency' => $this->urgency($product, $daysLeft),
            ];
        })->filter(function (array $item) use ($warningDays) {
            if ($item['days_left'] === null) {
                return false;
            }

            return $item['days_left'] <= $warningDays;
        })->sortBy('days_left')->values();

        if ($items->isEmpty()) {
            return ToolResult::success(
                data: ['items' => [], 'period_days' => $days, 'warning_days' => $warningDays],
                message: "Tidak ada produk yang diprediksi habis dalam {$warningDays} hari ke depan (analisis {$days} hari terakhir)"
            );
        }

        $critical = $items->filter(fn (array $item) => $item['urgency'] === 'kritis' || $item['urgency'] === 'habis')->count();

        return ToolResult::success(
            data: [
                'items' => $items,
                'total' => $items->count(),
                'critical_count' => $critical,
                'period_days' => $days,
                'warning_days' => $warningDays,
            ],
            message: "{$items->count()} produk diprediksi habis dalam {$warningDays} hari ke depan"
                .($critical > 0 ? " ({$critical} di antaranya kritis)" : '')
        );
    }

    protected function urgency(Product $product, ?int $daysLeft): string
    {
        if ((float) $product->stock_quantity <= 0) {
            return 'habis';
        }

        if ($daysLeft === null) {
            return 'aman';
        }

        if ($daysLeft <= 3) {
            return 'kritis';
        }

        if ($daysLeft <= 14) {
            return 'waspada';
        }

        return 'aman';
    }

    protected function formatStock(string $stock): string
    {
        if (! str_contains($stock, '.')) {
            return $stock;
        }

        return rtrim(rtrim($stock, '0'), '.');
    }
}
