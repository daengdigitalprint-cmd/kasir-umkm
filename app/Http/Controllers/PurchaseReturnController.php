<?php

namespace App\Http\Controllers;

use App\Http\Requests\Purchases\StorePurchaseReturnRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\BatchTracking;
use App\Support\InventoryType;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseReturnController extends Controller
{
    public function index()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup retur pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $returns = $store->purchaseReturns()
            ->with(['supplier:id,name', 'user:id,name', 'purchase:id,purchase_number'])
            ->withCount('lines')
            ->latest('returned_at')
            ->limit(100)
            ->get()
            ->map(fn (PurchaseReturn $r) => [
                'id' => $r->id,
                'return_number' => $r->return_number,
                'returned_at' => $r->returned_at?->toIso8601String(),
                'notes' => $r->notes,
                'lines_count' => $r->lines_count,
                'supplier_name' => $r->supplier?->name,
                'user_name' => $r->user?->name,
                'purchase_id' => $r->purchase_id,
                'purchase_number' => $r->purchase?->purchase_number,
            ]);

        return Inertia::render('PurchaseReturns/Index', [
            'returns' => $returns,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $purchaseReturn->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup retur pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $purchaseReturn->load([
            'supplier:id,name,phone',
            'user:id,name',
            'purchase:id,purchase_number,purchased_at',
            'lines.product:id,name,sku,unit',
            'lines.productVariant:id,name',
            'lines.productBatch:id,batch_code,expiry_date',
        ]);

        $lines = $purchaseReturn->lines->map(fn (PurchaseReturnLine $line) => [
            'id' => $line->id,
            'product_name' => $line->product?->name,
            'sku' => $line->product?->sku,
            'unit' => $line->product?->unit,
            'variant_name' => $line->productVariant?->name,
            'quantity' => $line->quantity,
            'product_batch_id' => $line->product_batch_id,
            'batch_code' => $line->productBatch?->batch_code,
            'batch_expiry_date' => $line->productBatch?->expiry_date?->format('Y-m-d'),
        ]);

        return Inertia::render('PurchaseReturns/Show', [
            'purchaseReturn' => [
                'id' => $purchaseReturn->id,
                'return_number' => $purchaseReturn->return_number,
                'returned_at' => $purchaseReturn->returned_at?->toIso8601String(),
                'notes' => $purchaseReturn->notes,
                'supplier' => $purchaseReturn->supplier ? [
                    'id' => $purchaseReturn->supplier->id,
                    'name' => $purchaseReturn->supplier->name,
                    'phone' => $purchaseReturn->supplier->phone,
                ] : null,
                'user_name' => $purchaseReturn->user?->name,
                'purchase' => $purchaseReturn->purchase ? [
                    'id' => $purchaseReturn->purchase->id,
                    'purchase_number' => $purchaseReturn->purchase->purchase_number,
                    'purchased_at' => $purchaseReturn->purchase->purchased_at?->toIso8601String(),
                ] : null,
                'lines' => $lines,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup retur pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $linkedPurchase = null;
        $purchaseId = $request->integer('purchase_id');
        if ($purchaseId > 0) {
            $p = Purchase::query()
                ->where('store_id', $store->id)
                ->with(['lines.product', 'lines.productVariant'])
                ->find($purchaseId);
            if ($p) {
                $linkedPurchase = $this->buildLinkedPurchaseView($store, $p);
            }
        }

        $batchMode = BatchTracking::enabled($store);

        $productsQuery = $store->products()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_bundle', false)
            ->whereIn('inventory_type', [InventoryType::RAW_MATERIAL, InventoryType::PACKAGING])
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')]);

        if ($batchMode) {
            $productsQuery->with([
                'batches' => fn ($q) => $q
                    ->where('quantity_remaining', '>', 0)
                    ->orderBy('expiry_date')
                    ->orderBy('id'),
            ]);
        }

        $products = $productsQuery
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit', 'stock_quantity', 'allows_decimal', 'has_variants'])
            ->map(function (Product $p) use ($batchMode) {
                $row = [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'unit' => $p->unit,
                    'stock_quantity' => $p->stock_quantity,
                    'allows_decimal' => (bool) $p->allows_decimal,
                    'has_variants' => (bool) $p->has_variants,
                    'variants' => $p->variants->map(fn (ProductVariant $v) => [
                        'id' => $v->id,
                        'name' => $v->name,
                        'stock_quantity' => $v->stock_quantity,
                    ])->values()->all(),
                    'batches' => [],
                ];
                if ($batchMode && $p->relationLoaded('batches')) {
                    $row['batches'] = $p->batches->map(fn (ProductBatch $b) => [
                        'id' => $b->id,
                        'expiry_date' => $b->expiry_date?->format('Y-m-d'),
                        'batch_code' => $b->batch_code,
                        'quantity_remaining' => $b->quantity_remaining,
                    ])->values()->all();
                }

                return $row;
            });

        return Inertia::render('PurchaseReturns/Create', [
            'suppliers' => $store->suppliers()->orderBy('name')->get(['id', 'name', 'phone']),
            'products' => $products,
            'batchTrackingEnabled' => $batchMode,
            'linkedPurchase' => $linkedPurchase,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function store(StorePurchaseReturnRequest $request)
    {
        $store = auth()->user()->store;

        if (! $store) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup retur pembelian. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $validated = $request->validated();

        if (isset($validated['supplier_id'])) {
            $supplierOk = Supplier::query()
                ->where('id', $validated['supplier_id'])
                ->where('store_id', $store->id)
                ->exists();
            if (! $supplierOk) {
                return back()->withErrors(['supplier_id' => 'Supplier tidak valid.'])->withInput();
            }
        }

        $linkedPurchaseId = isset($validated['purchase_id']) ? (int) $validated['purchase_id'] : null;
        $sourcePurchase = null;
        if ($linkedPurchaseId) {
            $sourcePurchase = Purchase::query()
                ->where('store_id', $store->id)
                ->find($linkedPurchaseId);
            if (! $sourcePurchase) {
                return back()->withErrors(['purchase_id' => 'Pembelian sumber tidak valid.'])->withInput();
            }
            if ($sourcePurchase->supplier_id !== null) {
                $validated['supplier_id'] = $sourcePurchase->supplier_id;
            }
            $purchasedMap = $this->purchasedQtyByProductVariant($sourcePurchase);
            $returnedMap = $this->returnedQtyByProductVariantForPurchase((int) $store->id, $linkedPurchaseId);
            try {
                $this->assertReturnTotalsWithinPurchase($store, $purchasedMap, $returnedMap, $validated['lines']);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['lines' => $e->getMessage()])->withInput();
            }
        }

        $batchMode = BatchTracking::enabled($store);
        $userId = auth()->id();

        DB::beginTransaction();

        try {
            $purchaseReturn = PurchaseReturn::create([
                'store_id' => $store->id,
                'purchase_id' => $linkedPurchaseId,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'user_id' => $userId,
                'returned_at' => $validated['returned_at'] ?? now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['lines'] as $row) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('id', $row['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $product->track_stock) {
                    throw new \RuntimeException("Produk «{$product->name}» tidak melacak stok.");
                }

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

                if ($product->has_variants) {
                    if (! $variant) {
                        throw new \RuntimeException("Pilih varian untuk «{$product->name}».");
                    }
                } elseif ($variant) {
                    throw new \RuntimeException("«{$product->name}» tidak memakai varian.");
                }

                if ($batchMode && $product->has_variants) {
                    throw new \RuntimeException("Retur batch belum didukung untuk «{$product->name}» (produk varian).");
                }

                try {
                    $qty = ProductQuantity::normalizeLineQuantity($product, $row['quantity']);
                } catch (\InvalidArgumentException) {
                    throw new \RuntimeException("Jumlah tidak valid untuk «{$product->name}».");
                }

                $batch = null;
                if ($batchMode) {
                    $batchId = $row['product_batch_id'] ?? null;
                    if ($batchId === null) {
                        throw new \RuntimeException("Pilih batch stok untuk «{$product->name}».");
                    }
                    $batch = ProductBatch::query()
                        ->where('id', (int) $batchId)
                        ->where('store_id', $store->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $batch) {
                        throw new \RuntimeException("Batch tidak valid untuk «{$product->name}».");
                    }
                    $rem = ProductQuantity::normalizeStorageAmount($product, $batch->quantity_remaining);
                    if (ProductQuantity::cmp($rem, $qty) < 0) {
                        throw new \RuntimeException("Stok batch tidak cukup untuk «{$product->name}».");
                    }
                } else {
                    if (! empty($row['product_batch_id'])) {
                        throw new \RuntimeException('Batch tidak dipakai saat mode batch toko nonaktif.');
                    }
                    if ($variant) {
                        $vBal = ProductQuantity::normalizeStorageAmount($product, $variant->stock_quantity);
                        if (ProductQuantity::cmp($vBal, $qty) < 0) {
                            throw new \RuntimeException("Stok varian tidak cukup untuk «{$product->name}».");
                        }
                    } else {
                        $pBal = ProductQuantity::normalizeStorageAmount($product, $product->stock_quantity);
                        if (ProductQuantity::cmp($pBal, $qty) < 0) {
                            throw new \RuntimeException("Stok tidak cukup untuk «{$product->name}».");
                        }
                    }
                }

                PurchaseReturnLine::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_batch_id' => $batch?->id,
                    'quantity' => $qty,
                ]);

                $negQty = ProductQuantity::sub('0.0000', $qty);

                if ($batchMode && $batch) {
                    $batch->decrement('quantity_remaining', $qty);
                    $synced = (string) ProductBatch::where('product_id', $product->id)->sum('quantity_remaining');
                    $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $synced)]);
                    $balanceAfter = (string) $product->stock_quantity;
                    StockMovement::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'user_id' => $userId,
                        'quantity_change' => $negQty,
                        'type' => StockMovement::TYPE_SUPPLIER_RETURN,
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $purchaseReturn->id,
                        'balance_after' => $balanceAfter,
                        'notes' => $purchaseReturn->return_number,
                    ]);
                } elseif ($variant) {
                    $variant->decrement('stock_quantity', $qty);
                    $variant->refresh();
                    $vSum = (string) ProductVariant::where('product_id', $product->id)->sum('stock_quantity');
                    $product->update(['stock_quantity' => ProductQuantity::normalizeStorageAmount($product, $vSum)]);
                    $balanceAfter = (string) $variant->stock_quantity;
                    StockMovement::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'user_id' => $userId,
                        'quantity_change' => $negQty,
                        'type' => StockMovement::TYPE_SUPPLIER_RETURN,
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $purchaseReturn->id,
                        'balance_after' => $balanceAfter,
                        'notes' => $purchaseReturn->return_number,
                    ]);
                } else {
                    $product->decrement('stock_quantity', $qty);
                    $product->refresh();
                    $balanceAfter = (string) $product->stock_quantity;
                    StockMovement::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'user_id' => $userId,
                        'quantity_change' => $negQty,
                        'type' => StockMovement::TYPE_SUPPLIER_RETURN,
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $purchaseReturn->id,
                        'balance_after' => $balanceAfter,
                        'notes' => $purchaseReturn->return_number,
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors(['lines' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('purchase-returns.show', $purchaseReturn)
            ->with('success', 'Retur pemasok tercatat dan stok berkurang.');
    }

    private function purchaseQtyKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? 0);
    }

    /**
     * @return array<string, string>
     */
    private function purchasedQtyByProductVariant(Purchase $purchase): array
    {
        $purchase->loadMissing('lines.product');
        $map = [];
        foreach ($purchase->lines as $line) {
            $product = $line->product;
            if (! $product) {
                continue;
            }
            $vid = $line->product_variant_id ? (int) $line->product_variant_id : null;
            $k = $this->purchaseQtyKey((int) $line->product_id, $vid);
            $q = ProductQuantity::normalizeStorageAmount($product, $line->quantity);
            $map[$k] = isset($map[$k]) ? bcadd($map[$k], $q, ProductQuantity::SCALE) : $q;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function returnedQtyByProductVariantForPurchase(int $storeId, int $purchaseId): array
    {
        $lines = PurchaseReturnLine::query()
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
            ->where('purchase_returns.store_id', $storeId)
            ->where('purchase_returns.purchase_id', $purchaseId)
            ->get([
                'purchase_return_lines.product_id',
                'purchase_return_lines.product_variant_id',
                'purchase_return_lines.quantity',
            ]);

        $map = [];
        foreach ($lines as $row) {
            $product = Product::query()->where('store_id', $storeId)->find($row->product_id);
            if (! $product) {
                continue;
            }
            $vid = $row->product_variant_id ? (int) $row->product_variant_id : null;
            $k = $this->purchaseQtyKey((int) $row->product_id, $vid);
            $q = ProductQuantity::normalizeStorageAmount($product, $row->quantity);
            $map[$k] = isset($map[$k]) ? bcadd($map[$k], $q, ProductQuantity::SCALE) : $q;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLinkedPurchaseView(Store $store, Purchase $purchase): array
    {
        $purchasedMap = $this->purchasedQtyByProductVariant($purchase);
        $returnedMap = $this->returnedQtyByProductVariantForPurchase((int) $store->id, (int) $purchase->id);

        $groups = [];
        foreach ($purchasedMap as $k => $purchasedQty) {
            $parts = explode(':', $k, 2);
            $pid = (int) $parts[0];
            $vidRaw = (int) ($parts[1] ?? 0);
            $vid = $vidRaw === 0 ? null : $vidRaw;

            $product = Product::query()->where('store_id', $store->id)->find($pid);
            if (! $product) {
                continue;
            }

            $variantName = null;
            if ($vid !== null) {
                $variantName = ProductVariant::query()
                    ->where('product_id', $pid)
                    ->where('id', $vid)
                    ->value('name');
            }

            $returned = $returnedMap[$k] ?? '0.0000';
            $returnable = ProductQuantity::sub($purchasedQty, $returned);
            if (ProductQuantity::cmp($returnable, '0.0000') < 0) {
                $returnable = '0.0000';
            }

            $groups[] = [
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'product_name' => $product->name,
                'variant_name' => $variantName,
                'has_variants' => (bool) $product->has_variants,
                'allows_decimal' => (bool) $product->allows_decimal,
                'qty_purchased' => $purchasedQty,
                'qty_already_returned' => $returned,
                'qty_max_returnable' => $returnable,
            ];
        }

        return [
            'id' => $purchase->id,
            'purchase_number' => $purchase->purchase_number,
            'purchased_at' => $purchase->purchased_at?->toIso8601String(),
            'supplier_id' => $purchase->supplier_id,
            'line_groups' => $groups,
        ];
    }

    /**
     * @param  array<string, string>  $purchasedMap
     * @param  array<string, string>  $returnedMap
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertReturnTotalsWithinPurchase(Store $store, array $purchasedMap, array $returnedMap, array $lines): void
    {
        $newTotals = [];
        foreach ($lines as $row) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $row['product_id'])
                ->first();
            if (! $product) {
                throw new \RuntimeException('Produk tidak ditemukan.');
            }
            $vid = ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;
            $k = $this->purchaseQtyKey((int) $product->id, $vid);
            $q = ProductQuantity::normalizeLineQuantity($product, $row['quantity']);
            $newTotals[$k] = isset($newTotals[$k]) ? bcadd($newTotals[$k], $q, ProductQuantity::SCALE) : $q;
        }

        foreach ($newTotals as $k => $newQty) {
            if (! isset($purchasedMap[$k])) {
                throw new \RuntimeException('Salah satu produk pada retur tidak termasuk pembelian sumber.');
            }
            $purchasedQty = $purchasedMap[$k];
            $prevReturned = $returnedMap[$k] ?? '0.0000';
            $allowed = ProductQuantity::sub($purchasedQty, $prevReturned);
            if (ProductQuantity::cmp($allowed, $newQty) < 0) {
                throw new \RuntimeException('Jumlah retur melebihi kuantitas pembelian yang masih boleh dikembalikan untuk salah satu produk.');
            }
        }
    }
}
