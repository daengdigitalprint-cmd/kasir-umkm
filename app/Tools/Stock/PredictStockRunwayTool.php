<?php

namespace App\Tools\Stock;

use App\Models\Product;
use App\Models\User;
use App\Services\AI\AiMetrics;
use App\Services\AI\AiPeriod;
use App\Services\AI\AiStockVelocity;
use App\Tools\AiTool;
use App\Tools\ToolResult;
use Illuminate\Support\Carbon;

class PredictStockRunwayTool extends AiTool
{
    protected const MAX_ROWS = 30;

    public function getName(): string
    {
        return 'predict_stock_runway';
    }

    public function getDescription(): string
    {
        return 'Memprediksi kapan stok produk akan habis berdasarkan rata-rata pemakaian harian (penjualan langsung maupun bahan baku yang terpakai lewat resep/produksi). Gunakan saat user bertanya "stok habis kapan", "kapan harus restock", "produk mana yang akan habis", atau prediksi/peringatan dini stok.';
    }

    public function getParameters(): array
    {
        return [
            'product_name' => [
                'type' => 'string',
                'description' => 'Nama produk spesifik yang ingin diprediksi (opsional, pencarian sebagian nama)',
            ],
            'days' => [
                'type' => 'integer',
                'description' => 'Periode analisis pemakaian dalam hari (default: 30)',
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
        $productName = trim((string) ($args['product_name'] ?? ''));
        $now = Carbon::now();

        $usage = AiStockVelocity::usage([$store->id], $now->copy()->subDays($days), $now);

        $query = AiStockVelocity::trackedProducts([$store->id]);

        if ($productName !== '') {
            $query->where('name', 'like', "%{$productName}%");
        }

        $products = $query->with('productCategory')->get();
        $today = AiPeriod::now($store);

        $all = $products->map(function (Product $product) use ($usage, $days, $now, $today) {
            $used = (float) ($usage[$product->id] ?? 0);
            $activeDays = AiStockVelocity::activeDays($product, $days, $now);
            $avgPerDay = $used / $activeDays;
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
                'stock' => $this->formatStock((string) $product->stock_quantity),
                'unit' => $product->unit ?? 'pcs',
                'sold_last_'.$days.'_days' => $this->formatStock((string) $used),
                'usage_days_basis' => $activeDays,
                'avg_per_day' => round($avgPerDay, 2),
                'days_left' => $daysLeft,
                'estimated_out_of_stock_date' => $daysLeft !== null
                    ? $today->copy()->addDays($daysLeft)->format('d/m/Y')
                    : null,
                'urgency' => $this->urgency($product, $daysLeft),
            ];
        });

        $noUsage = $all->filter(fn (array $item) => $item['days_left'] === null);

        $items = $all
            ->filter(fn (array $item) => $item['days_left'] !== null && $item['days_left'] <= $warningDays)
            ->sortBy('days_left')
            ->values();

        if ($items->isEmpty()) {
            $message = match (true) {
                $productName !== '' && $products->isEmpty() => "Tidak ditemukan produk berstok dengan nama \"{$productName}\".",
                $productName !== '' && $noUsage->count() === $all->count() => "Tidak ada produk \"{$productName}\" yang terjual/terpakai dalam {$days} hari terakhir, jadi tanggal habisnya belum bisa diperkirakan (stok sekarang: "
                    .$all->take(3)->map(fn (array $item) => "{$item['name']} {$item['stock']} {$item['unit']}")->implode(', ').').',
                $usage->isEmpty() => "Tidak ada produk yang bisa diprediksi: belum ada penjualan/pemakaian stok tercatat dalam {$days} hari terakhir.",
                default => "Tidak ada produk yang diprediksi habis dalam {$warningDays} hari ke depan (analisis {$days} hari terakhir, {$all->count()} produk berstok diperiksa)",
            };

            return ToolResult::success(
                data: ['items' => [], 'period_days' => $days, 'warning_days' => $warningDays, 'products_checked' => $all->count(), 'products_without_usage' => $noUsage->count()],
                message: $message
            );
        }

        $critical = $items->filter(fn (array $item) => $item['urgency'] === 'kritis' || $item['urgency'] === 'habis')->count();
        $shown = $items->take(self::MAX_ROWS)->values();

        $lines = [
            "{$items->count()} produk diprediksi habis dalam {$warningDays} hari ke depan"
                .($critical > 0 ? " ({$critical} di antaranya kritis)" : '')
                ." — berdasarkan pemakaian {$days} hari terakhir:",
            '',
        ];

        foreach ($shown->take(10) as $item) {
            $lines[] = '- **'.$item['name'].'** — sisa '.$item['stock'].' '.$item['unit']
                .', rata-rata '.AiMetrics::number($item['avg_per_day'], 2).'/hari → '
                .($item['days_left'] <= 0 ? '**sudah habis**' : "habis ±{$item['days_left']} hari lagi ({$item['estimated_out_of_stock_date']})")
                ." [{$item['urgency']}]";
        }

        if ($items->count() > 10) {
            $lines[] = '- …dan '.($items->count() - 10).' produk lainnya.';
        }

        return ToolResult::success(
            data: [
                'items' => $shown,
                'total' => $items->count(),
                'critical_count' => $critical,
                'period_days' => $days,
                'warning_days' => $warningDays,
                'products_checked' => $all->count(),
                'products_without_usage' => $noUsage->count(),
            ],
            message: implode("\n", $lines)
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
