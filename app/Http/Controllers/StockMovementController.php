<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Support\CostVisibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        return CostVisibility::render('StockMovements/Index', [
            'movements' => $movements,
            'products' => $products,
            'filters' => [
                'product_id' => $validated['product_id'] ?? null,
                'type' => $validated['type'] ?? null,
                'date_from' => isset($validated['date_from']) ? (string) $validated['date_from'] : null,
                'date_to' => isset($validated['date_to']) ? (string) $validated['date_to'] : null,
            ],
            'typeLabels' => StockMovement::typeLabels(),
        ]);
    }
}
