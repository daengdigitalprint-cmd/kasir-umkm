<?php

namespace App\Http\Controllers;

use App\Models\TransactionItem;
use App\Support\BatchTracking;
use App\Support\ExpiringProductBatches;
use App\Support\FnBFeatures;
use App\Support\PharmacyFeatures;
use App\Support\ReceiptSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            return Inertia::render('Dashboard');
        }

        if (! $store->is_active) {
            return Inertia::render('Dashboard', [
                'store' => null,
                'storeInactive' => true,
                'storeName' => $store->name,
            ]);
        }

        // ─── Core stats ───────────────────────────────────────────
        $todaySales = $store->transactions()
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->sum('total_amount');

        $yesterdaySales = $store->transactions()
            ->whereDate('created_at', today()->subDay())
            ->where('status', 'completed')
            ->sum('total_amount');

        $lastMonthOmset = $store->transactions()
            ->where('status', 'completed')
            ->whereBetween('created_at', [now()->startOfMonth()->subMonth(), now()->endOfMonth()->subMonth()])
            ->sum('total_amount');

        $todayTransactions = $store->transactions()
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->count();

        $yesterdayTransactions = $store->transactions()
            ->whereDate('created_at', today()->subDay())
            ->where('status', 'completed')
            ->count();

        $recentTransactions = $store->transactions()
            ->with('customer')
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->latest()
            ->limit(5)
            ->get();

        // ─── Low stock ────────────────────────────────────────────
        $lowStockCount = $store->products()
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->count();

        $lowStockProducts = $store->products()
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->limit(5)
            ->get();

        // ─── Pending payment ───────────────────────────────────────
        $pendingPayment = $store->subscriptionPayments()
            ->where('status', 'pending')
            ->latest()
            ->first();

        // ─── Expiring batches ──────────────────────────────────────
        $expiringBatches = [];
        $batchExpiryWarnDays = null;
        if (BatchTracking::enabled($store)) {
            $warnDays = BatchTracking::expiryWarnDays($store);
            $batchExpiryWarnDays = $warnDays;
            $expiringBatches = ExpiringProductBatches::alertPayload($store, 10);
        }

        // ─── Pending kitchen orders ───────────────────────────────
        $pendingKitchenOrderCount = $store->queueEntries()
            ->whereDate('created_at', today())
            ->whereIn('status', ['waiting', 'serving'])
            ->count();

        // ─── Dashboard insight data (realtime — di-query langsung saat render) ──
        $insights = $this->getDashboardInsights((int) $store->id);

        return Inertia::render('Dashboard', [
            'is_kitchen' => $user->getRoleForActiveStore() === 'kitchen',
            'pending_kitchen_order_count' => $pendingKitchenOrderCount,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'description' => $store->description,
                'currency' => $store->currency,
                'products_count' => $store->products()->count(),
                'customers_count' => $store->customers()->count(),
                'today_sales' => $todaySales,
                'today_transactions' => $todayTransactions,
                'yesterday_sales' => $yesterdaySales,
                'yesterday_transactions' => $yesterdayTransactions,
                'last_month_omset' => $lastMonthOmset,
                'subscription_end' => $store->subscription_end,
                'is_subscription_active' => $store->is_subscription_active,
                'subscription_days' => $store->subscription_days,
                'tax_name' => $store->tax_name ?? 'PPN',
                'receipt' => ReceiptSettings::payload($store),
                'pharmacy_enabled' => PharmacyFeatures::enabled($store),
                'fnb_enabled' => FnBFeatures::enabled($store),
            ],
            'recentTransactions' => $recentTransactions,
            'lowStockProducts' => $lowStockProducts,
            'lowStockCount' => $lowStockCount,
            'expiringBatches' => $expiringBatches,
            'batch_expiry_warn_days' => $batchExpiryWarnDays,
            'pendingPayment' => $pendingPayment,
            'weeklyTrend' => $insights['weekly_trend'],
            'peakHours' => $insights['peak_hours'],
            'topProducts' => $insights['top_products'],
        ]);
    }

    /**
     * Gather dashboard insight data: weekly trend, peak hours, top products.
     *
     * @return array{
     *     weekly_trend: array<int, array{date: string, revenue: float, count: int}>,
     *     peak_hours: array<int, array{hour: int, label: string, count: int, revenue: float}>,
     *     top_products: array<int, array{id: int, name: string, quantity: float, revenue: float}>
     * }
     */
    private function getDashboardInsights(int $storeId): array
    {
        // ─── Weekly trend (last 7 days) ───────────────────────────
        $weeklyRaw = DB::table('transactions')
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [now()->subDays(6)->startOfDay(), now()->endOfDay()])
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as revenue, COUNT(*) as count')
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $row = $weeklyRaw->get($day);
            $weeklyTrend[] = [
                'date' => $day,
                'revenue' => $row ? (float) $row->revenue : 0,
                'count' => $row ? (int) $row->count : 0,
            ];
        }

        // ─── Peak hours (this month) ────────────────────────────
        $hourExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $peakRaw = DB::table('transactions')
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw("{$hourExpr} as hour, COUNT(*) as count, SUM(total_amount) as revenue")
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $peakHours = [];
        for ($h = 7; $h <= 22; $h++) {
            $row = $peakRaw->get($h);
            $peakHours[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $row ? (int) $row->count : 0,
                'revenue' => $row ? (float) $row->revenue : 0,
            ];
        }

        // ─── Top 5 products (this month) ─────────────────────────
        $topProducts = TransactionItem::query()
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.store_id', $storeId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('products.id, products.name, SUM(transaction_items.quantity) as total_qty, SUM(transaction_items.quantity * transaction_items.unit_price) as total_revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => (float) $item->total_qty,
                'revenue' => (float) $item->total_revenue,
            ])
            ->all();

        return [
            'weekly_trend' => $weeklyTrend,
            'peak_hours' => $peakHours,
            'top_products' => $topProducts,
        ];
    }
}
