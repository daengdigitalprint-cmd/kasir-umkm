<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BarcodeController extends Controller
{
    /**
     * Cari produk berdasarkan barcode (atau SKU sebagai fallback).
     *
     * GET /barcode/lookup?code={barcode}&context={purchase|pos}
     *
     * Response 200: { found: true, product: {...} }
     * Response 200: { found: false, barcode: '...' }
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|min:1|max:200',
            'context' => 'nullable|in:purchase,pos',
        ]);

        /** @var \App\Models\User|null $authUser */
        $authUser = auth()->user();
        $store = $authUser?->store;
        $code = trim($request->input('code'));
        $context = $request->input('context', 'pos');

        if (! $store) {
            return response()->json(['error' => 'Toko tidak ditemukan.'], 403);
        }

        // Cari di produk utama (barcode atau SKU) - case insensitive
        $product = $store->products()
            ->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->whereRaw('LOWER(barcode) = ?', [mb_strtolower($code)])
                    ->orWhereRaw('LOWER(sku) = ?', [mb_strtolower($code)]);
            })
            ->with([
                'variants' => fn ($q) => $q->where('is_active', true)
                    ->orderBy('sort_order')->orderBy('id'),
                'productCategory:id,name',
            ])
            ->select([
                'id', 'store_id', 'product_category_id', 'name', 'sku', 'barcode',
                'price', 'cost_price', 'stock_quantity', 'unit', 'category',
                'allows_decimal', 'has_variants', 'is_bundle', 'track_stock', 'is_active',
            ])
            ->first();

        // Jika tidak ketemu di produk, cari di varian - case insensitive
        $matchedVariant = null;
        if (! $product) {
            $variant = $store->products()
                ->where('is_active', true)
                ->whereHas('variants', function ($q) use ($code) {
                    $q->where(function ($q2) use ($code) {
                        $q2->whereRaw('LOWER(barcode) = ?', [mb_strtolower($code)])
                            ->orWhereRaw('LOWER(sku) = ?', [mb_strtolower($code)]);
                    });
                })
                ->with([
                    'variants' => fn ($q) => $q->where(function ($q2) use ($code) {
                        $q2->whereRaw('LOWER(barcode) = ?', [mb_strtolower($code)])
                            ->orWhereRaw('LOWER(sku) = ?', [mb_strtolower($code)]);
                    }),
                    'productCategory:id,name',
                ])
                ->select([
                    'id', 'store_id', 'product_category_id', 'name', 'sku', 'barcode',
                    'price', 'cost_price', 'stock_quantity', 'unit', 'category',
                    'allows_decimal', 'has_variants', 'is_bundle', 'track_stock', 'is_active',
                ])
                ->first();

            if ($variant) {
                $product = $variant;
                $matchedVariant = $variant->variants->first();
            }
        }

        // Log audit setiap percobaan pemindaian (dengan detail untuk debugging)
        Log::channel('stack')->info('barcode_scan', [
            'store_id' => $store->id,
            'user_id' => $authUser?->id,
            'barcode' => $code,
            'barcode_length' => strlen($code),
            'context' => $context,
            'found' => (bool) $product,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'matched_barcode' => $product?->barcode,
            'matched_sku' => $product?->sku,
            'matched_variant_id' => $matchedVariant?->id ?? null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (! $product) {
            return response()->json([
                'found' => false,
                'barcode' => $code,
            ]);
        }

        $payload = [
            'found' => true,
            'barcode' => $code,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => (float) $product->price,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'stock_quantity' => (float) $product->stock_quantity,
                'unit' => $product->unit,
                'category' => $product->category,
                'category_name' => $product->productCategory?->name,
                'product_category_id' => $product->product_category_id,
                'allows_decimal' => (bool) $product->allows_decimal,
                'has_variants' => (bool) $product->has_variants,
                'is_bundle' => (bool) $product->is_bundle,
                'track_stock' => (bool) $product->track_stock,
                'variants' => $product->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'price' => (float) ($v->price ?? $product->price),
                    'stock_quantity' => (float) $v->stock_quantity,
                ])->values(),
            ],
        ];

        if ($matchedVariant) {
            $payload['matched_variant_id'] = $matchedVariant->id;
        }

        return response()->json($payload);
    }

    /**
     * Catat log aktivitas pemindaian dari sisi klien.
     *
     * POST /barcode/scan-log
     */
    public function scanLog(Request $request): JsonResponse
    {
        $request->validate([
            'barcode' => 'required|string|max:200',
            'mode' => 'required|in:camera,keyboard',
            'context' => 'required|in:purchase,pos',
            'success' => 'required|boolean',
            'note' => 'nullable|string|max:500',
            'batch' => 'nullable|array|max:50',
        ]);

        /** @var \App\Models\User|null $authUser2 */
        $authUser2 = auth()->user();
        $store = $authUser2?->store;

        Log::channel('stack')->info('barcode_client_log', [
            'store_id' => $store?->id,
            'user_id' => $authUser2?->id,
            'barcode' => $request->input('barcode'),
            'mode' => $request->input('mode'),
            'context' => $request->input('context'),
            'success' => $request->boolean('success'),
            'note' => $request->input('note'),
            'batch' => $request->input('batch'),
            'ip' => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }
}
