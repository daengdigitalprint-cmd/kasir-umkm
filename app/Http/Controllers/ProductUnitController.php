<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\PurchaseUnitResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F3 — Satuan beli & isi per produk (mis. 1 karton = 24 pcs).
 * Disimpan terpisah dari form produk supaya alur simpan produk tidak berubah.
 */
class ProductUnitController extends Controller
{
    use AuthorizesRequests;

    public function update(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'units' => 'present|array|max:10',
            'units.*.name' => 'required|string|max:20',
            'units.*.factor' => 'required|numeric|gt:0|max:1000000',
            'units.*.is_purchase_default' => 'boolean',
        ], [
            'units.*.name.required' => 'Nama satuan wajib diisi.',
            'units.*.name.max' => 'Nama satuan maksimal 20 karakter.',
            'units.*.factor.required' => 'Isi satuan wajib diisi.',
            'units.*.factor.gt' => 'Isi satuan harus lebih dari 0.',
        ]);

        $baseUnit = $product->baseUnit();
        $seen = [];
        $rows = [];
        foreach ($validated['units'] as $i => $unit) {
            $name = PurchaseUnitResolver::normalizeName($unit['name']);

            if (in_array($name, PurchaseUnitResolver::reservedNames(), true)) {
                throw ValidationException::withMessages([
                    "units.$i.name" => "«{$name}» sudah otomatis tersedia. Pakai nama lain, mis. karton, dus, pack.",
                ]);
            }
            if (isset($seen[$name])) {
                throw ValidationException::withMessages(["units.$i.name" => "Satuan «{$name}» tertulis dua kali."]);
            }
            $seen[$name] = true;

            $rows[] = [
                'name' => $name,
                'factor' => round((float) $unit['factor'], 6),
                'is_purchase_default' => (bool) ($unit['is_purchase_default'] ?? false),
            ];
        }

        // Hanya satu satuan yang jadi pilihan awal di form Pembelian.
        $defaultFound = false;
        foreach ($rows as &$row) {
            if ($row['is_purchase_default'] && $defaultFound) {
                $row['is_purchase_default'] = false;
            }
            $defaultFound = $defaultFound || $row['is_purchase_default'];
        }
        unset($row);

        DB::transaction(function () use ($product, $rows) {
            $product->units()->delete();
            foreach ($rows as $row) {
                $product->units()->create($row);
            }
        });

        return back()->with('success', $rows === []
            ? 'Satuan beli dihapus.'
            : 'Satuan beli disimpan. Satuan dasar stok tetap '.$baseUnit.'.');
    }
}
