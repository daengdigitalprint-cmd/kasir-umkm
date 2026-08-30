<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Support\BaseUnit;
use App\Support\InventoryType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecipeController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        abort_unless(PharmacyFeatures::enabled($store), 404);
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $recipes = Recipe::query()
            ->where('store_id', $store->id)
            ->with(['product:id,name,sku,inventory_type,base_unit', 'ingredients.product:id,name,sku,inventory_type,base_unit'])
            ->withCount('ingredients')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Recipes/Index', [
            'recipes' => $recipes,
            'currentStore' => ['id' => $store->id, 'name' => $store->name],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $store = $request->user()->store;
        if (! $store) {
            return redirect()->route('stores.create')->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'inventory_type', 'base_unit']);

        // Bahan: raw_material dan production_output
        $ingredients = $products->filter(fn (Product $p) => InventoryType::usableAsIngredient($p))->values();
        // Output: production_output
        $outputs = $products->filter(fn (Product $p) => InventoryType::isProductionOutput($p))->values();

        return Inertia::render('Recipes/Create', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'ingredients' => $ingredients,
            'outputs' => $outputs,
            'units' => BaseUnit::LABELS,
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
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'output_quantity' => ['required', 'numeric', 'min:0.0001'],
            'output_unit' => ['nullable', 'string', 'max:10'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.base_unit' => ['nullable', 'string', 'max:10'],
            'ingredients.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validasi produk output
        $outputProduct = Product::whereKey($validated['product_id'])->firstOrFail();
        if ((int) $outputProduct->store_id !== (int) $store->id) {
            abort(403, 'Produk output bukan milik toko ini.');
        }
        if (! InventoryType::isProductionOutput($outputProduct)) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk output harus bertipe "Hasil produksi".',
            ]);
        }

        // Kode unik per toko
        if (! empty($validated['code'])) {
            $exists = Recipe::where('store_id', $store->id)
                ->where('code', $validated['code'])
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => "Kode \"{$validated['code']}\" sudah digunakan di toko ini.",
                ]);
            }
        }

        $transfer = DB::transaction(function () use ($store, $user, $validated) {
            $recipe = Recipe::create([
                'store_id' => $store->id,
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'product_id' => $validated['product_id'],
                'output_quantity' => $validated['output_quantity'],
                'output_unit' => $validated['output_unit'] ?? null,
                'is_active' => true,
                'created_by' => $user->id,
            ]);

            $order = 0;
            foreach ($validated['ingredients'] as $idx => $ing) {
                $product = Product::whereKey($ing['product_id'])->first();
                if (! $product || (int) $product->store_id !== (int) $store->id) {
                    throw ValidationException::withMessages([
                        "ingredients.{$idx}.product_id" => 'Produk bahan bukan milik toko ini.',
                    ]);
                }
                if (! InventoryType::usableAsIngredient($product)) {
                    throw ValidationException::withMessages([
                        "ingredients.{$idx}.product_id" => 'Produk tidak dapat menjadi bahan recipe.',
                    ]);
                }

                // Hapus validasi dimensi karena bahan bisa beda dimensi dari output
                // (misal: Output = Cairan(Volume), Bahan = Gula(Berat))
                $ingBaseUnit = $ing['base_unit'] ?: $product->baseUnit();

                $order = max($order, ($ing['sort_order'] ?? 0));

                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $product->id,
                    'quantity' => $ing['quantity'],
                    'base_unit' => $ingBaseUnit,
                    'sort_order' => $ing['sort_order'] ?? ($order),
                ]);

                $order++;
            }

            return $recipe;
        });

        return redirect()->route('recipes.show', $transfer)
            ->with('success', 'Recipe berhasil dibuat.');
    }

    public function show(Request $request, Recipe $recipe): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store || (int) $recipe->store_id !== (int) $store->id) {
            abort(403);
        }

        $recipe->load([
            'product:id,name,sku,inventory_type,base_unit,average_cost',
            'ingredients.product:id,name,sku,inventory_type,base_unit,average_cost',
            'createdBy:id,name',
            'updatedBy:id,name',
        ]);

        $totalCost = $recipe->totalIngredientCost();
        $unitCost = $recipe->unitCostPerOutput();

        return Inertia::render('Recipes/Show', [
            'recipe' => $recipe,
            'totalIngredientCost' => $totalCost,
            'unitCostPerOutput' => $unitCost,
        ]);
    }

    public function edit(Request $request, Recipe $recipe): Response|RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store || (int) $recipe->store_id !== (int) $store->id) {
            abort(403);
        }

        $recipe->load('ingredients.product');

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'inventory_type', 'base_unit']);

        $ingredients = $products->filter(fn (Product $p) => InventoryType::usableAsIngredient($p))->values();
        $outputs = $products->filter(fn (Product $p) => InventoryType::isProductionOutput($p))->values();

        return Inertia::render('Recipes/Edit', [
            'recipe' => $recipe,
            'ingredients' => $ingredients,
            'outputs' => $outputs,
            'units' => BaseUnit::LABELS,
        ]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store || (int) $recipe->store_id !== (int) $store->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'output_quantity' => ['required', 'numeric', 'min:0.0001'],
            'output_unit' => ['nullable', 'string', 'max:10'],
            'is_active' => ['required', 'boolean'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'ingredients.*.base_unit' => ['nullable', 'string', 'max:10'],
            'ingredients.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $outputProduct = Product::whereKey($validated['product_id'])->firstOrFail();
        if ((int) $outputProduct->store_id !== (int) $store->id) {
            abort(403, 'Produk output bukan milik toko ini.');
        }
        if (! InventoryType::isProductionOutput($outputProduct)) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk output harus bertipe "Hasil produksi".',
            ]);
        }

        if (! empty($validated['code'])) {
            $exists = Recipe::where('store_id', $store->id)
                ->where('code', $validated['code'])
                ->where('id', '!=', $recipe->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => "Kode \"{$validated['code']}\" sudah digunakan di toko ini.",
                ]);
            }
        }

        DB::transaction(function () use ($recipe, $store, $user, $validated) {
            $recipe->update([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'product_id' => $validated['product_id'],
                'output_quantity' => $validated['output_quantity'],
                'output_unit' => $validated['output_unit'] ?? null,
                'is_active' => $validated['is_active'],
                'version' => $recipe->version + 1,
                'updated_by' => $user->id,
            ]);

            $recipe->ingredients()->delete();

            $order = 0;
            foreach ($validated['ingredients'] as $idx => $ing) {
                $product = Product::whereKey($ing['product_id'])->first();
                if (! $product || (int) $product->store_id !== (int) $store->id) {
                    throw ValidationException::withMessages([
                        "ingredients.{$idx}.product_id" => 'Produk bahan bukan milik toko ini.',
                    ]);
                }
                if (! InventoryType::usableAsIngredient($product)) {
                    throw ValidationException::withMessages([
                        "ingredients.{$idx}.product_id" => 'Produk tidak dapat menjadi bahan recipe.',
                    ]);
                }

                $ingBaseUnit = $ing['base_unit'] ?: $product->baseUnit();

                $order = max($order, ($ing['sort_order'] ?? 0));

                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $product->id,
                    'quantity' => $ing['quantity'],
                    'base_unit' => $ingBaseUnit,
                    'sort_order' => $ing['sort_order'] ?? ($order),
                ]);

                $order++;
            }
        });

        return redirect()->route('recipes.show', $recipe)
            ->with('success', 'Recipe berhasil diperbarui. Versi baru: v'.$recipe->fresh()->version.'.');
    }

    public function destroy(Request $request, Recipe $recipe): RedirectResponse
    {
        $user = $request->user();
        $store = $user->store;
        if (! $store || (int) $recipe->store_id !== (int) $store->id) {
            abort(403);
        }

        $recipe->delete();

        return redirect()->route('recipes.index')
            ->with('success', 'Recipe berhasil dihapus.');
    }
}
