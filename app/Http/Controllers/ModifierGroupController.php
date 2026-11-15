<?php

namespace App\Http\Controllers;

use App\Models\ModifierGroup;
use App\Models\Store;
use App\Support\ModifierOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Grup pilihan tambahan (gula, topping, level pedas) yang dibuat sekali per toko
 * lalu ditempel ke banyak produk lewat form produk.
 */
class ModifierGroupController extends Controller
{
    public function index(): InertiaResponse|RedirectResponse
    {
        $store = auth()->user()?->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        return Inertia::render('ProductOptionGroups/Index', [
            'groups' => self::groupsForStore($store, withCounts: true),
            'store' => ['id' => $store->id, 'name' => $store->name],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = auth()->user()?->store;
        if (! $store) {
            return response()->json(['message' => 'Anda perlu membuat toko terlebih dahulu.'], 400);
        }

        $data = $this->validated($request);

        $group = ModifierGroup::query()->create([
            'store_id' => $store->id,
            ...$data,
            'sort_order' => (int) ModifierGroup::query()->where('store_id', $store->id)->max('sort_order') + 1,
        ]);

        return response()->json(['group' => self::present($group)], 201);
    }

    public function update(Request $request, ModifierGroup $modifierGroup): JsonResponse
    {
        $this->assertOwned($modifierGroup);

        $modifierGroup->update($this->validated($request));

        return response()->json(['group' => self::present($modifierGroup->fresh())]);
    }

    public function destroy(ModifierGroup $modifierGroup): RedirectResponse
    {
        $this->assertOwned($modifierGroup);

        $modifierGroup->delete();

        return redirect()->route('products.option-groups.index')
            ->with('success', 'Grup pilihan dihapus.');
    }

    /**
     * Daftar grup toko untuk form produk & halaman kelola.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function groupsForStore(Store $store, bool $withCounts = false): array
    {
        return ModifierGroup::query()
            ->where('store_id', $store->id)
            ->when($withCounts, fn ($q) => $q->withCount('products'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ModifierGroup $g) => self::present($g))
            ->all();
    }

    /** @return array<string, mixed> */
    private static function present(ModifierGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'selection' => $group->selection,
            'is_required' => (bool) $group->is_required,
            'options' => array_values($group->options ?? []),
            'products_count' => $group->products_count ?? null,
        ];
    }

    /** @return array{name: string, selection: string, is_required: bool, options: array<int, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'selection' => ['required', Rule::in([ModifierGroup::SELECTION_SINGLE, ModifierGroup::SELECTION_MULTIPLE])],
            'is_required' => 'nullable|boolean',
            'options' => 'required|array|min:1|max:50',
            'options.*.key' => 'nullable|string|max:64',
            'options.*.label' => 'nullable|string|max:120',
            'options.*.price' => 'nullable|numeric|min:0|max:999999999',
        ], [
            'name.required' => 'Nama grup wajib diisi.',
            'options.required' => 'Isi minimal satu pilihan.',
            'options.min' => 'Isi minimal satu pilihan.',
        ]);

        $options = ModifierOptions::normalizeGroupOptions($validated['options']);
        if ($options === []) {
            abort(response()->json([
                'message' => 'Isi minimal satu pilihan.',
                'errors' => ['options' => ['Isi minimal satu pilihan.']],
            ], 422));
        }

        return [
            'name' => trim($validated['name']),
            'selection' => $validated['selection'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'options' => $options,
        ];
    }

    private function assertOwned(ModifierGroup $group): void
    {
        $store = auth()->user()?->store;
        abort_unless($store && (int) $group->store_id === (int) $store->id, 404);
    }
}
