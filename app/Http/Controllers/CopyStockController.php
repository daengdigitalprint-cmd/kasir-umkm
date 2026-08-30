<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CopyStockController extends Controller
{
    /** Outlet utama yang boleh menjalankan fitur ini. */
    private const ALLOWED_EMAIL = 'outletberkahf3@gmail.com';

    /** Nonaktifkan lacak stok untuk semua produk jual di seluruh grup usaha. */
    public function disableSellableStockTracking(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        $allStores = $store->organization
            ? $store->organization->stores()->where('is_active', true)->pluck('id')
            : [$store->id];

        $dryRun = $request->boolean('dry_run', false);

        $query = \App\Models\Product::query()
            ->whereIn('store_id', $allStores)
            ->where('inventory_type', 'sellable')
            ->where('track_stock', true);

        $count = $query->count();

        if (! $dryRun) {
            $query->update(['track_stock' => false]);
        }

        return response()->json([
            'mode' => $dryRun ? 'dry_run' : 'applied',
            'stores_affected' => count($allStores),
            'products_disabled' => $count,
        ]);
    }

    /** Debug: cek komponen konsumsi untuk produk tertentu di outlet utama vs cabang. */
    public function debugConsumptions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;
        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        $primaryStore = $store->organization
            ? $store->organization->stores()->where('is_primary', true)->first()
            : $store;

        if (! $primaryStore) {
            return response()->json(['error' => 'Outlet utama tidak ditemukan.'], 400);
        }

        $otherStores = $primaryStore->organization
            ? $primaryStore->organization->stores()->where('id', '!=', $primaryStore->id)->where('is_active', true)->get()
            : collect();

        $skuFilter = $request->input('sku');

        // Outlet utama
        $sourceQuery = $primaryStore->products()
            ->where('inventory_type', 'sellable')
            ->where('is_active', true);
        if ($skuFilter) {
            $sourceQuery->where('sku', $skuFilter);
        }

        $sourceProducts = $sourceQuery->with('saleConsumptions.component')->get();
        $sourceData = $sourceProducts->map(fn ($p) => [
            'sku' => $p->sku,
            'name' => $p->name,
            'consumptions_count' => $p->saleConsumptions->count(),
            'components' => $p->saleConsumptions->map(fn ($c) => [
                'component_sku' => $c->component?->sku,
                'component_name' => $c->component?->name,
                'quantity' => $c->quantity,
                'base_unit' => $c->base_unit,
            ]),
        ]);

        // Outlet cabang pertama
        $destSample = $otherStores->first();
        $destData = [];
        if ($destSample) {
            $destQuery = $destSample->products()
                ->where('inventory_type', 'sellable')
                ->where('is_active', true);
            if ($skuFilter) {
                $destQuery->where('sku', $skuFilter);
            }
            $destProducts = $destQuery->with('saleConsumptions.component')->get();
            $destData = [
                'store' => $destSample->name,
                'products' => $destProducts->map(fn ($p) => [
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'consumptions_count' => $p->saleConsumptions->count(),
                    'components' => $p->saleConsumptions->map(fn ($c) => [
                        'component_sku' => $c->component?->sku,
                        'component_name' => $c->component?->name,
                        'quantity' => $c->quantity,
                        'base_unit' => $c->base_unit,
                    ]),
                ]),
            ];
        }

        return response()->json([
            'source_store' => $primaryStore->name,
            'source_products' => $sourceData,
            'dest_sample' => $destData,
        ]);
    }

    /** Set stok produk jual = 0 di seluruh grup usaha (kecuali outlet utama). */
    public function resetSellableStock(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        $primaryStore = $store->organization
            ? $store->organization->stores()->where('is_primary', true)->first()
            : $store;

        if (! $primaryStore) {
            return response()->json(['error' => 'Outlet utama tidak ditemukan.'], 400);
        }

        $otherStores = $primaryStore->organization
            ? $primaryStore->organization->stores()
                ->where('id', '!=', $primaryStore->id)
                ->where('is_active', true)
                ->get()
            : collect();

        $dryRun = $request->boolean('dry_run', false);
        $totalReset = 0;
        $results = [];

        foreach ($otherStores as $destStore) {
            $query = $destStore->products()
                ->where('inventory_type', 'sellable')
                ->where('is_active', true)
                ->where('stock_quantity', '!=', 0);

            $count = $query->count();

            if (! $dryRun && $count > 0) {
                $query->update(['stock_quantity' => 0]);
            }

            $totalReset += $count;
            $results[] = [
                'store' => $destStore->name,
                'store_id' => $destStore->id,
                'products_reset' => $count,
            ];
        }

        return response()->json([
            'mode' => $dryRun ? 'dry_run' : 'applied',
            'source_store' => $primaryStore->name.' (tidak diubah)',
            'total_reset' => $totalReset,
            'destinations' => $results,
        ]);
    }

    /** Set stok bahan baku (raw_material + production_output) = 0 di outlet cabang. */
    public function resetRawMaterialStock(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        $primaryStore = $store->organization
            ? $store->organization->stores()->where('is_primary', true)->first()
            : $store;

        if (! $primaryStore) {
            return response()->json(['error' => 'Outlet utama tidak ditemukan.'], 400);
        }

        $otherStores = $primaryStore->organization
            ? $primaryStore->organization->stores()
                ->where('id', '!=', $primaryStore->id)
                ->where('is_active', true)
                ->get()
            : collect();

        $dryRun = $request->boolean('dry_run', false);
        $totalReset = 0;
        $results = [];

        foreach ($otherStores as $destStore) {
            $query = $destStore->products()
                ->whereIn('inventory_type', ['raw_material', 'production_output', 'packaging'])
                ->where('is_active', true)
                ->where('stock_quantity', '!=', 0);

            $count = $query->count();

            if (! $dryRun && $count > 0) {
                $query->update(['stock_quantity' => 0]);
            }

            $totalReset += $count;
            $results[] = [
                'store' => $destStore->name,
                'store_id' => $destStore->id,
                'products_reset' => $count,
            ];
        }

        return response()->json([
            'mode' => $dryRun ? 'dry_run' : 'applied',
            'source_store' => $primaryStore->name.' (tidak diubah)',
            'total_reset' => $totalReset,
            'destinations' => $results,
        ]);
    }

    /** Salin komponen konsumsi penjualan dari outlet utama ke outlet lain. */
    public function copySaleConsumptions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        $primaryStore = $store->organization
            ? $store->organization->stores()->where('is_primary', true)->first()
            : $store;

        if (! $primaryStore) {
            return response()->json(['error' => 'Outlet utama tidak ditemukan.'], 400);
        }

        $otherStores = $primaryStore->organization
            ? $primaryStore->organization->stores()
                ->where('id', '!=', $primaryStore->id)
                ->where('is_active', true)
                ->get()
            : collect();

        if ($otherStores->isEmpty()) {
            return response()->json(['message' => 'Tidak ada outlet lain dalam grup.']);
        }

        $dryRun = $request->boolean('dry_run', false);

        // Ambil produk jual outlet utama
        $sourceProducts = $primaryStore->products()
            ->where('inventory_type', 'sellable')
            ->where('is_active', true)
            ->get()
            ->keyBy(fn ($p) => $p->sku ?: $p->name);

        $results = [];

        foreach ($otherStores as $destStore) {
            $created = 0;

            // Load semua produk aktif di outlet tujuan (sellable + raw_material + production_output)
            // untuk bisa match komponen konsumsi yang merujuk ke bahan baku
            $destProducts = $destStore->products()
                ->where('is_active', true)
                ->get()
                ->keyBy(fn ($p) => $p->sku ?: $p->name);

            foreach ($sourceProducts as $skuKey => $srcProduct) {
                if (! isset($destProducts[$skuKey])) {
                    continue;
                }

                $destProduct = $destProducts[$skuKey];

                $srcConsumptions = \App\Models\ProductSaleConsumption::where('product_id', $srcProduct->id)
                    ->get();

                if ($srcConsumptions->isEmpty()) {
                    continue;
                }

                if (! $dryRun) {
                    // Hapus konsumsi lama
                    \App\Models\ProductSaleConsumption::where('product_id', $destProduct->id)->delete();

                    foreach ($srcConsumptions as $srcCons) {
                        // Cari component_product_id di outlet tujuan berdasarkan SKU
                        $srcComponent = $srcCons->component;
                        if (! $srcComponent) {
                            continue;
                        }

                        $componentKey = $srcComponent->sku ?: $srcComponent->name;
                        $destComponent = $destProducts[$componentKey]
                            ?? \App\Models\Product::where('store_id', $destStore->id)
                                ->where(fn ($q) => $q->where('sku', $srcComponent->sku)->orWhere('name', $srcComponent->name))
                                ->first();

                        if (! $destComponent) {
                            continue;
                        }

                        \App\Models\ProductSaleConsumption::create([
                            'store_id' => $destStore->id,
                            'product_id' => $destProduct->id,
                            'product_variant_id' => $srcCons->product_variant_id ? $this->mapVariant($srcCons, $destProduct, $srcProduct) : null,
                            'component_product_id' => $destComponent->id,
                            'quantity' => $srcCons->quantity,
                            'base_unit' => $srcCons->base_unit,
                            'sort_order' => $srcCons->sort_order,
                        ]);
                    }
                }

                $created++;
            }

            $results[] = [
                'store' => $destStore->name,
                'store_id' => $destStore->id,
                'products_copied' => $created,
            ];
        }

        return response()->json([
            'mode' => $dryRun ? 'dry_run' : 'applied',
            'source_store' => $primaryStore->name,
            'destinations' => $results,
        ]);
    }

    private function mapVariant(\App\Models\ProductSaleConsumption $srcCons, \App\Models\Product $destProduct, \App\Models\Product $srcProduct): ?int
    {
        if (! $srcCons->product_variant_id) {
            return null;
        }

        $srcVariant = $srcCons->variant;
        if (! $srcVariant) {
            return null;
        }

        // Cari varian yang sama SKU di produk tujuan
        $destVariant = $destProduct->variants()
            ->where('sku', $srcVariant->sku)
            ->first();

        return $destVariant?->id;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->email !== self::ALLOWED_EMAIL) {
            abort(403);
        }

        $store = $user->store;

        if (! $store) {
            return response()->json(['error' => 'Tidak ada toko aktif.'], 400);
        }

        // Outlet utama (is_primary) atau fallback: toko pertama user
        $primaryStore = $store->organization
            ? $store->organization->stores()->where('is_primary', true)->first()
            : $store;

        if (! $primaryStore) {
            return response()->json(['error' => 'Outlet utama tidak ditemukan.'], 400);
        }

        // Semua outlet lain dalam organisasi yang sama (selain outlet utama)
        $otherStores = $primaryStore->organization
            ? $primaryStore->organization->stores()
                ->where('id', '!=', $primaryStore->id)
                ->where('is_active', true)
                ->get()
            : collect();

        if ($otherStores->isEmpty()) {
            return response()->json(['message' => 'Tidak ada outlet lain dalam grup.']);
        }

        $sourceProducts = $primaryStore->products()
            ->where('track_stock', true)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn ($p) => $p->sku ?: $p->name);

        $results = [];
        $dryRun = $request->boolean('dry_run', false);

        foreach ($otherStores as $destStore) {
            $copied = 0;
            $matched = 0;

            $destProducts = $destStore->products()
                ->where('track_stock', true)
                ->where('is_active', true)
                ->get()
                ->keyBy(fn ($p) => $p->sku ?: $p->name);

            foreach ($sourceProducts as $key => $srcProduct) {
                if (! isset($destProducts[$key])) {
                    continue;
                }

                $destProduct = $destProducts[$key];
                $matched++;

                if ($destProduct->stock_quantity == $srcProduct->stock_quantity) {
                    continue;
                }

                $diff = round((float) $srcProduct->stock_quantity - (float) $destProduct->stock_quantity, 4);

                if (abs($diff) < 0.0001) {
                    continue;
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($destProduct, $diff, $srcProduct, $destStore, $user) {
                        $destProduct->update(['stock_quantity' => $srcProduct->stock_quantity]);

                        StockMovement::create([
                            'store_id' => $destStore->id,
                            'product_id' => $destProduct->id,
                            'user_id' => $user->id,
                            'quantity_change' => $diff,
                            'type' => 'adjustment',
                            'reference_type' => null,
                            'reference_id' => null,
                            'notes' => 'Copy stok dari outlet utama (stock override)',
                            'balance_after' => $srcProduct->stock_quantity,
                        ]);
                    });
                }

                $copied++;
            }

            $results[] = [
                'store' => $destStore->name,
                'store_id' => $destStore->id,
                'matched_products' => $matched,
                'stock_updated' => $copied,
            ];
        }

        return response()->json([
            'mode' => $dryRun ? 'dry_run' : 'applied',
            'source_store' => $primaryStore->name,
            'destinations' => $results,
        ]);
    }
}
