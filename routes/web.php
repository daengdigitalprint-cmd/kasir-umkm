<?php

use App\Http\Controllers\StoreController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SuperAdminController;
use App\Support\BatchTracking;
use App\Support\ExpiringProductBatches;
use App\Support\FnBFeatures;
use App\Support\LandingSeo;
use App\Support\PharmacyFeatures;
use App\Support\ReceiptSettings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/robots.txt', function () {
    return response()
        ->view('seo.robots', ['sitemapUrl' => url('/sitemap.xml')])
        ->header('Content-Type', 'text/plain; charset=UTF-8');
})->name('robots');

Route::get('/sitemap.xml', function () {
    $maskotPath = public_path('maskot.webp');
    $lastmodHome = is_file($maskotPath) ? date('Y-m-d', filemtime($maskotPath)) : null;

    $urls = [
        array_filter([
            'loc' => url('/'),
            'changefreq' => 'weekly',
            'priority' => '1.0',
            'lastmod' => $lastmodHome,
        ]),
        [
            'loc' => url('/panduan'),
            'changefreq' => 'monthly',
            'priority' => '0.7',
        ],
        [
            'loc' => url('/privacy'),
            'changefreq' => 'monthly',
            'priority' => '0.5',
        ],
        [
            'loc' => url('/refund'),
            'changefreq' => 'monthly',
            'priority' => '0.5',
        ],
        [
            'loc' => url('/terms'),
            'changefreq' => 'monthly',
            'priority' => '0.5',
        ],
    ];

    return response()
        ->view('seo.sitemap', ['urls' => $urls])
        ->header('Content-Type', 'application/xml; charset=UTF-8');
})->name('sitemap');

Route::get('/', function () {
    if (auth()->check()) {
        if (auth()->user()?->isSuperAdmin()) {
            return redirect()->route('superadmin.dashboard');
        }

        return redirect()->route('dashboard');
    }

    app()->setLocale('id');

    $og = LandingSeo::openGraphImage();

    return Inertia::render('Landing/Index', [
        'maskotUrl' => LandingSeo::maskotAssetUrl(),
        'canonicalUrl' => url('/'),
        'ogImageUrl' => $og['url'],
        'ogImageType' => $og['mime'],
        'ogImageWidth' => $og['width'],
        'ogImageHeight' => $og['height'],
    ]);
})->name('home');

Route::get('/panduan', function () {
    app()->setLocale('id');

    $og = LandingSeo::openGraphImage();

    return Inertia::render('Landing/Panduan', [
        'maskotUrl' => LandingSeo::maskotAssetUrl(),
        'canonicalUrl' => url('/panduan'),
        'ogImageUrl' => $og['url'],
        'ogImageType' => $og['mime'],
        'ogImageWidth' => $og['width'],
        'ogImageHeight' => $og['height'],
    ]);
})->name('panduan');

Route::get('/privacy', function () {
    app()->setLocale('id');

    $og = LandingSeo::openGraphImage();

    return Inertia::render('Landing/Privacy', [
        'maskotUrl' => LandingSeo::maskotAssetUrl(),
        'canonicalUrl' => url('/privacy'),
        'ogImageUrl' => $og['url'],
        'ogImageType' => $og['mime'],
        'ogImageWidth' => $og['width'],
        'ogImageHeight' => $og['height'],
    ]);
})->name('privacy');

Route::get('/refund', function () {
    app()->setLocale('id');

    $og = LandingSeo::openGraphImage();

    return Inertia::render('Landing/RefundPolicy', [
        'maskotUrl' => LandingSeo::maskotAssetUrl(),
        'canonicalUrl' => url('/refund'),
        'ogImageUrl' => $og['url'],
        'ogImageType' => $og['mime'],
        'ogImageWidth' => $og['width'],
        'ogImageHeight' => $og['height'],
    ]);
})->name('refund');

Route::get('/terms', function () {
    app()->setLocale('id');

    $og = LandingSeo::openGraphImage();

    return Inertia::render('Landing/Terms', [
        'maskotUrl' => LandingSeo::maskotAssetUrl(),
        'canonicalUrl' => url('/terms'),
        'ogImageUrl' => $og['url'],
        'ogImageType' => $og['mime'],
        'ogImageWidth' => $og['width'],
        'ogImageHeight' => $og['height'],
    ]);
})->name('terms');

Route::get('dashboard', function () {
    $user = auth()->user();
    $store = $user->store;

    if (! $store) {
        return Inertia::render('Dashboard');
    }

    // Check if store is active
    if (! $store->is_active) {
        return Inertia::render('Dashboard', [
            'store' => null,
            'storeInactive' => true,
            'storeName' => $store->name,
        ]);
    }

    $todaySales = $store->transactions()
        ->whereDate('created_at', today())
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

    $recentTransactions = $store->transactions()
        ->with('customer')
        ->whereDate('created_at', today())
        ->where('status', 'completed')
        ->latest()
        ->limit(5)
        ->get();

    // Total produk stok rendah (tanpa batas, untuk angka statistik)
    $lowStockCount = $store->products()
        ->whereColumn('stock_quantity', '<=', 'min_stock_level')
        ->where('track_stock', true)
        ->where('unlimited_stock', false)
        ->count();

    // Daftar singkat untuk sidebar/strip (maks 5)
    $lowStockProducts = $store->products()
        ->whereColumn('stock_quantity', '<=', 'min_stock_level')
        ->where('track_stock', true)
        ->where('unlimited_stock', false)
        ->limit(5)
        ->get();

    // Check for pending payments
    $pendingPayment = $store->subscriptionPayments()
        ->where('status', 'pending')
        ->latest()
        ->first();

    $expiringBatches = [];
    $batchExpiryWarnDays = null;
    if (BatchTracking::enabled($store)) {
        $warnDays = BatchTracking::expiryWarnDays($store);
        $batchExpiryWarnDays = $warnDays;
        $expiringBatches = ExpiringProductBatches::alertPayload($store, 10);
    }

    $pendingKitchenOrderCount = $store->queueEntries()
        ->whereDate('created_at', today())
        ->whereIn('status', ['waiting', 'serving'])
        ->count();

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
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('payment', function () {
    $user = auth()->user();
    $store = $user->store;

    if (! $store) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Payment', [
        'storeName' => $store->name,
    ]);
})->middleware(['auth', 'verified'])->name('payment');

Route::post('payment/process', [App\Http\Controllers\PaymentController::class, 'processPayment'])->middleware(['auth', 'verified'])->name('payment.process');
Route::get('payment/plans', [App\Http\Controllers\PaymentController::class, 'getPlans'])->middleware(['auth', 'verified'])->name('payment.plans');

// Subscription Routes (User-facing)
Route::middleware(['auth', 'verified'])->prefix('subscription')->name('subscription.')->group(function () {
    Route::get('/', [SubscriptionController::class, 'index'])->name('index');
    Route::get('/checkout/{plan}', [SubscriptionController::class, 'checkout'])->name('checkout');
    Route::get('/checkout-outlet', [SubscriptionController::class, 'checkoutOutlet'])->name('checkout-outlet');
    Route::get('/checkout-attendance-payroll', [SubscriptionController::class, 'checkoutAttendancePayroll'])->name('checkout-attendance-payroll');
    Route::post('/apply-voucher', [SubscriptionController::class, 'applyVoucher'])->name('apply-voucher');
    Route::post('/pay', [SubscriptionController::class, 'submitPayment'])->name('pay');
    Route::post('/pay-outlet', [SubscriptionController::class, 'submitPaymentOutlet'])->name('pay-outlet');
    Route::post('/buy-outlet-addon', [SubscriptionController::class, 'submitOutletAddOn'])->name('buy-outlet-addon');
    Route::post('/buy-attendance-payroll', [SubscriptionController::class, 'submitAttendancePayroll'])->name('buy-attendance-payroll');
    Route::post('/upload-proof/{payment}', [SubscriptionController::class, 'uploadProof'])->name('upload-proof');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('stores/switch', [App\Http\Controllers\StoreAccessController::class, 'switch'])->name('stores.switch');
    Route::post('stores/{store}/members', [App\Http\Controllers\StoreAccessController::class, 'attachMember'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('stores.members.attach');
    Route::post('stores/{store}/members/create', [App\Http\Controllers\StoreAccessController::class, 'createMember'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('stores.members.create');
    Route::patch('stores/{store}/members/{user}', [App\Http\Controllers\StoreAccessController::class, 'updateMember'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('stores.members.update');
    Route::delete('stores/{store}/members/{user}', [App\Http\Controllers\StoreAccessController::class, 'removeMember'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('stores.members.destroy');
    Route::resource('stores', StoreController::class);

    // Tim & Akses
    Route::get('manage-staff', [StoreController::class, 'manageStaff'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('manage-staff');

    // Hak Akses Menu (per role)
    Route::get('menu-permissions', [App\Http\Controllers\StoreAccessController::class, 'menuPermissions'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('menu-permissions.index');
    Route::post('menu-permissions', [App\Http\Controllers\StoreAccessController::class, 'updateMenuPermissions'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('menu-permissions.update');

    // Kelola Produk — salin produk dari Gudang Pusat ke outlet
    Route::get('manage-products', [StoreController::class, 'manageProducts'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('manage-products');
    Route::post('manage-products/copy', [StoreController::class, 'copyProductsToOutlets'])
        ->middleware(['store.active', 'role.store_admin'])
        ->name('manage-products.copy');
    Route::get('products/catalog', [App\Http\Controllers\ProductCatalogController::class, 'index'])
        ->middleware(['store.active'])
        ->name('products.catalog');

    // Ekspor & impor produk — didaftarkan sebelum resource agar tidak tertangkap products/{product}
    Route::middleware(['store.active', 'role.inventory'])->group(function () {
        Route::get('products/export', [App\Http\Controllers\ProductController::class, 'exportExcel'])
            ->name('products.export');
        Route::get('products/export/with-components', [App\Http\Controllers\ProductController::class, 'exportWithComponents'])
            ->name('products.export.with-components');
        Route::get('products/import/template', [App\Http\Controllers\ProductController::class, 'importTemplate'])
            ->name('products.import.template');
        Route::post('products/import', [App\Http\Controllers\ProductController::class, 'importStore'])
            ->name('products.import');
        Route::post('products/bulk-delete', [App\Http\Controllers\ProductController::class, 'bulkDestroy'])
            ->name('products.bulk-delete');
    });

    Route::resource('products', App\Http\Controllers\ProductController::class)->middleware(['store.active', 'role.inventory']);

    Route::post('product-batches/{product_batch}/destroy', [App\Http\Controllers\ProductBatchController::class, 'destroy'])
        ->name('product-batches.destroy')->middleware(['store.active', 'role.inventory']);
    Route::post('product-batches/{product_batch}/quarantine', [App\Http\Controllers\ProductBatchController::class, 'quarantine'])
        ->name('product-batches.quarantine')->middleware(['store.active', 'role.inventory']);
    Route::post('product-batches/{product_batch}/release', [App\Http\Controllers\ProductBatchController::class, 'release'])
        ->name('product-batches.release')->middleware(['store.active', 'role.inventory']);

    // Barcode lookup & scan-log
    Route::prefix('barcode')->name('barcode.')->middleware(['store.active'])->group(function () {
        Route::get('lookup', [App\Http\Controllers\BarcodeController::class, 'lookup'])->name('lookup');
        Route::post('scan-log', [App\Http\Controllers\BarcodeController::class, 'scanLog'])->name('scan-log');
    });

    Route::middleware(['store.active', 'role.inventory'])->group(function () {
        Route::get('product-categories', [App\Http\Controllers\ProductController::class, 'productCategoriesIndex'])
            ->name('product-categories.index');
        Route::post('product-categories', [App\Http\Controllers\ProductController::class, 'productCategoriesStore'])
            ->name('product-categories.store');
        Route::post('product-categories/quick', [App\Http\Controllers\ProductController::class, 'productCategoriesQuickStore'])
            ->name('product-categories.quick-store');
        Route::match(['put', 'patch'], 'product-categories/{product_category}', [App\Http\Controllers\ProductController::class, 'productCategoriesUpdate'])
            ->name('product-categories.update');
        Route::delete('product-categories/{product_category}', [App\Http\Controllers\ProductController::class, 'productCategoriesDestroy'])
            ->name('product-categories.destroy');
    });
    Route::get('customers/search', [App\Http\Controllers\CustomerController::class, 'search'])->middleware(['store.active']);
    Route::post('customers/quick-store', [App\Http\Controllers\CustomerController::class, 'quickStore'])->middleware(['store.active']);
    Route::resource('customers', App\Http\Controllers\CustomerController::class)->middleware(['store.active', 'role.backoffice']);

    Route::resource('discounts', App\Http\Controllers\DiscountController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['store.active', 'role.backoffice']);

    // Beban Operasional
    Route::get('expenses', [App\Http\Controllers\OperationalExpenseController::class, 'index'])->name('expenses.index')->middleware(['store.active', 'role.backoffice']);
    Route::post('expenses', [App\Http\Controllers\OperationalExpenseController::class, 'store'])->name('expenses.store')->middleware(['store.active', 'role.backoffice']);
    Route::delete('expenses/{expense}', [App\Http\Controllers\OperationalExpenseController::class, 'destroy'])->name('expenses.destroy')->middleware(['store.active', 'role.backoffice']);

    // Log Aktivitas
    Route::get('activity-logs', [App\Http\Controllers\ActivityLogController::class, 'index'])->name('activity-logs.index')->middleware(['store.active']);

    // KPI Dashboard
    Route::get('kpi', [App\Http\Controllers\KPIController::class, 'index'])->name('kpi.index')->middleware(['store.active']);
    Route::post('kpi/settings', [App\Http\Controllers\KPIController::class, 'settings'])->name('kpi.settings')->middleware(['store.active', 'role.store_admin']);

    // Program Loyalitas & Poin
    Route::prefix('loyalty')->name('loyalty.')->middleware(['store.active', 'role.backoffice'])->group(function () {
        Route::get('/', [App\Http\Controllers\LoyaltyController::class, 'index'])->name('index');
        Route::put('/settings', [App\Http\Controllers\LoyaltyController::class, 'updateSettings'])->name('settings.update');
        Route::post('/rewards', [App\Http\Controllers\LoyaltyController::class, 'storeReward'])->name('rewards.store');
        Route::match(['put', 'patch'], '/rewards/{reward}', [App\Http\Controllers\LoyaltyController::class, 'updateReward'])->name('rewards.update');
        Route::delete('/rewards/{reward}', [App\Http\Controllers\LoyaltyController::class, 'destroyReward'])->name('rewards.destroy');
        Route::post('/redeem', [App\Http\Controllers\LoyaltyController::class, 'redeem'])->name('redeem');
        Route::post('/adjust', [App\Http\Controllers\LoyaltyController::class, 'adjust'])->name('adjust');
        Route::post('/promo', [App\Http\Controllers\LoyaltyController::class, 'broadcastPromo'])->name('promo');
        Route::get('/notifications', [App\Http\Controllers\LoyaltyController::class, 'notifications'])->name('notifications');
        Route::post('/notifications/{notification}/read', [App\Http\Controllers\LoyaltyController::class, 'markNotificationRead'])->name('notifications.read');
    });

    Route::get('stock-movements', [App\Http\Controllers\StockMovementController::class, 'index'])
        ->name('stock-movements.index')
        ->middleware(['store.active', 'role.inventory']);

    Route::post('stock-adjustments/{stock_adjustment}/approve', [App\Http\Controllers\StockAdjustmentController::class, 'approve'])
        ->name('stock-adjustments.approve')
        ->middleware(['store.active', 'role.stock_adjustment', 'plan.advanced']);
    Route::post('stock-adjustments/{stock_adjustment}/cancel', [App\Http\Controllers\StockAdjustmentController::class, 'cancel'])
        ->name('stock-adjustments.cancel')
        ->middleware(['store.active', 'role.stock_adjustment', 'plan.advanced']);
    Route::resource('stock-adjustments', App\Http\Controllers\StockAdjustmentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.stock_adjustment', 'plan.advanced']);

    Route::post('stock-opnames/{stock_opname}/draft', [App\Http\Controllers\StockOpnameController::class, 'saveDraft'])
        ->name('stock-opnames.draft')
        ->middleware(['store.active', 'role.inventory', 'plan.advanced']);
    Route::post('stock-opnames/{stock_opname}/post', [App\Http\Controllers\StockOpnameController::class, 'post'])
        ->name('stock-opnames.post')
        ->middleware(['store.active', 'role.inventory', 'plan.advanced']);
    Route::post('stock-opnames/{stock_opname}/approve', [App\Http\Controllers\StockOpnameController::class, 'approve'])
        ->name('stock-opnames.approve')
        ->middleware(['store.active', 'role.inventory', 'plan.advanced']);
    Route::post('stock-opnames/{stock_opname}/cancel', [App\Http\Controllers\StockOpnameController::class, 'cancel'])
        ->name('stock-opnames.cancel')
        ->middleware(['store.active', 'role.inventory', 'plan.advanced']);
    Route::resource('stock-opnames', App\Http\Controllers\StockOpnameController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.inventory', 'plan.advanced']);

    Route::get('stock-transfers/match', [App\Http\Controllers\StockTransferController::class, 'match'])
        ->name('stock-transfers.match')
        ->middleware(['store.active', 'role.inventory']);
    Route::post('stock-transfers/{stock_transfer}/send', [App\Http\Controllers\StockTransferController::class, 'send'])
        ->name('stock-transfers.send')
        ->middleware(['store.active', 'role.inventory']);
    Route::post('stock-transfers/{stock_transfer}/receive', [App\Http\Controllers\StockTransferController::class, 'receive'])
        ->name('stock-transfers.receive')
        ->middleware(['store.active', 'role.inventory']);
    Route::post('stock-transfers/{stock_transfer}/cancel', [App\Http\Controllers\StockTransferController::class, 'cancel'])
        ->name('stock-transfers.cancel')
        ->middleware(['store.active', 'role.inventory']);
    Route::resource('stock-transfers', App\Http\Controllers\StockTransferController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.inventory']);

    Route::resource('recipes', App\Http\Controllers\RecipeController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->middleware(['store.active', 'role.inventory']);

    Route::post('productions/{production_batch}/complete', [App\Http\Controllers\ProductionController::class, 'complete'])
        ->name('productions.complete')
        ->middleware(['store.active', 'role.inventory']);
    Route::post('productions/{production_batch}/cancel', [App\Http\Controllers\ProductionController::class, 'cancel'])
        ->name('productions.cancel')
        ->middleware(['store.active', 'role.inventory']);
    Route::resource('productions', App\Http\Controllers\ProductionController::class)
        ->parameters(['productions' => 'production_batch'])
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.inventory']);

    Route::resource('suppliers', App\Http\Controllers\SupplierController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['store.active', 'role.inventory']);

    Route::post('purchases/suppliers', [App\Http\Controllers\PurchaseController::class, 'storeSupplier'])
        ->name('purchases.suppliers.store')
        ->middleware(['store.active', 'role.inventory']);

    Route::resource('purchases', App\Http\Controllers\PurchaseController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy'])
        ->middleware(['store.active', 'role.inventory']);

    Route::post('purchase-payments', [App\Http\Controllers\PurchasePaymentController::class, 'store'])
        ->name('purchase-payments.store')
        ->middleware(['store.active', 'role.inventory']);

    Route::post('receivable-payments', [App\Http\Controllers\ReceivablePaymentController::class, 'store'])
        ->name('receivable-payments.store')
        ->middleware(['store.active']);

    Route::get('receivables', [App\Http\Controllers\ReceivablesController::class, 'index'])
        ->name('receivables.index')
        ->middleware(['store.active', 'role.backoffice']);
    Route::get('receivables/customers/{customer}', [App\Http\Controllers\ReceivablesController::class, 'showCustomer'])
        ->name('receivables.customers.show')
        ->middleware(['store.active', 'role.backoffice']);
    Route::get('receivables/{transaction}', [App\Http\Controllers\ReceivablesController::class, 'show'])
        ->name('receivables.show')
        ->middleware(['store.active', 'role.backoffice']);

    Route::resource('purchase-returns', App\Http\Controllers\PurchaseReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.inventory']);

    Route::resource('sales-returns', App\Http\Controllers\SalesReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.backoffice']);

    Route::resource('pre-orders', App\Http\Controllers\PreOrderController::class)
        ->only(['index', 'create', 'store', 'show', 'update'])
        ->middleware(['store.active', 'role.inventory']);
    Route::post('pre-orders/{pre_order}/cancel', [App\Http\Controllers\PreOrderController::class, 'cancel'])
        ->name('pre-orders.cancel')
        ->middleware(['store.active', 'role.inventory']);

    Route::resource('laundry-orders', App\Http\Controllers\LaundryOrderController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware(['store.active', 'role.pos']);
    Route::post('laundry-orders/{laundry_order}/status', [App\Http\Controllers\LaundryOrderController::class, 'updateStatus'])
        ->name('laundry-orders.status')
        ->middleware(['store.active', 'role.pos']);
    Route::post('laundry-orders/{laundry_order}/payment', [App\Http\Controllers\LaundryOrderController::class, 'recordPayment'])
        ->name('laundry-orders.payment')
        ->middleware(['store.active', 'role.pos']);
    Route::post('laundry-orders/{laundry_order}/cancel', [App\Http\Controllers\LaundryOrderController::class, 'cancel'])
        ->name('laundry-orders.cancel')
        ->middleware(['store.active', 'role.pos']);

    Route::prefix('cash-shifts')->name('cash-shifts.')->middleware(['store.active', 'role.pos'])->group(function () {
        Route::get('/', [App\Http\Controllers\CashShiftController::class, 'index'])->name('index');
        Route::post('/open', [App\Http\Controllers\CashShiftController::class, 'open'])->name('open');
        Route::post('/{cash_shift}/close', [App\Http\Controllers\CashShiftController::class, 'close'])->name('close');

        // Transaksi Kasir (Uang Masuk / Keluar)
        Route::get('/transactions', [App\Http\Controllers\CashTransactionController::class, 'index'])->name('transactions.index');
        Route::post('/transactions', [App\Http\Controllers\CashTransactionController::class, 'store'])->name('transactions.store');
    });

    Route::prefix('pos')->name('pos.')->middleware(['store.active', 'role.pos'])->group(function () {
        Route::get('/', [App\Http\Controllers\POSController::class, 'index'])->name('index');
        Route::get('/cash-shift-current', [App\Http\Controllers\POSController::class, 'cashShiftCurrent'])->name('cash-shift-current');
        Route::get('/transactions', [App\Http\Controllers\POSController::class, 'transactionHistory'])->name('transactions');
        Route::post('/add-item', [App\Http\Controllers\POSController::class, 'addItem'])->name('add-item');
        Route::post('/remove-item', [App\Http\Controllers\POSController::class, 'removeItem'])->name('remove-item');
        Route::post('/process-payment', [App\Http\Controllers\POSController::class, 'processPayment'])->name('process-payment');

        // Open Bills (Order Hold)
        Route::get('/order-holds', [App\Http\Controllers\POSController::class, 'orderHolds'])->name('order-holds');
        Route::post('/order-hold', [App\Http\Controllers\POSController::class, 'storeOrderHold'])->name('order-hold.store');
        Route::get('/order-holds/{transaction}', [App\Http\Controllers\POSController::class, 'showOrderHold'])->name('order-holds.show');
        Route::post('/order-holds/{transaction}/pay', [App\Http\Controllers\POSController::class, 'payOrderHold'])->name('order-holds.pay');
        Route::post('/order-holds/{transaction}/cancel', [App\Http\Controllers\POSController::class, 'cancelOrderHold'])->name('order-holds.cancel');
    });

    // Overview stok untuk kasir, admin, & gudang (read-only) — bisa pilih outlet
    Route::get('/stock-overview', [App\Http\Controllers\ReportController::class, 'stockOverview'])
        ->name('stock-overview')
        ->middleware(['store.active', 'role.stock_view']);

    Route::get('/transactions/{transaction}/receipt-data', [App\Http\Controllers\POSController::class, 'receiptJson'])
        ->name('transactions.receipt-data')
        ->middleware(['store.active', 'role.invoice']);

    Route::put('/transactions/{transaction}', [App\Http\Controllers\TransactionController::class, 'update'])
        ->name('transactions.update')
        ->middleware(['store.active', 'role.backoffice']);

    Route::post('/transactions/{transaction}/void', [App\Http\Controllers\TransactionController::class, 'void'])
        ->name('transactions.void')
        ->middleware(['store.active', 'role.backoffice']);

    Route::delete('/transactions/{transaction}', [App\Http\Controllers\TransactionController::class, 'destroy'])
        ->name('transactions.destroy')
        ->middleware(['store.active', 'role.backoffice']);

    Route::get('/invoice/{transaction}/pdf', [App\Http\Controllers\POSController::class, 'exportPdf'])->name('invoice.pdf')->middleware(['store.active', 'role.invoice']);

    Route::get('/invoice/{transaction}', [App\Http\Controllers\POSController::class, 'showInvoice'])->name('invoice.show')->middleware(['store.active', 'role.invoice']);

    Route::get('/invoice/{transaction}/json', [App\Http\Controllers\POSController::class, 'invoiceJson'])->name('invoice.json')->middleware(['store.active', 'role.invoice']);

    Route::get('/kitchen', [App\Http\Controllers\KitchenQueueController::class, 'index'])
        ->name('kitchen.index')
        ->middleware(['store.active', 'menu:kitchen_queue']);

    // Modul Antrian
    Route::get('/queues', [App\Http\Controllers\QueueController::class, 'index'])
        ->name('queues.index')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/queues', [App\Http\Controllers\QueueController::class, 'store'])
        ->name('queues.store')
        ->middleware(['store.active', 'role.pos']);
    Route::get('/queues/{queue}', [App\Http\Controllers\QueueController::class, 'show'])
        ->name('queues.show')
        ->middleware(['store.active', 'role.pos']);
    Route::patch('/queues/{queue}', [App\Http\Controllers\QueueController::class, 'update'])
        ->name('queues.update')
        ->middleware(['store.active', 'role.pos']);
    Route::delete('/queues/{queue}', [App\Http\Controllers\QueueController::class, 'destroy'])
        ->name('queues.destroy')
        ->middleware(['store.active', 'role.pos']);

    Route::patch('/queue-entries/{queue_entry}', [App\Http\Controllers\QueueController::class, 'updateEntry'])
        ->name('queue-entries.update')
        ->middleware(['store.active', 'menu:kitchen_queue']);
    Route::delete('/queue-entries/{queue_entry}', [App\Http\Controllers\QueueController::class, 'destroyEntry'])
        ->name('queue-entries.destroy')
        ->middleware(['store.active', 'role.pos']);

    // Modul Scan Meja (Manajemen Meja)
    Route::get('/tables', [App\Http\Controllers\TableController::class, 'index'])
        ->name('tables.index')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/tables', [App\Http\Controllers\TableController::class, 'store'])
        ->name('tables.store')
        ->middleware(['store.active', 'role.pos']);
    Route::patch('/tables/{table}', [App\Http\Controllers\TableController::class, 'update'])
        ->name('tables.update')
        ->middleware(['store.active', 'role.pos']);
    Route::delete('/tables/{table}', [App\Http\Controllers\TableController::class, 'destroy'])
        ->name('tables.destroy')
        ->middleware(['store.active', 'role.pos']);
    Route::patch('/tables/{table}/toggle', [App\Http\Controllers\TableController::class, 'toggleActive'])
        ->name('tables.toggle')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/tables/{table}/qr', [App\Http\Controllers\TableController::class, 'regenerateQr'])
        ->name('tables.qr')
        ->middleware(['store.active', 'role.pos']);

    // Pesanan Masuk dari Scan Meja (Pesanan Meja)
    Route::get('/table-orders', [App\Http\Controllers\TableOrderController::class, 'index'])
        ->name('table-orders.index')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/table-orders/{transaction}/accept', [App\Http\Controllers\TableOrderController::class, 'accept'])
        ->name('table-orders.accept')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/table-orders/{transaction}/pay', [App\Http\Controllers\TableOrderController::class, 'pay'])
        ->name('table-orders.pay')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/table-orders/{transaction}/complete', [App\Http\Controllers\TableOrderController::class, 'complete'])
        ->name('table-orders.complete')
        ->middleware(['store.active', 'role.pos']);
    Route::post('/table-orders/{transaction}/reject', [App\Http\Controllers\TableOrderController::class, 'reject'])
        ->name('table-orders.reject')
        ->middleware(['store.active', 'role.pos']);

    Route::prefix('reports')->name('reports.')->middleware(['store.active', 'role.backoffice'])->group(function () {
        Route::get('/', [App\Http\Controllers\ReportController::class, 'index'])->name('index');
        Route::get('/sales', [App\Http\Controllers\ReportController::class, 'sales'])->name('sales');
        Route::get('/purchases', [App\Http\Controllers\ReportController::class, 'purchases'])->name('purchases');
        Route::get('/production', [App\Http\Controllers\ReportController::class, 'production'])->name('production');
        Route::get('/inventory', [App\Http\Controllers\ReportController::class, 'inventory'])->name('inventory');
        Route::get('/cash-shifts', [App\Http\Controllers\ReportController::class, 'cashShifts'])->name('cash-shifts');
        Route::get('/export/cash-shifts-excel', [App\Http\Controllers\ReportController::class, 'exportCashShiftsExcel'])->name('export.cash-shifts-excel');
        Route::get('/export/pdf', [App\Http\Controllers\ReportController::class, 'exportPDF'])->name('export.pdf');
        Route::get('/export/pdf-stream', [App\Http\Controllers\ReportController::class, 'exportPDFStream'])->name('export.pdf-stream');
        Route::get('/export/excel', [App\Http\Controllers\ReportController::class, 'exportExcel'])->name('export.excel');
        Route::get('/export/test', [App\Http\Controllers\ReportController::class, 'testExport'])->name('export.test');

        Route::get('/cash-flow', [App\Http\Controllers\CashFlowController::class, 'index'])->name('cash-flow');
        Route::get('/cash-transactions', [App\Http\Controllers\CashTransactionController::class, 'report'])->name('cash-transactions');
        Route::get('/aging-payables', [App\Http\Controllers\AgingPayablesController::class, 'index'])->name('aging-payables');
        Route::get('/tax/ppn-summary', [App\Http\Controllers\ReportController::class, 'taxPpnSummary'])->name('tax.ppn-summary');
        Route::get('/destructions', [App\Http\Controllers\StockReportController::class, 'destructions'])->name('destructions');
        Route::get('/stock-variance', [App\Http\Controllers\StockReportController::class, 'variance'])
            ->name('stock-variance')
            ->middleware('plan.advanced');
        Route::get('/stock-card', [App\Http\Controllers\StockReportController::class, 'stockCard'])
            ->name('stock-card')
            ->middleware('plan.advanced');

        // Ekspor generik semua laporan ke PDF / Excel.
        Route::get('/export/{report}/{format}', [App\Http\Controllers\ReportExportController::class, 'export'])
            ->whereIn('format', ['pdf', 'excel'])
            ->name('export.generic');
    });
});

// ── Modul Akuntansi — URL /financial/... ──
Route::middleware(['auth', 'verified', 'store.active', 'role.backoffice'])->prefix('financial')->name('financial.')->group(function () {
    Route::get('/accounting-periods', [App\Http\Controllers\AccountingPeriodController::class, 'index'])->name('accounting-periods');
    Route::post('/accounting-periods', [App\Http\Controllers\AccountingPeriodController::class, 'store'])->name('accounting-periods.store');
    Route::get('/accounting-periods/{period}/checklist', [App\Http\Controllers\AccountingPeriodController::class, 'checklist'])->name('accounting-periods.checklist');
    Route::post('/accounting-periods/{period}/close', [App\Http\Controllers\AccountingPeriodController::class, 'close'])->name('accounting-periods.close');
    Route::post('/accounting-periods/{period}/reopen', [App\Http\Controllers\AccountingPeriodController::class, 'reopen'])->name('accounting-periods.reopen');
    Route::get('/journal', [App\Http\Controllers\JournalEntryController::class, 'index'])->name('journal');
    Route::post('/journal/{entry}/reverse', [App\Http\Controllers\JournalEntryController::class, 'reverse'])->name('journal.reverse');
    Route::get('/trial-balance', [App\Http\Controllers\ReportController::class, 'financialTrialBalance'])->name('trial-balance');
    Route::get('/balance-sheet', [App\Http\Controllers\ReportController::class, 'financialBalanceSheet'])->name('balance-sheet');
    Route::post('/capital-injection', [App\Http\Controllers\ReportController::class, 'storeCapitalInjection'])->name('capital-injection');
    Route::get('/income-statement', [App\Http\Controllers\ReportController::class, 'financialIncomeStatement'])->name('income-statement');
    Route::get('/calk', [App\Http\Controllers\ReportController::class, 'financialCaLK'])->name('calk');
    Route::get('/reconciliation', [App\Http\Controllers\ReportController::class, 'financialReconciliation'])->name('reconciliation');
    Route::get('/statement-of-equity-changes', [App\Http\Controllers\ReportController::class, 'statementOfEquityChanges'])->name('statement-of-equity-changes');
    Route::get('/cash-flow', [App\Http\Controllers\ReportController::class, 'financialCashFlow'])->name('cash-flow');
    Route::post('/report-status', [App\Http\Controllers\ReportController::class, 'updateFinancialReportStatus'])->name('report-status');
    Route::post('/report-reviewer', [App\Http\Controllers\ReportController::class, 'updateReportReviewer'])->name('report-reviewer');
});

// Owner Dashboard Routes
// ── Fitur rahasia: copy stok dari outlet utama ke outlet lain ──
Route::get('dev/copy-stock', App\Http\Controllers\CopyStockController::class)
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.copy-stock');
Route::get('dev/disable-sellable-tracking', [App\Http\Controllers\CopyStockController::class, 'disableSellableStockTracking'])
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.disable-sellable-tracking');
Route::get('dev/copy-sale-consumptions', [App\Http\Controllers\CopyStockController::class, 'copySaleConsumptions'])
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.copy-sale-consumptions');
Route::get('dev/reset-raw-stock', [App\Http\Controllers\CopyStockController::class, 'resetRawMaterialStock'])
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.reset-raw-stock');
Route::get('dev/reset-sellable-stock', [App\Http\Controllers\CopyStockController::class, 'resetSellableStock'])
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.reset-sellable-stock');
Route::get('dev/debug-consumptions', [App\Http\Controllers\CopyStockController::class, 'debugConsumptions'])
    ->middleware(['auth', 'verified', 'store.active'])
    ->name('dev.debug-consumptions');

Route::prefix('owner')->name('owner.')->middleware(['auth', 'owner'])->group(function () {
    Route::get('/dashboard', [App\Http\Controllers\OwnerDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/revenue', [App\Http\Controllers\OwnerDashboardController::class, 'revenue'])->name('dashboard.revenue');
    Route::get('/dashboard/export', [App\Http\Controllers\OwnerDashboardController::class, 'exportExcel'])->name('dashboard.export');
});

// Super Admin Routes
Route::prefix('superadmin')->name('superadmin.')->middleware(['auth', 'superadmin'])->group(function () {
    Route::get('/', [SuperAdminController::class, 'dashboard'])->name('dashboard');

    // Store management
    Route::post('/stores/{store}/extend', [SuperAdminController::class, 'extendSubscription'])->name('stores.extend');
    Route::post('/stores/{store}/toggle', [SuperAdminController::class, 'toggleStoreStatus'])->name('stores.toggle');
    Route::post('/stores/{store}/activate-trial', [SuperAdminController::class, 'activateProTrial'])->name('stores.activate-trial');
    Route::post('/stores/{store}/stop-trial', [SuperAdminController::class, 'stopProTrial'])->name('stores.stop-trial');
    Route::get('/trial-logs', [SuperAdminController::class, 'trialLogs'])->name('trial-logs');
    Route::delete('/stores/{store}', [SuperAdminController::class, 'deleteStore'])->name('stores.delete');

    // Subscription plan management
    Route::post('/plans', [SuperAdminController::class, 'createPlan'])->name('plans.create');
    Route::put('/plans/{plan}', [SuperAdminController::class, 'updatePlan'])->name('plans.update');
    Route::delete('/plans/{plan}', [SuperAdminController::class, 'deletePlan'])->name('plans.delete');

    // Payment confirmation
    Route::post('/payments/{payment}/confirm', [SuperAdminController::class, 'confirmPayment'])->name('payments.confirm');
    Route::post('/payments/{payment}/reject', [SuperAdminController::class, 'rejectPayment'])->name('payments.reject');
    Route::get('/payments', [SuperAdminController::class, 'allPayments'])->name('payments.index');
    Route::post('/transfer-settings', [SuperAdminController::class, 'updateTransferSettings'])->name('transfer-settings.update');
    Route::post('/attendance-payroll-pricing', [SuperAdminController::class, 'updateAttendancePayrollPricing'])->name('attendance-payroll-pricing.update');

    // Voucher management
    Route::post('/vouchers', [SuperAdminController::class, 'createVoucher'])->name('vouchers.create');
    Route::put('/vouchers/{voucher}', [SuperAdminController::class, 'updateVoucher'])->name('vouchers.update');
    Route::delete('/vouchers/{voucher}', [SuperAdminController::class, 'deleteVoucher'])->name('vouchers.delete');
    Route::post('/stores/{store}/use-voucher', [SuperAdminController::class, 'useVoucher'])->name('stores.use-voucher');
    Route::get('/voucher-usages', [SuperAdminController::class, 'voucherUsages'])->name('voucher-usages');

    // AI Auditor (read-only analysis, khusus Super Admin)
    Route::get('/ai-auditor', [App\Http\Controllers\AiAuditorController::class, 'index'])->name('ai-auditor.index');
    Route::post('/ai-auditor/analyze', [App\Http\Controllers\AiAuditorController::class, 'analyze'])->name('ai-auditor.analyze');

    // Pengaturan AI (provider & API key) dari halaman Super Admin
    Route::get('/ai-settings', [App\Http\Controllers\AiSettingController::class, 'index'])->name('ai-settings.index');
    Route::put('/ai-settings', [App\Http\Controllers\AiSettingController::class, 'update'])->name('ai-settings.update');
    Route::delete('/ai-settings', [App\Http\Controllers\AiSettingController::class, 'destroy'])->name('ai-settings.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// ── Public Guest Routes: Scan Meja (Menu Digital) ──
Route::get('/m/{slug}', [App\Http\Controllers\GuestMenuController::class, 'show'])
    ->name('guest.menu');

Route::get('/m/{slug}/status/{statusToken}', [App\Http\Controllers\GuestMenuController::class, 'status'])
    ->name('guest.menu.status');

// Unduh PDF struk publik tanpa login, dilindungi token acak (status_token) per transaksi.
Route::get('/i/pdf/{statusToken}', [App\Http\Controllers\POSController::class, 'exportPublicPdf'])
    ->name('invoice.public-pdf');

Route::prefix('api/guest')->name('guest.')->group(function () {
    Route::post('/order', [App\Http\Controllers\GuestOrderController::class, 'store'])
        ->name('order.store');
    Route::get('/order/{statusToken}/status', [App\Http\Controllers\GuestOrderController::class, 'status'])
        ->name('order.status');
});
require __DIR__.'/attendance.php';
require __DIR__.'/payroll.php';
require __DIR__.'/cashier_pin.php';
