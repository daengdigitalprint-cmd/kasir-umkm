<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalisasi opsi modifier produk (topping/varian rasa F&B).
 *
 * Dipakai bersama oleh form produk dan impor produk supaya aturan kunci unik,
 * batas panjang label, dan harga minimum sama persis di kedua jalur.
 */
final class ModifierOptions
{
    /**
     * @param  array<int, mixed>  $decoded  hasil json_decode bentuk list of {key?, label, price?}
     * @return array<int, array{key: string, label: string, price: float}>|null
     */
    public static function normalize(array $decoded): ?array
    {
        $normalized = [];
        $usedKeys = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($label === '') {
                continue;
            }

            $key = isset($row['key']) ? trim((string) $row['key']) : '';
            if ($key === '') {
                $base = Str::slug($label, '_');
                if ($base === '') {
                    $base = 'opt';
                }
            } else {
                $base = Str::slug($key, '_');
                if ($base === '') {
                    $base = 'opt';
                }
            }

            $key = $base;
            $n = 1;
            while (in_array($key, $usedKeys, true)) {
                $key = $base.'_'.$n;
                $n++;
            }

            $usedKeys[] = $key;
            $normalized[] = [
                'key' => mb_substr($key, 0, 64),
                'label' => mb_substr($label, 0, 120),
                'price' => round(max(0, (float) ($row['price'] ?? 0)), 2),
            ];
        }

        return $normalized === [] ? null : $normalized;
    }

    /** Apakah toko ini memakai opsi modifier (khusus F&B). */
    public static function enabled(?\App\Models\Store $store): bool
    {
        return ($store?->business_type ?? '') === 'fnb';
    }

    /** Teks JSON untuk kolom ekspor; null bila produk tidak punya opsi. */
    public static function toJson(mixed $options): ?string
    {
        if (! is_array($options) || $options === []) {
            return null;
        }

        return json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
    }
}
