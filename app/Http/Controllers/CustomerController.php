<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\LoyaltySettings;
use App\Support\StorePlanLimits;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $customers = $store->customers()
            ->withCount('transactions')
            ->latest()
            ->paginate(20);

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        return Inertia::render('Customers/Create', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'customer_type' => 'nullable|in:ecer,grosir',
        ]);

        StorePlanLimits::assertCanCreateCustomer($store);

        $customer = $store->customers()->create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'customer_type' => ($store->settings['wholesale_enabled'] ?? false) && $request->customer_type === 'grosir' ? 'grosir' : 'ecer',
        ]);

        return redirect()->route('customers.index')
            ->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $customer = $store->customers()->findOrFail($id);

        // Get customer's transaction history
        $transactions = $customer->transactions()
            ->with('items.product')
            ->latest()
            ->paginate(10);

        // Riwayat transaksi poin loyalitas.
        $pointHistory = $customer->pointLedgers()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'type' => $l->type,
                'points' => $l->points,
                'balance_after' => $l->balance_after,
                'description' => $l->description,
                'expires_at' => $l->expires_at?->toIso8601String(),
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        $loyaltyEnabled = StorePlanLimits::allowsAdvancedRetailFeatures($store)
            && LoyaltySettings::enabled($store);
        $availableRewards = $loyaltyEnabled
            ? $store->loyaltyRewards()->where('is_active', true)->orderBy('points_cost')->get()
            : collect();

        // Piutang pelanggan ini
        $receivables = $customer->transactions()
            ->where('status', 'completed')
            ->whereIn('payment_status', ['partial', 'unpaid'])
            ->orderBy('debt_due_at', 'asc')
            ->get()
            ->map(fn ($t) => [
                'id'                 => $t->id,
                'transaction_number' => $t->transaction_number,
                'total_amount'       => (float) $t->total_amount,
                'amount_paid'        => (float) $t->amount_paid,
                'outstanding'        => round((float) $t->total_amount - (float) $t->amount_paid, 2),
                'payment_status'     => $t->payment_status,
                'debt_due_at'        => $t->debt_due_at?->toDateString(),
                'created_at'         => $t->created_at->toDateTimeString(),
                'is_overdue'         => $t->debt_due_at && $t->debt_due_at->isPast(),
            ]);

        $totalOutstanding = $receivables->sum('outstanding');

        return Inertia::render('Customers/Show', [
            'customer'     => $customer,
            'transactions' => $transactions,
            'loyalty'      => [
                'enabled'              => $loyaltyEnabled,
                'points_balance'       => (int) $customer->points_balance,
                'points_earned_total'  => (int) $customer->points_earned_total,
                'points_redeemed_total'=> (int) $customer->points_redeemed_total,
                'history'              => $pointHistory,
                'rewards'              => $availableRewards,
            ],
            'receivables'     => $receivables->values(),
            'totalOutstanding'=> round($totalOutstanding, 2),
            'store'           => [
                'id'       => $store->id,
                'name'     => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $customer = $store->customers()->findOrFail($id);

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'wholesaleEnabled' => (bool) ($store->settings['wholesale_enabled'] ?? false),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $customer = $store->customers()->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'customer_type' => 'nullable|in:ecer,grosir',
        ]);

        $customer->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'customer_type' => ($store->settings['wholesale_enabled'] ?? false) && $request->customer_type === 'grosir' ? 'grosir' : 'ecer',
        ]);

        return redirect()->route('customers.index')
            ->with('success', 'Pelanggan berhasil diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $customer = $store->customers()->findOrFail($id);

        // Check if customer has transactions
        if ($customer->transactions()->count() > 0) {
            return redirect()->route('customers.index')
                ->with('error', 'Tidak dapat menghapus pelanggan yang memiliki riwayat transaksi.');
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', 'Pelanggan berhasil dihapus.');
    }

    /**
     * Pencarian pelanggan cepat untuk POS (JSON).
     */
    public function search(Request $request)
    {
        $user  = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['customers' => []]);
        }

        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }

        $customers = $store->customers()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        return response()->json(['customers' => $customers]);
    }

    /**
     * Pendaftaran pelanggan baru secara instan dari halaman POS (JSON).
     * Digunakan saat kasir menjumpai pelanggan yang belum terdaftar
     * pada proses penjualan kredit, tanpa meninggalkan halaman penjualan.
     */
    public function quickStore(Request $request)
    {
        $user  = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json(['message' => 'Toko tidak ditemukan.'], 422);
        }

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        StorePlanLimits::assertCanCreateCustomer($store);

        $customer = $store->customers()->create([
            'name'          => $data['name'],
            'phone'         => $data['phone'] ?? null,
            'customer_type' => 'ecer',
        ]);

        return response()->json([
            'customer' => [
                'id'    => $customer->id,
                'name'  => $customer->name,
                'phone' => $customer->phone,
            ],
        ], 201);
    }
}
