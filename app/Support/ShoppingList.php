<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F2 — Daftar Belanja otomatis.
 *
 * Mengumpulkan produk/bahan (dan varian) yang stoknya sudah di bawah atau sama
 * dengan Stok Minimum, lengkap dengan saran jumlah beli. Stok Minimum 0 berarti
 * "tidak diatur" sehingga barang itu tidak pernah masuk daftar.
 */
final class ShoppingList
{
    /**
     * Saran jumlah beli: isi kembali sampai 2 × Stok Minimum.
     * Barang yang tidak boleh desimal dibulatkan ke atas; minimal 1.
     */
    public static function suggestedQuantity(float $stock, float $min, bool $allowsDecimal): float
    {
        $need = max(0.0, $min * 2 - $stock);

        if (! $allowsDecimal) {
            return max(1.0, ceil($need - 1e-9));
        }

        return max(round($need, 2), 0.01);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function items(Store $store): Collection
    {
        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('unlimited_stock', false)
            ->where('is_bundle', false)
            ->where('has_variants', false)
            ->where('min_stock_level', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'min_stock_level')
            ->get(['id', 'name', 'sku', 'unit', 'base_unit', 'stock_quantity', 'min_stock_level',
                'cost_price', 'average_cost', 'allows_decimal', 'inventory_type']);

        $variants = ProductVariant::query()
            ->where('product_variants.store_id', $store->id)
            ->where('product_variants.is_active', true)
            ->where('product_variants.min_stock_level', '>', 0)
            ->whereColumn('product_variants.stock_quantity', '<=', 'product_variants.min_stock_level')
            ->whereHas('product', fn ($q) => $q->where('is_active', true)
                ->where('track_stock', true)
                ->where('unlimited_stock', false)
                ->where('has_variants', true))
            ->with('product:id,name,unit,base_unit,allows_decimal,inventory_type,average_cost,cost_price')
            ->get(['product_variants.id', 'product_variants.product_id', 'product_variants.name', 'product_variants.sku',
                'product_variants.stock_quantity', 'product_variants.min_stock_level', 'product_variants.cost_price']);

        $lastSuppliers = self::lastSuppliers($store, $products->pluck('id')->merge($variants->pluck('product_id'))->unique()->all());

        $rows = collect();

        foreach ($products as $product) {
            $stock = (float) $product->stock_quantity;
            $min = (float) $product->min_stock_level;
            $rows->push(self::row(
                product: $product,
                variantId: null,
                name: $product->name,
                variantName: null,
                sku: $product->sku,
                stock: $stock,
                min: $min,
                unitCost: (float) ($product->average_cost ?: $product->cost_price),
                supplier: $lastSuppliers[$product->id] ?? null,
            ));
        }

        foreach ($variants as $variant) {
            $product = $variant->product;
            if (! $product) {
                continue;
            }
            $rows->push(self::row(
                product: $product,
                variantId: $variant->id,
                name: $product->name,
                variantName: $variant->name,
                sku: $variant->sku,
                stock: (float) $variant->stock_quantity,
                min: (float) $variant->min_stock_level,
                unitCost: (float) ($variant->cost_price ?: ($product->average_cost ?: $product->cost_price)),
                supplier: $lastSuppliers[$product->id] ?? null,
            ));
        }

        // Yang paling mendesak di atas: habis dulu, lalu persentase stok terhadap minimum.
        return $rows->sortBy([
            fn ($a, $b) => ($a['stock'] > 0) <=> ($b['stock'] > 0),
            fn ($a, $b) => ($a['stock'] / $a['min_stock']) <=> ($b['stock'] / $b['min_stock']),
            fn ($a, $b) => strcasecmp($a['name'], $b['name']),
        ])->values();
    }

    /** @return array<string, mixed> */
    private static function row(
        Product $product,
        ?int $variantId,
        string $name,
        ?string $variantName,
        ?string $sku,
        float $stock,
        float $min,
        float $unitCost,
        ?array $supplier,
    ): array {
        $allowsDecimal = (bool) $product->allows_decimal;
        $unit = $product->baseUnit();

        return [
            'key' => $product->id.'-'.($variantId ?? 0),
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'name' => $name,
            'variant_name' => $variantName,
            'sku' => $sku,
            'unit' => $unit,
            'inventory_type' => InventoryType::of($product),
            'allows_decimal' => $allowsDecimal,
            'stock' => $stock,
            'min_stock' => $min,
            'suggested_quantity' => self::suggestedQuantity($stock, $min, $allowsDecimal),
            'unit_cost' => round($unitCost, 2),
            'last_supplier_id' => $supplier['id'] ?? null,
            'last_supplier_name' => $supplier['name'] ?? null,
        ];
    }

    /**
     * Supplier dari pembelian terakhir tiap produk, supaya daftar bisa dipilah per supplier.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{id: int, name: string}>
     */
    private static function lastSuppliers(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.store_id', $store->id)
            ->whereIn('purchase_lines.product_id', $productIds)
            ->orderByDesc('purchases.purchased_at')
            ->orderByDesc('purchases.id')
            ->get(['purchase_lines.product_id', 'suppliers.id as supplier_id', 'suppliers.name as supplier_name']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->product_id] ??= ['id' => (int) $row->supplier_id, 'name' => $row->supplier_name];
        }

        return $result;
    }
}
