<?php

namespace App\Http\Controllers;

use App\Models\CashShift;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseCharge;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\Accounting\AccountingPeriodGuard;
use App\Support\Accounting\JournalPoster;
use App\Support\BaseUnit;
use App\Support\BatchTracking;
use App\Support\InventoryCosting;
use App\Support\InventoryType;
use App\Support\ProductQuantity;
use App\Support\StoreLocationType;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseController extends Controller
{
    private const CENTRAL_WAREHOUSE_MESSAGE = 'Pembelian supplier untuk outlet ini dialihkan ke Gudang Pusat. Aktifkan opsi "Izinkan pembelian lokal outlet" di Pengaturan Toko jika ingin membeli mandiri.';

    /**
     * Pembelian lokal diizinkan saat toko adalah outlet (bukan Gudang Pusat),
     * sistem gudang aktif, dan pengaturan is_allow_local_purchase diaktifkan.
     */
    private static function allowsLocalPurchase(Store $store): bool
    {
        return StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && (bool) ($store->settings['is_allow_local_purchase'] ?? false);
    }

    public function index()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! self::allowsLocalPurchase($store)) {
            return redirect()->route('dashboard')->with('warning', self::CENTRAL_WAREHOUSE_MESSAGE);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup modul pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $purchases = $store->purchases()
            ->with(['supplier:id,name', 'user:id,name'])
            ->withCount('lines')
            ->latest('purchased_at')
            ->limit(100)
            ->get()
            ->map(fn (Purchase $p) => [
                'id' => $p->id,
                'purchase_number' => $p->purchase_number,
                'purchased_at' => $p->purchased_at?->toIso8601String(),
                'notes' => $p->notes,
                'lines_count' => $p->lines_count,
                'supplier_name' => $p->supplier?->name,
                'user_name' => $p->user?->name,
            ]);

        return Inertia::render('Purchases/Index', [
            'purchases' => $purchases,
            'store' => $this->storePayload($store),
        ]);
    }

    public function create()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        // DEBUG: Log konfigurasi toko
        \Log::info('PurchaseController::create debug', [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'location_type' => $store->location_type,
            'organization_id' => $store->organization_id,
            'warehouse_enabled' => $store->settings['warehouse_enabled'] ?? false,
            'is_allow_local_purchase' => $store->settings['is_allow_local_purchase'] ?? false,
            'mustRouteThroughCentral' => StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store),
            'allowsLocal' => self::allowsLocalPurchase($store),
            'centralWarehouse' => $store->organization_id ? \App\Models\Store::query()
                ->where('organization_id', $store->organization_id)
                ->where('location_type', 'central_warehouse')
                ->first(['id', 'name']) : null,
        ]);

        if (StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! self::allowsLocalPurchase($store)) {
            return redirect()->route('dashboard')->with('warning', self::CENTRAL_WAREHOUSE_MESSAGE);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup modul pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $suppliers = $store->suppliers()->orderBy('name')->get(['id', 'name', 'phone']);
        $products = $store->products()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_bundle', false)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get([
                'id', 'name', 'sku', 'barcode', 'unit', 'base_unit', 'stock_quantity', 'cost_price',
                'average_cost', 'allows_decimal', 'has_variants', 'inventory_type',
            ])
            ->map(function (Product $product) {
                $data = $product->toArray();
                $data['base_unit'] = $product->baseUnit();
                $data['input_units'] = BaseUnit::inputUnitsFor($product->baseUnit());
                $data['inventory_type'] = InventoryType::of($product);

                return $data;
            });

        return Inertia::render('Purchases/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'chargeSuggestions' => $this->chargeSuggestions($store),
            'batchTrackingEnabled' => BatchTracking::enabled($store),
            'store' => $this->storePayload($store),
        ]);
    }

    /**
     * Nama biaya yang pernah dipakai toko ini, untuk saran autocomplete.
     * Digabung dengan preset umum agar toko baru tetap punya pilihan cepat.
     *
     * @return list<string>
     */
    private function chargeSuggestions($store): array
    {
        $used = PurchaseCharge::query()
            ->where('store_id', $store->id)
            ->select('name')
            ->selectRaw('COUNT(*) as usage_count')
            ->groupBy('name')
            ->orderByDesc('usage_count')
            ->limit(30)
            ->pluck('name')
            ->all();

        $presets = ['Ongkos kirim', 'Biaya bongkar muat', 'Biaya kuli angkut', 'Biaya packing', 'Biaya administrasi'];

        $merged = [];
        foreach ([...$used, ...$presets] as $name) {
            $key = mb_strtolower(trim($name));
            if ($key !== '' && ! isset($merged[$key])) {
                $merged[$key] = trim($name);
            }
        }

        return array_values($merged);
    }

    public function store(Request $request)
    {
        $store = auth()->user()->store;

        if (! $store) {
            abort(404);
        }

        if (StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! self::allowsLocalPurchase($store)) {
            return back()->withErrors(['store' => self::CENTRAL_WAREHOUSE_MESSAGE])->withInput();
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return back()->withErrors(['store' => 'Paket Starter tidak mencakup modul pembelian.'])->withInput();
        }

        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchased_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'nullable|array',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.new_product' => 'nullable|array',
            'lines.*.new_product.name' => 'required_with:lines.*.new_product|string|max:255',
            'lines.*.new_product.sku' => 'nullable|string|max:255',
            'lines.*.new_product.barcode' => 'nullable|string|max:255',
            'lines.*.new_product.inventory_type' => 'nullable|string|in:'.implode(',', array_keys(InventoryType::LABELS)),
            'lines.*.new_product.base_unit' => 'nullable|string|in:'.implode(',', array_keys(BaseUnit::LABELS)),
            'lines.*.new_product.unit' => 'nullable|string|max:50',
            'lines.*.new_product.allows_decimal' => 'nullable|boolean',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.input_unit' => 'required|string|max:20',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.batch_expiry_date' => 'nullable|date',
            'lines.*.batch_code' => 'nullable|string|max:120',
            'charges' => 'nullable|array',
            'charges.*.name' => 'required|string|max:255',
            'charges.*.amount' => 'required|numeric|min:0.01',
            'charges.*.notes' => 'nullable|string|max:1000',
            'ppn_amount' => 'nullable|numeric|min:0',
            'supplier_tax_invoice_no' => 'nullable|string|max:120',
            'is_paid' => 'nullable|boolean',
            'payment_method' => 'nullable|string|in:cash,transfer,ewallet',
            'amount_paid' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
        ]);

        $validated['lines'] = $validated['lines'] ?? [];
        $validated['charges'] = $validated['charges'] ?? [];

        // Pembelian boleh berisi barang saja, biaya saja (mis. ongkos kirim), atau keduanya.
        if ($validated['lines'] === [] && $validated['charges'] === []) {
            return back()->withErrors([
                'lines' => 'Tambahkan minimal satu barang atau satu biaya.',
            ])->withInput();
        }

        // Validasi: setiap baris harus punya product_id ATAU new_product, tidak keduanya
        foreach ($validated['lines'] as $idx => $row) {
            $hasProductId = ! empty($row['product_id']);
            $hasNewProduct = ! empty($row['new_product']['name']);
            if (! $hasProductId && ! $hasNewProduct) {
                return back()->withErrors([
                    "lines.{$idx}.product_id" => 'Pilih produk dari daftar atau isi data produk baru.',
                ])->withInput();
            }
            if ($hasProductId && $hasNewProduct) {
                return back()->withErrors([
                    "lines.{$idx}.product_id" => 'Gunakan salah satu: produk dari daftar atau produk baru, bukan keduanya.',
                ])->withInput();
            }
        }

        // 🛡️ Cegah input pada periode akuntansi tertutup ATAU backdate melebihi limit H-7
        \App\Support\Accounting\AccountingPeriodGuard::assertDateOpen($store, $validated['purchase_date'], 'purchase_date');

        if (isset($validated['supplier_id'])) {
            $supplierOk = Supplier::query()
                ->where('id', $validated['supplier_id'])
                ->where('store_id', $store->id)
                ->exists();
            if (! $supplierOk) {
                return back()->withErrors(['supplier_id' => 'Supplier tidak valid.'])->withInput();
            }
        }

        $pairs = [];
        foreach ($validated['lines'] as $row) {
            if (! empty($row['product_id'])) {
                $pair = ((int) $row['product_id']).':'.((int) ($row['product_variant_id'] ?? 0));
            } else {
                $pair = 'new:'.mb_strtolower(trim($row['new_product']['name']));
            }
            if (isset($pairs[$pair])) {
                return back()->withErrors([
                    'lines' => 'Produk dan varian yang sama tidak boleh dicatat lebih dari satu baris.',
                ])->withInput();
            }
            $pairs[$pair] = true;
        }

        $batchMode = BatchTracking::enabled($store);
        $userId = auth()->id();

        try {
            $purchase = DB::transaction(function () use ($validated, $store, $batchMode, $userId) {
                // Resolusi baris new_product: buat master produk jika belum ada
                foreach ($validated['lines'] as &$row) {
                    if (empty($row['new_product']['name'])) {
                        continue;
                    }
                    $np = $row['new_product'];
                    $npName = mb_trim($np['name']);

                    // Cari duplikat: nama produk existing di store ini
                    $existing = Product::query()
                        ->where('store_id', $store->id)
                        ->where('name', $npName)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $row['product_id'] = $existing->id;
                    } else {
                        StorePlanLimits::assertCanCreateProduct($store);
                        $product = $store->products()->create([
                            'name' => $npName,
                            'sku' => ! empty($np['sku']) ? mb_trim($np['sku']) : null,
                            'barcode' => ! empty($np['barcode']) ? mb_trim($np['barcode']) : null,
                            'inventory_type' => InventoryType::normalize($np['inventory_type'] ?? null),
                            'base_unit' => BaseUnit::normalizeBaseUnit($np['base_unit'] ?? null),
                            'unit' => ! empty($np['unit']) ? mb_trim($np['unit']) : 'pcs',
                            'price' => 0,
                            'cost_price' => 0,
                            'average_cost' => 0,
                            'stock_quantity' => 0,
                            'min_stock_level' => 0,
                            'track_stock' => true,
                            'allows_decimal' => (bool) ($np['allows_decimal'] ?? false),
                            'has_variants' => false,
                            'is_bundle' => false,
                            'is_active' => true,
                        ]);
                        $row['product_id'] = $product->id;
                    }
                }
                unset($row);

                $productIds = collect($validated['lines'])
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->sort()
                    ->values();

                $products = Product::query()
                    ->where('store_id', $store->id)
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== $productIds->count()) {
                    throw new \RuntimeException('Salah satu produk tidak ditemukan di toko ini.');
                }

                foreach ($products as $product) {
                    if (! $product->is_active) {
                        throw new \RuntimeException("Produk «{$product->name}» tidak aktif.");
                    }
                    if (! $product->track_stock || $product->is_bundle) {
                        throw new \RuntimeException("Produk «{$product->name}» tidak dapat menerima stok pembelian.");
                    }
                }

                $isPaid = $validated['is_paid'] ?? true;
                $paymentMethod = $validated['payment_method'] ?? 'cash';

                $purchase = Purchase::create([
                    'store_id' => $store->id,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'user_id' => $userId,
                    'purchased_at' => $validated['purchased_at'] ?? now(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                $dppCalc = '0.00';
                $lines = collect($validated['lines'])->sortBy(fn ($row) => sprintf(
                    '%020d:%020d',
                    (int) $row['product_id'],
                    (int) ($row['product_variant_id'] ?? 0),
                ));

                foreach ($lines as $row) {
                    /** @var Product $product */
                    $product = $products->get((int) $row['product_id']);
                    $variant = null;

                    if (! empty($row['product_variant_id'])) {
                        $variant = ProductVariant::query()
                            ->where('id', (int) $row['product_variant_id'])
                            ->where('product_id', $product->id)
                            ->where('store_id', $store->id)
                            ->where('is_active', true)
                            ->lockForUpdate()
                            ->first();
                        if (! $variant) {
                            throw new \RuntimeException('Varian tidak valid pada salah satu baris.');
                        }
                    }

                    if ($product->has_variants && ! $variant) {
                        throw new \RuntimeException("Pilih varian untuk «{$product->name}».");
                    }
                    if (! $product->has_variants && $variant) {
                        throw new \RuntimeException("«{$product->name}» tidak memakai varian.");
                    }
                    if ($batchMode && $product->has_variants) {
                        throw new \RuntimeException("Pembelian batch belum didukung untuk «{$product->name}» (produk varian).");
                    }

                    $inputUnit = mb_strtolower(trim((string) $row['input_unit']));
                    $baseUnit = $product->baseUnit();
                    try {
                        $qtyBase = BaseUnit::toBaseQuantity($row['quantity'], $inputUnit, $baseUnit);
                    } catch (\InvalidArgumentException $e) {
                        throw new \RuntimeException("Satuan untuk «{$product->name}» tidak valid: {$e->getMessage()}");
                    }
                    if (bccomp($qtyBase, '0', 4) <= 0) {
                        throw new \RuntimeException("Jumlah dasar untuk «{$product->name}» harus lebih dari 0.");
                    }

                    $quantityInput = number_format(round((float) $row['quantity'], 4), 4, '.', '');
                    $unitCostInput = null;
                    $unitCostBase = null;
                    $subtotal = null;
                    if (array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null) {
                        $unitCostInput = number_format(round((float) $row['unit_cost'], 2), 2, '.', '');
                        $unitCostBase = BaseUnit::toBaseUnitCost($unitCostInput, $inputUnit, $baseUnit);
                        $subtotal = InventoryCosting::extendedCost($qtyBase, $unitCostBase, 2);
                        $dppCalc = bcadd($dppCalc, $subtotal, 2);

                        $averageCost = InventoryCosting::averageCostAfterInbound($product, $qtyBase, $unitCostBase);
                        $baseCostRounded = round((float) $unitCostBase, 2);
                        $product->average_cost = $averageCost;
                        $product->cost_price = $baseCostRounded;
                        $product->save();
                        if ($variant) {
                            $variant->update(['cost_price' => $baseCostRounded]);
                        }
                    }

                    $batchExpiry = $row['batch_expiry_date'] ?? null;
                    $batchCode = isset($row['batch_code']) ? mb_substr(trim((string) $row['batch_code']), 0, 120) : null;
                    $batchCode = $batchCode === '' ? null : $batchCode;

                    PurchaseLine::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'product_name_snapshot' => $product->name,
                        'sku_snapshot' => $product->sku,
                        'quantity' => $qtyBase,
                        'unit_cost' => $unitCostBase === null ? null : round((float) $unitCostBase, 2),
                        'input_unit' => $inputUnit,
                        'quantity_input' => $quantityInput,
                        'base_unit' => $baseUnit,
                        'qty_base' => $qtyBase,
                        'unit_cost_input' => $unitCostInput,
                        'unit_cost_base' => $unitCostBase,
                        'subtotal' => $subtotal,
                        'batch_expiry_date' => $batchExpiry,
                        'batch_code' => $batchCode,
                    ]);

                    if ($batchMode) {
                        ProductBatch::create([
                            'store_id' => $store->id,
                            'product_id' => $product->id,
                            'quantity_remaining' => $qtyBase,
                            'expiry_date' => $batchExpiry,
                            'batch_code' => $batchCode,
                            'status' => ProductBatch::STATUS_AVAILABLE,
                        ]);
                        $synced = (string) ProductBatch::query()
                            ->where('store_id', $store->id)
                            ->where('product_id', $product->id)
                            ->sum('quantity_remaining');
                        $product->stock_quantity = $synced;
                        $product->save();
                        $balanceAfter = (string) $product->stock_quantity;
                    } elseif ($variant) {
                        $variant->increment('stock_quantity', $qtyBase);
                        $variant->refresh();
                        $variantSum = (string) ProductVariant::query()
                            ->where('store_id', $store->id)
                            ->where('product_id', $product->id)
                            ->sum('stock_quantity');
                        $product->stock_quantity = $variantSum;
                        $product->save();
                        $balanceAfter = (string) $variant->stock_quantity;
                    } else {
                        $product->increment('stock_quantity', $qtyBase);
                        $product->refresh();
                        $balanceAfter = (string) $product->stock_quantity;
                    }

                    StockMovement::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'user_id' => $userId,
                        'quantity_change' => $qtyBase,
                        'type' => StockMovement::TYPE_PURCHASE,
                        'reference_type' => Purchase::class,
                        'reference_id' => $purchase->id,
                        'balance_after' => $balanceAfter,
                        'notes' => $purchase->purchase_number,
                    ]);
                }

                $chargesTotal = '0.00';
                foreach ($validated['charges'] as $charge) {
                    $chargeName = mb_substr(mb_trim($charge['name']), 0, 255);
                    if ($chargeName === '') {
                        throw new \RuntimeException('Nama biaya tidak boleh kosong.');
                    }
                    $chargeAmount = number_format(round((float) $charge['amount'], 2), 2, '.', '');

                    PurchaseCharge::create([
                        'purchase_id' => $purchase->id,
                        'store_id' => $store->id,
                        'name' => $chargeName,
                        'amount' => $chargeAmount,
                        'notes' => ! empty($charge['notes']) ? mb_trim($charge['notes']) : null,
                    ]);

                    $chargesTotal = bcadd($chargesTotal, $chargeAmount, 2);
                }

                $ppn = number_format(round((float) ($validated['ppn_amount'] ?? 0), 2), 2, '.', '');
                if (bccomp($ppn, '0', 2) > 0 && bccomp($dppCalc, '0.01', 2) < 0) {
                    throw new \RuntimeException('Isi harga beli per baris (nilai DPP) jika mencatat PPN Masukan.');
                }

                $totalAmountCalc = (float) bcadd(bcadd($dppCalc, $chargesTotal, 2), $ppn, 2);
                $amountPaid = isset($validated['amount_paid'])
                    ? round((float) $validated['amount_paid'], 2)
                    : ($isPaid ? $totalAmountCalc : 0);

                $purchase->update([
                    'dpp_amount' => $dppCalc,
                    'charges_amount' => $chargesTotal,
                    'ppn_amount' => $ppn,
                    'total_amount' => $totalAmountCalc,
                    'is_paid' => $amountPaid >= $totalAmountCalc,
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $amountPaid,
                    'due_date' => $validated['due_date'] ?? null,
                    'supplier_tax_invoice_no' => isset($validated['supplier_tax_invoice_no'])
                        ? mb_substr(trim((string) $validated['supplier_tax_invoice_no']), 0, 120)
                        : null,
                ]);

                return $purchase;
            });

            JournalPoster::postPurchase($store, $purchase->fresh(['lines', 'charges']));
        } catch (\Throwable $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        $purchase->load('supplier:id,name');

        $message = $purchase->lines()->exists()
            ? 'Pembelian tercatat dan stok bertambah.'
            : 'Biaya pembelian tercatat sebagai pengeluaran.';

        // Pembelian lokal outlet yang dilunasi tunai: catat pengeluaran dari kas kasir aktif.
        if (self::allowsLocalPurchase($store) && $purchase->is_paid && $purchase->payment_method === 'cash') {
            $openShift = CashShift::query()
                ->where('store_id', $store->id)
                ->where('user_id', $userId)
                ->whereNull('closed_at')
                ->first();

            if ($openShift) {
                CashTransaction::create([
                    'store_id' => $store->id,
                    'user_id' => $userId,
                    'cash_shift_id' => $openShift->id,
                    'type' => CashTransaction::TYPE_OUT,
                    'category' => CashTransaction::CATEGORY_EXPENSE,
                    'amount' => $purchase->total_amount,
                    'party_name' => $purchase->supplier?->name,
                    'notes' => 'Pembelian lokal '.$purchase->purchase_number,
                ]);
            } else {
                $message .= ' Kas keluar tidak dicatat karena tidak ada shift kasir terbuka.';
            }
        }

        // Log aktivitas pembelian
        \App\Services\ActivityLogger::logCreated(
            store: $store,
            subject: $purchase,
            description: "Membuat pembelian {$purchase->purchase_number}",
            metadata: [
                'purchase_number' => $purchase->purchase_number,
                'supplier' => $purchase->supplier?->name,
                'lines_count' => $purchase->lines()->count(),
            ]
        );

        return redirect()->route('purchases.index')->with('success', $message);
    }

    public function show(Purchase $purchase)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $purchase->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup modul pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $purchase->load([
            'supplier:id,name,phone',
            'user:id,name',
            'lines.product:id,name,sku,unit,base_unit,allows_decimal,has_variants',
            'lines.productVariant:id,name',
            'charges',
        ]);

        $lines = $purchase->lines->map(function (PurchaseLine $line) {
            $baseUnit = $line->base_unit ?? $line->product?->baseUnit() ?? $line->product?->unit ?? BaseUnit::PIECE;
            $qtyBase = $line->qty_base ?? $line->quantity;
            $unitCostBase = $line->unit_cost_base ?? $line->unit_cost;
            $subtotal = $line->subtotal;
            if ($subtotal === null && $unitCostBase !== null) {
                $subtotal = InventoryCosting::extendedCost($qtyBase, $unitCostBase, 2);
            }

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name_snapshot ?? $line->product?->name,
                'product_variant_id' => $line->product_variant_id,
                'variant_name' => $line->productVariant?->name,
                'sku' => $line->sku_snapshot ?? $line->product?->sku,
                'quantity_input' => $line->quantity_input ?? $line->quantity,
                'input_unit' => $line->input_unit ?? $baseUnit,
                'qty_base' => $qtyBase,
                'base_unit' => $baseUnit,
                'unit_cost_input' => $line->unit_cost_input ?? $line->unit_cost,
                'unit_cost_base' => $unitCostBase,
                'subtotal' => $subtotal,
                'batch_expiry_date' => $line->batch_expiry_date?->format('Y-m-d'),
                'batch_code' => $line->batch_code,
            ];
        });

        $charges = $purchase->charges->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'amount' => $c->amount,
            'notes' => $c->notes,
        ])->values();

        return Inertia::render('Purchases/Show', [
            'purchase' => [
                'id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'purchased_at' => $purchase->purchased_at?->toIso8601String(),
                'created_at' => $purchase->created_at?->toIso8601String(),
                'notes' => $purchase->notes,
                'dpp_amount' => $purchase->dpp_amount,
                'charges_amount' => $purchase->charges_amount,
                'ppn_amount' => $purchase->ppn_amount,
                'total_amount' => $purchase->total_amount,
                'supplier_tax_invoice_no' => $purchase->supplier_tax_invoice_no,
                'is_paid' => $purchase->is_paid ?? true,
                'amount_paid' => (float) ($purchase->amount_paid ?? 0),
                'payment_method' => $purchase->payment_method,
                'due_date' => $purchase->due_date?->format('Y-m-d'),
                'supplier' => $purchase->supplier ? [
                    'id' => $purchase->supplier->id,
                    'name' => $purchase->supplier->name,
                    'phone' => $purchase->supplier->phone,
                ] : null,
                'user_name' => $purchase->user?->name,
                'lines' => $lines,
                'charges' => $charges,
            ],
            'store' => $this->storePayload($store),
        ]);
    }

    public function storeSupplier(Request $request)
    {
        $store = auth()->user()->store;
        if (! $store) {
            abort(404);
        }

        if (StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)
            && ! self::allowsLocalPurchase($store)) {
            return response()->json(['message' => self::CENTRAL_WAREHOUSE_MESSAGE], 403);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return response()->json(['message' => 'Paket Starter tidak mencakup modul pembelian.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
        ]);

        $supplier = $store->suppliers()->create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
        ]);

        return response()->json([
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
            ],
        ]);
    }

    /**
     * Hapus pembelian permanen — mengembalikan stok produk/varian (atau mengurangi
     * sisa batch saat mode batch aktif), membatalkan jurnal, dan menghapus
     * baris pembelian, biaya, serta riwayat stok terkait.
     */
    public function destroy(Purchase $purchase)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $purchase->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return back()->with('error', 'Paket Starter tidak mencakup modul pembelian.');
        }

        if ($purchase->purchaseReturns()->exists()) {
            return back()->with('error', 'Pembelian tidak dapat dihapus karena sudah memiliki retur supplier. Hapus retur terlebih dahulu.');
        }

        DB::transaction(function () use ($purchase, $store) {
            $purchase->load(['lines.product', 'lines.productVariant']);

            $batchMode = BatchTracking::enabled($store);
            $userId = auth()->id();

            foreach ($purchase->lines as $line) {
                $product = $line->product;
                if (! $product) {
                    continue;
                }

                $qtyBase = (string) ($line->qty_base ?? $line->quantity);

                if ($batchMode) {
                    // Kurangi sisa batch (FIFO/FEFO) lalu sinkronkan stok produk.
                    self::rollbackBatchStock($product, $qtyBase);
                    $balanceAfter = (string) $product->fresh()->stock_quantity;
                } elseif ($line->product_variant_id && $line->productVariant) {
                    $variant = $line->productVariant;
                    $variant->decrement('stock_quantity', $qtyBase);
                    $variant->refresh();
                    $variantSum = (string) ProductVariant::query()
                        ->where('store_id', $store->id)
                        ->where('product_id', $product->id)
                        ->sum('stock_quantity');
                    $product->stock_quantity = $variantSum;
                    $product->save();
                    $balanceAfter = (string) $variant->stock_quantity;
                } else {
                    $product->decrement('stock_quantity', $qtyBase);
                    $product->refresh();
                    $balanceAfter = (string) $product->stock_quantity;
                }

                StockMovement::create([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $line->product_variant_id,
                    'user_id' => $userId,
                    'quantity_change' => bcmul($qtyBase, '-1', 4),
                    'type' => StockMovement::TYPE_ADJUSTMENT,
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'balance_after' => $balanceAfter,
                    'notes' => 'Hapus pembelian: '.$purchase->purchase_number,
                ]);
            }

            // Hapus jurnal pembelian beserta jurnal pelunasan utang (cascade ke journal_lines).
            $paymentIds = $purchase->payments()->pluck('id');
            $journalEntries = JournalEntry::query()
                ->where(function ($query) use ($purchase, $paymentIds) {
                    $query->where('reference_type', Purchase::class)
                        ->where('reference_id', $purchase->id)
                        ->orWhere(function ($query) use ($paymentIds) {
                            $query->where('reference_type', \App\Models\PurchasePayment::class)
                                ->whereIn('reference_id', $paymentIds);
                        });
                })
                ->get();
            foreach ($journalEntries as $entry) {
                AccountingPeriodGuard::assertDateOpen($store, $entry->entry_date);
            }
            JournalEntry::query()->whereKey($journalEntries->modelKeys())->delete();

            $purchase->lines()->delete();
            $purchase->charges()->delete();
            $purchase->delete();
        });

        // Log aktivitas penghapusan
        \App\Services\ActivityLogger::logDeleted(
            store: $store,
            subject: $purchase,
            description: "Menghapus pembelian {$purchase->purchase_number}"
        );

        return back()->with('success', 'Pembelian berhasil dihapus, stok dan jurnal dikembalikan.');
    }

    /**
     * Kurangi sisa stok batch paling tua (FIFO/FEFO) sebesar qty pembelian
     * yang dihapus, lalu sinkronkan stock_quantity produk dengan total batch.
     */
    private static function rollbackBatchStock(Product $product, string $qty): void
    {
        $remaining = ProductQuantity::normalizeStorageAmount($product, $qty);

        $batches = ProductBatch::query()
            ->where('product_id', $product->id)
            ->where('quantity_remaining', '>', 0)
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
            $batch->decrement('quantity_remaining', $take);
            $remaining = ProductQuantity::sub($remaining, $take);
        }

        $synced = (string) ProductBatch::query()
            ->where('product_id', $product->id)
            ->sum('quantity_remaining');
        $product->stock_quantity = ProductQuantity::normalizeStorageAmount($product, $synced);
        $product->save();
    }

    private function storePayload($store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'currency' => $store->currency,
            'location_type' => StoreLocationType::of($store),
            'location_label' => StoreLocationType::label($store),
        ];
    }
}
