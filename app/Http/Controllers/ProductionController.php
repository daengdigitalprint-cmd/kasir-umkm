<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Support\InventoryCosting;
use App\Support\ProductQuantity;
use App\Support\StorePlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductionController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $query = ProductionBatch::query()
            ->where('store_id', $store->id)
            ->with(['recipe:id,name,code', 'product:id,name,sku'])
            ->withCount('lines');

        if ($request->filled('status') && in_array($request->input('status'), ['draft', 'completed', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        $batches = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Productions/Index', [
            'batches' => $batches,
            'currentStore' => ['id' => $store->id, 'name' => $store->name],
            'statusFilter' => $request->input('status'),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        if (! StorePlanLimits::allowsAdvancedRetailFeatures($store)) {
            return redirect()->route('productions.index')
                ->with('warning', 'Paket Starter tidak mencakup produksi. Upgrade ke paket GrowUp.');
        }

        $recipes = Recipe::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with(['product:id,name,sku,base_unit,average_cost', 'ingredients.product:id,name,sku,base_unit,average_cost,stock_quantity'])
            ->get();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('track_stock', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock_quantity', 'base_unit', 'average_cost']);

        return Inertia::render('Productions/Create', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'recipes' => $recipes,
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'multiplier' => ['required', 'numeric', 'min:0.0001', 'max:9999'],
        ]);

        $recipe = Recipe::with(['ingredients.product', 'product'])->findOrFail($validated['recipe_id']);

        if ((int) $recipe->store_id !== (int) $store->id) {
            abort(403, 'Recipe bukan milik toko ini.');
        }
        if (! $recipe->isActive()) {
            throw ValidationException::withMessages(['recipe_id' => 'Recipe tidak aktif.']);
        }

        $multiplier = $validated['multiplier'];

        try {
            $batch = DB::transaction(function () use ($recipe, $store, $multiplier) {
                // Kunci produk bahan berurutan
                $ingredientProductIds = $recipe->ingredients->pluck('product_id')->sort()->values();
                if ($ingredientProductIds->isNotEmpty()) {
                    Product::query()->whereIn('id', $ingredientProductIds)->orderBy('id')->lockForUpdate()->get();
                }

                // Validasi stok bahan
                foreach ($recipe->ingredients as $ing) {
                    $product = $ing->product;
                    $product->refresh();
                    $requiredQty = bcmul((string) $ing->quantity, (string) $multiplier, 4);
                    if (ProductQuantity::lt((string) $product->stock_quantity, $requiredQty)) {
                        throw ValidationException::withMessages([
                            'multiplier' => "Stok bahan \"{$product->name}\" tidak mencukupi. Diperlukan {$requiredQty} {$ing->base_unit}, tersedia {$product->stock_quantity}.",
                        ]);
                    }
                }

                // Kunci produk output
                Product::query()->whereKey($recipe->product_id)->lockForUpdate()->first();

                // Buat batch
                $outputQty = bcmul((string) $recipe->output_quantity, (string) $multiplier, 4);

                $batch = ProductionBatch::create([
                    'store_id' => $store->id,
                    'recipe_id' => $recipe->id,
                    'product_id' => $recipe->product_id,
                    'status' => ProductionBatch::STATUS_DRAFT,
                    'multiplier' => $multiplier,
                    'output_quantity_initial' => $outputQty,
                    'output_quantity_remaining' => $outputQty,
                    'total_cost' => 0,
                    'unit_cost' => 0,
                    // Snapshot recipe
                    'recipe_name' => $recipe->name,
                    'recipe_code' => $recipe->code,
                    'recipe_version' => $recipe->version,
                    'recipe_output_quantity' => $recipe->output_quantity,
                    'recipe_output_unit' => $recipe->output_unit,
                ]);

                // Buat baris bahan
                $order = 0;
                foreach ($recipe->ingredients as $ing) {
                    $product = $ing->product->fresh();
                    $requiredQty = bcmul((string) $ing->quantity, (string) $multiplier, 4);
                    $avgCost = InventoryCosting::currentAverageCost($product);
                    $lineCost = InventoryCosting::extendedCost($requiredQty, $avgCost, 2);

                    ProductionBatchLine::create([
                        'production_batch_id' => $batch->id,
                        'product_id' => $product->id,
                        'quantity_required' => $requiredQty,
                        'quantity_consumed' => 0,
                        'base_unit' => $ing->base_unit,
                        'unit_cost_snapshot' => $avgCost,
                        'total_cost' => $lineCost,
                    ]);

                    $order++;
                }

                return $batch;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('productions.show', $batch)
            ->with('success', 'Batch produksi draft berhasil dibuat. Klik "Selesaikan" untuk memproses stok.');
    }

    public function show(Request $request, ProductionBatch $productionBatch): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store || (int) $productionBatch->store_id !== (int) $store->id) {
            abort(403);
        }

        $productionBatch->load([
            'recipe:id,name,code',
            'product:id,name,sku,base_unit,average_cost',
            'lines.product:id,name,sku,base_unit,average_cost',
            'producedBy:id,name',
            'cancelledBy:id,name',
        ]);

        $totalLinesCost = $productionBatch->lines->sum('total_cost');

        // Strip trailing zeros dari decimal fields agar tampil bersih di frontend
        $strip = fn ($v) => is_string($v) ? rtrim(rtrim($v, '0'), '.') : $v;
        $batchData = [
            'id' => $productionBatch->id,
            'batch_number' => $productionBatch->batch_number,
            'status' => $productionBatch->status,
            'multiplier' => $strip($productionBatch->multiplier),
            'output_quantity_initial' => $strip($productionBatch->output_quantity_initial),
            'output_quantity_remaining' => $strip($productionBatch->output_quantity_remaining),
            'total_cost' => $strip($productionBatch->total_cost),
            'unit_cost' => $strip($productionBatch->unit_cost),
            'produced_at' => $productionBatch->produced_at?->toIso8601String(),
            'cancelled_at' => $productionBatch->cancelled_at?->toIso8601String(),
            'cancel_reason' => $productionBatch->cancel_reason,
            'recipe_name' => $productionBatch->recipe_name,
            'recipe_code' => $productionBatch->recipe_code,
            'recipe_version' => $productionBatch->recipe_version,
            'recipe_output_quantity' => $strip($productionBatch->recipe_output_quantity),
            'recipe_output_unit' => $productionBatch->recipe_output_unit,
            'product' => $productionBatch->product,
            'produced_by' => $productionBatch->producedBy,
            'cancelled_by' => $productionBatch->cancelledBy,
            'lines' => $productionBatch->lines->map(fn ($ln) => [
                'id' => $ln->id,
                'quantity_required' => $strip($ln->quantity_required),
                'quantity_consumed' => $strip($ln->quantity_consumed),
                'base_unit' => $ln->base_unit,
                'unit_cost_snapshot' => $strip($ln->unit_cost_snapshot),
                'total_cost' => $strip($ln->total_cost),
                'product' => $ln->product,
            ]),
            'created_at' => $productionBatch->created_at->toIso8601String(),
        ];

        return Inertia::render('Productions/Show', [
            'batch' => $batchData,
            'totalLinesCost' => $totalLinesCost,
            'canComplete' => $productionBatch->isDraft()
                && $request->user()->isMemberOfStore($productionBatch->store_id)
                && ($request->user()->isAdmin() || $request->user()->isManager() || $request->user()->isCashier()),
            'canCancel' => $productionBatch->isDraft()
                && $request->user()->isMemberOfStore($productionBatch->store_id)
                && ($request->user()->isAdmin() || $request->user()->isManager() || $request->user()->isCashier()),
        ]);
    }

    /**
     * Selesaikan batch — kurangi bahan + tambah output + hitung HPP.
     */
    public function complete(Request $request, ProductionBatch $productionBatch): RedirectResponse
    {
        $store = $request->user()->store;
        $user = $request->user();
        if (! $store || (int) $productionBatch->store_id !== (int) $store->id) {
            abort(403);
        }

        if (! $user->isAdmin() && ! $user->isManager() && ! $user->isCashier()) {
            abort(403, 'Hanya admin, manager, atau kasir yang dapat menyelesaikan produksi.');
        }

        try {
            DB::transaction(function () use ($user, $productionBatch) {
                $productionBatch->lockForUpdate();
                $productionBatch->assertCanTransitionTo(ProductionBatch::STATUS_COMPLETED);

                $lines = $productionBatch->lines()->with('product')->get();
                $totalCost = '0';

                // Kunci seluruh produk bahan
                $lineProductIds = $lines->pluck('product_id')->sort()->values();
                Product::query()->whereIn('id', $lineProductIds)->orderBy('id')->lockForUpdate()->get();

                // Kurangi stok bahan
                foreach ($lines as $line) {
                    $product = $line->product;
                    $product->refresh();
                    $qty = (string) $line->quantity_required;

                    if (ProductQuantity::lt((string) $product->stock_quantity, $qty)) {
                        throw ValidationException::withMessages([
                            'multiplier' => "Stok bahan \"{$product->name}\" tidak mencukupi saat produksi (diperlukan {$qty}).",
                        ]);
                    }

                    // Kurangi stok
                    if ($product->track_stock) {
                        $newStock = bcsub((string) $product->stock_quantity, $qty, 4);
                        $product->update(['stock_quantity' => $newStock]);
                    }

                    // Batch tracking
                    if ($product->batch_tracking) {
                        \App\Support\BatchTracking::consumeFifoBatch($product, $qty, $user);
                    }

                    // Stock movement
                    StockMovement::create([
                        'store_id' => $productionBatch->store_id,
                        'product_id' => $product->id,
                        'user_id' => $user->id,
                        'quantity_change' => '-'.$qty,
                        'type' => StockMovement::TYPE_PRODUCTION_CONSUMPTION,
                        'reference_type' => ProductionBatchLine::class,
                        'reference_id' => $line->id,
                        'balance_after' => $newStock ?? $product->stock_quantity,
                        'notes' => "Batch #{$productionBatch->batch_number}",
                    ]);

                    $line->update(['quantity_consumed' => $qty]);
                    $totalCost = bcadd($totalCost, (string) $line->total_cost, 2);
                }

                // Tambah stok output
                $outputProduct = Product::lockForUpdate()->find($productionBatch->product_id);
                $outputQty = (string) $productionBatch->output_quantity_initial;
                $newOutputStock = bcadd((string) $outputProduct->stock_quantity, $outputQty, 4);
                $outputProduct->update(['stock_quantity' => $newOutputStock]);

                // Batch tracking output
                if ($outputProduct->batch_tracking) {
                    $batchCode = 'PRD-'.$productionBatch->batch_number;
                    \App\Support\BatchTracking::findOrCreateActiveBatch($productionBatch->store_id, $outputProduct->id, $batchCode, $user)
                        ->increment('quantity_remaining', $outputQty);
                }

                // Weighted average cost output
                $oldAvgCost = InventoryCosting::currentAverageCost($outputProduct);
                $newAvgCost = InventoryCosting::weightedAverage(
                    bcsub((string) $outputProduct->stock_quantity, $outputQty, 4),
                    $oldAvgCost,
                    $outputQty,
                    InventoryCosting::unitCostFromTotal($totalCost, $outputQty),
                );
                $unitCost = InventoryCosting::unitCostFromTotal($totalCost, $outputQty);
                $outputProduct->update([
                    'average_cost' => $newAvgCost,
                    'cost_price' => round((float) $newAvgCost, 2),
                ]);

                // Stock movement output
                StockMovement::create([
                    'store_id' => $productionBatch->store_id,
                    'product_id' => $outputProduct->id,
                    'user_id' => $user->id,
                    'quantity_change' => $outputQty,
                    'type' => StockMovement::TYPE_PRODUCTION_OUTPUT,
                    'reference_type' => ProductionBatch::class,
                    'reference_id' => $productionBatch->id,
                    'balance_after' => $newOutputStock,
                    'notes' => "Batch #{$productionBatch->batch_number}",
                ]);

                // Update batch
                $productionBatch->update([
                    'status' => ProductionBatch::STATUS_COMPLETED,
                    'total_cost' => $totalCost,
                    'unit_cost' => $unitCost,
                    'produced_by' => $user->id,
                    'produced_at' => now(),
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('productions.show', $productionBatch)
            ->with('success', 'Produksi selesai. Stok bahan dikurangi dan output ditambahkan.');
    }

    /**
     * Batalkan batch draft — tanpa mutasi stok.
     */
    public function cancel(Request $request, ProductionBatch $productionBatch): RedirectResponse
    {
        $store = $request->user()->store;
        $user = $request->user();
        if (! $store || (int) $productionBatch->store_id !== (int) $store->id) {
            abort(403);
        }

        if (! $user->isAdmin() && ! $user->isManager() && ! $user->isCashier()) {
            abort(403, 'Hanya admin, manager, atau kasir yang dapat membatalkan produksi.');
        }

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $productionBatch->lockForUpdate();
        $productionBatch->assertCanTransitionTo(ProductionBatch::STATUS_CANCELLED);

        $productionBatch->update([
            'status' => ProductionBatch::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        return redirect()->route('productions.show', $productionBatch)
            ->with('success', 'Batch produksi dibatalkan.');
    }
}
