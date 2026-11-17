<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUnit;

/**
 * F3 — Satuan beli & isi.
 *
 * Mengonversi jumlah dan harga dari satuan yang dipilih di Pembelian ke satuan
 * dasar produk. Satuan beli produk (tabel product_units, mis. karton = 24 pcs)
 * dicek lebih dulu; selain itu jatuh ke konversi sedimensi BaseUnit (kg→gram).
 * Produk tanpa satuan beli berperilaku persis seperti sebelumnya.
 */
final class PurchaseUnitResolver
{
    /** Nama satuan standar yang tidak boleh dipakai sebagai nama satuan beli. */
    public static function reservedNames(): array
    {
        return ['kg', 'kilogram', 'gram', 'g', 'liter', 'l', 'ml', 'mililiter', 'pcs', 'buah'];
    }

    public static function normalizeName(mixed $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }

    /** Satuan beli produk yang cocok dengan nama satuan input, atau null. */
    public static function customUnit(Product $product, string $inputUnit): ?ProductUnit
    {
        $name = self::normalizeName($inputUnit);
        if ($name === '' || in_array($name, self::reservedNames(), true)) {
            return null;
        }

        return $product->units->first(fn (ProductUnit $u) => self::normalizeName($u->name) === $name);
    }

    /**
     * Pilihan satuan di form Pembelian: satuan sedimensi + satuan beli produk.
     *
     * @return list<string>
     */
    public static function inputUnitsFor(Product $product): array
    {
        $units = BaseUnit::inputUnitsFor($product->baseUnit());
        foreach ($product->units as $unit) {
            $units[] = $unit->name;
        }

        return array_values(array_unique($units));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function toBaseQuantity(Product $product, mixed $quantity, string $inputUnit): string
    {
        $custom = self::customUnit($product, $inputUnit);
        if (! $custom) {
            return BaseUnit::toBaseQuantity($quantity, $inputUnit, $product->baseUnit());
        }

        return bcmul(self::decimal($quantity), (string) $custom->factor, ProductQuantity::SCALE);
    }

    /**
     * Harga per satuan input → harga per satuan dasar (Rp240.000/karton isi 24 → Rp10.000/pcs).
     *
     * @throws \InvalidArgumentException
     */
    public static function toBaseUnitCost(Product $product, mixed $cost, string $inputUnit): string
    {
        $custom = self::customUnit($product, $inputUnit);
        if (! $custom) {
            return BaseUnit::toBaseUnitCost($cost, $inputUnit, $product->baseUnit());
        }

        if (bccomp((string) $custom->factor, '0', 6) <= 0) {
            throw new \InvalidArgumentException("Isi satuan «{$custom->name}» harus lebih dari 0.");
        }

        return bcdiv(self::decimal($cost), (string) $custom->factor, InventoryCosting::COST_SCALE);
    }

    private static function decimal(mixed $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }
        if (is_float($raw)) {
            return number_format($raw, InventoryCosting::COST_SCALE, '.', '');
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            return trim($raw);
        }

        throw new \InvalidArgumentException('Nilai numerik tidak valid.');
    }
}
