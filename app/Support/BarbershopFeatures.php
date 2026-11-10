<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Store;

/**
 * Saklar fitur khas barbershop (layanan jasa, teknisi, antrian per teknisi).
 * Selaras dengan pola LaundryFeatures/FnBFeatures: satu codebase, aktivasi via business_type.
 */
class BarbershopFeatures
{
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }

        return ($store->business_type ?? '') === 'barbershop';
    }

    /**
     * Tarif komisi teknisi yang berlaku untuk sebuah produk.
     * Override per produk didahulukan; jika tidak ada, memakai tarif global toko
     * (stores.settings["barber_commission"]).
     *
     * @return array{mode: string, value: float}
     */
    public static function commissionRateFor(Store $store, ?Product $product = null): array
    {
        $global = $store->settings['barber_commission'] ?? [];
        $mode = in_array($global['mode'] ?? null, ['percent', 'fixed'], true) ? $global['mode'] : 'percent';
        $value = is_numeric($global['value'] ?? null) ? (float) $global['value'] : 0.0;

        if ($product !== null && $product->barber_commission_mode !== null) {
            $mode = $product->barber_commission_mode;
            $value = is_numeric($product->barber_commission_value) ? (float) $product->barber_commission_value : 0.0;
        }

        return ['mode' => $mode, 'value' => max(0.0, $value)];
    }

    /**
     * Hitung komisi satu baris keranjang: persentase dari line_total, atau nilai tetap per unit.
     *
     * @return array{percent: float, amount: float}
     */
    public static function commissionForLine(Store $store, ?Product $product, float $lineTotal, int $quantity = 1): array
    {
        $rate = self::commissionRateFor($store, $product);

        if ($rate['value'] <= 0) {
            return ['percent' => 0.0, 'amount' => 0.0];
        }

        if ($rate['mode'] === 'fixed') {
            return ['percent' => 0.0, 'amount' => round($rate['value'] * $quantity, 2)];
        }

        $percent = min(100.0, $rate['value']);

        return ['percent' => $percent, 'amount' => round($lineTotal * $percent / 100, 2)];
    }
}
