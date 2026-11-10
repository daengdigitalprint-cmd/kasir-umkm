<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSyncController extends Controller
{
    /**
     * Mengambil daftar produk untuk sinkronisasi lokal SQLite di perangkat mobile.
     * Mendukung filter incremental via param `updated_since`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $storeId = $user->store_id;

        $query = Product::query()
            ->with(['productCategory:id,name'])
            ->where('store_id', $storeId);

        if ($request->filled('updated_since')) {
            $query->where('updated_at', '>=', $request->input('updated_since'));
        }

        $products = $query->orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'count' => $products->count(),
            'data' => $products->map(function (Product $p) {
                return [
                    'id' => $p->id,
                    'store_id' => $p->store_id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'barcode' => $p->barcode,
                    'price' => (float) $p->price,
                    'cost_price' => (float) ($p->cost_price ?? 0),
                    'stock' => (int) $p->stock_quantity,
                    'min_stock' => (int) ($p->min_stock_level ?? 0),
                    'category' => $p->productCategory?->name ?? $p->category,
                    'unit' => $p->unit ?? 'pcs',
                    'is_active' => (bool) $p->is_active,
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ];
            }),
        ]);
    }
}
