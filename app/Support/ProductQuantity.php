<?php

namespace App\Support;

use App\Models\Product;

/**
 * Normalisasi & perbandingan kuantitas stok/jual dengan presisi 4 desimal.
 * Membutuhkan ekstensi PHP bcmath.
 */
final class ProductQuantity
{
    public const SCALE = 4;

    public static function normalizeStorageAmount(Product $product, mixed $raw): string
    {
        $v = self::toFloat($raw);
        if ($v < 0) {
            $v = 0.0;
        }

        if (! $product->allows_decimal) {
            return (string) max(0, (int) round($v));
        }

        return self::fmt($v);
    }

    /**
     * Qty baris transaksi / pembelian (> 0).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeLineQuantity(Product $product, mixed $raw): string
    {
        $v = self::toFloat($raw);

        if (! $product->allows_decimal) {
            return (string) max(1, (int) round($v));
        }

        $v = round($v, self::SCALE);
        if ($v < 0.0001) {
            throw new \InvalidArgumentException('Jumlah harus lebih dari 0.');
        }

        return self::fmt($v);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function lt(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) < 0;
    }

    public static function lte(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) <= 0;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    /** Nilai minimum dari dua kuantitas (string ternormalisasi). */
    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $a : $b;
    }

    private static function fmt(float $v): string
    {
        return number_format(round($v, self::SCALE), self::SCALE, '.', '');
    }

    private static function toFloat(mixed $raw): float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        if (is_string($raw) && is_numeric($raw)) {
            return (float) $raw;
        }

        if ($raw instanceof \Stringable) {
            return is_numeric((string) $raw) ? (float) (string) $raw : 0.0;
        }

        return 0.0;
    }
}
