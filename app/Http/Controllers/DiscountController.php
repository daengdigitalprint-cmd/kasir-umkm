<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Support\StorePlanLimits;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DiscountController extends Controller
{
    public function index()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        $tz = $store->timezone ?: 'UTC';

        return Inertia::render('Discounts/Index', [
            'discounts' => $store->discounts()->latest()->get()
                ->map(fn (Discount $d) => $this->forClient($d, $tz)),
            // Pilihan "Berlaku untuk": kategori (nama) dan produk jual aktif.
            'categories' => ProductCategory::query()
                ->where('store_id', $store->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name'),
            'products' => Product::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('inventory_type', 'sellable')->orWhereNull('inventory_type'))
                ->orderBy('name')
                ->get(['id', 'name', 'category']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = auth()->user()->store;
        if (! $store) {
            abort(404);
        }

        StorePlanLimits::assertAdvancedRetail(
            $store,
            'Paket Starter tidak mencakup modul diskon. Upgrade ke paket GrowUp di halaman langganan.',
        );

        $data = $this->validated($request, $store);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        $store->discounts()->create($data + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('discounts.index')->with('success', 'Promo ditambahkan.');
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $this->authorizeDiscount($discount);

        $store = auth()->user()->store;
        StorePlanLimits::assertAdvancedRetail(
            $store,
            'Paket Starter tidak mencakup modul diskon. Upgrade ke paket GrowUp di halaman langganan.',
        );

        $data = $this->validated($request, $store);
        if ($data instanceof RedirectResponse) {
            return $data;
        }

        $discount->update($data + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('discounts.index')->with('success', 'Promo diperbarui.');
    }

    public function destroy(Discount $discount): RedirectResponse
    {
        $this->authorizeDiscount($discount);
        $discount->delete();

        return redirect()->route('discounts.index')->with('success', 'Promo dihapus.');
    }

    /**
     * Validasi & normalisasi isian form promo (dipakai tambah dan ubah).
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function validated(Request $request, Store $store): array|RedirectResponse
    {
        $request->merge([
            'starts_at' => $request->input('starts_at') ?: null,
            'expires_at' => $request->input('expires_at') ?: null,
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:64',
            'type' => 'required|in:percentage,fixed_amount,buy_x_get_y',
            'value' => 'required|numeric|min:0',
            'buy_x' => 'nullable|integer|min:1|max:999',
            'get_y' => 'nullable|integer|min:1|max:999',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_uses' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            // Berlaku untuk: semua, kategori tertentu (nama), atau produk tertentu (id).
            'applies_to' => 'nullable|in:all,categories,products',
            'applicable_categories' => 'nullable|array',
            'applicable_categories.*' => ['string', Rule::exists('product_categories', 'name')->where('store_id', $store->id)],
            'applicable_products' => 'nullable|array',
            'applicable_products.*' => ['integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
        ], [
            'expires_at.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
            'applicable_categories.*.exists' => 'Kategori tidak ditemukan di toko ini.',
            'applicable_products.*.exists' => 'Produk tidak ditemukan di toko ini.',
        ]);

        if ($validated['type'] === 'percentage' && (float) $validated['value'] > 100) {
            return back()->withErrors(['value' => 'Diskon persentase maksimal 100%.'])->withInput();
        }

        $buyX = null;
        $getY = null;
        $value = (string) $validated['value'];
        if ($validated['type'] === 'buy_x_get_y') {
            $buyX = (int) ($validated['buy_x'] ?? 0);
            $getY = (int) ($validated['get_y'] ?? 0);
            if ($buyX < 1 || $getY < 1) {
                return back()->withErrors(['buy_x' => 'Isi minimal Beli 1 dan Gratis 1.'])->withInput();
            }
            $value = '0';
        } elseif ((float) $validated['value'] <= 0) {
            return back()->withErrors(['value' => 'Nilai harus lebih dari 0.'])->withInput();
        }

        $appliesTo = $validated['applies_to'] ?? 'all';
        $categories = $appliesTo === 'categories' ? array_values(array_unique($validated['applicable_categories'] ?? [])) : [];
        $products = $appliesTo === 'products' ? array_values(array_unique(array_map('intval', $validated['applicable_products'] ?? []))) : [];
        if ($appliesTo === 'categories' && $categories === []) {
            return back()->withErrors(['applicable_categories' => 'Pilih minimal satu kategori.'])->withInput();
        }
        if ($appliesTo === 'products' && $products === []) {
            return back()->withErrors(['applicable_products' => 'Pilih minimal satu produk.'])->withInput();
        }

        $tz = $store->timezone ?: 'UTC';
        $codeRaw = isset($validated['code']) ? trim((string) $validated['code']) : '';

        return [
            'name' => $validated['name'],
            'code' => $codeRaw !== '' ? strtoupper($codeRaw) : null,
            'type' => $validated['type'],
            'value' => $value,
            'buy_x' => $buyX,
            'get_y' => $getY,
            'minimum_amount' => $validated['minimum_amount'] ?? null,
            'maximum_uses' => $validated['maximum_uses'] ?? null,
            'starts_at' => $this->toUtc($validated['starts_at'] ?? null, $tz),
            'expires_at' => $this->toUtc($validated['expires_at'] ?? null, $tz),
            'description' => $validated['description'] ?? null,
            'applicable_categories' => $categories ?: null,
            'applicable_products' => $products ?: null,
        ];
    }

    private function authorizeDiscount(Discount $discount): void
    {
        if ((int) $discount->store_id !== (int) auth()->user()->store_id) {
            abort(403);
        }
    }

    /** Konversi input datetime-local (waktu toko) menjadi timestamp UTC untuk disimpan. */
    private function toUtc(?string $value, string $tz): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value, $tz)->utc()->format('Y-m-d H:i:s');
    }

    /** Tanggal dalam waktu toko untuk form, plus status yang sebenarnya berlaku di kasir. */
    private function forClient(Discount $d, string $tz): Discount
    {
        $d->starts_at_local = $d->starts_at?->setTimezone($tz)->format('Y-m-d\TH:i');
        $d->expires_at_local = $d->expires_at?->setTimezone($tz)->format('Y-m-d\TH:i');

        $now = now();
        $d->status = match (true) {
            ! $d->is_active => 'off',
            $d->starts_at !== null && $d->starts_at->gt($now) => 'scheduled',
            $d->expires_at !== null && $d->expires_at->lt($now) => 'ended',
            $d->maximum_uses !== null && (int) $d->used_count >= (int) $d->maximum_uses => 'quota',
            default => 'running',
        };

        return $d;
    }
}
