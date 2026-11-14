<?php

namespace App\Services;

use App\Models\KpiSnapshot;
use App\Models\KpiTarget;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\CacheKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            ->selectRaw(self::NET_REVENUE_SQL.' as revenue, COUNT(*) as transactions')
            ->first();

        // Dikurangi retur supaya target disusun dari dasar yang sama dengan
        // realisasi; kalau tidak, progres target jadi terlihat lebih buruk.
        $previousRevenue = (float) $prevStats->revenue - $this->returnsQuery(
            $store,
            $previousMonth,
            $previousMonth->copy()->endOfMonth(),
        );
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
                '.self::NET_REVENUE_SQL.' as revenue,
                COUNT(*) as transactions,
                COUNT(DISTINCT customer_id) as active_customers
            ')
            ->first();

        $revenue = (float) $stats->revenue - $this->returnsQuery($store, $monthStart, $monthEnd);
        $transactions = (int) $stats->transactions;
        $activeCustomers = (int) $stats->active_customers;
        $avgTransaction = $transactions > 0 ? $revenue / $transactions : 0;

        // HPP & laba kotor. Keduanya kini dibandingkan terhadap penjualan
        // bersih (tanpa pajak), sehingga marginnya tidak lagi menggelembung.
        $cogsData = $this->cogsQuery($store, $monthStart, $monthEnd);
        $cogs = $cogsData['cogs'];

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
            ->selectRaw('DATE(created_at) as day_key, SUM(subtotal - discount_amount) as revenue')
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
            'cogs' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_percentage' => round($grossMarginPercentage, 1),
            // Persen penjualan yang harga modalnya diketahui. Margin di atas
            // hanya bisa dipercaya sejauh angka ini.
            'cogs_coverage' => $cogsData['coverage'],
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
                '.self::NET_REVENUE_SQL.' as revenue,
                COUNT(*) as transactions,
                COUNT(DISTINCT customer_id) as active_customers
            ')
            ->first();

        $revenue = (float) $stats->revenue - $this->returnsQuery($store, $start, $end);
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

    /**
     * Ekspresi SQL penjualan bersih.
     *
     * Sengaja BUKAN total_amount. total_amount dibentuk sebagai
     * subtotal - diskon + pajak + service charge + tip, sehingga memuat uang
     * yang bukan pendapatan usaha (PPN milik negara, tip milik karyawan).
     * Memakainya untuk margin membuat marginnya menggelembung, karena HPP
     * tidak mengandung pajak. Definisi ini sama dengan OwnerDashboardService.
     */
    private const NET_REVENUE_SQL = 'COALESCE(SUM(subtotal - discount_amount), 0)';

    /** Penjualan bersih setelah dikurangi retur. */
    private function revenueQuery(Store $store, Carbon $start, Carbon $end): float
    {
        $gross = (float) Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('subtotal - discount_amount'));

        return $gross - $this->returnsQuery($store, $start, $end);
    }

    /** Retur penjualan, dinilai sebelum pajak agar sebanding dengan penjualan. */
    private function returnsQuery(Store $store, Carbon $start, Carbon $end): float
    {
        return (float) DB::table('sales_returns')
            ->where('store_id', $store->id)
            ->whereBetween('returned_at', [$start, $end])
            ->sum(DB::raw('subtotal - discount_amount'));
    }

    /**
     * HPP beserta cakupannya.
     *
     * Tidak semua baris punya harga modal. Cakupan dihitung berbobot omzet
     * supaya menggambarkan seberapa besar bagian penjualan yang marginnya
     * benar-benar terhitung — tanpa ini, outlet tanpa harga modal tampak
     * bermargin 100%.
     *
     * @return array{cogs: float, coverage: float}
     */
    private function cogsQuery(Store $store, Carbon $start, Carbon $end): array
    {
        $row = DB::table('transaction_items as ti')
            ->join('transactions as t', 'ti.transaction_id', '=', 't.id')
            ->where('t.store_id', $store->id)
            ->where('t.status', 'completed')
            ->whereBetween('t.created_at', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(ti.quantity * COALESCE(ti.unit_cost_snapshot, 0)), 0) as cogs,
                COALESCE(SUM(CASE WHEN COALESCE(ti.unit_cost_snapshot, 0) > 0 THEN ti.total_price ELSE 0 END), 0) as covered,
                COALESCE(SUM(ti.total_price), 0) as total
            ')
            ->first();

        $total = (float) ($row->total ?? 0);

        return [
            'cogs' => (float) ($row->cogs ?? 0),
            'coverage' => $total > 0 ? round(((float) $row->covered / $total) * 100, 1) : 0.0,
        ];
    }

    private function transactionsQuery(Store $store, Carbon $start, Carbon $end)
    {
        return Transaction::query()
            ->where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end]);
    }
}
