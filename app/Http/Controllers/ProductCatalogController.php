<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store tidak ditemukan',
            ], 404);
        }

        $updatedAfter = $request->query('updated_after');

        $query = $store->products()
            ->select([
                'id', 'store_id', 'name', 'sku', 'barcode', 'price', 'stock_quantity',
                'category', 'unit', 'is_active', 'images', 'modifier_options', 'allows_decimal',
                'has_variants', 'is_bundle', 'track_stock', 'unlimited_stock', 'pharmacy_sale_class', 'updated_at',
            ])
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'bundleItems' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with([
                    'componentProduct' => fn ($q2) => $q2->select(
                        'id', 'store_id', 'name', 'sku', 'stock_quantity', 'allows_decimal', 'track_stock', 'unlimited_stock', 'has_variants', 'is_active',
                    ),
                    'componentVariant' => fn ($q3) => $q3->select('id', 'product_id', 'name', 'stock_quantity'),
                ]),
            ]);

        if ($updatedAfter) {
            // updatedAfter is a Unix timestamp in milliseconds
            $carbonDate = Carbon::createFromTimestampMs((int) $updatedAfter);
            $query->where('updated_at', '>=', $carbonDate);
        }

        $products = $query->get()->map(function ($product) {
            return [
                'id' => $product->id,
                'store_id' => $product->store_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => (float) $product->price,
                'stock_quantity' => (float) $product->stock_quantity,
                'category' => $product->category,
                'unit' => $product->unit,
                'is_active' => (bool) $product->is_active,
                'images' => $product->images,
                'modifier_options' => $product->modifier_options,
                'allows_decimal' => (bool) $product->allows_decimal,
                'has_variants' => (bool) $product->has_variants,
                'is_bundle' => (bool) $product->is_bundle,
                'track_stock' => (bool) $product->track_stock,
                'unlimited_stock' => (bool) $product->unlimited_stock,
                'pharmacy_sale_class' => $product->pharmacy_sale_class,
                'image_url' => $product->image_url,
                'variants' => $product->variants,
                'bundle_items' => $product->bundleItems,
                'updated_at' => $product->updated_at->timestamp * 1000,
                // If inactive, mark deleted_at to tell client to remove it from cache
                'deleted_at' => ! $product->is_active ? $product->updated_at->timestamp * 1000 : null,
            ];
        });

        return response()->json([
            'products' => $products,
            'total' => $products->count(),
            'synced_at' => now()->timestamp * 1000,
        ]);
    }
}
