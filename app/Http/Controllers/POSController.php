<?php

namespace App\Http\Controllers;

use App\Enums\SalesChannel;
use App\Exceptions\ComponentStockInsufficientException;
use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\PreOrder;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Modules\CashierPin\Services\CashierPinService;
use App\Services\LoyaltyService;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\JournalPoster;
use App\Support\BatchTracking;
use App\Support\BusinessTypePresets;
use App\Support\FnBFeatures;
use App\Support\InventoryCosting;
use App\Support\InventoryType;
use App\Support\LoyaltySettings;
use App\Support\PharmacyFeatures;
use App\Support\ProductQuantity;
use App\Support\ReceiptSettings;
use App\Support\SaleConsumptionResolver;
use App\Support\StorePlanLimits;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class POSController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $products = $store->products()
            ->where('is_active', true)
            ->where(function ($q) {
                // Produk jual + bahan produksi (read-only) tampil di POS
                $q->where('inventory_type', InventoryType::SELLABLE);
                $q->orWhere('inventory_type', InventoryType::PRODUCTION_OUTPUT);
                $q->orWhereNull('inventory_type'); // legacy products
                $q->orWhere('is_bundle', true);    // bundle products
            })
            ->select([
                'id', 'store_id', 'name', 'sku', 'barcode', 'price', 'wholesale_price', 'stock_quantity',
                'category', 'product_category_id', 'inventory_type', 'unit', 'is_active', 'images', 'modifier_options', 'allows_decimal', 'has_variants', 'is_bundle',
                'track_stock', 'unlimited_stock', 'allow_negative_stock', 'pharmacy_sale_class',
            ])
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'bundleItems' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with([
                    'componentProduct' => fn ($q2) => $q2->select(
                        'id', 'store_id', 'name', 'sku', 'stock_quantity', 'allows_decimal', 'track_stock', 'unlimited_stock', 'has_variants', 'is_active',
                    ),
                    'componentVariant' => fn ($q3) => $q3->select('id', 'product_id', 'name', 'stock_quantity'),
                ]),
                'saleConsumptions' => fn ($q) => $q->select(
                    'id', 'product_id', 'product_variant_id', 'component_product_id', 'quantity', 'base_unit', 'sort_order',
                )->with([
                    'component' => fn ($q2) => $q2->select(
                        'id', 'name', 'stock_quantity', 'track_stock', 'unlimited_stock', 'inventory_type', 'base_unit',
                    ),
                ]),
            ])
            ->get()
            ->append('image_url');

        $activeDiscounts = $store->discounts()
            ->valid()
            ->get([
                'id', 'store_id', 'name', 'code', 'type', 'value', 'buy_x', 'get_y', 'minimum_amount',
                'applicable_products', 'applicable_categories',
            ]);

        $bt = $store->business_type ?? 'general';

        $preOrderHydration = null;
        if (request()->filled('pre_order')) {
            $po = PreOrder::query()
                ->where('store_id', $store->id)
                ->where('id', (int) request()->query('pre_order'))
                ->where('status', 'open')
                ->with(['lines'])
                ->first();
            if ($po) {
                $preOrderHydration = [
                    'id' => $po->id,
                    'order_number' => $po->order_number,
                    'deposit_received' => round((float) $po->deposit_received, 2),
                    'lines' => $po->lines->map(fn ($l) => [
                        'product_id' => $l->product_id,
                        'product_variant_id' => $l->product_variant_id,
                        'quantity' => (float) $l->quantity,
                        'modifiers' => $l->modifiers ?? [],
                    ])->values()->all(),
                ];
            }
        }

        $openCashShift = CashShift::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        $cashShiftOpen = null;
        if ($openCashShift) {
            $salesCash = (float) Transaction::query()
                ->where('cash_shift_id', $openCashShift->id)
                ->where('payment_method', 'cash')
                ->where('status', 'completed')
                ->sum('total_amount');

            $cashIn = (float) CashTransaction::query()
                ->where('cash_shift_id', $openCashShift->id)
                ->where('type', 'in')
                ->sum('amount');

            $cashOut = (float) CashTransaction::query()
                ->where('cash_shift_id', $openCashShift->id)
                ->where('type', 'out')
                ->sum('amount');

            $expectedCash = (float) $openCashShift->opening_cash + $salesCash + $cashIn - $cashOut;

            $expectedNonCash = (float) Transaction::query()
                ->where('cash_shift_id', $openCashShift->id)
                ->where('payment_method', '!=', 'cash')
                ->where('status', 'completed')
                ->sum('total_amount');

            $cashShiftOpen = [
                'id' => $openCashShift->id,
                'shift_number' => $openCashShift->shift_number,
                'opened_at' => $openCashShift->opened_at?->toIso8601String(),
                'opening_cash' => (float) $openCashShift->opening_cash,
                'expected_cash' => $expectedCash,
                'expected_non_cash' => $expectedNonCash,
            ];
        }

        // 10 produk paling sering dibeli (sepanjang waktu, transaksi completed)
        $popularProductIds = DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'completed')
            ->select('transaction_items.product_id', DB::raw('SUM(transaction_items.quantity) as total_qty'))
            ->groupBy('transaction_items.product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->pluck('product_id')
            ->toArray();

        $pinService = app(CashierPinService::class);
        $cashiersForPin = ($store->cashier_login_mode ?? 'email') === 'pin'
            ? $pinService->getCashiersForStore($store->id)
                ->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'position' => $pinService->roleLabel($member->pivot->role ?? null),
                    'has_pin' => $member->hasCashierPin(),
                ])
                ->values()
                ->all()
            : [];

        return Inertia::render('POS/Index', [
            'products' => $products,
            'popularProductIds' => $popularProductIds,
            'categories' => ProductCategory::where('store_id', $store->id)
                ->whereHas('products', function ($q) {
                    $q->where('is_active', true)
                        ->where(function ($q2) {
                            $q2->where('inventory_type', InventoryType::SELLABLE)
                                ->orWhereNull('inventory_type')
                                ->orWhere('is_bundle', true);
                        });
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->select('id', 'name', 'sort_order')
                ->get(),
            'authUserId' => $user->id,
            'preOrderHydration' => $preOrderHydration,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
                'tax_enabled' => $store->tax_enabled ?? false,
                'tax_rate' => $store->tax_rate ?? 11.00,
                'tax_name' => $store->tax_name ?? 'PPN',
                'business_type' => $bt,
                'fnb_enabled' => FnBFeatures::enabled($store),
                'fnb_order_types_enabled' => FnBFeatures::orderTypesEnabled($store),
                'fnb_split_bill_enabled' => FnBFeatures::splitBillEnabled($store),
                'open_bill_enabled' => FnBFeatures::openBillEnabled($store),
                'cup_label_enabled' => (bool) ($store->settings['cup_label_enabled'] ?? false),
                'pharmacy_enabled' => PharmacyFeatures::enabled($store),
                'default_service_charge_percent' => FnBFeatures::defaultServiceChargePercent($store),
                'receipt' => ReceiptSettings::payload($store),
                'pos_show_barcode' => (bool) ($store->settings['pos_show_barcode'] ?? false),
                'wholesale_enabled' => (bool) ($store->settings['wholesale_enabled'] ?? false),
                'online_sales_enabled' => (bool) ($store->settings['online_sales_enabled'] ?? false),
                'online_commission_gofood' => (float) ($store->settings['online_commission_gofood'] ?? 20),
                'online_commission_grabfood' => (float) ($store->settings['online_commission_grabfood'] ?? 20),
                'online_commission_shopeefood' => (float) ($store->settings['online_commission_shopeefood'] ?? 15),
            ],
            'activeDiscounts' => $activeDiscounts,
            'businessTypeLabel' => BusinessTypePresets::TYPES[$bt] ?? BusinessTypePresets::TYPES['general'],
            'cashShiftOpen' => $cashShiftOpen,
            'cashier_login_mode' => $store->cashier_login_mode ?? 'email',
            'active_cashier' => session('active_cashier'),
            'cashiers' => $cashiersForPin,
            'accountingPeriodLock' => AccountingPeriodGuard::posLockMessage($store, now()),
        ]);
    }

    /**
     * Riwayat transaksi kasir — render halaman Reports/Sales yang sudah ada.
     */
    public function transactionHistory(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Tidak ada toko aktif.');
        }

        $startDate = $request->input('date_from', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->input('date_to', now()->format('Y-m-d'));

        $query = Transaction::where('store_id', $store->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        // Cashier hanya melihat transaksi sendiri, admin/owner/superadmin melihat semua
        if ($user->isCashier()) {
            $query->where('user_id', $user->id);
        }

        $transactions = $query->with('customer:id,name,phone')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Reports/Sales', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'dateRange' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'transactions' => $transactions,
            'readonly' => true,
        ]);
    }

    /**
     * AJAX — ambil data cash shift aktif (JSON) supaya saldo POS bisa di-refresh tanpa reload.
     */
    public function cashShiftCurrent(): JsonResponse
    {
        $user = Auth::user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['cashShiftOpen' => null]);
        }

        $openCashShift = CashShift::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if (! $openCashShift) {
            return response()->json(['cashShiftOpen' => null]);
        }

        $salesCash = (float) Transaction::query()
            ->where('cash_shift_id', $openCashShift->id)
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->sum('total_amount');

        $cashIn = (float) CashTransaction::query()
            ->where('cash_shift_id', $openCashShift->id)
            ->where('type', 'in')
            ->sum('amount');

        $cashOut = (float) CashTransaction::query()
            ->where('cash_shift_id', $openCashShift->id)
            ->where('type', 'out')
            ->sum('amount');

        $expectedCash = (float) $openCashShift->opening_cash + $salesCash + $cashIn - $cashOut;

        $expectedNonCash = (float) Transaction::query()
            ->where('cash_shift_id', $openCashShift->id)
            ->where('payment_method', '!=', 'cash')
            ->where('status', 'completed')
            ->sum('total_amount');

        return response()->json([
            'cashShiftOpen' => [
                'id' => $openCashShift->id,
                'shift_number' => $openCashShift->shift_number,
                'opened_at' => $openCashShift->opened_at?->toIso8601String(),
                'opening_cash' => (float) $openCashShift->opening_cash,
                'expected_cash' => $expectedCash,
                'expected_non_cash' => $expectedNonCash,
            ],
        ]);
    }

    public function addItem(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $product = Product::findOrFail($request->product_id);

        try {
            $qty = ProductQuantity::normalizeLineQuantity($product, $request->quantity);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }

        // Produk dengan konfigurasi konsumsi komponen (F&B) — stok dikelola di level komponen,
        // validasi dilakukan di processPayment(). Skip validasi stok produk induk.
        $store = auth()->user()->store;
        $hasConsumptionConfig = $store && SaleConsumptionResolver::hasConfig(
            $store,
            $product,
            null,
        );

        if (! $hasConsumptionConfig && $product->track_stock && ! $product->unlimited_stock && ! $product->allow_negative_stock && ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
            return response()->json([
                'error' => 'Stok tidak mencukupi',
            ], 400);
        }

        return response()->json([
            'product' => $product,
            'quantity' => $qty,
            'subtotal' => round((float) $product->price * (float) $qty, 2),
        ]);
    }

    public function removeItem(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $product = Product::findOrFail($request->product_id);

        return response()->json([
            'success' => true,
            'message' => 'Item berhasil dihapus dari keranjang',
        ]);
    }

    public function processPayment(Request $request)
    {
        try {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
                'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
                'items.*.unit_price' => 'nullable|numeric|min:0',
                'items.*.subtotal' => 'nullable|numeric|min:0',
                'items.*.product_name' => 'nullable|string|max:255',
                'items.*.variant_name' => 'nullable|string|max:255',
                'items.*.modifiers' => 'nullable|array',
                'items.*.modifiers.*.key' => 'required|string|max:64',
                'payment_method' => 'required|in:cash,card,ewallet,transfer,other,credit',
                'amount_paid' => 'required|numeric|min:0',
                'is_credit' => 'nullable|boolean',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'debt_due_at' => 'nullable|date',
                'total_amount' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'customer_name' => 'nullable|string|max:255',
                'customer_type' => 'nullable|in:ecer,grosir',
                'discount_code' => 'nullable|string|max:100',
                'order_type' => 'nullable|in:dine_in,takeaway,delivery',
                'sales_channel' => 'nullable|string|in:'.implode(',', array_column(SalesChannel::cases(), 'value')),
                'commission_percent' => 'nullable|numeric|min:0|max:100',
                'table_reference' => 'nullable|string|max:64',
                'service_charge_percent' => 'nullable|numeric|min:0|max:100',
                'tip_amount' => 'nullable|numeric|min:0',
                'pre_order_id' => 'nullable|integer|exists:pre_orders,id',
                'split_bill_count' => 'nullable|integer|min:1|max:20',
                'pharmacy_sale_reference' => 'nullable|string|max:512',
                'prescription' => 'nullable|array',
                'prescription.reference' => 'nullable|string|max:512',
                'prescription.number' => 'nullable|string|max:512',
                'prescription.patient_name' => 'nullable|string|max:255',
                'prescription.doctor_name' => 'nullable|string|max:255',
                'prescription.issued_at' => 'nullable|date',
                'prescription.items' => 'nullable|array',
                'prescription.items.*.product_id' => 'required_with:prescription.items|integer|exists:products,id',
                'prescription.items.*.quantity' => 'required_with:prescription.items|numeric|min:0.0001',
                'storeId' => 'nullable|integer',
                'created_at' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validasi POS gagal', [
                'errors' => $e->errors(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $offlineSaleId = $request->header('X-Offline-Sale-Id');
        $isCredit = (bool) $request->input('is_credit', false);

        // Transaksi kredit diblokir ketika request berasal dari antrean offline.
        if ($isCredit && $offlineSaleId) {
            return response()->json([
                'success' => false,
                'message' => 'Penjualan kredit tidak dapat diproses dalam mode offline.',
            ], 422);
        }

        if ($offlineSaleId) {
            $existing = Transaction::where('offline_sale_id', $offlineSaleId)->first();
            if ($existing) {
                $existing->load(['items.product', 'items.productVariant', 'store', 'user', 'discount', 'customer']);
                $payload = $this->receiptTransactionPayload($existing, $store);

                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'transaction' => $payload,
                    'message' => 'Transaksi duplicate (sudah diproses sebelumnya)',
                ]);
            }
        }

        // Aturan sistem: transaksi tidak boleh diproses tanpa shift kasir terbuka.
        // Berlaku hanya bila fitur shift aktif (paket advanced retail) — pengguna
        // paket dasar tidak memakai modul shift sehingga tetap boleh transaksi.
        if (StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            $hasOpenShift = CashShift::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->exists();

            if (! $hasOpenShift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Buka shift kasir terlebih dahulu sebelum melakukan transaksi.',
                ], 422);
            }
        }

        $periodLock = AccountingPeriodGuard::posLockMessage($store, now());
        if ($periodLock) {
            return response()->json([
                'success' => false,
                'message' => $periodLock,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $lockedPreOrder = null;
            if ($request->filled('pre_order_id')) {
                $lockedPreOrder = PreOrder::query()
                    ->where('store_id', $store->id)
                    ->where('id', (int) $request->input('pre_order_id'))
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
                if (! $lockedPreOrder) {
                    throw new \Exception('Pre-order tidak ditemukan, sudah selesai, atau dibatalkan.');
                }
            }

            $cartLines = [];
            $subtotal = 0.0;
            $cartHasPrescription = false;
            $cartHasLimited = false;
            $cartPrescriptionItems = [];
            $customerType = ($store->settings['wholesale_enabled'] ?? false) && $request->input('customer_type') === 'grosir' ? 'grosir' : 'ecer';

            foreach ($request->items as $item) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('id', $item['product_id'])
                    ->firstOrFail();

                if (! $product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak aktif");
                }

                if (PharmacyFeatures::enabled($store)) {
                    $saleClass = (string) ($product->pharmacy_sale_class ?? 'otc');
                    if ($saleClass === 'prescription') {
                        $cartHasPrescription = true;
                    } elseif ($saleClass === 'limited') {
                        $cartHasLimited = true;
                    }
                }

                try {
                    $qty = ProductQuantity::normalizeLineQuantity($product, $item['quantity']);
                } catch (\InvalidArgumentException $e) {
                    throw new \Exception($e->getMessage());
                }

                $variant = null;

                if ($product->is_bundle) {
                    if (! empty($item['product_variant_id'])) {
                        throw new \Exception("Produk paket «{$product->name}» tidak memakai varian.");
                    }
                    if ($product->has_variants) {
                        throw new \Exception('Konfigurasi produk paket tidak valid.');
                    }
                    $rawModsBundle = $item['modifiers'] ?? null;
                    if (is_array($rawModsBundle) && count($rawModsBundle) > 0) {
                        throw new \Exception('Produk paket tidak mendukung modifier.');
                    }
                    $product->load([
                        'bundleItems' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with(['componentProduct', 'componentVariant']),
                    ]);
                    if ($product->bundleItems->isEmpty()) {
                        throw new \Exception("Paket «{$product->name}» belum memiliki komponen.");
                    }
                    foreach ($product->bundleItems as $bi) {
                        $comp = $bi->componentProduct;
                        if (! $comp || (int) $comp->store_id !== (int) $store->id || ! $comp->is_active) {
                            throw new \Exception('Komponen paket tidak valid atau nonaktif.');
                        }
                        $vComp = $bi->componentVariant;
                        if ($comp->has_variants) {
                            if (! $vComp || (int) $vComp->product_id !== (int) $comp->id) {
                                throw new \Exception('Komponen paket «'.$comp->name.'» tidak lengkap (varian).');
                            }
                        } elseif ($vComp) {
                            throw new \Exception('Data komponen paket tidak konsisten.');
                        }
                        $need = bcmul($qty, (string) $bi->quantity, ProductQuantity::SCALE);
                        if (ProductQuantity::cmp($need, '0.0000') <= 0) {
                            continue;
                        }
                        if (! $comp->track_stock || $comp->unlimited_stock || $comp->allow_negative_stock) {
                            continue;
                        }

                        // Refresh data dari database untuk memastikan stok terbaru
                        $comp->refresh();
                        if ($vComp) {
                            $vComp->refresh();
                        }

                        if ($vComp) {
                            if (ProductQuantity::lt((string) $vComp->stock_quantity, $need)) {
                                throw new \Exception("Stok komponen «{$comp->name}» ({$vComp->name}) tidak mencukupi untuk jumlah paket ini.");
                            }
                        } elseif (ProductQuantity::lt((string) $comp->stock_quantity, $need)) {
                            throw new \Exception("Stok komponen «{$comp->name}» tidak mencukupi untuk jumlah paket ini.");
                        }
                    }
                } else {
                    if (! empty($item['product_variant_id'])) {
                        $variant = ProductVariant::query()
                            ->where('id', (int) $item['product_variant_id'])
                            ->where('product_id', $product->id)
                            ->where('store_id', $store->id)
                            ->where('is_active', true)
                            ->first();
                        if (! $variant) {
                            throw new \Exception("Varian tidak valid untuk {$product->name}.");
                        }
                    }

                    if ($product->has_variants) {
                        if (! $variant) {
                            throw new \Exception("Pilih varian untuk {$product->name}.");
                        }
                    } elseif ($variant) {
                        throw new \Exception("Produk {$product->name} tidak memakai varian.");
                    }

                    // Refresh data dari database untuk memastikan stok terbaru
                    $product->refresh();
                    if ($variant) {
                        $variant->refresh();
                    }

                    if ($product->track_stock && ! $product->unlimited_stock && ! $product->allow_negative_stock) {
                        if ($variant && ! $variant->allow_negative_stock) {
                            if (ProductQuantity::lt((string) $variant->stock_quantity, $qty)) {
                                throw new \Exception("Stok varian {$product->name} ({$variant->name}) tidak mencukupi");
                            }
                        } elseif (ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                            throw new \Exception("Stok produk {$product->name} tidak mencukupi");
                        }
                    }

                    // Validasi stok komponen konsumsi penjualan
                    SaleConsumptionResolver::validateStock($store, $product, $variant, $qty);
                }

                $modifiers = $this->normalizeLineModifiers($product, $item['modifiers'] ?? null);
                $modifierUnitSum = 0.0;
                foreach ($modifiers as $m) {
                    $modifierUnitSum += (float) $m['price'];
                }
                $baseUnit = $variant
                    ? $this->resolveLinePrice($variant->wholesale_price ?? $product->wholesale_price, $variant->effectiveUnitPrice(), $customerType)
                    : $this->resolveLinePrice($product->wholesale_price, (float) $product->price, $customerType);
                $unitPrice = round($baseUnit + $modifierUnitSum, 2);
                $lineTotal = round($unitPrice * (float) $qty, 2);
                $subtotal += $lineTotal;
                if (PharmacyFeatures::enabled($store) && ($product->pharmacy_sale_class ?? 'otc') === 'prescription') {
                    $cartPrescriptionItems[$product->id] = isset($cartPrescriptionItems[$product->id])
                        ? bcadd($cartPrescriptionItems[$product->id], $qty, ProductQuantity::SCALE)
                        : $qty;
                }
                $cartLines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                    'unit_price' => $unitPrice,
                    'modifiers' => $modifiers,
                ];
            }

            $subtotal = round($subtotal, 2);

            $pharmacySaleReference = '';
            $prescription = null;
            if (PharmacyFeatures::enabled($store)) {
                $rawPrescription = $request->input('prescription');
                if (is_array($rawPrescription)) {
                    $prescription = array_filter([
                        'reference' => isset($rawPrescription['reference']) ? trim((string) $rawPrescription['reference']) : null,
                        'number' => isset($rawPrescription['number']) ? trim((string) $rawPrescription['number']) : null,
                        'patient_name' => isset($rawPrescription['patient_name']) ? trim((string) $rawPrescription['patient_name']) : null,
                        'doctor_name' => isset($rawPrescription['doctor_name']) ? trim((string) $rawPrescription['doctor_name']) : null,
                        'issued_at' => $rawPrescription['issued_at'] ?? null,
                    ], fn ($value) => $value !== null && $value !== '');
                    $prescription = $prescription ?: null;
                }
                $rawPhRef = $request->input('pharmacy_sale_reference');
                $pharmacySaleReference = is_string($rawPhRef) ? trim(mb_substr($rawPhRef, 0, 512)) : '';
                $pharmacySaleReference = $pharmacySaleReference !== '' ? $pharmacySaleReference : (string) ($prescription['reference'] ?? $prescription['number'] ?? '');
                if ($cartHasPrescription && $pharmacySaleReference === '') {
                    throw new \Exception('Transaksi berisi obat resep: isi nomor atau rujukan resep terlebih dahulu.');
                }
                if ($cartHasPrescription) {
                    $canonicalPrescriptionItems = collect($cartPrescriptionItems)
                        ->map(fn ($quantity, $productId) => [
                            'product_id' => (int) $productId,
                            'quantity' => $quantity,
                        ])
                        ->values()
                        ->all();

                    if (is_array($rawPrescription) && array_key_exists('items', $rawPrescription)) {
                        if ($rawPrescription['items'] === []) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'prescription.items' => 'Daftar item resep wajib diisi untuk obat resep.',
                            ]);
                        }

                        $prescriptionItems = [];
                        foreach ($rawPrescription['items'] as $item) {
                            $productId = (int) $item['product_id'];
                            $product = Product::query()->where('store_id', $store->id)->find($productId);
                            if (! $product || ($product->pharmacy_sale_class ?? 'otc') !== 'prescription') {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'prescription.items' => 'Daftar item resep tidak cocok dengan keranjang.',
                                ]);
                            }
                            $quantity = ProductQuantity::normalizeLineQuantity($product, $item['quantity']);
                            $prescriptionItems[$productId] = isset($prescriptionItems[$productId])
                                ? bcadd($prescriptionItems[$productId], $quantity, ProductQuantity::SCALE)
                                : $quantity;
                        }

                        ksort($prescriptionItems);
                        ksort($cartPrescriptionItems);
                        if (array_keys($prescriptionItems) !== array_keys($cartPrescriptionItems)) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'prescription.items' => 'Daftar item resep tidak cocok dengan keranjang.',
                            ]);
                        }
                        foreach ($cartPrescriptionItems as $productId => $quantity) {
                            if (ProductQuantity::lt($prescriptionItems[$productId], $quantity)) {
                                throw \Illuminate\Validation\ValidationException::withMessages([
                                    'prescription.items' => 'Jumlah item resep tidak mencukupi untuk keranjang.',
                                ]);
                            }
                        }
                    }

                    $prescription = array_merge($prescription ?? [], ['items' => $canonicalPrescriptionItems]);
                }
            }

            $discountModel = null;
            $discountAmount = 0.0;
            $buyXGetYAllocation = ['allocations' => []];

            if ($request->filled('discount_code')) {
                $rawCode = trim($request->input('discount_code'));
                $discountModel = Discount::query()
                    ->where('store_id', $store->id)
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($rawCode)])
                    ->valid()
                    ->first();

                if (! $discountModel || ! $discountModel->code) {
                    throw new \Exception('Kode diskon tidak valid atau sudah tidak berlaku.');
                }

                $eligible = $this->eligibleSubtotalForDiscount($discountModel, $cartLines);

                if ($discountModel->minimum_amount && $eligible < (float) $discountModel->minimum_amount) {
                    throw new \Exception('Subtotal belum memenuhi minimum untuk diskon ini.');
                }

                if ($discountModel->type === 'buy_x_get_y') {
                    $buyXGetYAllocation = $this->computeBuyXGetYAllocation($discountModel, $cartLines);
                    $discountAmount = $buyXGetYAllocation['discount_amount'];
                } elseif ($discountModel->type === 'percentage') {
                    $discountAmount = round($eligible * ((float) $discountModel->value / 100), 2);
                } else {
                    $discountAmount = min(round((float) $discountModel->value, 2), $eligible);
                }

                $discountAmount = min($discountAmount, $subtotal);
            }

            $afterDiscount = max(0, round($subtotal - $discountAmount, 2));

            $fnb = FnBFeatures::enabled($store);
            $svcPct = 0.0;
            if ($fnb) {
                $rawSvc = $request->input('service_charge_percent');
                if ($rawSvc === null || $rawSvc === '') {
                    $svcPct = FnBFeatures::defaultServiceChargePercent($store);
                } else {
                    $svcPct = max(0.0, min(100.0, (float) $rawSvc));
                }
            }
            $serviceChargeAmount = $fnb && $svcPct > 0
                ? round($afterDiscount * ($svcPct / 100), 2)
                : 0.0;

            $taxRate = ($store->tax_enabled && $store->tax_rate) ? (float) $store->tax_rate : 0.0;
            $taxAmount = 0.0;
            if ($taxRate > 0) {
                if ($serviceChargeAmount > 0) {
                    $taxBase = $afterDiscount + $serviceChargeAmount;
                    $taxAmount = round($taxBase * ($taxRate / 100), 2);
                } else {
                    $taxAmount = round($afterDiscount * ($taxRate / 100), 2);
                }
            }

            $tipAmount = $fnb ? max(0.0, round((float) $request->input('tip_amount', 0), 2)) : 0.0;

            $totalAmount = round($afterDiscount + $serviceChargeAmount + $taxAmount + $tipAmount, 2);

            $orderType = $fnb ? $request->input('order_type') : null;
            if (! is_string($orderType) || $orderType === '' || ! in_array($orderType, ['dine_in', 'takeaway', 'delivery'], true)) {
                $orderType = null;
            }
            $tableRef = $fnb ? mb_substr((string) $request->input('table_reference', ''), 0, 64) : null;
            if ($tableRef === '') {
                $tableRef = null;
            }

            // Sales channel: dari mana transaksi datang (walk_in, gofood, dll).
            $salesChannel = SalesChannel::tryFrom((string) $request->input('sales_channel', SalesChannel::WalkIn->value))
                ?? SalesChannel::WalkIn;

            // Guard: aggregator (GoFood/GrabFood/ShopeeFood) hanya tersedia jika modul online aktif.
            if ($salesChannel->isAggregator() && ! (bool) ($store->settings['online_sales_enabled'] ?? false)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sales_channel' => 'Modul penjualan online belum diaktifkan. Aktifkan di Pengaturan > Toko.',
                ]);
            }

            // Komisi aggregator: default per channel, bisa di-override dari input.
            $commissionPercent = 0.0;
            $commissionAmount = 0.0;
            if ($salesChannel->isAggregator()) {
                $inputPercent = (float) $request->input('commission_percent', $salesChannel->defaultCommissionPercent());
                $commissionPercent = max(0.0, min(100.0, round($inputPercent, 2)));
                if ($commissionPercent > 0) {
                    $commissionAmount = round($totalAmount * $commissionPercent / 100, 2);
                }
            }

            $customer = null;
            if ($request->customer_name) {
                $customer = Customer::firstOrCreate([
                    'store_id' => $store->id,
                    'name' => $request->customer_name,
                ], [
                    'customer_type' => $customerType,
                ]);
            }

            // Kaitkan transaksi ke shift terbuka untuk semua metode bayar,
            // agar ringkasan non-tunai per shift ikut terhitung.
            $cashShiftId = CashShift::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->value('id');

            // Cek batas transaksi bulanan untuk paket Starter
            StorePlanLimits::assertCanCreateTransaction($store);

            $paymentDetails = null;
            if ($fnb && $request->filled('split_bill_count')) {
                $n = max(1, min(20, (int) $request->input('split_bill_count')));
                if ($n >= 2) {
                    $paymentDetails = [
                        'split_bill' => [
                            'count' => $n,
                            'shares' => $this->splitTotalIntoRupiahShares($totalAmount, $n),
                        ],
                    ];
                }
            }

            $depositApplied = 0.0;
            $balanceDue = $totalAmount;
            if ($lockedPreOrder) {
                $depRaw = round(max(0.0, (float) $lockedPreOrder->deposit_received), 2);
                $depositApplied = min($depRaw, $totalAmount);
                $balanceDue = round($totalAmount - $depositApplied, 2);
                $preOrderMeta = [
                    'id' => $lockedPreOrder->id,
                    'order_number' => $lockedPreOrder->order_number,
                    'deposit_applied' => $depositApplied,
                    'balance_due' => $balanceDue,
                ];
                $paymentDetails = is_array($paymentDetails)
                    ? array_merge($paymentDetails, ['pre_order' => $preOrderMeta])
                    : ['pre_order' => $preOrderMeta];
            }

            if (PharmacyFeatures::enabled($store) && ($cartHasPrescription || $cartHasLimited || $pharmacySaleReference !== '' || $prescription !== null)) {
                $pharmacyMeta = [
                    'sale_reference' => $pharmacySaleReference !== '' ? $pharmacySaleReference : null,
                    'prescription' => $prescription,
                    'has_prescription_lines' => $cartHasPrescription,
                    'has_limited_lines' => $cartHasLimited,
                ];
                $paymentDetails = is_array($paymentDetails)
                    ? array_merge($paymentDetails, ['pharmacy' => $pharmacyMeta])
                    : ['pharmacy' => $pharmacyMeta];
            }

            $amountPaid = round((float) $request->amount_paid, 2);
            if ($isCredit) {
                // Mode kredit: validasi customer wajib ada jika ada sisa tagihan
                if ($amountPaid < $balanceDue - 0.009) {
                    $creditCustomer = $request->input('customer_id')
                        ? \App\Models\Customer::where('id', $request->input('customer_id'))
                            ->where('store_id', $store->id)
                            ->first()
                        : null;
                    if (! $creditCustomer) {
                        throw new \Exception('Pilih pelanggan terlebih dahulu untuk penjualan kredit.');
                    }
                    // Ganti customer yang sudah dipilih dari customer_name ke customer_id
                    $customer = $creditCustomer;
                }
            } else {
                if ($amountPaid + 0.009 < $balanceDue) {
                    throw new \Exception('Jumlah bayar kurang dari sisa yang harus dibayar (setelah DP pre-order).');
                }
            }

            // Tentukan payment_status: paid, partial, atau unpaid
            $outstandingAmount = max(0, round($balanceDue - $amountPaid, 2));
            $paymentStatus = match (true) {
                $outstandingAmount <= 0.009 => 'paid',
                $amountPaid > 0.009 => 'partial',
                default => 'unpaid',
            };

            $debtDueAt = ($paymentStatus !== 'paid' && $request->filled('debt_due_at'))
                ? $request->input('debt_due_at')
                : null;

            $transaction = Transaction::create([
                'store_id' => $store->id,
                'customer_id' => $customer?->id,
                'discount_id' => $discountModel?->id,
                'user_id' => $user->id,
                'cash_shift_id' => $cashShiftId,
                'status_token' => Str::random(64),
                'status' => 'completed',
                'payment_method' => $request->payment_method,
                'payment_status' => $paymentStatus,
                'order_type' => $orderType,
                'table_reference' => $tableRef,
                'sales_channel' => $salesChannel->value,
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'service_charge_percent' => $fnb && $svcPct > 0 ? round($svcPct, 2) : null,
                'service_charge_amount' => $serviceChargeAmount,
                'tip_amount' => $tipAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_amount' => max(0, round($amountPaid - $balanceDue, 2)),
                'payment_details' => $paymentDetails,
                'completed_at' => now(),
                'offline_sale_id' => $offlineSaleId,
                'debt_due_at' => $debtDueAt,
            ]);

            foreach ($cartLines as $idx => $line) {
                $product = $line['product'];
                $variant = $line['variant'];
                $qty = $line['quantity'];
                $alloc = $buyXGetYAllocation['allocations'][$idx] ?? null;
                $freeQty = $alloc['free_quantity'] ?? '0.0000';
                $itemDiscount = $alloc['discount_amount'] ?? 0.0;

                $displayName = $variant
                    ? "{$product->name} — {$variant->name}"
                    : $product->name;

                $transactionItem = TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $displayName,
                    'product_sku' => $variant?->sku ?? $product->sku,
                    'variant_label' => $variant?->name,
                    'quantity' => $qty,
                    'free_quantity' => $freeQty,
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['line_total'],
                    'discount_amount' => $itemDiscount,
                    'product_snapshot' => $product->toArray(),
                    'modifiers' => $line['modifiers'] !== [] ? $line['modifiers'] : null,
                ]);

                if ($product->is_bundle) {
                    $product->loadMissing([
                        'bundleItems' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with(['componentProduct', 'componentVariant']),
                    ]);
                    foreach ($product->bundleItems as $bi) {
                        $comp = $bi->componentProduct;
                        $vComp = $bi->componentVariant;
                        $need = bcmul($qty, (string) $bi->quantity, ProductQuantity::SCALE);
                        if (ProductQuantity::cmp($need, '0.0000') <= 0 || ! $comp || ! $comp->track_stock) {
                            continue;
                        }
                        $noteSuffix = ' (paket: '.$product->name.')';
                        $this->applySaleStockDeduction($store, $user, $comp, $vComp, $need, $transaction, $noteSuffix);
                    }
                } elseif (SaleConsumptionResolver::hasConfig($store, $product, $variant)) {
                    // Konfigurasi konsumsi penjualan (production_output / packaging)
                    $hpp = SaleConsumptionResolver::consume(
                        $store, $user, $product, $variant, $qty,
                        $transactionItem, $transaction->transaction_number,
                    );
                    if ($hpp) {
                        $transactionItem->update([
                            'unit_cost_snapshot' => $hpp['unit_cost'],
                            'total_cost_snapshot' => $hpp['total_cost'],
                            'gross_profit_snapshot' => $hpp['gross_profit'],
                        ]);
                    }
                } elseif ($product->track_stock) {
                    $this->applySaleStockDeduction($store, $user, $product, $variant, $qty, $transaction, null);
                    // HPP snapshot untuk produk track_stock biasa (bukan bundle/SaleConsumption)
                    // ponytail: weighted average dari InventoryCosting sudah cukup untuk SAK EMKM;
                    // upgrade ke FIFO layer kalau margin tipis perlu costing lebih granular.
                    if ($product->track_stock && ! $product->unlimited_stock) {
                        $unitCost = InventoryCosting::currentAverageCost($product);
                        $totalCost = InventoryCosting::extendedCost($qty, $unitCost, 2);
                        $lineRevenue = (float) $line['line_total'];
                        $transactionItem->update([
                            'unit_cost_snapshot' => round($unitCost, 6),
                            'total_cost_snapshot' => $totalCost,
                            'gross_profit_snapshot' => round($lineRevenue - $itemDiscount - $totalCost, 2),
                        ]);
                    }
                }
            }

            if ($discountModel && $discountAmount > 0) {
                $discountModel->increment('used_count');
            }

            if ($customer) {
                $customer->increment('total_orders');
                $customer->increment('total_spent', $totalAmount);
            }

            if ($lockedPreOrder) {
                $lockedPreOrder->update([
                    'status' => 'completed',
                    'fulfilled_transaction_id' => $transaction->id,
                ]);
            }

            $transaction->load(['items.product', 'items.productVariant', 'store', 'user', 'discount', 'customer']);
            JournalPoster::postSaleTransaction($store, $transaction);

            DB::commit();

            // Log aktivitas transaksi
            \App\Services\ActivityLogger::log(
                store: $store,
                action: 'transaction.created',
                description: "Transaksi POS {$transaction->transaction_number} sebesar ".number_format($totalAmount, 0, ',', '.'),
                subject: $transaction,
                metadata: [
                    'transaction_number' => $transaction->transaction_number,
                    'total_amount' => $totalAmount,
                    'items_count' => count($cartLines),
                    'payment_method' => $request->payment_method,
                ]
            );

            $pointsEarned = 0;
            if ($customer && StorePlanLimits::allowsAdvancedRetailFeatures($store) && LoyaltySettings::enabled($store)) {
                try {
                    $pointsEarned = app(LoyaltyService::class)->earnFromTransaction($transaction);
                } catch (\Throwable $e) {
                    Log::warning('[loyalty] gagal menambah poin transaksi', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $payload = $this->receiptTransactionPayload($transaction, $store);
            $payload['loyalty_points_earned'] = $pointsEarned;

            // Auto-create queue entry jika modul antrian aktif
            if ($store->settings['queue_module_enabled'] ?? false) {
                try {
                    $queue = $store->queues()->where('is_active', true)->first();
                    if (! $queue) {
                        $queue = $store->queues()->create([
                            'name' => 'Kasir',
                            'code' => 'Q001',
                            'is_active' => true,
                        ]);
                    }
                    $transaction->load('items');
                    $lastNumber = $queue->entries()->max('number');
                    $nextNumber = $lastNumber ? ((int) $lastNumber + 1) : 1;
                    $items = $transaction->items->map(fn ($item) => [
                        'product_name' => $item->product_name,
                        'quantity' => (int) $item->quantity,
                    ])->toArray();
                    $queue->entries()->create([
                        'store_id' => $store->id,
                        'number' => $nextNumber,
                        'status' => 'waiting',
                        'order_type' => $orderType,
                        'table_number' => $tableRef,
                        'customer_name' => $request->customer_name,
                        'customer_phone' => $customer?->phone,
                        'transaction_id' => $transaction->id,
                        'items' => $items,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[queue] gagal membuat antrian otomatis', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'transaction' => $payload,
                'message' => 'Transaksi berhasil diproses',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            $firstError = collect($e->errors())->flatten()->first();

            return response()->json([
                'success' => false,
                'message' => is_string($firstError) ? $firstError : 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        } catch (ComponentStockInsufficientException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'component_stock_issues' => $e->getIssues(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================================
     |  Open Bills — pesanan tersimpan (BELUM BAYAR) untuk alur pelayan restoran
     * ========================================================================= */

    /** Daftar open bills: transaksi OPEN milik toko, belum dibayar. */
    public function orderHolds(Request $request): JsonResponse
    {
        $store = auth()->user()?->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan'], 404);
        }

        $query = Transaction::query()
            ->where('store_id', $store->id)
            ->where('order_status', 'open')
            ->where('payment_status', 'unpaid')
            ->with(['items:id,transaction_id,product_name,variant_label,quantity,unit_price,total_price'])
            ->latest();

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($sub) use ($search) {
                $sub->where('table_reference', 'like', "%{$search}%")
                    ->orWhere('transaction_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $holds = $query
            ->limit(50)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'transaction_number' => $t->transaction_number,
                'table_reference' => $t->table_reference,
                'customer_name' => $t->customer?->name,
                'order_type' => $t->order_type,
                'items_count' => (int) $t->items->sum('quantity'),
                'total_amount' => (float) $t->total_amount,
                'created_at' => $t->created_at?->toIso8601String(),
                'elapsed_minutes' => (int) abs(now()->diffInMinutes($t->created_at)),
            ]);

        return response()->json(['success' => true, 'holds' => $holds]);
    }

    /**
     * Simpan keranjang sebagai open bill (BELUM BAYAR).
     * Tidak ada pengurangan stok / jurnal / loyalitas di fase ini.
     */
    public function storeOrderHold(Request $request): JsonResponse
    {
        $user = auth()->user();
        $store = $user?->store;

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Toko tidak ditemukan'], 404);
        }

        try {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
                'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
                'items.*.unit_price' => 'nullable|numeric|min:0',
                'items.*.modifiers' => 'nullable|array',
                'order_type' => 'nullable|in:dine_in,takeaway,delivery',
                'table_reference' => 'nullable|string|max:64',
                'customer_name' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        }

        // Open bill: aktif untuk F&B atau toko dengan modul open bill diaktifkan.
        if (! FnBFeatures::openBillEnabled($store)) {
            return response()->json([
                'success' => false,
                'message' => 'Fitur open bill belum diaktifkan. Aktifkan modul Open Bill di pengaturan usaha.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $cartLines = [];
            $subtotal = 0.0;
            $customerType = ($store->settings['wholesale_enabled'] ?? false) && $request->input('customer_type') === 'grosir' ? 'grosir' : 'ecer';

            foreach ($request->items as $item) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('id', $item['product_id'])
                    ->firstOrFail();

                if (! $product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak aktif");
                }

                try {
                    $qty = ProductQuantity::normalizeLineQuantity($product, $item['quantity']);
                } catch (\InvalidArgumentException $e) {
                    throw new \Exception($e->getMessage());
                }

                $variant = null;

                if (! empty($item['product_variant_id'])) {
                    $variant = ProductVariant::query()
                        ->where('id', (int) $item['product_variant_id'])
                        ->where('product_id', $product->id)
                        ->where('store_id', $store->id)
                        ->where('is_active', true)
                        ->first();
                    if (! $variant) {
                        throw new \Exception("Varian tidak valid untuk {$product->name}.");
                    }
                }

                $modifiers = $this->normalizeLineModifiers($product, $item['modifiers'] ?? null);
                $modifierUnitSum = 0.0;
                foreach ($modifiers as $m) {
                    $modifierUnitSum += (float) $m['price'];
                }
                $baseUnit = $variant
                    ? $this->resolveLinePrice($variant->wholesale_price ?? $product->wholesale_price, $variant->effectiveUnitPrice(), $customerType)
                    : $this->resolveLinePrice($product->wholesale_price, (float) $product->price, $customerType);
                $unitPrice = round($baseUnit + $modifierUnitSum, 2);
                $lineTotal = round($unitPrice * (float) $qty, 2);
                $subtotal += $lineTotal;

                $cartLines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                    'unit_price' => $unitPrice,
                    'modifiers' => $modifiers,
                ];
            }

            $subtotal = round($subtotal, 2);

            $orderType = $request->input('order_type');
            if (! is_string($orderType) || ! in_array($orderType, ['dine_in', 'takeaway', 'delivery'], true)) {
                $orderType = null;
            }
            $tableRef = mb_substr((string) $request->input('table_reference', ''), 0, 64);
            if ($tableRef === '') {
                $tableRef = null;
            }

            $customer = null;
            if ($request->customer_name) {
                $customer = Customer::firstOrCreate([
                    'store_id' => $store->id,
                    'name' => $request->customer_name,
                ], [
                    'customer_type' => $customerType,
                ]);
            }

            StorePlanLimits::assertCanCreateTransaction($store);

            $transaction = Transaction::create([
                'store_id' => $store->id,
                'customer_id' => $customer?->id,
                'user_id' => $user->id,
                'status_token' => Str::random(64),
                'status' => 'pending',
                'order_status' => 'open',
                'payment_status' => 'unpaid',
                'payment_method' => 'cash',
                'order_type' => $orderType,
                'table_reference' => $tableRef,
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'service_charge_percent' => null,
                'service_charge_amount' => 0,
                'tip_amount' => 0,
                'total_amount' => $subtotal,
                'amount_paid' => 0,
                'change_amount' => 0,
                'payment_details' => [
                    'source' => 'open_bill',
                    'waiter_name' => $request->input('waiter_name') ?: null,
                ],
                'notes' => $request->input('notes'),
                'completed_at' => null,
            ]);

            foreach ($cartLines as $line) {
                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'product_name' => $line['variant']
                        ? "{$line['product']->name} — {$line['variant']->name}"
                        : $line['product']->name,
                    'product_sku' => $line['variant']?->sku ?? $line['product']->sku,
                    'variant_label' => $line['variant']?->name,
                    'quantity' => $line['quantity'],
                    'free_quantity' => '0.0000',
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['line_total'],
                    'discount_amount' => 0,
                    'product_snapshot' => $line['product']->toArray(),
                    'modifiers' => $line['modifiers'] !== [] ? $line['modifiers'] : null,
                ]);
            }

            DB::commit();

            \App\Services\ActivityLogger::log(
                store: $store,
                action: 'open_bill.created',
                description: "Open bill {$transaction->transaction_number} dibuat".($tableRef ? " untuk meja {$tableRef}" : ''),
                subject: $transaction,
                metadata: [
                    'transaction_number' => $transaction->transaction_number,
                    'total_amount' => $subtotal,
                    'table_reference' => $tableRef,
                ]
            );

            $transaction->load(['items', 'store']);

            return response()->json([
                'success' => true,
                'message' => 'Pesanan tersimpan sebagai belum bayar',
                'transaction' => $this->receiptTransactionPayload($transaction, $store),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bagi total (rupiah) ke N bagian bilangan bulat sen, jumlah tepat sama dengan total.
     *
     * @return list<float>
     */
    private function splitTotalIntoRupiahShares(float $total, int $parts): array
    {
        if ($parts < 2) {
            return [round($total, 2)];
        }

        $cents = (int) round($total * 100);
        $base = intdiv($cents, $parts);
        $rem = $cents % $parts;
        $out = [];
        for ($i = 0; $i < $parts; $i++) {
            $out[] = round(($base + ($i < $rem ? 1 : 0)) / 100, 2);
        }

        return $out;
    }

    /** Detail satu open bill (untuk dimuat kembali ke keranjang POS). */
    public function showOrderHold(Request $request, Transaction $transaction): JsonResponse
    {
        $store = auth()->user()?->store;

        if (! $store || (int) $transaction->store_id !== (int) $store->id) {
            return response()->json(['success' => false, 'message' => 'Open bill tidak ditemukan'], 404);
        }

        if ($transaction->order_status !== 'open' || $transaction->payment_status !== 'unpaid') {
            return response()->json(['success' => false, 'message' => 'Pesanan ini sudah diproses sebelumnya'], 422);
        }

        $transaction->load(['items', 'customer']);

        return response()->json([
            'success' => true,
            'hold' => [
                'id' => $transaction->id,
                'transaction_number' => $transaction->transaction_number,
                'table_reference' => $transaction->table_reference,
                'customer_name' => $transaction->customer?->name,
                'order_type' => $transaction->order_type,
                'notes' => $transaction->notes,
                'subtotal' => (float) $transaction->subtotal,
                'total_amount' => (float) $transaction->total_amount,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'items' => $transaction->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'product_variant_id' => $i->product_variant_id,
                    'product_name' => $i->product_name,
                    'variant_label' => $i->variant_label,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'total_price' => (float) $i->total_price,
                    'modifiers' => $i->modifiers ?? [],
                ]),
            ],
        ]);
    }

    /**
     * Bayar open bill: finalisasi transaksi — potong stok, posting jurnal, dsb.
     * Semua perhitungan pajak/service charge dihitung ulang dari items saat bayar.
     */
    public function payOrderHold(Request $request, Transaction $transaction): JsonResponse
    {
        $user = auth()->user();
        $store = $user?->store;

        if (! $store || (int) $transaction->store_id !== (int) $store->id) {
            return response()->json(['success' => false, 'message' => 'Open bill tidak ditemukan'], 404);
        }

        if ($transaction->payment_status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Pesanan sudah dibayar'], 422);
        }
        if ($transaction->order_status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak dapat dibayar (status: '.$transaction->order_status.')'], 422);
        }

        try {
            $request->validate([
                'payment_method' => 'required|in:cash,card,ewallet,transfer,other',
                'amount_paid' => 'required|numeric|min:0',
                'discount_code' => 'nullable|string|max:100',
                'service_charge_percent' => 'nullable|numeric|min:0|max:100',
                'tip_amount' => 'nullable|numeric|min:0',
                'split_bill_count' => 'nullable|integer|min:1|max:20',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Data tidak valid',
            ], 422);
        }

        // Shift kasir wajib terbuka untuk toko paket advanced retail.
        if (StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            $hasOpenShift = CashShift::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->exists();

            if (! $hasOpenShift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Buka shift kasir terlebih dahulu sebelum memproses pembayaran.',
                ], 422);
            }
        }

        $periodLock = AccountingPeriodGuard::posLockMessage($store, now());
        if ($periodLock) {
            return response()->json(['success' => false, 'message' => $periodLock], 422);
        }

        DB::beginTransaction();

        try {
            $items = TransactionItem::query()
                ->where('transaction_id', $transaction->id)
                ->with(['product', 'productVariant'])
                ->get();

            // Hitung ulang service charge, tip, dan pajak dari data tersimpan.
            $subtotal = (float) $transaction->subtotal;
            $fnb = FnBFeatures::enabled($store);

            $svcPct = 0.0;
            if ($fnb) {
                $rawSvc = $request->input('service_charge_percent');
                $svcPct = ($rawSvc === null || $rawSvc === '')
                    ? FnBFeatures::defaultServiceChargePercent($store)
                    : max(0.0, min(100.0, (float) $rawSvc));
            }
            $afterDiscount = $subtotal;
            $serviceChargeAmount = $fnb && $svcPct > 0 ? round($afterDiscount * ($svcPct / 100), 2) : 0.0;

            $taxRate = ($store->tax_enabled && $store->tax_rate) ? (float) $store->tax_rate : 0.0;
            $taxAmount = 0.0;
            if ($taxRate > 0) {
                $taxBase = $afterDiscount + $serviceChargeAmount;
                $taxAmount = round($taxBase * ($taxRate / 100), 2);
            }

            $tipAmount = $fnb ? max(0.0, round((float) $request->input('tip_amount', 0), 2)) : 0.0;

            $totalAmount = round($afterDiscount + $serviceChargeAmount + $taxAmount + $tipAmount, 2);

            $splitDetails = null;
            if ($fnb && $request->filled('split_bill_count')) {
                $n = max(1, min(20, (int) $request->input('split_bill_count')));
                if ($n >= 2) {
                    $splitDetails = [
                        'split_bill' => [
                            'count' => $n,
                            'shares' => $this->splitTotalIntoRupiahShares($totalAmount, $n),
                        ],
                    ];
                }
            }

            $amountPaid = round((float) $request->amount_paid, 2);
            if ($amountPaid + 0.009 < $totalAmount) {
                throw new \Exception('Jumlah bayar kurang dari total tagihan.');
            }

            // Kaitkan ke shift kasir terbuka.
            $cashShiftId = CashShift::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->whereNull('closed_at')
                ->value('id');

            $details = is_array($transaction->payment_details) ? $transaction->payment_details : [];
            $details['paid_by'] = $user->id;
            $details['paid_at'] = now()->toIso8601String();
            if ($splitDetails) {
                $details = array_merge($details, $splitDetails);
            }

            $transaction->update([
                'cash_shift_id' => $cashShiftId,
                'user_id' => $user->id,
                'status' => 'completed',
                'order_status' => 'completed',
                'payment_status' => 'paid',
                'payment_method' => $request->payment_method,
                'service_charge_percent' => $svcPct > 0 ? round($svcPct, 2) : null,
                'service_charge_amount' => $serviceChargeAmount,
                'tax_amount' => $taxAmount,
                'tip_amount' => $tipAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_amount' => max(0, round($amountPaid - $totalAmount, 2)),
                'payment_details' => $details,
                'completed_at' => now(),
            ]);

            // Deduksi stok per item (sama seperti processPayment).
            foreach ($items as $item) {
                $product = $item->product;
                if (! $product) {
                    continue;
                }
                $qty = ProductQuantity::normalizeStorageAmount($product, (string) $item->quantity);
                $variant = $item->productVariant;

                if ($product->is_bundle) {
                    continue; // Bundle ditangani saat item dibuat; sederhanakan: skip bundle di open bill.
                } elseif (SaleConsumptionResolver::hasConfig($store, $product, $variant)) {
                    SaleConsumptionResolver::consume(
                        $store, $user, $product, $variant, $qty, $item, $transaction->transaction_number,
                    );
                } elseif ($product->track_stock) {
                    $this->applySaleStockDeduction($store, $user, $product, $variant, $qty, $transaction, null);
                }
            }

            if ($transaction->customer) {
                $transaction->customer->increment('total_orders');
                $transaction->customer->increment('total_spent', $totalAmount);
            }

            JournalPoster::postSaleTransaction($store, $transaction->fresh());

            DB::commit();

            \App\Services\ActivityLogger::log(
                store: $store,
                action: 'open_bill.paid',
                description: "Open bill {$transaction->transaction_number} dibayar sebesar ".number_format($totalAmount, 0, ',', '.'),
                subject: $transaction,
                metadata: [
                    'transaction_number' => $transaction->transaction_number,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                ]
            );

            $pointsEarned = 0;
            if ($transaction->customer && StorePlanLimits::allowsAdvancedRetailFeatures($store) && LoyaltySettings::enabled($store)) {
                try {
                    $pointsEarned = app(LoyaltyService::class)->earnFromTransaction($transaction->fresh());
                } catch (\Throwable $e) {
                    Log::warning('[loyalty] gagal menambah poin open bill', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $payload = $this->receiptTransactionPayload($transaction->fresh(), $store);
            $payload['loyalty_points_earned'] = $pointsEarned;

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil',
                'transaction' => $payload,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /** Batalkan open bill sebelum pembayaran. Tidak ada jurnal/stok yang berubah. */
    public function cancelOrderHold(Request $request, Transaction $transaction): JsonResponse
    {
        $store = auth()->user()?->store;

        if (! $store || (int) $transaction->store_id !== (int) $store->id) {
            return response()->json(['success' => false, 'message' => 'Open bill tidak ditemukan'], 404);
        }

        if (! in_array($transaction->order_status, ['open'], true)) {
            return response()->json(['success' => false, 'message' => 'Hanya pesanan OPEN yang bisa dibatalkan'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));
        $notesSuffix = "\n[Dibatalkan kasir".($reason !== '' ? ": {$reason}" : '').']';

        $transaction->update([
            'order_status' => 'cancelled',
            'status' => 'cancelled',
            'notes' => ($transaction->notes ?? '').$notesSuffix,
            'user_id' => auth()->id(),
        ]);

        \App\Services\ActivityLogger::log(
            store: $store,
            action: 'open_bill.cancelled',
            description: "Open bill {$transaction->transaction_number} dibatalkan",
            subject: $transaction,
            metadata: [
                'transaction_number' => $transaction->transaction_number,
                'reason' => $reason,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pesanan dibatalkan',
        ]);
    }

    /**
     * Pilih harga satuan per baris POS berdasarkan tipe pelanggan.
     * Grosir memakai harga grosir bila tersedia, selain itu harga ecer.
     */
    private function resolveLinePrice($wholesalePrice, float $retailPrice, string $customerType): float
    {
        if ($customerType === 'grosir' && $wholesalePrice !== null && is_numeric($wholesalePrice)) {
            return (float) $wholesalePrice;
        }

        return $retailPrice;
    }

    private function productMatchesDiscountScope(Discount $discount, Product $product): bool
    {
        $pidFilter = $discount->applicable_products;
        $catFilter = $discount->applicable_categories;
        $hasP = is_array($pidFilter) && count($pidFilter) > 0;
        $hasC = is_array($catFilter) && count($catFilter) > 0;

        if (! $hasP && ! $hasC) {
            return true;
        }
        if ($hasP && in_array((int) $product->id, array_map('intval', $pidFilter), true)) {
            return true;
        }
        if ($hasC && $product->category && in_array($product->category, $catFilter, true)) {
            return true;
        }

        return false;
    }

    private function eligibleSubtotalForDiscount(Discount $discount, array $cartLines): float
    {
        $eligible = 0.0;
        foreach ($cartLines as $line) {
            if ($this->productMatchesDiscountScope($discount, $line['product'])) {
                $eligible += $line['line_total'];
            }
        }

        return round($eligible, 2);
    }

    /**
     * Hitung diskon Beli X Gratis Y beserta alokasi unit gratis & nominal diskon per baris keranjang.
     *
     * @param  array<int, array{product: Product, variant: ?ProductVariant, quantity: string, line_total: float}>  $cartLines
     * @return array{discount_amount: float, allocations: array<int, array{free_quantity: string, discount_amount: float}>}
     */
    private function computeBuyXGetYAllocation(Discount $discount, array $cartLines): array
    {
        $buyX = (int) ($discount->buy_x ?? 0);
        $getY = (int) ($discount->get_y ?? 0);
        if ($buyX < 1 || $getY < 1) {
            throw new \Exception('Promo Beli X Gratis Y belum dikonfigurasi (menu Diskon).');
        }

        $den = $buyX + $getY;
        $groups = [];

        foreach ($cartLines as $idx => $line) {
            if (! $this->productMatchesDiscountScope($discount, $line['product'])) {
                continue;
            }
            $vid = $line['variant']?->id ?? 0;
            $key = $line['product']->id.'_'.$vid;
            if (! isset($groups[$key])) {
                $groups[$key] = ['qty' => '0.0000', 'subtotal' => 0.0, 'lines' => []];
            }
            $groups[$key]['qty'] = bcadd($groups[$key]['qty'], $line['quantity'], ProductQuantity::SCALE);
            $groups[$key]['subtotal'] = round($groups[$key]['subtotal'] + $line['line_total'], 2);
            $groups[$key]['lines'][] = $idx;
        }

        $totalDiscount = 0.0;
        $allocations = [];

        foreach ($groups as $g) {
            $qty = $g['qty'];
            $sub = $g['subtotal'];
            if (ProductQuantity::cmp($qty, '0.0000') <= 0 || $sub <= 0) {
                continue;
            }
            $ratio = bcdiv($qty, (string) $den, 8);
            $bundles = (int) floor(((float) $ratio) + 1e-10);
            if ($bundles < 1) {
                continue;
            }
            $freeUnits = bcmul((string) $getY, (string) $bundles, ProductQuantity::SCALE);
            $unitAvg = bcdiv(number_format($sub, 2, '.', ''), $qty, 6);
            $groupDisc = round((float) bcmul($freeUnits, $unitAvg, 4), 2);
            $groupDisc = min($groupDisc, $sub);
            $totalDiscount += $groupDisc;

            // Sebar diskon & unit gratis ke baris dalam grup, proporsional subtotal.
            $remainingDisc = $groupDisc;
            $remainingFree = $freeUnits;
            $lineCount = count($g['lines']);
            foreach ($g['lines'] as $i => $idx) {
                $line = $cartLines[$idx];
                $isLast = $i === $lineCount - 1;
                if ($isLast) {
                    $lineDisc = round($remainingDisc, 2);
                    $lineFree = $remainingFree;
                } else {
                    $lineDisc = round($groupDisc * ($line['line_total'] / $sub), 2);
                    $remainingDisc = round($remainingDisc - $lineDisc, 2);
                    $lineFree = ProductQuantity::min(
                        $remainingFree,
                        bcmul($line['quantity'], bcdiv($freeUnits, $qty, 8), 4),
                    );
                    $remainingFree = ProductQuantity::sub($remainingFree, $lineFree);
                }
                $allocations[$idx] = [
                    'free_quantity' => $lineFree,
                    'discount_amount' => max(0.0, $lineDisc),
                ];
            }
        }

        $cartSubtotal = round(array_sum(array_column($cartLines, 'line_total')), 2);

        return [
            'discount_amount' => round(min($totalDiscount, $cartSubtotal), 2),
            'allocations' => $allocations,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $raw
     * @return list<array{key: string, label: string, price: float}>
     */
    private function applySaleStockDeduction(
        Store $store,
        User $user,
        Product $product,
        ?ProductVariant $variant,
        string $qty,
        Transaction $transaction,
        ?string $noteSuffix = null,
    ): void {
        if (! $product->track_stock || $product->unlimited_stock) {
            return;
        }

        $balanceAfter = null;

        if (BatchTracking::enabled($store)) {
            if ($product->has_variants) {
                throw new \Exception("Produk «{$product->name}» bertingkat varian — mode batch tidak didukung.");
            }
            $this->ensureBatchStockAlignedWithProduct($product);
            $remaining = $qty;
            $batches = ProductBatch::query()
                ->where('product_id', $product->id)
                ->where('quantity_remaining', '>', 0)
                ->where('status', ProductBatch::STATUS_AVAILABLE)
                ->when(PharmacyFeatures::enabled($store), fn ($query) => $query->where(function ($query) {
                    $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today());
                }))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($batches as $batch) {
                if (ProductQuantity::cmp($remaining, '0.0000') <= 0) {
                    break;
                }
                $batchRem = ProductQuantity::normalizeStorageAmount($product, $batch->quantity_remaining);
                if (ProductQuantity::cmp($batchRem, '0.0000') <= 0) {
                    continue;
                }
                $take = ProductQuantity::min($remaining, $batchRem);
                if (ProductQuantity::cmp($take, '0.0000') > 0) {
                    $batch->decrement('quantity_remaining', $take);
                    $remaining = ProductQuantity::sub($remaining, $take);
                }
            }
            if (ProductQuantity::cmp($remaining, '0.0000') > 0 && ! $product->allow_negative_stock) {
                throw new \Exception("Stok batch produk {$product->name} tidak mencukupi.");
            }
            $synced = (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
            $product->update(['stock_quantity' => $synced]);
            $balanceAfter = (string) $product->fresh()->stock_quantity;
        } elseif ($variant) {
            $variant->decrement('stock_quantity', $qty);
            $variant->refresh();
            $vSum = (string) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
            $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $vSum)]);
            $balanceAfter = (string) $variant->stock_quantity;
        } else {
            $product->decrement('stock_quantity', $qty);
            $balanceAfter = (string) $product->fresh()->stock_quantity;
        }

        StockMovement::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'user_id' => $user->id,
            'quantity_change' => bcsub('0.0000', $qty, ProductQuantity::SCALE),
            'type' => StockMovement::TYPE_SALE,
            'reference_type' => Transaction::class,
            'reference_id' => $transaction->id,
            'balance_after' => $balanceAfter ?? (string) $product->fresh()->stock_quantity,
            'notes' => $transaction->transaction_number.($noteSuffix ?? ''),
        ]);
    }

    private function normalizeLineModifiers(Product $product, ?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if ($product->is_bundle) {
            return [];
        }

        $options = collect($product->modifier_options ?? []);
        $byKey = $options->keyBy(fn ($o) => (string) ($o['key'] ?? ''));

        $out = [];
        foreach ($raw as $m) {
            if (! is_array($m)) {
                continue;
            }
            $key = (string) ($m['key'] ?? '');
            if ($key === '' || ! $byKey->has($key)) {
                continue;
            }
            $opt = $byKey->get($key);
            $out[] = [
                'key' => $key,
                'label' => (string) ($opt['label'] ?? $key),
                'price' => round(max(0.0, (float) ($opt['price'] ?? 0)), 2),
            ];
        }

        return $out;
    }

    private function ensureBatchStockAlignedWithProduct(Product $product): void
    {
        $batchSum = (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
        $stockStr = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);
        if (ProductQuantity::cmp($batchSum, '0.0000') === 0 && ProductQuantity::cmp($stockStr, '0.0000') > 0) {
            ProductBatch::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'quantity_remaining' => $stockStr,
                'expiry_date' => null,
                'batch_code' => null,
            ]);

            return;
        }
        if (ProductQuantity::cmp($batchSum, $stockStr) !== 0) {
            throw new \Exception(
                "Stok batch tidak sinkron untuk {$product->name} (total batch {$batchSum} vs stok {$stockStr}). Buka edit produk lalu simpan."
            );
        }
    }

    /**
     * Payload JSON untuk pratinjau / cetak struk thermal (sama bentuknya dengan respons process-payment).
     *
     * @return array<string, mixed>
     */
    private function receiptTransactionPayload(Transaction $transaction, Store $store): array
    {
        $transaction->loadMissing(['items', 'store', 'user', 'discount', 'customer']);

        $payload = $transaction->toArray();
        $payload['receipt'] = ReceiptSettings::payload($store);

        return $payload;
    }

    public function receiptJson(Transaction $transaction): JsonResponse
    {
        $user = auth()->user();
        $store = $user?->store;

        abort_unless($store && (int) $transaction->store_id === (int) $store->id, 404);

        return response()->json([
            'success' => true,
            'transaction' => $this->receiptTransactionPayload($transaction, $store),
        ]);
    }

    /**
     * Halaman struk / invoice (Inertia) untuk transaksi milik toko aktif.
     */
    public function showInvoice(Transaction $transaction): InertiaResponse
    {
        $user = auth()->user();
        $store = $user?->store;

        abort_unless($store && (int) $transaction->store_id === (int) $store->id, 404);

        $transaction->load([
            'items',
            'store:id,name,address,phone,email',
            'user:id,name',
            'customer:id,name,phone,email',
        ]);

        return Inertia::render('Invoice', [
            'transaction' => $transaction,
            'fnb_enabled' => FnBFeatures::enabled($store),
            'pharmacy_enabled' => PharmacyFeatures::enabled($store),
        ]);
    }

    /**
     * JSON data untuk invoice — digunakan popup modal di riwayat transaksi.
     */
    public function invoiceJson(Transaction $transaction): JsonResponse
    {
        $user = auth()->user();
        $store = $user?->store;

        abort_unless($store && (int) $transaction->store_id === (int) $store->id, 404);

        $transaction->load([
            'items',
            'store:id,name,address,phone,email',
            'user:id,name',
            'customer:id,name,phone,email',
        ]);

        return response()->json([
            'transaction' => $transaction,
            'fnb_enabled' => FnBFeatures::enabled($store),
            'pharmacy_enabled' => PharmacyFeatures::enabled($store),
        ]);
    }

    /**
     * Unduh struk transaksi sebagai PDF.
     */
    public function exportPdf(Transaction $transaction): Response
    {
        $user = auth()->user();
        $store = $user?->store;

        abort_unless($store && (int) $transaction->store_id === (int) $store->id, 404);

        $transaction->load([
            'items',
            'store',
            'user:id,name',
            'customer:id,name,phone,email',
        ]);

        $pdf = Pdf::loadView('pdf.invoice', [
            'transaction' => $transaction,
            'store' => $transaction->store ?? $store,
            'receipt' => ReceiptSettings::payload($store),
        ])->setPaper('a4');

        $filename = 'invoice-'.$transaction->transaction_number.'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Unduh PDF struk secara publik (tanpa login), dilindungi token acak (status_token).
     *
     * Digunakan untuk tautan yang dibagikan via WhatsApp agar pelanggan bisa
     * mengunduh PDF tanpa harus masuk ke aplikasi.
     */
    public function exportPublicPdf(string $statusToken): Response
    {
        $transaction = Transaction::with('store')
            ->where('status_token', $statusToken)
            ->first();

        abort_if(! $transaction, 404);

        $store = $transaction->store;

        $transaction->load([
            'items',
            'user:id,name',
            'customer:id,name,phone,email',
        ]);

        $pdf = Pdf::loadView('pdf.invoice', [
            'transaction' => $transaction,
            'store' => $store,
            'receipt' => ReceiptSettings::payload($store),
        ])->setPaper('a4');

        $filename = 'invoice-'.$transaction->transaction_number.'.pdf';

        return $pdf->download($filename);
    }
}
