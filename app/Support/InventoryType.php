<?php

namespace App\Support;

use App\Models\Product;

/**
 * Klasifikasi fungsi inventory produk (Tahap 2 modul gudang/produksi).
 *
 * Produk lama otomatis dianggap `sellable` agar alur retail existing tidak berubah.
 */
final class InventoryType
{
    /** Bahan mentah, contoh: daun teh, gula, air. */
    public const RAW_MATERIAL = 'raw_material';

    /** Kemasan, contoh: cup, tutup, sedotan. */
    public const PACKAGING = 'packaging';

    /** Hasil produksi recipe, contoh: Biang Teh. */
    public const PRODUCTION_OUTPUT = 'production_output';

    /** Produk yang dijual di POS, contoh: Es Teh 200 ml. */
    public const SELLABLE = 'sellable';

    /** @var array<string, string> */
    public const LABELS = [
        self::SELLABLE => 'Produk jual (POS)',
        self::RAW_MATERIAL => 'Bahan mentah',
        self::PACKAGING => 'Kemasan',
        self::PRODUCTION_OUTPUT => 'Hasil produksi',
    ];

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::LABELS);
    }

    public static function normalize(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return self::isValid($value) ? $value : self::SELLABLE;
    }

    public static function of(Product $product): string
    {
        return self::normalize($product->inventory_type);
    }

    /** Bahan yang boleh dipakai sebagai komposisi recipe. */
    public static function usableAsIngredient(Product $product): bool
    {
        return in_array(self::of($product), [self::RAW_MATERIAL, self::PRODUCTION_OUTPUT], true);
    }

    public static function isProductionOutput(Product $product): bool
    {
        return self::of($product) === self::PRODUCTION_OUTPUT;
    }

    public static function isSellable(Product $product): bool
    {
        return self::of($product) === self::SELLABLE;
    }
}
