<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\UpsellRule;
use App\Support\InventoryType;
use App\Support\UpsellSuggestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F8 — Pengaturan Saran Tambahan Menu: saklar modul, saran otomatis,
 * dan pasangan manual "pesan A → tawarkan B".
 */
class UpsellRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->store;

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('inventory_type', InventoryType::SELLABLE)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);
        $names = $products->pluck('name', 'id');

        $rules = UpsellRule::query()
            ->where('store_id', $store->id)
            ->orderBy('trigger_product_id')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (UpsellRule $rule) => [
                'id' => $rule->id,
                'trigger_product_id' => $rule->trigger_product_id,
                'trigger_name' => $names[$rule->trigger_product_id] ?? 'Produk nonaktif',
                'suggested_product_id' => $rule->suggested_product_id,
                'suggested_name' => $names[$rule->suggested_product_id] ?? 'Produk nonaktif',
                'is_active' => $rule->is_active,
            ]);

        // Contoh pasangan yang sering dibeli bersama, untuk inspirasi aturan manual.
        $pairs = UpsellSuggestions::frequentPairs($store, 40)
            ->filter(fn ($p) => $names->has($p->trigger_id) && $names->has($p->suggested_id) && $p->trigger_id < $p->suggested_id)
            ->take(10)
            ->map(fn ($p) => [
                'trigger_product_id' => (int) $p->trigger_id,
                'trigger_name' => $names[$p->trigger_id],
                'suggested_product_id' => (int) $p->suggested_id,
                'suggested_name' => $names[$p->suggested_id],
                'together' => (int) $p->together,
            ])
            ->values();

        return Inertia::render('Upsell/Index', [
            'enabled' => UpsellSuggestions::enabled($store),
            'autoEnabled' => UpsellSuggestions::autoEnabled($store),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price])->values(),
            'rules' => $rules,
            'frequentPairs' => $pairs,
            'minTogether' => UpsellSuggestions::MIN_TOGETHER,
            'lookbackDays' => UpsellSuggestions::LOOKBACK_DAYS,
        ]);
    }

    /** Saklar modul & saran otomatis (disimpan di stores.settings). */
    public function settings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_enabled' => ['required', 'boolean'],
        ]);

        $store = $request->user()->store;
        $settings = $store->settings ?? [];
        $settings['upsell_enabled'] = (bool) $validated['enabled'];
        $settings['upsell_auto_enabled'] = (bool) $validated['auto_enabled'];
        $store->update(['settings' => $settings]);
        UpsellSuggestions::forget($store->id);

        return back()->with('success', $validated['enabled'] ? 'Saran menu aktif di kasir & Scan Meja.' : 'Saran menu dimatikan.');
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->store;
        $sellable = Rule::exists('products', 'id')->where('store_id', $store->id)->where('is_active', true);

        $validated = $request->validate([
            'trigger_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'suggested_product_ids' => ['required', 'array', 'min:1', 'max:'.UpsellSuggestions::MAX_PER_PRODUCT],
            'suggested_product_ids.*' => ['integer', 'distinct', $sellable->where('inventory_type', InventoryType::SELLABLE), 'different:trigger_product_id'],
        ], [
            'suggested_product_ids.required' => 'Pilih minimal satu menu yang ditawarkan.',
            'suggested_product_ids.max' => 'Maksimal '.UpsellSuggestions::MAX_PER_PRODUCT.' saran per menu.',
            'suggested_product_ids.*.different' => 'Menu tidak bisa menyarankan dirinya sendiri.',
            'suggested_product_ids.*.exists' => 'Menu yang ditawarkan harus menu jual yang aktif.',
        ]);

        $existing = UpsellRule::query()
            ->where('store_id', $store->id)
            ->where('trigger_product_id', $validated['trigger_product_id'])
            ->count();
        $new = collect($validated['suggested_product_ids'])
            ->reject(fn ($id) => UpsellRule::query()
                ->where('store_id', $store->id)
                ->where('trigger_product_id', $validated['trigger_product_id'])
                ->where('suggested_product_id', $id)
                ->exists());

        if ($existing + $new->count() > UpsellSuggestions::MAX_PER_PRODUCT) {
            return back()->withErrors([
                'suggested_product_ids' => 'Satu menu maksimal punya '.UpsellSuggestions::MAX_PER_PRODUCT.' saran. Hapus saran lama dulu.',
            ]);
        }

        foreach ($new->values() as $i => $suggestedId) {
            UpsellRule::create([
                'store_id' => $store->id,
                'trigger_product_id' => $validated['trigger_product_id'],
                'suggested_product_id' => $suggestedId,
                'sort' => $existing + $i,
            ]);
        }

        return back()->with('success', 'Saran menu disimpan.');
    }

    public function update(Request $request, UpsellRule $upsellRule): RedirectResponse
    {
        abort_unless((int) $upsellRule->store_id === (int) $request->user()->store?->id, 404);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $upsellRule->update($validated);

        return back();
    }

    public function destroy(Request $request, UpsellRule $upsellRule): RedirectResponse
    {
        abort_unless((int) $upsellRule->store_id === (int) $request->user()->store?->id, 404);

        $upsellRule->delete();

        return back()->with('success', 'Saran dihapus.');
    }
}
