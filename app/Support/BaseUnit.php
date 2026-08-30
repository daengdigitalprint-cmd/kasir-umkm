<?php

namespace App\Support;

/**
 * Satuan dasar dan konversi input (Tahap 2 modul gudang/produksi).
 *
 * Stok disimpan dalam satuan dasar terpilih: pcs, gram, kg, ml, atau liter.
 * Konversi hanya diperbolehkan dalam dimensi yang sama supaya 5 kg tidak pernah
 * menjadi 5.000 ml.
 */
final class BaseUnit
{
    public const GRAM = 'gram';

    public const MILLILITER = 'ml';

    public const PIECE = 'pcs';

    public const KILOGRAM = 'kg';

    public const LITER = 'liter';

    public const DIMENSION_WEIGHT = 'weight';

    public const DIMENSION_VOLUME = 'volume';

    public const DIMENSION_UNIT = 'unit';

    /** @var array<string, string> */
    public const LABELS = [
        self::PIECE => 'Pcs (satuan)',
        self::GRAM => 'Gram (berat)',
        self::KILOGRAM => 'Kg (berat)',
        self::MILLILITER => 'Mililiter (volume)',
        self::LITER => 'Liter (volume)',
    ];

    /** @var array<string, string> */
    private const BASE_DIMENSIONS = [
        self::GRAM => self::DIMENSION_WEIGHT,
        self::KILOGRAM => self::DIMENSION_WEIGHT,
        self::MILLILITER => self::DIMENSION_VOLUME,
        self::LITER => self::DIMENSION_VOLUME,
        self::PIECE => self::DIMENSION_UNIT,
    ];

    /**
     * Faktor relatif ke satuan terkecil tiap dimensi (gram, ml, pcs).
     *
     * @var array<string, array{dimension: string, factor: string}>
     */
    private const UNITS = [
        'kg' => ['dimension' => self::DIMENSION_WEIGHT, 'factor' => '1000'],
        'kilogram' => ['dimension' => self::DIMENSION_WEIGHT, 'factor' => '1000'],
        'gram' => ['dimension' => self::DIMENSION_WEIGHT, 'factor' => '1'],
        'g' => ['dimension' => self::DIMENSION_WEIGHT, 'factor' => '1'],
        'liter' => ['dimension' => self::DIMENSION_VOLUME, 'factor' => '1000'],
        'l' => ['dimension' => self::DIMENSION_VOLUME, 'factor' => '1000'],
        'ml' => ['dimension' => self::DIMENSION_VOLUME, 'factor' => '1'],
        'mililiter' => ['dimension' => self::DIMENSION_VOLUME, 'factor' => '1'],
        'pcs' => ['dimension' => self::DIMENSION_UNIT, 'factor' => '1'],
        'buah' => ['dimension' => self::DIMENSION_UNIT, 'factor' => '1'],
    ];

    public static function isValidBaseUnit(string $unit): bool
    {
        return array_key_exists($unit, self::BASE_DIMENSIONS);
    }

    public static function normalizeBaseUnit(mixed $raw): string
    {
        $value = is_string($raw) ? mb_strtolower(trim($raw)) : '';

        return self::isValidBaseUnit($value) ? $value : self::PIECE;
    }

    public static function dimensionOf(string $baseUnit): string
    {
        return self::BASE_DIMENSIONS[self::normalizeBaseUnit($baseUnit)];
    }

    /** @return list<string> */
    public static function inputUnitsFor(string $baseUnit): array
    {
        $dimension = self::dimensionOf($baseUnit);

        $units = [];
        foreach (self::UNITS as $unit => $meta) {
            if ($meta['dimension'] === $dimension) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    public static function isConvertible(string $inputUnit, string $baseUnit): bool
    {
        $input = mb_strtolower(trim($inputUnit));
        if (! array_key_exists($input, self::UNITS)) {
            return false;
        }

        return self::UNITS[$input]['dimension'] === self::dimensionOf($baseUnit);
    }

    /**
     * Konversi kuantitas input ke satuan dasar, contoh 5 kg menjadi "5000.0000".
     *
     * @throws \InvalidArgumentException
     */
    public static function toBaseQuantity(mixed $quantity, string $inputUnit, string $baseUnit): string
    {
        [$inputFactor, $baseFactor] = self::factors($inputUnit, $baseUnit);

        return bcmul(
            bcdiv(self::toDecimalString($quantity), $baseFactor, ProductQuantity::SCALE + 4),
            $inputFactor,
            ProductQuantity::SCALE
        );
    }

    /**
     * Biaya per satuan input dikonversi menjadi biaya per satuan dasar.
     * Contoh: Rp20.000 per kg menjadi Rp20 per gram.
     *
     * @throws \InvalidArgumentException
     */
    public static function toBaseUnitCost(mixed $cost, string $inputUnit, string $baseUnit): string
    {
        [$inputFactor, $baseFactor] = self::factors($inputUnit, $baseUnit);

        return bcmul(
            bcdiv(self::toDecimalString($cost), $inputFactor, InventoryCosting::COST_SCALE + 4),
            $baseFactor,
            InventoryCosting::COST_SCALE
        );
    }

    /**
     * @return array{string, string} [faktorInput, faktorBase]
     *
     * @throws \InvalidArgumentException
     */
    private static function factors(string $inputUnit, string $baseUnit): array
    {
        $input = mb_strtolower(trim($inputUnit));
        if (! array_key_exists($input, self::UNITS)) {
            throw new \InvalidArgumentException("Satuan «{$inputUnit}» tidak dikenal.");
        }

        $base = self::normalizeBaseUnit($baseUnit);
        if (self::UNITS[$input]['dimension'] !== self::BASE_DIMENSIONS[$base]) {
            throw new \InvalidArgumentException(
                "Satuan «{$inputUnit}» tidak sedimensi dengan satuan dasar «{$base}»."
            );
        }

        return [self::UNITS[$input]['factor'], self::UNITS[$base]['factor']];
    }

    private static function toDecimalString(mixed $raw): string
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
