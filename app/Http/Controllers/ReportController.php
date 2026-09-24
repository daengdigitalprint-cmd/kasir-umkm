<?php

namespace App\Http\Controllers;

use App\Enums\SalesChannel;
use App\Models\AccountingPeriod;
use App\Models\CashShift;
use App\Models\ChartOfAccount;
use App\Models\FinancialReportStatus;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Accounting\AccountingFeatures;
use App\Support\Accounting\ChartOfAccountsBootstrap;
use App\Support\Accounting\JournalPoster;
use App\Support\BatchTracking;
use App\Support\PharmacyFeatures;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * Gate modul akuntansi: semua endpoint laporan keuangan (financial.*)
     * 404 saat modul mati agar tidak bocor via URL langsung.
     */
    private function abortUnlessAccountingEnabled(): void
    {
        $user = auth()->user();
        $store = $user?->store;

        if (! $store || ! AccountingFeatures::enabled($store)) {
            abort(404);
        }
    }

    /**
     * Resolve store IDs untuk laporan.
     * Owner bisa pilih outlet tertentu atau semua outlet.
     * Role lain hanya lihat store aktif saja.
     */
    private function resolveStoreIds(User $user, Request $request): array
    {
        $store = $user->store;
        if (! $store) {
            return [];
        }

        // Owner bisa pilih outlet tertentu atau konsolidasi semua outlet.
        if ($user->isOwner()) {
            $owned = $user->stores()->pluck('stores.id')->toArray();

            $storeId = $request->integer('store_id');
            if ($storeId && in_array($storeId, $owned, true)) {
                return [$storeId];
            }

            // Tanpa store_id: konsolidasi seluruh outlet milik owner.
            return $owned;
        }

        return [$store->id];
    }

    /**
     * Validasi parameter tanggal laporan: format, start <= end.
     * Mengembalikan array ['start_date' => ..., 'end_date' => ...] dengan default.
     */
    private function validateDateRange(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        return [
            'start_date' => $validated['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d'),
            'end_date' => $validated['end_date'] ?? Carbon::now()->format('Y-m-d'),
        ];
    }

    /**
     * Validasi parameter tanggal `as_of`: format valid.
     */
    private function validateAsOf(Request $request): string
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $validated['as_of'] ?? Carbon::now()->format('Y-m-d');
    }

    /**
     * Daftar outlet yang bisa diakses user (untuk dropdown filter).
     */
    private function accessibleStoresData(User $user): array
    {
        return $user->stores()
            ->select('stores.id', 'stores.name', 'stores.slug')
            ->get()
            ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, request());
        $isMultiOutlet = count($storeIds) > 1;
        $pharmacyEnabled = PharmacyFeatures::enabled($store);

        // Get date range (validasi format & urutan)
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        // Sales summary — multi-outlet
        $totalSales = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->sum('total_amount');

        $totalTransactions = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->count();

        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        // Top products — multi-outlet
        $topProducts = \App\Models\Product::query()
            ->whereIn('store_id', $storeIds)
            ->withSum(['transactionItems as total_sold' => function ($query) use ($storeIds, $startDate, $endDate) {
                $query->whereHas('transaction', function ($q) use ($storeIds, $startDate, $endDate) {
                    $q->whereIn('store_id', $storeIds)
                        ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
                        ->where('status', 'completed');
                });
            }], 'quantity')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        // Recent transactions — multi-outlet
        $recentTransactions = Transaction::whereIn('store_id', $storeIds)
            ->with(['customer', 'store'])
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->latest()
            ->limit(10)
            ->get();

        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();
        $daysSpan = max(1, $rangeStart->diffInDays($rangeEnd) + 1);

        $prevRangeEnd = $rangeStart->copy()->subDay()->endOfDay();
        $prevRangeStart = $prevRangeEnd->copy()->subDays($daysSpan - 1)->startOfDay();

        $prevTotalSales = (float) Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$prevRangeStart, $prevRangeEnd])
            ->where('status', 'completed')
            ->sum('total_amount');

        $prevTotalTransactions = (int) Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$prevRangeStart, $prevRangeEnd])
            ->where('status', 'completed')
            ->count();

        $salesChangePct = $prevTotalSales > 0
            ? round((($totalSales - $prevTotalSales) / $prevTotalSales) * 100, 1)
            : null;

        $transactionsChangePct = $prevTotalTransactions > 0
            ? round((($totalTransactions - $prevTotalTransactions) / $prevTotalTransactions) * 100, 1)
            : null;

        $completedTxInRange = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->get(['created_at', 'total_amount', 'payment_method', 'discount_amount', 'tax_amount', 'sales_channel', 'commission_amount']);

        $totalsByDay = $completedTxInRange->groupBy(fn ($t) => $t->created_at->format('Y-m-d'))
            ->map(fn ($rows) => round((float) $rows->sum('total_amount'), 2));

        $dailyTotalsMap = [];
        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $key = $cursor->format('Y-m-d');
            $dailyTotalsMap[] = [
                'date' => $key,
                'total' => (float) ($totalsByDay[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        $salesTrend = $dailyTotalsMap;
        if (count($dailyTotalsMap) > 62) {
            $grouped = collect($dailyTotalsMap)->groupBy(fn ($row) => substr($row['date'], 0, 7));
            $salesTrend = $grouped->map(function ($rows, $month) {
                return [
                    'period' => $month,
                    'label' => Carbon::parse($month.'-01')->translatedFormat('M Y'),
                    'total' => round($rows->sum('total'), 2),
                ];
            })->values()->all();
        } else {
            $salesTrend = collect($dailyTotalsMap)->map(fn ($row) => [
                'period' => $row['date'],
                'label' => Carbon::parse($row['date'])->format('d/m'),
                'total' => $row['total'],
            ])->all();
        }

        $paymentBreakdown = $completedTxInRange->groupBy('payment_method')
            ->map(function ($rows, $method) {
                return [
                    'method' => $method ?: 'cash',
                    'transaction_count' => $rows->count(),
                    'total_amount' => round((float) $rows->sum('total_amount'), 2),
                ];
            })->values()->sortByDesc('total_amount')->values()->all();

        $channelBreakdown = $completedTxInRange->groupBy('sales_channel')
            ->map(function ($rows, $channel) {
                $enum = SalesChannel::tryFrom((string) $channel) ?? SalesChannel::WalkIn;
                $gross = (float) $rows->sum('total_amount');
                $commission = (float) $rows->sum('commission_amount');

                return [
                    'channel' => $enum->value,
                    'label' => $enum->label(),
                    'group' => $enum->group(),
                    'is_aggregator' => $enum->isAggregator(),
                    'transaction_count' => $rows->count(),
                    'gross_revenue' => round($gross, 2),
                    'commission_amount' => round($commission, 2),
                    'net_revenue' => round($gross - $commission, 2),
                ];
            })->values()->sortByDesc('gross_revenue')->values()->all();

        $channelOfflineTotal = collect($channelBreakdown)->where('group', 'offline')->sum('gross_revenue');
        $channelOnlineTotal = collect($channelBreakdown)->where('group', 'online')->sum('gross_revenue');
        $channelTotalCommission = collect($channelBreakdown)->sum('commission_amount');

        $categoryExpr = "CASE WHEN products.category IS NULL OR TRIM(products.category) = '' THEN 'Tanpa kategori' ELSE products.category END";

        $categoryRevenue = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->selectRaw("{$categoryExpr} as category, SUM(transaction_items.total_price) as revenue")
            ->groupByRaw($categoryExpr)
            ->orderByDesc('revenue')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();

        $itemsSold = (float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->sum('transaction_items.quantity');

        $totalDiscount = round((float) $completedTxInRange->sum('discount_amount'), 2);
        $totalTax = round((float) $completedTxInRange->sum('tax_amount'), 2);

        $totalFreeQuantity = round((float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->sum('transaction_items.free_quantity'), 4);

        $discountByType = DB::table('transactions')
            ->leftJoin('discounts', 'transactions.discount_id', '=', 'discounts.id')
            ->whereIn('transactions.store_id', $storeIds)
            ->whereBetween('transactions.created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('transactions.status', 'completed')
            ->where('transactions.discount_amount', '>', 0)
            ->selectRaw("COALESCE(discounts.type, 'manual') as type, SUM(transactions.discount_amount) as total")
            ->groupByRaw("COALESCE(discounts.type, 'manual')")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'total' => round((float) $row->total, 2),
            ])
            ->all();

        $topProductsForChart = $topProducts->take(8)->map(function ($p) {
            $sold = (float) ($p->total_sold ?? 0);

            return [
                'name' => $p->name,
                'total_sold' => $sold,
                'revenue' => round((float) $p->price * $sold, 2),
            ];
        })->values()->all();

        // Customer statistics
        $totalCustomers = \App\Models\Customer::whereIn('store_id', $storeIds)->count();
        $newCustomers = \App\Models\Customer::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->count();

        $prevNewCustomers = \App\Models\Customer::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$prevRangeStart, $prevRangeEnd])
            ->count();

        $newCustomersChangePct = $prevNewCustomers > 0
            ? round((($newCustomers - $prevNewCustomers) / $prevNewCustomers) * 100, 1)
            : null;

        return Inertia::render('Reports/Index', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'pharmacyEnabled' => $pharmacyEnabled,
            'accessibleStores' => $this->accessibleStoresData($user),
            'selectedStoreId' => count($storeIds) === 1 ? $storeIds[0] : null,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'comparison' => [
                'prev_start' => $prevRangeStart->format('Y-m-d'),
                'prev_end' => $prevRangeEnd->format('Y-m-d'),
                'prev_total_sales' => $prevTotalSales,
                'prev_total_transactions' => $prevTotalTransactions,
                'sales_change_pct' => $salesChangePct,
                'transactions_change_pct' => $transactionsChangePct,
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'total_transactions' => $totalTransactions,
                'average_order_value' => $averageOrderValue,
                'total_customers' => $totalCustomers,
                'new_customers' => $newCustomers,
                'items_sold' => $itemsSold,
                'total_discount' => $totalDiscount,
                'total_tax' => $totalTax,
                'total_free_quantity' => $totalFreeQuantity,
                'discount_by_type' => $discountByType,
            ],
            'new_customers_change_pct' => $newCustomersChangePct,
            'topProducts' => $topProducts,
            'topProductsChart' => $topProductsForChart,
            'recentTransactions' => $recentTransactions,
            'salesTrend' => $salesTrend,
            'paymentBreakdown' => $paymentBreakdown,
            'channelBreakdown' => $channelBreakdown,
            'channelOfflineTotal' => round($channelOfflineTotal, 2),
            'channelOnlineTotal' => round($channelOnlineTotal, 2),
            'channelTotalCommission' => round($channelTotalCommission, 2),
            'categoryRevenue' => $categoryRevenue,
        ]);
    }

    /**
     * Display sales report.
     */
    public function sales(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, $request);
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        $saleClass = $request->get('sale_class');
        $transactions = Transaction::whereIn('store_id', $storeIds)
            ->with(['customer', 'store', 'items.product', 'items.allocations'])
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->when($saleClass === 'prescription', fn ($q) => $q->whereJsonContains('payment_details->has_prescription_lines', true))
            ->when($saleClass === 'non_prescription', fn ($q) => $q->where(function ($q) {
                $q->whereNull('payment_details')->orWhereJsonDoesntContain('payment_details->has_prescription_lines', true);
            }))
            ->latest()
            ->paginate(20);

        // Summary HPP dari snapshot per item
        $hppSummary = \App\Models\TransactionItem::query()
            ->whereHas('transaction', function ($q) use ($storeIds, $startDate, $endDate) {
                $q->whereIn('store_id', $storeIds)
                    ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
                    ->where('status', 'completed');
            })
            ->selectRaw('
                COALESCE(SUM(total_price), 0) as total_revenue,
                COALESCE(SUM(total_cost_snapshot), 0) as total_cost,
                COALESCE(SUM(gross_profit_snapshot), 0) as total_gross_profit,
                COALESCE(SUM(CASE WHEN total_cost_snapshot IS NOT NULL THEN 1 ELSE 0 END), 0) as items_with_hpp
            ')
            ->first();

        $customers = \App\Models\Customer::whereIn('store_id', $storeIds)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        // Breakdown per sales channel (online vs offline + komisi aggregator).
        $salesChannelRows = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('sales_channel, COUNT(*) as tx_count, SUM(total_amount) as gross, SUM(commission_amount) as commission')
            ->groupBy('sales_channel')
            ->get();

        $channelBreakdown = $salesChannelRows->map(function ($row) {
            $rawChannel = $row->sales_channel instanceof SalesChannel
                ? $row->sales_channel->value
                : (string) $row->sales_channel;
            $enum = SalesChannel::tryFrom($rawChannel) ?? SalesChannel::WalkIn;
            $gross = (float) $row->gross;
            $commission = (float) $row->commission;

            return [
                'channel' => $enum->value,
                'label' => $enum->label(),
                'group' => $enum->group(),
                'is_aggregator' => $enum->isAggregator(),
                'transaction_count' => (int) $row->tx_count,
                'gross_revenue' => round($gross, 2),
                'commission_amount' => round($commission, 2),
                'net_revenue' => round($gross - $commission, 2),
            ];
        })->sortByDesc('gross_revenue')->values()->all();

        return Inertia::render('Reports/Sales', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'accessibleStores' => $this->accessibleStoresData($user),
            'selectedStoreId' => count($storeIds) === 1 ? $storeIds[0] : null,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'saleClass' => $saleClass,
            'transactions' => $transactions,
            'customers' => $customers,
            'hppSummary' => [
                'total_revenue' => $hppSummary->total_revenue,
                'total_cost' => $hppSummary->total_cost,
                'total_gross_profit' => $hppSummary->total_gross_profit,
                'items_with_hpp' => $hppSummary->items_with_hpp,
            ],
            'channelBreakdown' => $channelBreakdown,
        ]);
    }

    /**
     * Display purchases report.
     */
    public function purchases(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, $request);
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        $purchases = \App\Models\Purchase::query()
            ->whereIn('store_id', $storeIds)
            ->with(['supplier:id,name', 'user:id,name', 'store:id,name'])
            ->withCount('lines')
            ->whereBetween('purchased_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $purchases->through(fn (\App\Models\Purchase $p) => [
            'id' => $p->id,
            'purchase_number' => $p->purchase_number,
            'purchased_at' => $p->purchased_at?->toIso8601String(),
            'supplier_name' => $p->supplier?->name,
            'user_name' => $p->user?->name,
            'store_name' => $p->store?->name,
            'lines_count' => $p->lines_count,
            'dpp_amount' => (float) $p->dpp_amount,
            'charges_amount' => (float) $p->charges_amount,
            'ppn_amount' => (float) $p->ppn_amount,
            'total_amount' => (float) $p->total_amount,
            'supplier_tax_invoice_no' => $p->supplier_tax_invoice_no,
        ]);

        $summaryRow = \App\Models\Purchase::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('purchased_at', [$rangeStart, $rangeEnd])
            ->selectRaw('
                COUNT(*) as total_purchases,
                COALESCE(SUM(dpp_amount), 0) as total_dpp,
                COALESCE(SUM(charges_amount), 0) as total_charges,
                COALESCE(SUM(ppn_amount), 0) as total_ppn,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $totalPurchases = (int) $summaryRow->total_purchases;
        $totalAmount = (float) $summaryRow->total_amount;

        // Pembelian per supplier
        $bySupplier = DB::table('purchases')
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->whereIn('purchases.store_id', $storeIds)
            ->whereBetween('purchases.purchased_at', [$rangeStart, $rangeEnd])
            ->selectRaw("COALESCE(suppliers.name, 'Tanpa supplier') as supplier, COUNT(*) as purchase_count, SUM(purchases.total_amount) as total_amount")
            ->groupByRaw("COALESCE(suppliers.name, 'Tanpa supplier')")
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'supplier' => $row->supplier,
                'purchase_count' => (int) $row->purchase_count,
                'total_amount' => round((float) $row->total_amount, 2),
            ])
            ->all();

        // Produk paling banyak dibeli (berdasarkan nilai subtotal)
        $topItems = DB::table('purchase_lines')
            ->join('purchases', 'purchase_lines.purchase_id', '=', 'purchases.id')
            ->whereIn('purchases.store_id', $storeIds)
            ->whereBetween('purchases.purchased_at', [$rangeStart, $rangeEnd])
            ->selectRaw('
                purchase_lines.product_name_snapshot as name,
                SUM(purchase_lines.quantity) as total_qty,
                SUM(purchase_lines.subtotal) as total_value
            ')
            ->groupBy('purchase_lines.product_name_snapshot')
            ->orderByDesc('total_value')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?: 'Tanpa nama',
                'total_qty' => (float) $row->total_qty,
                'total_value' => round((float) $row->total_value, 2),
            ])
            ->all();

        // Tren pembelian harian / bulanan
        $daily = DB::table('purchases')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('purchased_at', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(purchased_at) as date, SUM(total_amount) as total')
            ->groupByRaw('DATE(purchased_at)')
            ->pluck('total', 'date');

        $dailyTotals = [];
        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $key = $cursor->format('Y-m-d');
            $dailyTotals[] = [
                'date' => $key,
                'total' => round((float) ($daily[$key] ?? 0), 2),
            ];
            $cursor->addDay();
        }

        if (count($dailyTotals) > 62) {
            $purchaseTrend = collect($dailyTotals)
                ->groupBy(fn ($row) => substr($row['date'], 0, 7))
                ->map(fn ($rows, $month) => [
                    'period' => $month,
                    'label' => Carbon::parse($month.'-01')->translatedFormat('M Y'),
                    'total' => round($rows->sum('total'), 2),
                ])
                ->values()
                ->all();
        } else {
            $purchaseTrend = collect($dailyTotals)
                ->map(fn ($row) => [
                    'period' => $row['date'],
                    'label' => Carbon::parse($row['date'])->format('d/m'),
                    'total' => $row['total'],
                ])
                ->all();
        }

        return Inertia::render('Reports/Purchases', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'accessibleStores' => $this->accessibleStoresData($user),
            'selectedStoreId' => count($storeIds) === 1 ? $storeIds[0] : null,
            'isOwner' => $user->isOwner(),
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'purchases' => $purchases,
            'summary' => [
                'total_purchases' => $totalPurchases,
                'total_dpp' => round((float) $summaryRow->total_dpp, 2),
                'total_charges' => round((float) $summaryRow->total_charges, 2),
                'total_ppn' => round((float) $summaryRow->total_ppn, 2),
                'total_amount' => round($totalAmount, 2),
                'average_purchase' => $totalPurchases > 0 ? round($totalAmount / $totalPurchases, 2) : 0,
            ],
            'bySupplier' => $bySupplier,
            'topItems' => $topItems,
            'purchaseTrend' => $purchaseTrend,
        ]);
    }

    /**
     * Display inventory report.
     */
    public function inventory(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $batchTracking = BatchTracking::enabled($store);
        $warnDays = BatchTracking::expiryWarnDays($store);
        $batchStatus = $request->get('batch_status');
        $batchCode = $request->get('batch_code');

        $products = $store->products()
            ->withSum(['transactionItems as total_sold' => function ($query) {
                $query->whereHas('transaction', function ($q) {
                    $q->where('status', 'completed');
                });
            }], 'quantity')
            ->orderBy('name')
            ->paginate(20);

        $batches = null;
        if ($batchTracking) {
            $batches = ProductBatch::query()
                ->where('store_id', $store->id)
                ->when(! $batchStatus, fn ($q) => $q->where('quantity_remaining', '>', 0))
                ->when($batchStatus, fn ($q) => $q->where('status', $batchStatus))
                ->when($batchCode, fn ($q) => $q->where('batch_code', 'like', "%{$batchCode}%"))
                ->with('product:id,name,unit')
                ->orderByRaw('expiry_date IS NULL')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->paginate(25, ['*'], 'batch_page');

            $batches->through(function (ProductBatch $b) use ($warnDays) {
                $status = 'no_expiry';
                if ($b->expiry_date !== null) {
                    $today = now()->startOfDay();
                    $ed = $b->expiry_date->copy()->startOfDay();
                    if ($ed->lt($today)) {
                        $status = 'expired';
                    } elseif ($ed->lte($today->copy()->addDays($warnDays))) {
                        $status = 'warning';
                    } else {
                        $status = 'ok';
                    }
                }

                return [
                    'id' => $b->id,
                    'product_id' => $b->product_id,
                    'product_name' => $b->product?->name ?? '—',
                    'unit' => $b->product?->unit ?? '',
                    'batch_code' => $b->batch_code,
                    'quantity_remaining' => $b->quantity_remaining,
                    'expiry_date' => $b->expiry_date?->format('Y-m-d'),
                    'expiry_status' => $status,
                ];
            });
        }

        // Ringkasan statistik stok
        $stockStats = $store->products()
            ->selectRaw('
                COUNT(*) as total_products,
                SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN stock_quantity <= 0 OR stock_quantity IS NULL THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN track_stock = 1 AND stock_quantity > 0 AND stock_quantity <= min_stock_level THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN track_stock = 0 THEN 1 ELSE 0 END) as untracked,
                COALESCE(SUM(CASE WHEN track_stock = 1 THEN stock_quantity ELSE 0 END), 0) as total_stock_qty,
                COALESCE(SUM(CASE WHEN track_stock = 1 THEN stock_quantity * average_cost ELSE 0 END), 0) as total_stock_value
            ')
            ->first();

        // Batch expired / warning count
        $batchExpiryStats = null;
        if ($batchTracking) {
            $today = now()->startOfDay();
            $batchExpiryStats = ProductBatch::query()
                ->where('store_id', $store->id)
                ->where('quantity_remaining', '>', 0)
                ->selectRaw('
                    COUNT(*) as total_batches,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < ? THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= DATE_ADD(?, INTERVAL ? DAY) THEN 1 ELSE 0 END) as expiring_soon,
                    SUM(CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END) as no_expiry
                ', [$today, $today, $today, $warnDays])
                ->first();
        }

        return Inertia::render('Reports/Inventory', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'products' => $products,
            'batch_tracking_enabled' => $batchTracking,
            'batch_expiry_warn_days' => $warnDays,
            'batches' => $batches,
            'stockStats' => $stockStats,
            'batchExpiryStats' => $batchExpiryStats,
        ]);
    }

    /**
     * Overview stok — read-only. Kasir lihat stok toko aktif; admin & gudang
     * bisa memilih outlet lain (query `store_id`) untuk melihat stok tiap outlet.
     */
    public function stockOverview(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Tidak ada toko aktif.');
        }

        // Toko/lokasi yang boleh dilihat stoknya (multi-outlet: admin & gudang).
        $accessibleStores = $user->isSuperAdmin()
            ? Store::query()->orderBy('name')->get(['id', 'name', 'location_type'])
            : $user->stores()->orderBy('stores.name')->get(['stores.id', 'stores.name', 'stores.location_type']);

        // Pastikan toko aktif selalu ada di daftar.
        if (! $accessibleStores->contains('id', $store->id)) {
            $accessibleStores->push($store);
        }

        $selectedStoreId = (int) $request->get('store_id', $store->id);
        $selectedStore = $accessibleStores->firstWhere('id', $selectedStoreId) ?? $store;

        $search = $request->get('search', '');
        $categoryId = $request->get('category_id');
        $showLowStock = $request->boolean('low_stock', false);

        $products = $selectedStore->products()
            ->where('track_stock', true)
            ->when($search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($q, $categoryId) {
                $q->where('product_category_id', $categoryId);
            })
            ->when($showLowStock, function ($q) {
                $q->whereColumn('stock_quantity', '<=', 'min_stock_level')
                    ->where('stock_quantity', '>', 0);
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $stats = $selectedStore->products()
            ->where('track_stock', true)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= min_stock_level THEN 1 ELSE 0 END) as low_stock,
                COALESCE(SUM(stock_quantity), 0) as total_qty,
                COALESCE(SUM(stock_quantity * COALESCE(average_cost, 0)), 0) as total_value
            ')
            ->first();

        // Tampilkan filter berdasarkan inventory_type yang ada di toko
        $inventoryTypes = $selectedStore->products()
            ->where('track_stock', true)
            ->where('inventory_type', '!=', 'sellable')
            ->selectRaw('inventory_type, count(*) as cnt')
            ->groupBy('inventory_type')
            ->get();

        $categories = ProductCategory::query()
            ->where('store_id', $selectedStore->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('StockOverview/Index', [
            'store' => [
                'id' => $selectedStore->id,
                'name' => $selectedStore->name,
            ],
            'products' => $products,
            'stats' => $stats,
            'categories' => $categories,
            'search' => $search,
            'categoryId' => $categoryId ? (int) $categoryId : null,
            'showLowStock' => $showLowStock,
            'stores' => $accessibleStores->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'location_type' => StoreLocationType::of($s),
                'location_label' => StoreLocationType::label($s),
            ])->values(),
            'selectedStoreId' => $selectedStore->id,
        ]);
    }

    /**
     * Laporan produksi: total batch, total output, total cost, rata-rata HPP per output.
     */
    public function production(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        $batches = \App\Models\ProductionBatch::query()
            ->with('product', 'recipe')
            ->where('store_id', $store->id)
            ->whereBetween('produced_at', [$startDate, $endDate.' 23:59:59'])
            ->orderByDesc('produced_at')
            ->paginate(20);

        // Summary
        $summary = \App\Models\ProductionBatch::query()
            ->where('store_id', $store->id)
            ->whereBetween('produced_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('
                COUNT(*) as total_batches,
                COALESCE(SUM(output_quantity_initial), 0) as total_output,
                COALESCE(SUM(total_cost), 0) as total_cost,
                COALESCE(SUM(total_cost) / NULLIF(SUM(output_quantity_initial), 0), 0) as avg_cost_per_unit
            ')
            ->first();

        return Inertia::render('Reports/Production', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'batches' => $batches,
            'summary' => [
                'total_batches' => $summary->total_batches,
                'total_output' => $summary->total_output,
                'total_cost' => $summary->total_cost,
                'avg_cost_per_unit' => $summary->avg_cost_per_unit,
            ],
        ]);
    }

    /**
     * Shift tutup per toko + filter tanggal (closed_at) & opsional kasir.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder, 1: string, 2: string}
     */
    protected function cashShiftsFilteredBase(Request $request, Store $store): array
    {
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];
        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        $base = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$rangeStart, $rangeEnd]);

        if ($request->filled('user_id')) {
            $base->where('user_id', (int) $request->input('user_id'));
        }

        return [$base, $startDate, $endDate];
    }

    /**
     * Laporan shift kasir (semua kasir) untuk admin/manajer — Fase F4.
     */
    public function cashShifts(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('reports.index')
                ->with('warning', 'Paket Starter tidak mencakup laporan shift kasir.');
        }

        [$base, $startDate, $endDate] = $this->cashShiftsFilteredBase($request, $store);

        $summaryShiftIds = (clone $base)->pluck('id');

        $totalCashSales = $summaryShiftIds->isEmpty()
            ? 0.0
            : (float) Transaction::query()
                ->whereIn('cash_shift_id', $summaryShiftIds)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->sum('total_amount');

        $shiftsClosed = (clone $base)->count();
        $shiftsWithDiff = (clone $base)
            ->whereNotNull('difference_amount')
            ->where('difference_amount', '!=', 0)
            ->count();
        $totalDifference = $summaryShiftIds->isEmpty()
            ? 0.0
            : (float) (clone $base)->sum('difference_amount');

        $openShiftsCount = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->count();

        $cashierIds = CashShift::query()
            ->where('store_id', $store->id)
            ->distinct()
            ->pluck('user_id');

        $cashiers = $cashierIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']);

        $paginator = (clone $base)
            ->with(['user:id,name'])
            ->withCount(['transactions' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('closed_at')
            ->paginate(25)
            ->withQueryString();

        $paginator->getCollection()->transform(function (CashShift $shift) {
            $cashSales = (float) Transaction::query()
                ->where('cash_shift_id', $shift->id)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->sum('total_amount');

            return [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'cashier_name' => $shift->user?->name ?? '—',
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'opening_cash' => (float) $shift->opening_cash,
                'expected_cash' => $shift->expected_cash !== null ? (float) $shift->expected_cash : null,
                'closing_cash_counted' => $shift->closing_cash_counted !== null ? (float) $shift->closing_cash_counted : null,
                'difference_amount' => $shift->difference_amount !== null ? (float) $shift->difference_amount : null,
                'closing_notes' => $shift->closing_notes,
                'transactions_count' => $shift->transactions_count,
                'cash_sales_total' => round($cashSales, 2),
            ];
        });

        return Inertia::render('Reports/CashShifts', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'filters' => [
                'user_id' => $request->input('user_id'),
            ],
            'cashiers' => $cashiers,
            'summary' => [
                'shifts_closed' => $shiftsClosed,
                'shifts_with_difference' => $shiftsWithDiff,
                'total_cash_sales_in_shifts' => round($totalCashSales, 2),
                'total_difference_sum' => round($totalDifference, 2),
                'open_shifts_now' => $openShiftsCount,
            ],
            'shifts' => $paginator,
        ]);
    }

    /**
     * Ekspor laporan shift kasir ke Excel (memakai filter yang sama dengan halaman).
     */
    public function exportCashShiftsExcel(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('reports.index')
                ->with('warning', 'Paket Starter tidak mencakup laporan shift kasir.');
        }

        [$base, $startDate, $endDate] = $this->cashShiftsFilteredBase($request, $store);

        $summaryShiftIds = (clone $base)->pluck('id');

        $totalCashSales = $summaryShiftIds->isEmpty()
            ? 0.0
            : (float) Transaction::query()
                ->whereIn('cash_shift_id', $summaryShiftIds)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->sum('total_amount');

        $shiftsClosed = (clone $base)->count();
        $shiftsWithDiff = (clone $base)
            ->whereNotNull('difference_amount')
            ->where('difference_amount', '!=', 0)
            ->count();
        $totalDifference = $summaryShiftIds->isEmpty()
            ? 0.0
            : (float) (clone $base)->sum('difference_amount');

        $openShiftsCount = CashShift::query()
            ->where('store_id', $store->id)
            ->whereNull('closed_at')
            ->count();

        $shifts = (clone $base)
            ->with(['user:id,name'])
            ->withCount(['transactions' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('closed_at')
            ->get();

        $cashByShift = collect();
        if ($summaryShiftIds->isNotEmpty()) {
            $cashByShift = Transaction::query()
                ->whereIn('cash_shift_id', $summaryShiftIds)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->selectRaw('cash_shift_id, SUM(total_amount) as cash_total')
                ->groupBy('cash_shift_id')
                ->get()
                ->keyBy('cash_shift_id')
                ->map(fn ($r) => (float) $r->cash_total);
        }

        $rows = [];
        foreach ($shifts as $index => $shift) {
            $cashSales = (float) ($cashByShift[$shift->id] ?? 0);
            $rows[] = [
                $index + 1,
                $shift->shift_number,
                $shift->user?->name ?? '—',
                $shift->opened_at?->format('d/m/Y H:i') ?? '',
                $shift->closed_at?->format('d/m/Y H:i') ?? '',
                round((float) $shift->opening_cash, 2),
                round($cashSales, 2),
                $shift->expected_cash !== null ? round((float) $shift->expected_cash, 2) : '',
                $shift->closing_cash_counted !== null ? round((float) $shift->closing_cash_counted, 2) : '',
                $shift->difference_amount !== null ? round((float) $shift->difference_amount, 2) : '',
                $shift->transactions_count,
                $shift->closing_notes ?? '',
            ];
        }

        $data = [
            'store' => $store,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'shifts_closed' => $shiftsClosed,
                'shifts_with_difference' => $shiftsWithDiff,
                'total_cash_sales_in_shifts' => round($totalCashSales, 2),
                'total_difference_sum' => round($totalDifference, 2),
                'open_shifts_now' => $openShiftsCount,
            ],
            'rows' => $rows,
        ];

        $filename = 'laporan-shift-kasir-'.$store->name.'-'.$startDate.'-to-'.$endDate.'.xlsx';

        return Excel::download(new \App\Exports\CashShiftsReportExport($data), $filename, \Maatwebsite\Excel\Excel::XLSX, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Export reports to PDF.
     */
    public function exportPDF(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, $request);
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        // Get report data
        $totalSales = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->sum('total_amount');

        $totalTransactions = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->count();

        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        $topProducts = \App\Models\Product::query()
            ->whereIn('store_id', $storeIds)
            ->withSum(['transactionItems as total_sold' => function ($query) use ($storeIds, $startDate, $endDate) {
                $query->whereHas('transaction', function ($q) use ($storeIds, $startDate, $endDate) {
                    $q->whereIn('store_id', $storeIds)
                        ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
                        ->where('status', 'completed');
                });
            }], 'quantity')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        $recentTransactions = Transaction::whereIn('store_id', $storeIds)
            ->with('customer')
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->latest()
            ->limit(20)
            ->get();

        $data = [
            'store' => $store,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'total_transactions' => $totalTransactions,
                'average_order_value' => $averageOrderValue,
            ],
            'topProducts' => $topProducts,
            'recentTransactions' => $recentTransactions,
        ];

        $pdf = Pdf::loadView('reports.pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'defaultFont' => 'Arial',
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
        ]);

        $filename = 'laporan-'.$store->name.'-'.$startDate.'-to-'.$endDate.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Test export functionality.
     */
    public function testExport()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        return response()->json([
            'message' => 'Export functionality is working',
            'store' => $store->name,
            'excel_class' => class_exists(\Maatwebsite\Excel\Facades\Excel::class),
            'pdf_class' => class_exists(\Barryvdh\DomPDF\Facade\Pdf::class),
        ]);
    }

    /**
     * Alternative PDF export with stream method.
     */
    public function exportPDFStream(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, $request);
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        // Get report data
        $totalSales = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->sum('total_amount');

        $totalTransactions = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->count();

        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        $topProducts = \App\Models\Product::query()
            ->whereIn('store_id', $storeIds)
            ->withSum(['transactionItems as total_sold' => function ($query) use ($storeIds, $startDate, $endDate) {
                $query->whereHas('transaction', function ($q) use ($storeIds, $startDate, $endDate) {
                    $q->whereIn('store_id', $storeIds)
                        ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
                        ->where('status', 'completed');
                });
            }], 'quantity')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        $recentTransactions = Transaction::whereIn('store_id', $storeIds)
            ->with('customer')
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->latest()
            ->limit(20)
            ->get();

        $data = [
            'store' => $store,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'total_transactions' => $totalTransactions,
                'average_order_value' => $averageOrderValue,
            ],
            'topProducts' => $topProducts,
            'recentTransactions' => $recentTransactions,
        ];

        $pdf = Pdf::loadView('reports.pdf', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'laporan-'.$store->name.'-'.$startDate.'-to-'.$endDate.'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Export reports to Excel.
     */
    public function exportExcel(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $storeIds = $this->resolveStoreIds($user, $request);
        $dateRange = $this->validateDateRange($request);
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        // Get report data
        $totalSales = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->sum('total_amount');

        $totalTransactions = Transaction::whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->count();

        $averageOrderValue = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

        $topProducts = \App\Models\Product::query()
            ->whereIn('store_id', $storeIds)
            ->withSum(['transactionItems as total_sold' => function ($query) use ($storeIds, $startDate, $endDate) {
                $query->whereHas('transaction', function ($q) use ($storeIds, $startDate, $endDate) {
                    $q->whereIn('store_id', $storeIds)
                        ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
                        ->where('status', 'completed');
                });
            }], 'quantity')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();

        $recentTransactions = Transaction::whereIn('store_id', $storeIds)
            ->with('customer')
            ->whereBetween('created_at', [$startDate, $endDate.' 23:59:59'])
            ->where('status', 'completed')
            ->latest()
            ->limit(100)
            ->get();

        $data = [
            'store' => $store,
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'total_transactions' => $totalTransactions,
                'average_order_value' => $averageOrderValue,
            ],
            'topProducts' => $topProducts,
            'recentTransactions' => $recentTransactions,
        ];

        $filename = 'laporan-'.$store->name.'-'.$startDate.'-to-'.$endDate.'.xlsx';

        return Excel::download(new \App\Exports\ReportExport($data), $filename, \Maatwebsite\Excel\Excel::XLSX, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Neraca saldo (mutasi jurnal per akun dalam periode).
     */
    public function financialTrialBalance(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '<=', $endDate)
            ->selectRaw('coa.code, coa.name, coa.type,
                SUM(CASE WHEN je.entry_date < ? THEN jl.debit ELSE 0 END) as opening_debit,
                SUM(CASE WHEN je.entry_date < ? THEN jl.credit ELSE 0 END) as opening_credit,
                SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit ELSE 0 END) as debit,
                SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit ELSE 0 END) as credit', [$startDate, $startDate, $startDate, $endDate, $startDate, $endDate])
            ->groupBy('coa.code', 'coa.name', 'coa.type')
            ->orderBy('coa.code')
            ->get();

        $normalDebitTypes = [ChartOfAccount::TYPE_ASSET, ChartOfAccount::TYPE_COGS, ChartOfAccount::TYPE_EXPENSE];

        $lines = $rows->map(function ($r) use ($normalDebitTypes) {
            $openingDebit = round((float) $r->opening_debit, 2);
            $openingCredit = round((float) $r->opening_credit, 2);
            $dr = round((float) $r->debit, 2);
            $cr = round((float) $r->credit, 2);
            $isNormalDebit = in_array($r->type, $normalDebitTypes, true);
            $openingSigned = $isNormalDebit ? $openingDebit - $openingCredit : $openingCredit - $openingDebit;
            $closingSigned = $openingSigned + ($isNormalDebit ? $dr - $cr : $cr - $dr);

            return [
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'opening_balance' => abs($openingSigned) < 0.005 ? 0 : round(abs($openingSigned), 2),
                'opening_balance_side' => abs($openingSigned) < 0.005 ? 'netral' : ($openingSigned > 0 ? ($isNormalDebit ? 'debit' : 'kredit') : ($isNormalDebit ? 'kredit' : 'debit')),
                'debit' => $dr,
                'credit' => $cr,
                'closing_balance' => abs($closingSigned) < 0.005 ? 0 : round(abs($closingSigned), 2),
                'closing_balance_side' => abs($closingSigned) < 0.005 ? 'netral' : ($closingSigned > 0 ? ($isNormalDebit ? 'debit' : 'kredit') : ($isNormalDebit ? 'kredit' : 'debit')),
            ];
        })->values()->all();

        return Inertia::render('Reports/FinancialTrialBalance', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'lines' => $lines,
            'totals' => [
                'debit' => round(collect($lines)->sum('debit'), 2),
                'credit' => round(collect($lines)->sum('credit'), 2),
            ],
        ]);
    }

    /**
     * Laba rugi sederhana dari mutasi jurnal (pendapatan, HPP, beban).
     */
    public function financialIncomeStatement(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->whereIn('coa.type', [ChartOfAccount::TYPE_REVENUE, ChartOfAccount::TYPE_COGS, ChartOfAccount::TYPE_EXPENSE])
            ->selectRaw('coa.code, coa.name, coa.type, coa.balance_sheet_group, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code', 'coa.name', 'coa.type', 'coa.balance_sheet_group')
            ->orderBy('coa.code')
            ->get();

        // Hitung total per tipe akun
        $agg = $rows->groupBy('type')->map(fn ($accounts) => (object) [
            'dr' => $accounts->sum('dr'),
            'cr' => $accounts->sum('cr'),
        ]);

        // Hitung beban pajak terpisah dari kode akun '6.1.03' (CODE_BEBAN_PAJAK)
        $taxRows = $rows->filter(fn ($r) => $r->code === ChartOfAccountsBootstrap::CODE_BEBAN_PAJAK);
        $taxExpense = round((float) $taxRows->sum('dr') - (float) $taxRows->sum('cr'), 2);

        // Hitung beban operasional murni (tanpa beban pajak)
        $expenseRows = $rows->filter(fn ($r) => $r->type === ChartOfAccount::TYPE_EXPENSE && $r->code !== ChartOfAccountsBootstrap::CODE_BEBAN_PAJAK);
        $expenseNet = round((float) $expenseRows->sum('dr') - (float) $expenseRows->sum('cr'), 2);

        $revenueNet = isset($agg['revenue'])
            ? round((float) $agg['revenue']->cr - (float) $agg['revenue']->dr, 2)
            : 0.0;
        $cogsNet = isset($agg['cogs'])
            ? round((float) $agg['cogs']->dr - (float) $agg['cogs']->cr, 2)
            : 0.0;

        $grossProfit = round($revenueNet - $cogsNet, 2);
        $operatingIncome = round($grossProfit - $expenseNet, 2);
        $incomeBeforeTax = $operatingIncome;
        $netIncome = round($incomeBeforeTax - $taxExpense, 2);

        // Perbandingan periode sebelumnya (panjang periode sama, outlet sama).
        $daysSpan = max(1, Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1);
        $prevEnd = Carbon::parse($startDate)->subDay()->format('Y-m-d');
        $prevStart = Carbon::parse($prevEnd)->subDays($daysSpan - 1)->format('Y-m-d');

        $prevRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereBetween('je.entry_date', [$prevStart, $prevEnd])
            ->whereIn('coa.type', [ChartOfAccount::TYPE_REVENUE, ChartOfAccount::TYPE_COGS, ChartOfAccount::TYPE_EXPENSE])
            ->selectRaw('coa.code, coa.type, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code', 'coa.type')
            ->get();

        $prevAgg = $prevRows->groupBy('type')->map(fn ($a) => (object) [
            'dr' => $a->sum('dr'),
            'cr' => $a->sum('cr'),
        ]);

        $prevRevenueNet = isset($prevAgg['revenue']) ? round((float) $prevAgg['revenue']->cr - (float) $prevAgg['revenue']->dr, 2) : 0.0;
        $prevCogsNet = isset($prevAgg['cogs']) ? round((float) $prevAgg['cogs']->dr - (float) $prevAgg['cogs']->cr, 2) : 0.0;

        $prevTaxRows = $prevRows->filter(fn ($r) => $r->code === ChartOfAccountsBootstrap::CODE_BEBAN_PAJAK);
        $prevTaxExpense = round((float) $prevTaxRows->sum('dr') - (float) $prevTaxRows->sum('cr'), 2);

        $prevExpenseOnly = $prevRows->filter(fn ($r) => $r->type === ChartOfAccount::TYPE_EXPENSE && $r->code !== ChartOfAccountsBootstrap::CODE_BEBAN_PAJAK);
        $prevExpenseNet = round((float) $prevExpenseOnly->sum('dr') - (float) $prevExpenseOnly->sum('cr'), 2);

        $prevGrossProfit = round($prevRevenueNet - $prevCogsNet, 2);
        $prevNetIncome = round($prevGrossProfit - $prevExpenseNet - $prevTaxExpense, 2);

        $grossMargin = $revenueNet != 0 ? round(($grossProfit / $revenueNet) * 100, 2) : null;
        $netMargin = $revenueNet != 0 ? round(($netIncome / $revenueNet) * 100, 2) : null;
        $revenueChangePct = $prevRevenueNet != 0 ? round((($revenueNet - $prevRevenueNet) / abs($prevRevenueNet)) * 100, 1) : null;
        $netIncomeChangePct = $prevNetIncome != 0 ? round((($netIncome - $prevNetIncome) / abs($prevNetIncome)) * 100, 1) : null;

        $accounts = $rows->map(function ($row) {
            $amount = match ($row->type) {
                ChartOfAccount::TYPE_REVENUE => (float) $row->cr - (float) $row->dr,
                default => (float) $row->dr - (float) $row->cr,
            };

            return ['code' => $row->code, 'name' => $row->name, 'type' => $row->type, 'amount' => round($amount, 2)];
        })->groupBy('type')->map->values()->all();

        return Inertia::render('Reports/FinancialIncomeStatement', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'amounts' => [
                'revenue' => $revenueNet,
                'cogs' => $cogsNet,
                'gross_profit' => $grossProfit,
                'expense' => $expenseNet,
                'operating_income' => $operatingIncome,
                'income_before_tax' => $incomeBeforeTax,
                'tax_expense' => $taxExpense,
                'net_income' => $netIncome,
            ],
            'comparison' => [
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'prev_revenue' => $prevRevenueNet,
                'prev_gross_profit' => $prevGrossProfit,
                'prev_net_income' => $prevNetIncome,
                'revenue_change_pct' => $revenueChangePct,
                'net_income_change_pct' => $netIncomeChangePct,
            ],
            'margins' => [
                'gross_margin' => $grossMargin,
                'net_margin' => $netMargin,
            ],
            'accounts' => $accounts,
            'reportStatus' => $this->reportStatus($store, 'laba_rugi', $endDate),
        ]);
    }

    /**
     * Rekap PPN dari jurnal (Utang PPN Keluaran vs PPN Masukan).
     */
    public function taxPpnSummary(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        ChartOfAccountsBootstrap::ensureForStore($store);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');

        $kel = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('coa.code', ChartOfAccountsBootstrap::CODE_UTANG_PPN_KELUARAN)
            ->selectRaw('COALESCE(SUM(jl.debit),0) as dr, COALESCE(SUM(jl.credit),0) as cr')
            ->first();

        $mas = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('coa.code', ChartOfAccountsBootstrap::CODE_PPN_MASUKAN)
            ->selectRaw('COALESCE(SUM(jl.debit),0) as dr, COALESCE(SUM(jl.credit),0) as cr')
            ->first();

        $ppnKeluaran = round((float) $kel->cr - (float) $kel->dr, 2);
        $ppnMasukan = round((float) $mas->dr - (float) $mas->cr, 2);
        $netPosition = round($ppnKeluaran - $ppnMasukan, 2);

        return Inertia::render('Reports/TaxPpnSummary', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'amounts' => [
                'ppn_keluaran' => $ppnKeluaran,
                'ppn_masukan' => $ppnMasukan,
                'net_position' => $netPosition,
            ],
        ]);
    }

    /**
     * Neraca / Laporan Posisi Keuangan (saldo kumulatif s.d. tanggal tertentu).
     */
    public function financialBalanceSheet(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $asOf = $validated['as_of'] ?? Carbon::now()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->selectRaw('coa.code, coa.name, coa.type, coa.balance_sheet_group, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code', 'coa.name', 'coa.type', 'coa.balance_sheet_group')
            ->orderBy('coa.code')
            ->get();

        $assets = [
            ChartOfAccount::GROUP_CURRENT_ASSET => ['label' => 'Aset Lancar', 'rows' => [], 'subtotal' => 0.0],
            ChartOfAccount::GROUP_NON_CURRENT_ASSET => ['label' => 'Aset Tidak Lancar', 'rows' => [], 'subtotal' => 0.0],
        ];
        $liabilities = [
            ChartOfAccount::GROUP_CURRENT_LIABILITY => ['label' => 'Liabilitas Jangka Pendek', 'rows' => [], 'subtotal' => 0.0],
            ChartOfAccount::GROUP_NON_CURRENT_LIABILITY => ['label' => 'Liabilitas Jangka Panjang', 'rows' => [], 'subtotal' => 0.0],
        ];
        $equity = ['rows' => [], 'subtotal' => 0.0];
        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;
        $revenueNet = 0.0;
        $cogsNet = 0.0;
        $expenseNet = 0.0;

        foreach ($rows as $r) {
            $dr = round((float) $r->dr, 2);
            $cr = round((float) $r->cr, 2);

            switch ($r->type) {
                case ChartOfAccount::TYPE_ASSET:
                    $bal = round($dr - $cr, 2);
                    $group = $r->balance_sheet_group ?: ChartOfAccount::GROUP_CURRENT_ASSET;
                    $assets[$group] ??= ['label' => $group, 'rows' => [], 'subtotal' => 0.0];
                    $assets[$group]['rows'][] = ['code' => $r->code, 'name' => $r->name, 'balance' => $bal];
                    $assets[$group]['subtotal'] += $bal;
                    $totalAssets += $bal;
                    break;
                case ChartOfAccount::TYPE_LIABILITY:
                    $bal = round($cr - $dr, 2);
                    $group = $r->balance_sheet_group ?: ChartOfAccount::GROUP_CURRENT_LIABILITY;
                    $liabilities[$group] ??= ['label' => $group, 'rows' => [], 'subtotal' => 0.0];
                    $liabilities[$group]['rows'][] = ['code' => $r->code, 'name' => $r->name, 'balance' => $bal];
                    $liabilities[$group]['subtotal'] += $bal;
                    $totalLiabilities += $bal;
                    break;
                case ChartOfAccount::TYPE_EQUITY:
                    $bal = round($cr - $dr, 2);
                    $equity['rows'][] = ['code' => $r->code, 'name' => $r->name, 'balance' => $bal];
                    $equity['subtotal'] += $bal;
                    $totalEquity += $bal;
                    break;
                case ChartOfAccount::TYPE_REVENUE:
                    $revenueNet += round($cr - $dr, 2);
                    break;
                case ChartOfAccount::TYPE_COGS:
                    $cogsNet += round($dr - $cr, 2);
                    break;
                case ChartOfAccount::TYPE_EXPENSE:
                    $expenseNet += round($dr - $cr, 2);
                    break;
            }
        }

        $retainedEarnings = round($revenueNet - $cogsNet - $expenseNet, 2);
        $equity['rows'][] = [
            'code' => '3.9.99',
            'name' => 'Laba (rugi) berjalan',
            'balance' => $retainedEarnings,
        ];
        $equity['subtotal'] += $retainedEarnings;
        $totalEquity = round($totalEquity + $retainedEarnings, 2);

        foreach ($assets as &$group) {
            $group['subtotal'] = round($group['subtotal'], 2);
        }
        foreach ($liabilities as &$group) {
            $group['subtotal'] = round($group['subtotal'], 2);
        }
        $equity['subtotal'] = round($equity['subtotal'], 2);

        $totalAssets = round($totalAssets, 2);
        $totalLiabilities = round($totalLiabilities, 2);
        $totalLiabilitiesEquity = round($totalLiabilities + $totalEquity, 2);

        return Inertia::render('Reports/FinancialBalanceSheet', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'asOf' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'liabilities_equity' => $totalLiabilitiesEquity,
                'balanced' => abs($totalAssets - $totalLiabilitiesEquity) < 0.01,
                'difference' => round($totalAssets - $totalLiabilitiesEquity, 2),
            ],
            'reportStatus' => $this->reportStatus($store, 'neraca', $asOf),
        ]);
    }

    /**
     * Catat setoran modal pemilik (Debit Kas, Kredit Modal).
     */
    public function storeCapitalInjection(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'entry_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        JournalPoster::postCapitalInjection(
            $store,
            (float) $validated['amount'],
            Carbon::parse($validated['entry_date'])->format('Y-m-d'),
            $user->id,
            $validated['note'] ?? null,
        );

        return redirect()->route('financial.balance-sheet')
            ->with('success', 'Setoran modal berhasil dicatat.');
    }

    /**
     * Catatan atas Laporan Keuangan (CaLK) — SAK EMKM.
     */
    public function financialCaLK(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);

        ChartOfAccountsBootstrap::ensureForStore($store);

        $asOf = $validated['as_of'] ?? Carbon::now()->format('Y-m-d');

        $totalProducts = $store->products()->count();
        $totalStockQty = $store->products()->where('track_stock', true)->sum('stock_quantity');
        $totalStockValue = $store->products()->where('track_stock', true)
            ->selectRaw('COALESCE(SUM(stock_quantity * average_cost), 0) as val')
            ->value('val') ?? 0;

        $journalCount = DB::table('journal_entries')
            ->where('store_id', $store->id)
            ->whereYear('entry_date', date('Y'))
            ->count();

        // Saldo akun neraca per tanggal pelaporan (dari jurnal).
        $codes = [
            ChartOfAccountsBootstrap::CODE_KAS,
            ChartOfAccountsBootstrap::CODE_BANK,
            ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA,
            ChartOfAccountsBootstrap::CODE_PERSEDIAAN,
            ChartOfAccountsBootstrap::CODE_UTANG_USAHA,
            ChartOfAccountsBootstrap::CODE_MODAL,
        ];

        $bookRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->where('je.store_id', $store->id)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('coa.code', $codes)
            ->selectRaw('coa.code, coa.type, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code', 'coa.type')
            ->get()
            ->keyBy('code');

        $book = function (string $code) use ($bookRows): float {
            $r = $bookRows->get($code);
            if (! $r) {
                return 0.0;
            }
            $dr = (float) $r->dr;
            $cr = (float) $r->cr;

            return in_array($r->type, [ChartOfAccount::TYPE_LIABILITY, ChartOfAccount::TYPE_EQUITY], true)
                ? $cr - $dr
                : $dr - $cr;
        };

        $balances = [
            'kas' => round($book(ChartOfAccountsBootstrap::CODE_KAS), 2),
            'bank' => round($book(ChartOfAccountsBootstrap::CODE_BANK), 2),
            'piutang_usaha' => round($book(ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA), 2),
            'persediaan' => round($book(ChartOfAccountsBootstrap::CODE_PERSEDIAAN), 2),
            'utang_usaha' => round($book(ChartOfAccountsBootstrap::CODE_UTANG_USAHA), 2),
            'modal' => round($book(ChartOfAccountsBootstrap::CODE_MODAL), 2),
        ];

        return Inertia::render('Reports/FinancialCaLK', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'asOf' => $asOf,
            'summary' => [
                'total_products' => $totalProducts,
                'total_stock_qty' => $totalStockQty,
                'total_stock_value' => (float) $totalStockValue,
                'journal_count' => $journalCount,
                'currency' => $store->currency ?? 'IDR',
            ],
            'balances' => $balances,
            'reportStatus' => $this->reportStatus($store, 'calk', $asOf),
        ]);
    }

    /**
     * Rekonsiliasi kas, bank, piutang, utang, dan persediaan.
     *
     * Membandingkan saldo buku (jurnal) dengan saldo operasional/fisik.
     */
    public function financialReconciliation(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $asOf = $validated['as_of'] ?? Carbon::now()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        $codes = [
            ChartOfAccountsBootstrap::CODE_KAS,
            ChartOfAccountsBootstrap::CODE_BANK,
            ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA,
            ChartOfAccountsBootstrap::CODE_PERSEDIAAN,
            ChartOfAccountsBootstrap::CODE_UTANG_USAHA,
        ];

        $bookRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('coa.code', $codes)
            ->selectRaw('coa.code, coa.type, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code', 'coa.type')
            ->get()
            ->keyBy('code');

        $book = function (string $code) use ($bookRows): float {
            $r = $bookRows->get($code);
            if (! $r) {
                return 0.0;
            }
            $dr = (float) $r->dr;
            $cr = (float) $r->cr;

            return $r->type === ChartOfAccount::TYPE_LIABILITY ? $cr - $dr : $dr - $cr;
        };

        // Kas fisik: hitungan kas dari shift terakhir yang sudah ditutup.
        $cashPhysical = null;
        $lastShift = CashShift::query()
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('closing_cash_counted')
            ->orderByDesc('closed_at')
            ->first();
        if ($lastShift) {
            $cashPhysical = round((float) $lastShift->closing_cash_counted, 2);
        }

        // Bank & piutang: sumber data operasional belum tersedia di modul kasir.
        $bankPhysical = null;
        $receivablePhysical = null;

        // Utang: sisa tagihan pembelian yang belum lunas.
        $payablePhysical = round((float) \App\Models\Purchase::query()
            ->whereIn('store_id', $storeIds)
            ->where('is_paid', false)
            ->whereNotNull('total_amount')
            ->get()
            ->reduce(fn ($carry, $p) => $carry + max(0, (float) $p->total_amount - (float) $p->amount_paid), 0.0), 2);

        // Persediaan: nilai stok fisik (qty x biaya rata-rata) produk yang dilacak.
        $inventoryPhysical = round((float) DB::table('products')
            ->whereIn('store_id', $storeIds)
            ->where('track_stock', true)
            ->sum(DB::raw('COALESCE(stock_quantity, 0) * COALESCE(average_cost, 0)')), 2);

        $make = function (string $code, string $name, ?float $physical, string $note) use ($book): array {
            $bookBalance = round($book($code), 2);
            $difference = $physical === null ? null : round($bookBalance - $physical, 2);
            $status = $physical === null
                ? 'belum_tersedia'
                : (abs((float) $difference) < 0.01 ? 'cocok' : 'selisih');

            return [
                'code' => $code,
                'name' => $name,
                'book_balance' => $bookBalance,
                'physical_balance' => $physical,
                'difference' => $difference,
                'status' => $status,
                'note' => $note,
            ];
        };

        $rows = [
            $make(ChartOfAccountsBootstrap::CODE_KAS, 'Kas', $cashPhysical, 'Hitungan kas dari shift terakhir yang ditutup.'),
            $make(ChartOfAccountsBootstrap::CODE_BANK, 'Bank (non-tunai)', $bankPhysical, 'Belum ada data mutasi rekening bank.'),
            $make(ChartOfAccountsBootstrap::CODE_PIUTANG_USAHA, 'Piutang usaha', $receivablePhysical, 'Belum ada ledger piutang pelanggan.'),
            $make(ChartOfAccountsBootstrap::CODE_UTANG_USAHA, 'Utang usaha', $payablePhysical, 'Sisa tagihan pembelian yang belum lunas.'),
            $make(ChartOfAccountsBootstrap::CODE_PERSEDIAAN, 'Persediaan barang dagangan', $inventoryPhysical, 'Nilai stok fisik (qty x biaya rata-rata).'),
        ];

        $summary = [
            'cocok' => collect($rows)->where('status', 'cocok')->count(),
            'selisih' => collect($rows)->where('status', 'selisih')->count(),
            'belum_tersedia' => collect($rows)->where('status', 'belum_tersedia')->count(),
        ];

        return Inertia::render('Reports/FinancialReconciliation', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'asOf' => $asOf,
            'rows' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * Laporan perubahan ekuitas (Simplified).
     */
    public function statementOfEquityChanges(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfYear()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        // Saldo ekuitas sebelum periode (opening).
        $equityCodes = [
            ChartOfAccountsBootstrap::CODE_MODAL,
            ChartOfAccountsBootstrap::CODE_SALDO_LABA,
        ];

        $openingRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '<', $startDate)
            ->whereIn('coa.code', $equityCodes)
            ->selectRaw('coa.code, SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->groupBy('coa.code')
            ->get()
            ->keyBy('code');

        $equilibriumBalance = function (string $code) use ($openingRows): float {
            $r = $openingRows->get($code);
            if (! $r) {
                return 0.0;
            }

            return round((float) $r->cr - (float) $r->dr, 2);
        };

        $openingModal = $equilibriumBalance(ChartOfAccountsBootstrap::CODE_MODAL);
        $openingSaldoLaba = $equilibriumBalance(ChartOfAccountsBootstrap::CODE_SALDO_LABA);

        // Perubahan modal selama periode: kredit = setoran, debit = penarikan.
        $modalChanges = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '>=', $startDate)
            ->whereDate('je.entry_date', '<=', $endDate)
            ->where('coa.code', ChartOfAccountsBootstrap::CODE_MODAL)
            ->selectRaw('SUM(jl.debit) as dr, SUM(jl.credit) as cr')
            ->first();

        $setoranModal = round(max(0, (float) ($modalChanges->cr ?? 0) - (float) ($modalChanges->dr ?? 0)), 2);
        $penarikanModal = round(max(0, (float) ($modalChanges->dr ?? 0) - (float) ($modalChanges->cr ?? 0)), 2);

        // Laba bersih periode: pendapatan - HPP - beban.
        $pnlAccountsByType = ChartOfAccount::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('type', [
                ChartOfAccount::TYPE_REVENUE,
                ChartOfAccount::TYPE_COGS,
                ChartOfAccount::TYPE_EXPENSE,
            ])
            ->pluck('type')
            ->unique();

        $revenueNet = 0.0;
        $cogsNet = 0.0;
        $expenseNet = 0.0;

        foreach ($pnlAccountsByType as $type) {
            $typeLine = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
                ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
                ->whereIn('je.store_id', $storeIds)
                ->whereDate('je.entry_date', '>=', $startDate)
                ->whereDate('je.entry_date', '<=', $endDate)
                ->where('coa.type', $type)
                ->selectRaw('SUM(jl.debit) as dr, SUM(jl.credit) as cr')
                ->first();

            switch ($type) {
                case ChartOfAccount::TYPE_REVENUE:
                    $revenueNet = round((float) ($typeLine->cr ?? 0) - (float) ($typeLine->dr ?? 0), 2);
                    break;
                case ChartOfAccount::TYPE_COGS:
                    $cogsNet = round((float) ($typeLine->dr ?? 0) - (float) ($typeLine->cr ?? 0), 2);
                    break;
                case ChartOfAccount::TYPE_EXPENSE:
                    $expenseNet = round((float) ($typeLine->dr ?? 0) - (float) ($typeLine->cr ?? 0), 2);
                    break;
            }
        }

        $labaBersih = round($revenueNet - $cogsNet - $expenseNet, 2);

        // Saldo akhir.
        $closingModal = round($openingModal + $setoranModal - $penarikanModal, 2);
        $closingSaldoLaba = round($openingSaldoLaba + $labaBersih, 2);

        $equityColumns = [
            'modal' => $closingModal,
            'saldo_laba' => $closingSaldoLaba,
            'total' => round($closingModal + $closingSaldoLaba, 2),
        ];

        return Inertia::render('Reports/StatementOfEquityChanges', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rows' => [
                'opening_modal' => $openingModal,
                'opening_saldo_laba' => $openingSaldoLaba,
                'opening_total' => round($openingModal + $openingSaldoLaba, 2),
                'setoran_modal' => $setoranModal,
                'penarikan_modal' => $penarikanModal,
                'laba_bersih' => $labaBersih,
                'closing_modal' => $closingModal,
                'closing_saldo_laba' => $closingSaldoLaba,
                'closing_total' => round($closingModal + $closingSaldoLaba, 2),
            ],
            'summary' => $equityColumns,
            'reportStatus' => $this->reportStatus($store, 'perubahan_ekuitas', $endDate),
        ]);
    }

    /**
     * Laporan arus kas akuntansi berbasis jurnal (metode langsung).
     */
    public function financialCashFlow(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $storeIds = $this->resolveStoreIds($user, $request);
        $endDate = $validated['end_date'] ?? Carbon::now()->format('Y-m-d');
        $startDate = $validated['start_date'] ?? Carbon::now()->startOfYear()->format('Y-m-d');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $reportStore) {
            ChartOfAccountsBootstrap::ensureForStore($reportStore);
        }

        $cashCodes = [
            ChartOfAccountsBootstrap::CODE_KAS,
            ChartOfAccountsBootstrap::CODE_BANK,
        ];

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.chart_of_account_id', '=', 'coa.id')
            ->whereIn('je.store_id', $storeIds)
            ->whereDate('je.entry_date', '>=', $startDate)
            ->whereDate('je.entry_date', '<=', $endDate)
            ->select('jl.journal_entry_id', 'jl.debit', 'jl.credit', 'coa.code', 'coa.type', 'coa.balance_sheet_group')
            ->get()
            ->groupBy('journal_entry_id');

        // Klasifikasi aktivitas berdasarkan akun lawan (non-kas/bank).
        $classify = function (object $line): string {
            if ($line->type === ChartOfAccount::TYPE_EQUITY) {
                return 'financing';
            }
            if (in_array($line->balance_sheet_group, [
                ChartOfAccount::GROUP_NON_CURRENT_ASSET,
                ChartOfAccount::GROUP_NON_CURRENT_LIABILITY,
            ], true)) {
                return 'investing';
            }

            return 'operating';
        };

        $buckets = [
            'operating' => ['inflow' => 0.0, 'outflow' => 0.0],
            'investing' => ['inflow' => 0.0, 'outflow' => 0.0],
            'financing' => ['inflow' => 0.0, 'outflow' => 0.0],
        ];

        foreach ($lines as $entryId => $entryLines) {
            $cashDelta = 0.0;
            $activity = null;

            foreach ($entryLines as $line) {
                $isCash = in_array($line->code, $cashCodes, true);
                if ($isCash) {
                    $cashDelta += (float) $line->debit - (float) $line->credit;
                } elseif ($activity === null) {
                    $activity = $classify($line);
                }
            }

            if (abs($cashDelta) < 0.01) {
                continue;
            }

            $activity ??= 'operating';
            if ($cashDelta > 0) {
                $buckets[$activity]['inflow'] += $cashDelta;
            } else {
                $buckets[$activity]['outflow'] += abs($cashDelta);
            }
        }

        $roundBucket = fn (array $b) => [
            'inflow' => round($b['inflow'], 2),
            'outflow' => round($b['outflow'], 2),
            'net' => round($b['inflow'] - $b['outflow'], 2),
        ];

        $operating = $roundBucket($buckets['operating']);
        $investing = $roundBucket($buckets['investing']);
        $financing = $roundBucket($buckets['financing']);

        $netIncrease = round($operating['net'] + $investing['net'] + $financing['net'], 2);

        return Inertia::render('Reports/FinancialCashFlow', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'netIncrease' => $netIncrease,
            'reportStatus' => $this->reportStatus($store, 'arus_kas', $endDate),
        ]);
    }

    /**
     * Status efektif laporan keuangan: draft, final, atau periode ditutup.
     */
    private function reportStatus(Store $store, string $reportType, string $periodEnd): array
    {
        $closed = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->whereDate('start_date', '<=', $periodEnd)
            ->whereDate('end_date', '>=', $periodEnd)
            ->exists();

        if ($closed) {
            return [
                'status' => 'closed',
                'label' => 'Periode ditutup',
                'finalized_at' => null,
                'finalized_by' => null,
                'report_number' => null,
                'issued_at' => null,
                'prepared_by' => null,
                'reviewed_by' => null,
            ];
        }

        $record = FinancialReportStatus::query()
            ->where('store_id', $store->id)
            ->where('report_type', $reportType)
            ->whereDate('period_end', $periodEnd)
            ->first();

        if ($record && $record->status === FinancialReportStatus::STATUS_FINAL) {
            return [
                'status' => 'final',
                'label' => 'Final',
                'finalized_at' => $record->finalized_at?->toDateTimeString(),
                'finalized_by' => $record->finalizedBy?->name,
                'report_number' => $record->report_number,
                'issued_at' => $record->issued_at?->toDateTimeString(),
                'prepared_by' => $record->preparedBy?->name,
                'reviewed_by' => $record->reviewed_by,
            ];
        }

        return [
            'status' => 'draft',
            'label' => 'Draft',
            'finalized_at' => null,
            'finalized_by' => null,
            'report_number' => null,
            'issued_at' => null,
            'prepared_by' => null,
            'reviewed_by' => null,
        ];
    }

    /**
     * Ubah status laporan (draft/final).
     */
    public function updateFinancialReportStatus(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'report_type' => ['required', 'string', 'max:40'],
            'period_end' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:draft,final'],
        ]);

        $closed = AccountingPeriod::query()
            ->where('store_id', $store->id)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->whereDate('start_date', '<=', $validated['period_end'])
            ->whereDate('end_date', '>=', $validated['period_end'])
            ->exists();

        if ($closed) {
            return back()->with('error', 'Periode sudah ditutup. Status laporan tidak dapat diubah.');
        }

        if ($validated['status'] === FinancialReportStatus::STATUS_FINAL) {
            $existing = FinancialReportStatus::query()
                ->where('store_id', $store->id)
                ->where('report_type', $validated['report_type'])
                ->whereDate('period_end', $validated['period_end'])
                ->first();

            FinancialReportStatus::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'report_type' => $validated['report_type'],
                    'period_end' => $validated['period_end'],
                ],
                [
                    'status' => FinancialReportStatus::STATUS_FINAL,
                    'finalized_by' => $user->id,
                    'finalized_at' => now(),
                    'report_number' => $existing?->report_number
                        ?? $this->generateReportNumber($store, $validated['report_type'], $validated['period_end']),
                    'issued_at' => $existing?->issued_at ?? now(),
                    'prepared_by' => $existing?->prepared_by ?? $user->id,
                ],
            );
        } else {
            FinancialReportStatus::query()
                ->where('store_id', $store->id)
                ->where('report_type', $validated['report_type'])
                ->whereDate('period_end', $validated['period_end'])
                ->delete();
        }

        return back()->with('success', 'Status laporan diperbarui.');
    }

    /**
     * Simpan nama pemeriksa pada laporan final.
     */
    public function updateReportReviewer(Request $request)
    {
        $this->abortUnlessAccountingEnabled();
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'report_type' => ['required', 'string', 'max:40'],
            'period_end' => ['required', 'date_format:Y-m-d'],
            'reviewed_by' => ['nullable', 'string', 'max:120'],
        ]);

        FinancialReportStatus::query()
            ->where('store_id', $store->id)
            ->where('report_type', $validated['report_type'])
            ->whereDate('period_end', $validated['period_end'])
            ->update(['reviewed_by' => $validated['reviewed_by'] ?: null]);

        return back()->with('success', 'Pemeriksa laporan diperbarui.');
    }

    private function reportTypeCode(string $reportType): string
    {
        return match ($reportType) {
            'laba_rugi' => 'LR',
            'neraca' => 'NRC',
            'calk' => 'CALK',
            'perubahan_ekuitas' => 'PE',
            'arus_kas' => 'AK',
            default => 'LAP',
        };
    }

    private function generateReportNumber(Store $store, string $reportType, string $periodEnd): string
    {
        $code = $this->reportTypeCode($reportType);
        $month = Carbon::parse($periodEnd)->format('Ym');
        $start = Carbon::parse($periodEnd)->startOfMonth();
        $end = Carbon::parse($periodEnd)->endOfMonth();

        $count = FinancialReportStatus::query()
            ->where('store_id', $store->id)
            ->where('report_type', $reportType)
            ->whereNotNull('report_number')
            ->whereDate('period_end', '>=', $start)
            ->whereDate('period_end', '<=', $end)
            ->count();

        return sprintf('%s-%s-%04d', $code, $month, $count + 1);
    }
}
