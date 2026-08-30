<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Support\StorePlanLimits;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscountController extends Controller
{
    public function index()
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('stores.create');
        }

        return Inertia::render('Discounts/Index', [
            'discounts' => $store->discounts()->latest()->get()
                ->map(fn (Discount $d) => $this->datesForClient($d, $store->timezone ?: 'UTC')),
        ]);
    }

    public function store(Request $request)
    {
        $store = auth()->user()->store;
        if (! $store) {
            abort(404);
        }

        StorePlanLimits::assertAdvancedRetail(
            $store,
            'Paket Starter tidak mencakup modul diskon. Upgrade ke paket GrowUp di halaman langganan.',
        );

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

        $validated['starts_at'] = $this->toUtc($validated['starts_at'] ?? null, $store->timezone ?: 'UTC');
        $validated['expires_at'] = $this->toUtc($validated['expires_at'] ?? null, $store->timezone ?: 'UTC');

        $codeRaw = isset($validated['code']) ? trim((string) $validated['code']) : '';
        $code = $codeRaw !== '' ? strtoupper($codeRaw) : null;

        $store->discounts()->create([
            'name' => $validated['name'],
            'code' => $code,
            'type' => $validated['type'],
            'value' => $value,
            'buy_x' => $buyX,
            'get_y' => $getY,
            'minimum_amount' => $validated['minimum_amount'],
            'maximum_uses' => $validated['maximum_uses'],
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'description' => $validated['description'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('discounts.index')->with('success', 'Diskon ditambahkan.');
    }

    public function update(Request $request, Discount $discount)
    {
        $this->authorizeDiscount($discount);

        $store = auth()->user()->store;
        if ($store) {
            StorePlanLimits::assertAdvancedRetail(
                $store,
                'Paket Starter tidak mencakup modul diskon. Upgrade ke paket GrowUp di halaman langganan.',
            );
        }

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

        $validated['starts_at'] = $this->toUtc($validated['starts_at'] ?? null, $store->timezone ?: 'UTC');
        $validated['expires_at'] = $this->toUtc($validated['expires_at'] ?? null, $store->timezone ?: 'UTC');

        $codeRaw = isset($validated['code']) ? trim((string) $validated['code']) : '';
        $code = $codeRaw !== '' ? strtoupper($codeRaw) : null;

        $discount->update([
            'name' => $validated['name'],
            'code' => $code,
            'type' => $validated['type'],
            'value' => $value,
            'buy_x' => $buyX,
            'get_y' => $getY,
            'minimum_amount' => $validated['minimum_amount'],
            'maximum_uses' => $validated['maximum_uses'],
            'starts_at' => $validated['starts_at'],
            'expires_at' => $validated['expires_at'],
            'description' => $validated['description'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('discounts.index')->with('success', 'Diskon diperbarui.');
    }

    public function destroy(Discount $discount)
    {
        $this->authorizeDiscount($discount);
        $discount->delete();

        return redirect()->route('discounts.index')->with('success', 'Diskon dihapus.');
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

    /** Siapkan tanggal dalam waktu toko untuk ditampilkan di form (datetime-local). */
    private function datesForClient(Discount $d, string $tz): Discount
    {
        $d->starts_at_local = $d->starts_at?->setTimezone($tz)->format('Y-m-d\TH:i:s');
        $d->expires_at_local = $d->expires_at?->setTimezone($tz)->format('Y-m-d\TH:i:s');

        return $d;
    }
}
