<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class TaxSettingsController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda belum memiliki toko. Silakan buat toko terlebih dahulu.');
        }

        return Inertia::render('settings/TaxSettings', [
            'store' => $store,
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda belum memiliki toko. Silakan buat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'tax_enabled' => 'required|boolean',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'tax_name' => 'required|string|max:50',
        ]);

        $store->update($validated);

        return redirect()->back()
            ->with('success', 'Pengaturan PPN berhasil diperbarui!');
    }
}
