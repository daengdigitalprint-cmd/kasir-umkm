<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PreOrder;
use App\Models\PreOrderLine;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PreOrderController extends Controller
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
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $orders = $store->preOrders()
            ->with(['customer:id,name', 'user:id,name'])
            ->withCount('lines')
            ->latest()
            ->limit(150)
            ->get()
            ->map(fn (PreOrder $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status,
                'expected_pickup_at' => $o->expected_pickup_at?->format('Y-m-d'),
                'deposit_required' => (float) $o->deposit_required,
                'deposit_received' => (float) $o->deposit_received,
                'estimated_total' => (float) $o->estimated_total,
                'customer_name' => $o->customer?->name,
                'user_name' => $o->user?->name,
                'lines_count' => $o->lines_count,
                'created_at' => $o->created_at?->toIso8601String(),
            ]);

        return Inertia::render('PreOrders/Index', [
            'preOrders' => $orders,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function create()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $customers = $store->customers()->orderBy('name')->get(['id', 'name']);

        $products = $store->products()
            ->where('is_active', true)
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('name')
            ->get([
                'id', 'name', 'sku', 'unit', 'price', 'stock_quantity', 'allows_decimal', 'has_variants', 'is_bundle',
                'track_stock', 'modifier_options',
            ]);

        return Inertia::render('PreOrders/Create', [
            'customers' => $customers,
            'products' => $products,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $store = auth()->user()->store;

        if (! $store) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'expected_pickup_at' => 'nullable|date',
            'deposit_required' => 'nullable|numeric|min:0',
            'deposit_received' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        DB::beginTransaction();

        try {
            $estimated = 0.0;
            $lineRows = [];

            foreach ($validated['lines'] as $row) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('id', $row['product_id'])
                    ->firstOrFail();

                if (! $product->is_active) {
                    throw new \Exception("Produk «{$product->name}» tidak aktif.");
                }

                $qty = ProductQuantity::normalizeLineQuantity($product, $row['quantity']);
                $variant = null;

                if ($product->is_bundle) {
                    if (! empty($row['product_variant_id'])) {
                        throw new \Exception('Paket tidak memakai varian.');
                    }
                } elseif ($product->has_variants) {
                    $variant = ProductVariant::query()
                        ->where('id', (int) $row['product_variant_id'])
                        ->where('product_id', $product->id)
                        ->where('store_id', $store->id)
                        ->where('is_active', true)
                        ->first();
                    if (! $variant) {
                        throw new \Exception("Pilih varian valid untuk «{$product->name}».");
                    }
                } elseif (! empty($row['product_variant_id'])) {
                    throw new \Exception("Produk «{$product->name}» tidak memakai varian.");
                }

                $unit = $variant ? (float) $variant->effectiveUnitPrice() : (float) $product->price;
                $lineTotal = round($unit * (float) $qty, 2);
                $estimated += $lineTotal;

                $display = $variant ? "{$product->name} — {$variant->name}" : $product->name;

                $lineRows[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $qty,
                    'unit_price' => round($unit, 2),
                    'product_name' => $display,
                    'modifiers' => null,
                ];
            }

            $customerId = $validated['customer_id'] ?? null;
            if ($customerId) {
                $c = Customer::query()->where('store_id', $store->id)->where('id', $customerId)->first();
                if (! $c) {
                    throw new \Exception('Pelanggan tidak valid.');
                }
            }

            $depReq = round((float) ($validated['deposit_required'] ?? 0), 2);
            $depRec = round((float) ($validated['deposit_received'] ?? 0), 2);

            $order = PreOrder::create([
                'store_id' => $store->id,
                'customer_id' => $customerId,
                'user_id' => auth()->id(),
                'status' => 'open',
                'expected_pickup_at' => $validated['expected_pickup_at'] ?? null,
                'deposit_required' => $depReq,
                'deposit_received' => $depRec,
                'estimated_total' => round($estimated, 2),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lineRows as $lr) {
                PreOrderLine::create([
                    'pre_order_id' => $order->id,
                    ...$lr,
                ]);
            }

            DB::commit();

            return redirect()->route('pre-orders.show', $order)->with('success', 'Pre-order disimpan.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function show(PreOrder $preOrder)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $preOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        $preOrder->load(['lines', 'customer:id,name', 'user:id,name', 'fulfilledTransaction:id,transaction_number,total_amount']);

        return Inertia::render('PreOrders/Show', [
            'preOrder' => [
                'id' => $preOrder->id,
                'order_number' => $preOrder->order_number,
                'status' => $preOrder->status,
                'expected_pickup_at' => $preOrder->expected_pickup_at?->format('Y-m-d'),
                'deposit_required' => (float) $preOrder->deposit_required,
                'deposit_received' => (float) $preOrder->deposit_received,
                'estimated_total' => (float) $preOrder->estimated_total,
                'notes' => $preOrder->notes,
                'customer' => $preOrder->customer ? ['id' => $preOrder->customer->id, 'name' => $preOrder->customer->name] : null,
                'user_name' => $preOrder->user?->name,
                'created_at' => $preOrder->created_at?->toIso8601String(),
                'lines' => $preOrder->lines->map(fn (PreOrderLine $l) => [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'product_variant_id' => $l->product_variant_id,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'line_total' => round((float) $l->quantity * (float) $l->unit_price, 2),
                    'product_name' => $l->product_name,
                ]),
                'fulfilled_transaction' => $preOrder->fulfilledTransaction
                    ? [
                        'id' => $preOrder->fulfilledTransaction->id,
                        'transaction_number' => $preOrder->fulfilledTransaction->transaction_number,
                        'total_amount' => (float) $preOrder->fulfilledTransaction->total_amount,
                    ]
                    : null,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function update(Request $request, PreOrder $preOrder)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $preOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        if ($preOrder->status !== 'open') {
            return back()->withErrors(['status' => 'Hanya pre-order berstatus terbuka yang bisa diubah.']);
        }

        $validated = $request->validate([
            'deposit_required' => 'sometimes|numeric|min:0',
            'deposit_received' => 'sometimes|numeric|min:0',
            'expected_pickup_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $preOrder->update([
            'deposit_required' => isset($validated['deposit_required'])
                ? round((float) $validated['deposit_required'], 2)
                : $preOrder->deposit_required,
            'deposit_received' => isset($validated['deposit_received'])
                ? round((float) $validated['deposit_received'], 2)
                : $preOrder->deposit_received,
            'expected_pickup_at' => array_key_exists('expected_pickup_at', $validated)
                ? $validated['expected_pickup_at']
                : $preOrder->expected_pickup_at,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $preOrder->notes,
        ]);

        return back()->with('success', 'Pre-order diperbarui.');
    }

    public function cancel(PreOrder $preOrder)
    {
        $store = auth()->user()->store;

        if (! $store || (int) $preOrder->store_id !== (int) $store->id) {
            abort(404);
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('dashboard')
                ->with('warning', 'Paket Starter tidak mencakup pre-order. Upgrade ke paket GrowUp di halaman langganan.');
        }

        if ($preOrder->status !== 'open') {
            return back()->withErrors(['status' => 'Pre-order ini sudah tidak aktif.']);
        }

        $preOrder->update(['status' => 'cancelled']);

        return redirect()->route('pre-orders.index')->with('success', 'Pre-order dibatalkan.');
    }
}
