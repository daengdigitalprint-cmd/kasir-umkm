<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        $store = auth()->user()->store;

        $suppliers = $store->suppliers()
            ->withCount('purchases')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'phone' => $s->phone,
                'email' => $s->email,
                'address' => $s->address,
                'notes' => $s->notes,
                'purchases_count' => $s->purchases_count,
            ]);

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $store = auth()->user()->store;

        $supplier = $store->suppliers()->create($validated);

        return Redirect::back()->with('success', 'Supplier berhasil ditambahkan');
    }

    public function update(Request $request, Supplier $supplier)
    {
        // Pastikan supplier milik store user
        if ($supplier->store_id !== auth()->user()->store_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $supplier->update($validated);

        return Redirect::back()->with('success', 'Supplier berhasil diperbarui');
    }

    public function destroy(Supplier $supplier)
    {
        // Pastikan supplier milik store user
        if ($supplier->store_id !== auth()->user()->store_id) {
            abort(403);
        }

        // Cek apakah supplier masih memiliki pembelian
        if ($supplier->purchases()->count() > 0) {
            return Redirect::back()->with('error', 'Supplier tidak dapat dihapus karena masih memiliki riwayat pembelian');
        }

        $supplier->delete();

        return Redirect::back()->with('success', 'Supplier berhasil dihapus');
    }
}
