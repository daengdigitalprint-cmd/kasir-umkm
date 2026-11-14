<?php

namespace App\Services\AI;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laju pemakaian stok per produk untuk prediksi habis & rekomendasi restock.
 *
 * Dua hal yang sebelumnya membuat rekomendasi meleset:
 * 1. Bahan baku/kemasan tidak pernah muncul di transaction_items — mereka
 *    terpakai lewat resep/komponen (stock_movements bertipe sale atau
 *    production_consumption). Tanpa itu, bahan baku tampak "tidak bergerak".
 * 2. Produk yang baru ada beberapa hari dibagi rata ke seluruh jendela
 *    (mis. 30 hari), sehingga lajunya tampak jauh lebih lambat dari kenyataan.
 */
class AiStockVelocity
{
    /** Produk baru minimal dianggap berjalan sekian hari agar tidak diekstrapolasi dari 1–2 hari. */
    public const MIN_ACTIVE_DAYS = 7;

    /**
     * Total keluar per produk dalam jendela waktu.
     *
     * Diambil yang terbesar antara penjualan langsung (transaction_items) dan
     * pergerakan stok keluar, supaya produk jual maupun bahan baku tercakup
     * tanpa terhitung dua kali.
     *
     * @param  array<int, int>  $storeIds
     * @return Collection<int, float> product_id => jumlah keluar
     */
    public static function usage(array $storeIds, Carbon $since, ?Carbon $until = null): Collection
    {
        $until ??= Carbon::now();

        $sold = AiMetrics::itemsQuery($storeIds, $since, $until)
            ->whereNotNull('transaction_items.product_id')
            ->groupBy('transaction_items.product_id')
            ->selectRaw('transaction_items.product_id as product_id, COALESCE(SUM(transaction_items.quantity), 0) as total')
            ->pluck('total', 'product_id');

        $moved = DB::table('stock_movements')
            ->whereIn('store_id', $storeIds)
            ->whereIn('type', [StockMovement::TYPE_SALE, StockMovement::TYPE_PRODUCTION_CONSUMPTION])
            ->whereBetween('created_at', [$since, $until])
            ->where('quantity_change', '<', 0)
            ->groupBy('product_id')
            ->selectRaw('product_id, COALESCE(SUM(-quantity_change), 0) as total')
            ->pluck('total', 'product_id');

        $usage = collect();

        foreach ($sold->keys()->merge($moved->keys())->unique() as $productId) {
            $usage[(int) $productId] = max((float) ($sold[$productId] ?? 0), (float) ($moved[$productId] ?? 0));
        }

        return $usage;
    }

    /**
     * Jumlah hari pembagi untuk rata-rata harian: seluruh jendela, kecuali
     * produk yang umurnya lebih pendek dari jendela itu.
     */
    public static function activeDays(Product $product, int $windowDays, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $createdAt = $product->created_at;

        if (! $createdAt) {
            return $windowDays;
        }

        $age = (int) round($createdAt->diffInSeconds($now, true) / 86400);

        return max(1, min($windowDays, max(self::MIN_ACTIVE_DAYS, $age)));
    }

    /**
     * Produk yang benar-benar perlu perhatian stok, untuk digest & alert:
     * - punya stok minimum dan stoknya sudah di/bawah minimum, atau
     * - stoknya habis DAN masih terpakai/terjual dalam `$usageDays` hari terakhir.
     *
     * Produk "sisa 0 (min 0)" yang tidak pernah bergerak tidak dilaporkan —
     * itu bukan stok menipis, hanya produk tanpa stok yang memang tidak dijual.
     *
     * @return array{items: Collection<int, Product>, total: int}
     */
    public static function lowStock(int $storeId, int $limit, int $usageDays = 30): array
    {
        $usedIds = self::usage([$storeId], Carbon::now()->subDays($usageDays))
            ->filter(fn (float $used) => $used > 0)
            ->keys()
            ->all();

        $query = self::trackedProducts([$storeId])
            ->where(function ($q) use ($usedIds) {
                $q->where(fn ($min) => $min->where('min_stock_level', '>', 0)->whereColumn('stock_quantity', '<=', 'min_stock_level'))
                    ->orWhere(fn ($out) => $out->where('stock_quantity', '<=', 0)->whereIn('id', $usedIds));
            });

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('stock_quantity')->orderBy('name')->limit($limit)->get(),
        ];
    }

    /** Stok yang dilacak dan bisa habis (bukan jasa / stok tak terbatas). */
    public static function trackedProducts(array $storeIds): \Illuminate\Database\Eloquent\Builder
    {
        return Product::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where(fn ($q) => $q->where('unlimited_stock', false)->orWhereNull('unlimited_stock'));
    }
}
