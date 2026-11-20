<?php

namespace App\Support;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Harga pokok (HPP), biaya rata-rata, nilai selisih, dan nilai stok hanya untuk
 * pengelola: admin, manajer, pemilik (sama dengan canAccessBackOffice()).
 *
 * Staf (gudang, kasir, dapur, teknisi) tetap bisa mengelola stok di halaman yang sama,
 * tetapi field biaya dibuang dari props DI SERVER — bukan hanya disembunyikan di UI —
 * supaya tidak terbaca lewat data halaman.
 *
 * Pembelian & retur pembelian sengaja tidak memakai ini: mencatat harga dari nota
 * supplier adalah bagian tugas gudang.
 */
final class CostVisibility
{
    /** Kunci yang dibuang (di level mana pun) untuk peran tanpa akses biaya. */
    public const COST_KEYS = [
        'cost_price',
        'average_cost',
        'unit_cost',
        'unit_cost_base',
        'unit_cost_input',
        'unit_cost_snapshot',
        'total_cost',
        'total_cost_snapshot',
        'variance_value',
        'total_variance_value',
        'total_value',
        'total_stock_value',
        'avg_cost_per_unit',
        'target_margin',
    ];

    public static function canSee(?User $user): bool
    {
        return $user !== null && $user->canAccessBackOffice();
    }

    /** Pengganti Inertia::render() untuk halaman stok yang memuat data biaya. */
    public static function render(string $component, array $props = []): Response
    {
        return Inertia::render($component, self::props($props, auth()->user()));
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public static function props(array $props, ?User $user): array
    {
        if (self::canSee($user)) {
            return $props;
        }

        // Serialisasi sama seperti yang dikirim Inertia (model, cast, paginator), lalu buang kunci biaya.
        $plain = json_decode(json_encode($props, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return self::strip($plain);
    }

    private static function strip(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::COST_KEYS, true)) {
                continue;
            }
            $out[$key] = self::strip($item);
        }

        return $out;
    }
}
