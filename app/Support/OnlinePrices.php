<?php

namespace App\Support;

use App\Enums\SalesChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;

/**
 * Harga toko online per aplikasi (GoFood/GrabFood/ShopeeFood).
 *
 * Disimpan di kolom JSON `online_prices` pada produk dan varian, berbentuk
 * {"gofood": 18000, "grabfood": 18000}. Kanal yang kosong berarti "pakai harga toko".
 */
final class OnlinePrices
{
    /**
     * Kanal yang punya harga sendiri: aggregator pesan-antar.
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        return array_map(fn (SalesChannel $c) => $c->value, SalesChannel::aggregators());
    }

    /** Modul pesanan online: kasir boleh memilih sumber pesanan GoFood/GrabFood/ShopeeFood. */
    public static function salesEnabled(?Store $store): bool
    {
        return (bool) ($store?->settings['online_sales_enabled'] ?? false);
    }

    /**
     * Saklar "Harga khusus pesanan online" di Pengaturan (turunan modul pesanan online).
     * Mati = isian harga online tidak tampil di form produk dan POS selalu memakai harga toko.
     */
    public static function enabled(?Store $store): bool
    {
        return self::salesEnabled($store)
            && (bool) ($store?->settings['online_price_enabled'] ?? false);
    }

    /**
     * Aturan validasi untuk isian `online_prices` di form produk.
     *
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        $rules = ['online_prices' => 'nullable|array'];
        foreach (self::channels() as $channel) {
            $rules["online_prices.{$channel}"] = 'nullable|numeric|min:0|max:9999999999999';
        }

        return $rules;
    }

    /**
     * Rapikan masukan mentah: hanya kanal yang dikenal dan bernilai > 0 yang disimpan.
     * Mengembalikan null bila tidak ada satu pun harga online yang diisi.
     *
     * @return array<string, float>|null
     */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $clean = [];
        foreach (self::channels() as $channel) {
            $value = $raw[$channel] ?? null;
            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }
            $value = round((float) $value, 2);
            if ($value > 0) {
                $clean[$channel] = $value;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Harga online yang berlaku untuk satu baris penjualan, atau null bila baris itu
     * harus memakai harga toko (kanal bukan aggregator, atau harga online tidak diisi).
     *
     * Varian: harga online varian didahulukan. Varian yang tidak punya harga sendiri
     * (mengikuti harga induk) ikut mewarisi harga online induk; varian dengan harga
     * sendiri tidak mewarisinya, supaya harga online induk tidak menimpa varian yang
     * harga tokonya lebih mahal.
     */
    public static function resolve(Product $product, ?ProductVariant $variant, SalesChannel $channel): ?float
    {
        if (! $channel->isAggregator()) {
            return null;
        }

        if ($variant) {
            $variantPrice = self::priceFor($variant->online_prices, $channel);
            if ($variantPrice !== null) {
                return $variantPrice;
            }
            if ($variant->price !== null) {
                return null;
            }
        }

        return self::priceFor($product->online_prices, $channel);
    }

    private static function priceFor(mixed $prices, SalesChannel $channel): ?float
    {
        if (! is_array($prices)) {
            return null;
        }
        $value = $prices[$channel->value] ?? null;
        if ($value === null || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return round((float) $value, 2);
    }
}
