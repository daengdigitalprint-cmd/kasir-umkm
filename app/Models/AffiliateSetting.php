<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aturan program afiliasi yang bisa diubah super admin (satu baris).
 * Kolom yang kosong memakai nilai bawaan di config/affiliate.php.
 */
class AffiliateSetting extends Model
{
    /** Aturan yang bisa diatur, beserta batas wajarnya [min, max]. */
    public const RULES = [
        'commission_percent' => [0, 100],
        'commission_months' => [0, 120], // 0 = sekali saja per toko
        'cookie_days' => [1, 365],
        'hold_days' => [0, 90],
        'min_payout' => [0, 100000000],
        'referred_trial_days' => [0, 365],
    ];

    private const CONTAINER_KEY = 'affiliate.settings.row';

    protected $fillable = [
        'commission_percent',
        'commission_months',
        'cookie_days',
        'hold_days',
        'min_payout',
        'referred_trial_days',
    ];

    protected $casts = [
        'commission_percent' => 'float',
        'commission_months' => 'integer',
        'cookie_days' => 'integer',
        'hold_days' => 'integer',
        'min_payout' => 'integer',
        'referred_trial_days' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCached());
        static::deleted(fn () => static::forgetCached());
    }

    /** Baris pengaturan (null bila belum pernah disimpan). Dibaca sekali per request. */
    public static function current(): ?self
    {
        $app = app();

        if (! $app->bound(self::CONTAINER_KEY)) {
            // Tabel belum ada (mis. sesaat sebelum migrasi saat deploy): pakai nilai bawaan, jangan galat.
            $row = rescue(fn () => static::query()->first(), null, false);
            $app->instance(self::CONTAINER_KEY, $row ?? false);
        }

        return $app->make(self::CONTAINER_KEY) ?: null;
    }

    public static function forgetCached(): void
    {
        app()->forgetInstance(self::CONTAINER_KEY);
    }

    /** Nilai aturan yang berlaku: pengaturan super admin, atau bawaan config. */
    public static function value(string $key): float|int
    {
        $value = static::current()?->getAttribute($key);

        if ($value === null) {
            $value = config('affiliate.'.$key);
        }

        return $key === 'commission_percent' ? (float) $value : (int) $value;
    }

    /**
     * Semua aturan yang berlaku + mana yang sudah diubah dari bawaan.
     *
     * @return array<string, array{value: float|int, default: float|int, custom: bool}>
     */
    public static function overview(): array
    {
        $row = static::current();
        $out = [];

        foreach (array_keys(self::RULES) as $key) {
            $default = config('affiliate.'.$key);
            $out[$key] = [
                'value' => static::value($key),
                'default' => $key === 'commission_percent' ? (float) $default : (int) $default,
                'custom' => $row?->getAttribute($key) !== null,
            ];
        }

        return $out;
    }
}
