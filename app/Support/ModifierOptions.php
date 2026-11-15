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

    /** Kunci baris transaksi untuk catatan bebas per item (bukan opsi berbayar). */
    public const NOTE_KEY = '__note';

    /**
     * Semua opsi yang sah untuk satu produk: opsi khusus produk + opsi dari grup
     * yang ditempel. Kunci opsi grup diberi awalan g{id}: agar tidak bentrok.
     *
     * @return array<string, array{key: string, label: string, price: float, group_id: int|null, single: bool}>
     */
    public static function allowedForProduct(\App\Models\Product $product): array
    {
        $allowed = [];

        foreach ($product->modifier_options ?? [] as $opt) {
            $key = (string) ($opt['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $allowed[$key] = [
                'key' => $key,
                'label' => (string) ($opt['label'] ?? $key),
                'price' => round(max(0.0, (float) ($opt['price'] ?? 0)), 2),
                'group_id' => null,
                'single' => false,
            ];
        }

        foreach ($product->modifierGroups()->get() as $group) {
            foreach ($group->options ?? [] as $opt) {
                $raw = (string) ($opt['key'] ?? '');
                if ($raw === '') {
                    continue;
                }
                $key = $group->optionKey($raw);
                $allowed[$key] = [
                    'key' => $key,
                    'label' => $group->name.': '.(string) ($opt['label'] ?? $raw),
                    'price' => round(max(0.0, (float) ($opt['price'] ?? 0)), 2),
                    'group_id' => (int) $group->id,
                    'single' => $group->selection === \App\Models\ModifierGroup::SELECTION_SINGLE,
                ];
            }
        }

        return $allowed;
    }

    /**
     * Ubah pilihan kasir (daftar {key}) + catatan bebas menjadi snapshot baris transaksi.
     * Opsi tak dikenal dibuang; grup "pilih satu" hanya menyimpan pilihan pertama.
     *
     * @param  array<int, mixed>|null  $raw
     * @return array<int, array{key: string, label: string, price: float}>
     */
    public static function resolveLine(\App\Models\Product $product, ?array $raw, ?string $note = null): array
    {
        $out = [];

        if ($raw !== null && $raw !== [] && ! $product->is_bundle) {
            $allowed = self::allowedForProduct($product);
            $usedKeys = [];
            $usedSingleGroups = [];

            foreach ($raw as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $key = (string) ($m['key'] ?? '');
                if ($key === '' || ! isset($allowed[$key]) || isset($usedKeys[$key])) {
                    continue;
                }
                $opt = $allowed[$key];
                if ($opt['single']) {
                    if (isset($usedSingleGroups[$opt['group_id']])) {
                        continue;
                    }
                    $usedSingleGroups[$opt['group_id']] = true;
                }
                $usedKeys[$key] = true;
                $out[] = ['key' => $key, 'label' => $opt['label'], 'price' => $opt['price']];
            }
        }

        $note = trim(preg_replace('/\s+/u', ' ', (string) $note) ?? '');
        if ($note !== '') {
            $note = mb_substr($note, 0, 150);
            $out[] = ['key' => self::NOTE_KEY, 'label' => 'Catatan: '.$note, 'price' => 0.0, 'note' => $note];
        }

        return $out;
    }

    /**
     * Normalisasi isian grup dari form (nama opsi + harga) menjadi daftar opsi tersimpan.
     * Kunci lama dipertahankan agar keranjang/open bill yang masih terbuka tetap cocok.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array{key: string, label: string, price: float}>
     */
    public static function normalizeGroupOptions(array $rows): array
    {
        return self::normalize($rows) ?? [];
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
