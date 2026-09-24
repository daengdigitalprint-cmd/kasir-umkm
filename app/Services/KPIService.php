<?php

namespace App\Services;

use App\Models\KpiSnapshot;
use App\Models\KpiTarget;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\CacheKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class KPIService
{
    private const DEFAULT_GROWTH = 10.0;

    /**
     * Generate/update target otomatis untuk periode tertentu (YYYY-MM)
     * berdasarkan performa bulan sebelumnya + growth %.
     */
    public function generateAutoTarget(Store $store, string $period): KpiTarget
    {
        $targetMonth = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $previousMonth = $targetMonth->copy()->subMonth();

        // Growth %: pakai target bulan sebelumnya, atau default
        $previousTarget = KpiTarget::query()
            ->where('store_id', $store->id)
            ->where('period', $previousMonth->format('Y-m'))
            ->first();

        $growth = $previousTarget?->growth_percentage ?? self::DEFAULT_GROWTH;

        // Baseline performa bulan sebelumnya (satu query gabungan)
        $prevStats = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$previousMonth, $previousMonth->copy()->endOfMonth()])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(*) as transactions')
            ->first();

        $previousRevenue = (float) $prevStats->revenue;
        $previousTransactions = (int) $prevStats->transactions;

        $targetRevenue = $previousRevenue > 0
            ? round($previousRevenue * (1 + ((float) $growth / 100)), 2)
            : null;
        $targetTransactions = $previousTransactions > 0
            ? (int) round($previousTransactions * (1 + ((float) $growth / 100)))
            : null;

        return KpiTarget::updateOrCreate(
            [
                'store_id' => $store->id,
                'period' => $period,
            ],
            [
                'growth_percentage' => $growth,
                'target_revenue' => $targetRevenue,
                'target_transactions' => $targetTransactions,
                'baseline_data' => [
                    'revenue' => $previousRevenue,
                    'transactions' => $previousTransactions,
                ],
                'auto_generated' => true,
            ]
        );
    }

    /**
     * Hitung KPI bulanan untuk outlet & periode tertentu.
     *
     * Hasil laporan di-cache 5 menit (REPORT_TTL_SECONDS). Saat transaksi
     * berubah, TransactionObserver langsung mem-flush cache via version bump,
     * sehingga dashboard selalu menampilkan data terkini.
     *
     * @return array<string, mixed>
     */
    public function calculateMonthlyKPI(Store $store, string $period): array
    {
        $key = CacheKeys::kpiMonthly((int) $store->id, $period);

        return Cache::remember($key, CacheKeys::REPORT_TTL_SECONDS, function () use ($store, $period) {
            return $this->calculateMonthlyKPIFresh($store, $period);
        });
    }

    /**
     * Perhitungan KPI aktual (dipanggil saat cache kosong/kedaluwarsa).
     *
     * @return array<string, mixed>
     */
    private function calculateMonthlyKPIFresh(Store $store, string $period): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Satu query gabungan: revenue, jumlah transaksi, & pelanggan aktif
        $stats = \App\Models\Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as revenue,
                COUNT(*) as transactions,
                COUNT(DISTINCT customer_id) as active_customers
            ')
            ->first();

        $revenue = (float) $stats->revenue;
        $transactions = (int) $stats->transactions;
        $activeCustomers = (int) $stats->active_customers;
        $avgTransaction = $transactions > 0 ? $revenue / $transactions : 0;

        // Hitung HPP (COGS) dan Gross Profit Margin
        $cogs = (float) \App\Models\TransactionItem::query()
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$monthStart, $monthEnd])
            ->sum(\Illuminate\Support\Facades\DB::raw('transaction_items.quantity * transaction_items.unit_cost_snapshot'));

        $grossProfit = $revenue - $cogs;
        $grossMarginPercentage = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

        $newCustomers = \App\Models\Customer::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        // Periode sebelumnya untuk growth rate
        $prevStart = $monthStart->copy()->subMonth();
        $prevEnd = $monthStart->copy()->subDay();
        $prevRevenue = (float) $this->revenueQuery($store, $prevStart, $prevEnd);
        $growthRate = $prevRevenue > 0 ? (($revenue - $prevRevenue) / $prevRevenue) * 100 : null;

        // Trend harian (isi semua hari periode, default 0)
        $dailyRaw = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('DATE(created_at) as day_key, SUM(total_amount) as revenue')
            ->groupBy('day_key')
            ->pluck('revenue', 'day_key');

        $dailyTrend = [];
        $cursor = $monthStart->copy();
        while ($cursor->lte($monthEnd)) {
            $key = $cursor->format('Y-m-d');
            $dailyTrend[] = [
                'date' => $key,
                'revenue' => (float) ($dailyRaw[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return [
            'revenue' => $revenue,
            'transactions' => $transactions,
            'avg_transaction_value' => round($avgTransaction, 2),
            'gross_margin_percentage' => round($grossMarginPercentage, 1),
            'active_customers' => $activeCustomers,
            'new_customers' => $newCustomers,
            'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            'daily_trend' => $dailyTrend,
        ];
    }

    /**
     * Bandingkan performa aktual dengan target periode.
     *
     * @return array<string, mixed>
     */
    public function compareWithTarget(Store $store, string $period): array
    {
        $target = KpiTarget::query()
            ->where('store_id', $store->id)
            ->where('period', $period)
            ->first();

        if (! $target) {
            $target = $this->generateAutoTarget($store, $period);
        }

        $kpi = $this->calculateMonthlyKPI($store, $period);

        $revenueTarget = (float) ($target->target_revenue ?? 0);
        $transactionsTarget = (int) ($target->target_transactions ?? 0);

        $revenueProgress = $revenueTarget > 0
            ? round((($kpi['revenue'] / $revenueTarget) * 100), 1)
            : 0;
        $transactionsProgress = $transactionsTarget > 0
            ? round((($kpi['transactions'] / $transactionsTarget) * 100), 1)
            : 0;

        $status = match (true) {
            $revenueProgress >= 100 => 'exceeded',
            $revenueProgress >= 80 => 'on-track',
            default => 'behind',
        };

        return [
            'target_revenue' => $revenueTarget,
            'target_transactions' => $transactionsTarget,
            'growth_percentage' => (float) $target->growth_percentage,
            'revenue_progress' => $revenueProgress,
            'transactions_progress' => $transactionsProgress,
            'status' => $status,
        ];
    }

    /**
     * Buat snapshot harian untuk outlet (dipanggil scheduled job / manual).
     */
    public function createDailySnapshot(Store $store): KpiSnapshot
    {
        $date = Carbon::today();
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        // Satu query gabungan untuk revenue, transaksi, & pelanggan aktif hari ini
        $stats = Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as revenue,
                COUNT(*) as transactions,
                COUNT(DISTINCT customer_id) as active_customers
            ')
            ->first();

        $revenue = (float) $stats->revenue;
        $transactions = (int) $stats->transactions;
        $activeCustomers = (int) $stats->active_customers;

        $newCustomers = \App\Models\Customer::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        return KpiSnapshot::updateOrCreate(
            [
                'store_id' => $store->id,
                'snapshot_date' => $date->format('Y-m-d'),
            ],
            [
                'metrics' => [
                    'revenue' => $revenue,
                    'transactions_count' => $transactions,
                    'avg_transaction_value' => $transactions > 0 ? round($revenue / $transactions, 2) : 0,
                    'active_customers' => $activeCustomers,
                    'new_customers' => $newCustomers,
                ],
            ]
        );
    }

    /**
     * Ambil daftar store aktif (helper untuk command snapshot).
     */
    public function activeStores()
    {
        return Store::query()->where('is_active', true)->get();
    }

    /**
     * Ambil Top 10 produk terlaris berdasarkan quantity dan revenue
     */
    public function getTopProducts(Store $store, string $period, int $limit = 10): array
    {
        $key = CacheKeys::kpiTopProducts((int) $store->id, $period, $limit);

        return Cache::remember($key, CacheKeys::REPORT_TTL_SECONDS, function () use ($store, $period, $limit) {
            return $this->getTopProductsFresh($store, $period, $limit);
        });
    }

    /**
     * Query Top Produk aktual (dipanggil saat cache kosong/kedaluwarsa).
     *
     * @return array<int, array{id: int|string, name: string, quantity: float, revenue: float}>
     */
    private function getTopProductsFresh(Store $store, string $period, int $limit): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return \App\Models\TransactionItem::query()
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw('products.id, products.name, SUM(transaction_items.quantity) as total_quantity, SUM(transaction_items.quantity * transaction_items.unit_price) as total_revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => (float) $item->total_quantity,
                    'revenue' => (float) $item->total_revenue,
                ];
            })
            ->all();
    }

    /**
     * Dapatkan Operational Alerts: stok menipis, expired (jika ada), slow moving
     */
    public function getOperationalAlerts(Store $store): array
    {
        // 1. Low stock count
        $lowStockCount = \App\Models\Product::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('track_stock', true)
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->count();

        // 2. Slow moving products (misal: terjual < 5 dalam 30 hari terakhir)
        $thirtyDaysAgo = now()->subDays(30);
        $activeProductsCount = \App\Models\Product::query()
            ->where('store_id', $store->id)
            ->active()
            ->where('track_stock', true)
            ->count();

        // Ambil produk yang terjual dalam 30 hari
        $soldProductIds = \App\Models\TransactionItem::query()
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'completed')
            ->where('transactions.created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->having('total_qty', '>=', 5)
            ->pluck('product_id');

        $slowMovingCount = max(0, $activeProductsCount - $soldProductIds->count());

        return [
            'low_stock_count' => $lowStockCount,
            'near_expiry_count' => 0, // Belum ada batch tracking, diset 0 dulu
            'slow_moving_count' => $slowMovingCount,
        ];
    }

    private function revenueQuery(Store $store, Carbon $start, Carbon $end): float
    {
        return (float) Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount');
    }

    private function transactionsQuery(Store $store, Carbon $start, Carbon $end)
    {
        return Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end]);
    }
}
