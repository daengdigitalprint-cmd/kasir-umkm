<?php

namespace App\Support;

use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use Carbon\CarbonInterface;

/**
 * Aturan hitung satu baris stock opname.
 *
 * - counted_quantity null  = belum dihitung (tidak mengubah stok saat posting kecuali
 *   pengguna memilih "anggap 0").
 * - Stok sistem dibekukan saat sesi dibuka. Bila baris dihitung belakangan, mutasi stok
 *   di antaranya (penjualan, penerimaan) disimpan di movement_during_count supaya
 *   selisih = fisik − (snapshot + mutasi), tidak terhitung dua kali.
 */
final class OpnameCounting
{
    /**
     * Isi hasil hitung satu baris. $countedAt null = waktu hitung tidak diketahui
     * (impor berkas): baris dianggap dihitung saat sesi dibuka.
     */
    public static function record(
        StockAdjustment $document,
        StockAdjustmentLine $line,
        string $counted,
        ?CarbonInterface $countedAt,
    ): void {
        $movement = $countedAt !== null
            ? self::movementBetween($document, $line, $countedAt)
            : '0';

        self::fill($line, $counted, $countedAt, $movement);
    }

    /** Kosongkan baris menjadi "belum dihitung". */
    public static function clear(StockAdjustmentLine $line): void
    {
        $line->forceFill([
            'counted_quantity' => null,
            'counted_at' => null,
            'movement_during_count' => '0',
            'variance_quantity' => '0',
            'variance_value' => '0',
        ])->save();
    }

    /**
     * Baris belum dihitung yang diputuskan "stok fisik 0" saat posting:
     * selisihnya mengosongkan stok berjalan saat ini.
     */
    public static function recordZeroNow(StockAdjustment $document, StockAdjustmentLine $line): void
    {
        self::fill($line, '0', now(), self::movementBetween($document, $line, now()));
    }

    /**
     * Total mutasi stok produk/varian baris ini sejak sesi dibuka sampai $until,
     * di luar mutasi dari dokumen opname ini sendiri.
     */
    public static function movementBetween(StockAdjustment $document, StockAdjustmentLine $line, CarbonInterface $until): string
    {
        // Baris per batch tidak punya mutasi per batch di stock_movements.
        if ($line->product_batch_id !== null || $document->counted_at === null) {
            return '0';
        }

        $sum = StockMovement::query()
            ->where('store_id', $document->store_id)
            ->where('product_id', $line->product_id)
            ->when(
                $line->product_variant_id !== null,
                fn ($q) => $q->where('product_variant_id', $line->product_variant_id),
                fn ($q) => $q->whereNull('product_variant_id'),
            )
            ->where('created_at', '>', $document->counted_at)
            ->where('created_at', '<=', $until)
            ->where(fn ($q) => $q
                ->whereNull('reference_type')
                ->orWhere('reference_type', '!=', StockAdjustmentLine::class)
                ->orWhereNotIn('reference_id', StockAdjustmentLine::query()
                    ->select('id')
                    ->where('stock_adjustment_id', $document->id)))
            ->sum('quantity_change');

        return bcadd((string) $sum, '0', ProductQuantity::SCALE);
    }

    private static function fill(StockAdjustmentLine $line, string $counted, ?CarbonInterface $countedAt, string $movement): void
    {
        $expected = bcadd((string) $line->system_quantity, $movement, ProductQuantity::SCALE);
        $variance = AdjustmentStockOperations::variance($counted, $expected);

        $line->forceFill([
            'counted_quantity' => $counted,
            'counted_at' => $countedAt,
            'movement_during_count' => $movement,
            'variance_quantity' => $variance,
            'variance_value' => InventoryCosting::extendedCost($variance, (string) $line->unit_cost),
        ])->save();
    }
}
