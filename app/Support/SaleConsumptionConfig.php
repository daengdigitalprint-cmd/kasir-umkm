<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSaleConsumption;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi konsumsi produk jual (Tahap 7).
 *
 * Produk jual dapat mengonsumsi hasil produksi (mis. Biang Teh 200 ml)
 * dan kemasan (mis. cup, sedotan) setiap satu unit terjual.
 */
class SaleConsumptionConfig
{
    /** Komponen yang boleh dikonsumsi saat penjualan. */
    public const ALLOWED_COMPONENT_TYPES = [
        InventoryType::RAW_MATERIAL,
        InventoryType::PRODUCTION_OUTPUT,
        InventoryType::PACKAGING,
    ];

    /**
     * Produk yang dapat dipilih sebagai komponen konsumsi di toko tertentu.
     *
     * @return Collection<int, Product>
     */
    public static function componentCatalogForStore(Store $store): Collection
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereIn('inventory_type', self::ALLOWED_COMPONENT_TYPES)
            ->where('has_variants', false)
            ->where('is_bundle', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'inventory_type', 'base_unit', 'stock_quantity', 'average_cost']);
    }

    /** Konfigurasi konsumsi hanya berlaku untuk produk jual non-paket. */
    public static function supportsConfiguration(Product $product): bool
    {
        return $product->isSellable() && ! $product->is_bundle;
    }

    /**
     * Normalisasi satu baris konfigurasi menjadi kuantitas satuan dasar komponen.
     *
     * @param  array{component_product_id?: mixed, quantity?: mixed, input_unit?: mixed}  $row
     * @return array{component: Product, quantity: string, base_unit: string}
     *
     * @throws ValidationException
     */
    public static function normalizeRow(Store $store, array $row, string $errorKey): array
    {
        $componentId = isset($row['component_product_id']) ? (int) $row['component_product_id'] : 0;
        if ($componentId <= 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Pilih komponen yang dikonsumsi saat penjualan.',
            ]);
        }

        $component = Product::query()
            ->where('store_id', $store->id)
            ->whereKey($componentId)
            ->first();

        // Isolasi wajib per toko: penjualan tidak boleh mengurangi stok outlet lain.
        if (! $component) {
            throw ValidationException::withMessages([
                $errorKey => 'Komponen tidak ditemukan pada toko ini.',
            ]);
        }
        if (! $component->is_active) {
            throw ValidationException::withMessages([
                $errorKey => "Komponen \"{$component->name}\" tidak aktif.",
            ]);
        }
        if (! in_array($component->inventoryType(), self::ALLOWED_COMPONENT_TYPES, true)) {
            throw ValidationException::withMessages([
                $errorKey => "Komponen \"{$component->name}\" harus bertipe bahan baku, hasil produksi, atau kemasan.",
            ]);
        }
        if ($component->has_variants || $component->is_bundle) {
            throw ValidationException::withMessages([
                $errorKey => "Komponen \"{$component->name}\" tidak boleh berupa produk varian atau paket.",
            ]);
        }

        $rawQuantity = $row['quantity'] ?? null;
        if (! is_numeric($rawQuantity) || (float) $rawQuantity <= 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Jumlah konsumsi harus lebih besar dari 0.',
            ]);
        }

        $componentBaseUnit = $component->baseUnit();
        $inputUnit = is_string($row['input_unit'] ?? null) && $row['input_unit'] !== ''
            ? $row['input_unit']
            : $componentBaseUnit;

        // Dimensi dibandingkan terhadap satuan komponen, bukan satuan produk jual.
        if (! BaseUnit::isConvertible($inputUnit, $componentBaseUnit)) {
            throw ValidationException::withMessages([
                $errorKey => "Satuan \"{$inputUnit}\" tidak dapat dikonversi ke satuan dasar komponen \"{$componentBaseUnit}\".",
            ]);
        }

        return [
            'component' => $component,
            'quantity' => BaseUnit::toBaseQuantity($rawQuantity, $inputUnit, $componentBaseUnit),
            'base_unit' => $componentBaseUnit,
        ];
    }

    /**
     * Ganti seluruh konfigurasi konsumsi produk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws ValidationException
     */
    public static function replaceForProduct(Product $product, Store $store, array $rows): void
    {
        ProductSaleConsumption::where('product_id', $product->id)->delete();

        if ($rows === []) {
            return;
        }

        if (! self::supportsConfiguration($product)) {
            throw ValidationException::withMessages([
                'sale_consumptions_json' => 'Konsumsi penjualan hanya dapat diatur untuk produk jual non-paket.',
            ]);
        }

        $variantsById = $product->has_variants
            ? $product->variants()->get()->keyBy('id')
            : collect();

        $seen = [];
        $order = 0;

        foreach ($rows as $index => $row) {
            $errorKey = "sale_consumptions.{$index}";
            $normalized = self::normalizeRow($store, is_array($row) ? $row : [], $errorKey);

            $variantId = self::resolveVariantId($product, $variantsById, $row, $errorKey);

            // Komponen tidak boleh sama dengan produk jual itu sendiri.
            if ((int) $normalized['component']->id === (int) $product->id) {
                throw ValidationException::withMessages([
                    $errorKey => 'Produk tidak dapat mengonsumsi dirinya sendiri.',
                ]);
            }

            $dedupeKey = ($variantId ?? 0).':'.$normalized['component']->id;
            if (isset($seen[$dedupeKey])) {
                throw ValidationException::withMessages([
                    $errorKey => "Komponen \"{$normalized['component']->name}\" sudah dipakai pada baris lain. Gabungkan jumlahnya.",
                ]);
            }
            $seen[$dedupeKey] = true;

            ProductSaleConsumption::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'component_product_id' => $normalized['component']->id,
                'quantity' => $normalized['quantity'],
                'base_unit' => $normalized['base_unit'],
                'sort_order' => $order++,
            ]);
        }
    }

    /**
     * Produk bervarian wajib menyebut varian agar 200 ml dan 250 ml tidak ambigu.
     *
     * @param  Collection<int, ProductVariant>  $variantsById
     *
     * @throws ValidationException
     */
    private static function resolveVariantId(
        Product $product,
        Collection $variantsById,
        mixed $row,
        string $errorKey,
    ): ?int {
        $rawVariantId = is_array($row) ? ($row['product_variant_id'] ?? null) : null;
        $variantId = is_numeric($rawVariantId) ? (int) $rawVariantId : null;

        if (! $product->has_variants) {
            if ($variantId !== null) {
                throw ValidationException::withMessages([
                    $errorKey => 'Produk tanpa varian tidak boleh menetapkan varian pada konsumsi.',
                ]);
            }

            return null;
        }

        if ($variantId === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Produk dengan varian wajib menetapkan varian pada setiap baris konsumsi.',
            ]);
        }

        $variant = $variantsById->get($variantId);
        if (! $variant || (int) $variant->store_id !== (int) $product->store_id) {
            throw ValidationException::withMessages([
                $errorKey => 'Varian tidak valid untuk produk ini.',
            ]);
        }

        return $variantId;
    }

    /**
     * Estimasi HPP per unit jual berdasarkan biaya komponen terkini.
     *
     * Nilai ini hanya untuk tampilan form. HPP transaksi dihitung dari batch aktual.
     */
    public static function estimatedUnitCost(Product $product, ?int $variantId = null): string
    {
        $query = ProductSaleConsumption::query()
            ->with('component')
            ->where('product_id', $product->id);

        $query->when(
            $variantId !== null,
            fn ($q) => $q->where('product_variant_id', $variantId),
            fn ($q) => $q->whereNull('product_variant_id'),
        );

        $total = '0.00';
        foreach ($query->get() as $consumption) {
            $total = bcadd($total, $consumption->estimatedUnitCost(), 2);
        }

        return $total;
    }

    /** Produk yang mengonsumsi hasil produksi tidak boleh memakai HPP manual. */
    public static function usesProductionOutput(Product $product): bool
    {
        return ProductSaleConsumption::query()
            ->where('product_id', $product->id)
            ->whereHas('component', fn ($q) => $q->where('inventory_type', InventoryType::PRODUCTION_OUTPUT))
            ->exists();
    }

    /** Satuan dasar komponen tidak boleh berubah selama masih direferensikan. */
    public static function isReferencedAsComponent(Product $product): bool
    {
        return ProductSaleConsumption::where('component_product_id', $product->id)->exists();
    }
}
