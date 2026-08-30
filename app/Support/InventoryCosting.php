<?php

namespace App\Support;

use App\Models\Product;

/**
 * Perhitungan biaya persediaan terpusat (Tahap 2 modul gudang/produksi).
 *
 * Metode awal: weighted average. Rumus stok masuk:
 *
 *   Average Cost Baru =
 *     ((Stok Lama x Average Cost Lama) + (Qty Masuk x Biaya Masuk))
 *     / (Stok Lama + Qty Masuk)
 *
 * Semua nilai dihitung dengan bcmath agar HPP seperti Rp25.000 / 18.000 ml
 * tidak kehilangan presisi sebelum dibulatkan untuk tampilan.
 */
final class InventoryCosting
{
    /** Presisi biaya per satuan dasar, contoh Rp1,388889 per ml. */
    public const COST_SCALE = 6;

    public const METHOD_WEIGHTED_AVERAGE = 'weighted_average';

    /**
     * Average cost baru setelah stok masuk.
     *
     * Kuantitas masuk nol atau negatif tidak mengubah biaya; ini menjaga
     * pembelian koreksi tidak merusak nilai persediaan.
     */
    public static function weightedAverage(
        mixed $currentQuantity,
        mixed $currentAverageCost,
        mixed $incomingQuantity,
        mixed $incomingUnitCost,
    ): string {
        $curQty = self::decimal($currentQuantity, ProductQuantity::SCALE);
        $curCost = self::decimal($currentAverageCost, self::COST_SCALE);
        $inQty = self::decimal($incomingQuantity, ProductQuantity::SCALE);
        $inCost = self::decimal($incomingUnitCost, self::COST_SCALE);

        if (bccomp($inQty, '0', ProductQuantity::SCALE) <= 0) {
            return self::scaleCost($curCost);
        }

        // Stok lama nol atau negatif: biaya mengikuti pembelian terbaru.
        if (bccomp($curQty, '0', ProductQuantity::SCALE) <= 0) {
            return self::scaleCost($inCost);
        }

        $currentValue = bcmul($curQty, $curCost, self::COST_SCALE);
        $incomingValue = bcmul($inQty, $inCost, self::COST_SCALE);
        $totalValue = bcadd($currentValue, $incomingValue, self::COST_SCALE);
        $totalQty = bcadd($curQty, $inQty, ProductQuantity::SCALE);

        if (bccomp($totalQty, '0', ProductQuantity::SCALE) === 0) {
            return self::scaleCost($curCost);
        }

        return self::divideRounded($totalValue, $totalQty, self::COST_SCALE);
    }

    /**
     * Average cost produk setelah menerima stok masuk.
     */
    public static function averageCostAfterInbound(
        Product $product,
        mixed $incomingQuantity,
        mixed $incomingUnitCost,
    ): string {
        return self::weightedAverage(
            $product->stock_quantity,
            self::currentAverageCost($product),
            $incomingQuantity,
            $incomingUnitCost,
        );
    }

    /** Biaya persediaan berjalan; fallback ke `cost_price` untuk produk lama. */
    public static function currentAverageCost(Product $product): string
    {
        $average = $product->average_cost;
        if ($average !== null && is_numeric((string) $average)) {
            return self::decimal($average, self::COST_SCALE);
        }

        return self::decimal($product->cost_price ?? 0, self::COST_SCALE);
    }

    /**
     * HPP per satuan dasar dari total biaya produksi dan kuantitas output.
     * Contoh: Rp25.000 dan 18.000 ml menghasilkan "1.388889".
     *
     * @throws \InvalidArgumentException
     */
    public static function unitCostFromTotal(mixed $totalCost, mixed $outputQuantity): string
    {
        $total = self::decimal($totalCost, self::COST_SCALE);
        $qty = self::decimal($outputQuantity, ProductQuantity::SCALE);

        if (bccomp($qty, '0', ProductQuantity::SCALE) <= 0) {
            throw new \InvalidArgumentException('Kuantitas output harus lebih dari 0.');
        }

        return self::divideRounded($total, $qty, self::COST_SCALE);
    }

    /**
     * Pembagian desimal dengan pembulatan half-up; `bcdiv` biasa hanya memotong angka.
     */
    private static function divideRounded(string $numerator, string $denominator, int $scale): string
    {
        $guardScale = $scale + 1;
        $raw = bcdiv($numerator, $denominator, $guardScale);
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($raw, '-')
            ? bcsub($raw, $half, $guardScale)
            : bcadd($raw, $half, $guardScale);

        // bcadd dengan skala target membuang digit penjaga setelah nilai dibulatkan.
        return bcadd($adjusted, '0', $scale);
    }

    /**
     * Total biaya dari kuantitas dan biaya per satuan dasar.
     * Contoh: 200 ml dengan HPP Rp1,388889 per ml menghasilkan "277.78".
     */
    public static function extendedCost(mixed $quantity, mixed $unitCost, int $scale = 2): string
    {
        $qty = self::decimal($quantity, ProductQuantity::SCALE);
        $cost = self::decimal($unitCost, self::COST_SCALE);

        $raw = bcmul($qty, $cost, $scale + 1);

        return self::roundHalfUp($raw, $scale);
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $guardScale = $scale + 1;
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $half, $guardScale)
            : bcadd($value, $half, $guardScale);

        return bcadd($adjusted, '0', $scale);
    }

    private static function scaleCost(string $value): string
    {
        return bcadd($value, '0', self::COST_SCALE);
    }

    private static function decimal(mixed $raw, int $scale): string
    {
        if (is_int($raw)) {
            return bcadd((string) $raw, '0', $scale);
        }
        if (is_float($raw)) {
            return bcadd(number_format($raw, $scale, '.', ''), '0', $scale);
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            return bcadd(trim($raw), '0', $scale);
        }
        if ($raw instanceof \Stringable && is_numeric((string) $raw)) {
            return bcadd((string) $raw, '0', $scale);
        }
        if ($raw === null) {
            return bcadd('0', '0', $scale);
        }

        throw new \InvalidArgumentException('Nilai numerik tidak valid.');
    }
}
