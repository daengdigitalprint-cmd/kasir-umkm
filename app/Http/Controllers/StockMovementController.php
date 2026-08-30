<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $store = $user->store;

        if (! $store) {
            return redirect()->route('stores.create')
                ->with('error', 'Anda perlu membuat toko terlebih dahulu.');
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'type' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = StockMovement::query()
            ->where('store_id', $store->id)
            ->with([
                'product:id,store_id,name,sku,unit',
                'user:id,name',
            ])
            ->orderByDesc('created_at');

        if (! empty($validated['product_id'])) {
            $query->where('product_id', (int) $validated['product_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $movements = $query->paginate(35)->withQueryString();

        $products = $store->products()
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        return Inertia::render('StockMovements/Index', [
            'movements' => $movements,
            'products' => $products,
            'filters' => [
                'product_id' => $validated['product_id'] ?? null,
                'type' => $validated['type'] ?? null,
                'date_from' => isset($validated['date_from']) ? (string) $validated['date_from'] : null,
                'date_to' => isset($validated['date_to']) ? (string) $validated['date_to'] : null,
            ],
            'typeLabels' => [
                StockMovement::TYPE_SALE => 'Penjualan',
                StockMovement::TYPE_ADJUSTMENT => 'Penyesuaian',
                StockMovement::TYPE_PURCHASE => 'Pembelian',
                StockMovement::TYPE_SUPPLIER_RETURN => 'Retur ke supplier',
                StockMovement::TYPE_RETURN => 'Retur',
                StockMovement::TYPE_DAMAGE => 'Rusak / hilang',
                StockMovement::TYPE_TRANSFER_OUT => 'Transfer keluar',
                StockMovement::TYPE_TRANSFER_IN => 'Transfer masuk',
                StockMovement::TYPE_TRANSFER_REVERSAL => 'Pembatalan transfer',
                StockMovement::TYPE_PRODUCTION_CONSUMPTION => 'Konsumsi produksi',
                StockMovement::TYPE_PRODUCTION_OUTPUT => 'Output produksi',
                StockMovement::TYPE_PRODUCTION_REVERSAL => 'Pembatalan produksi',
                StockMovement::TYPE_OPNAME => 'Stock opname',
                StockMovement::TYPE_ADJUSTMENT_REVERSAL => 'Pembatalan penyesuaian',
            ],
        ]);
    }
}
