<?php

namespace App\Support;

use App\Models\Store;

/**
 * Pengaturan & aturan program loyalitas per toko.
 * Disimpan di kolom stores.settings['loyalty'] agar tidak perlu tabel terpisah.
 */
final class LoyaltySettings
{
    /** Nilai default aturan perolehan poin. */
    public const DEFAULTS = [
        'enabled' => false,
        // 1 poin per Rp {rupiah_per_point} nilai transaksi.
        'rupiah_per_point' => 10000,
        // Poin bonus saat ulang tahun pelanggan (0 = nonaktif).
        'birthday_bonus_points' => 0,
        // Poin bonus untuk pengundang saat mengundang teman (0 = nonaktif).
        'referral_bonus_points' => 0,
        // Masa berlaku poin dalam bulan sejak diperoleh (0 = tidak kadaluarsa).
        'expiry_months' => 12,
        // Ambang hari untuk peringatan "poin hampir kadaluarsa".
        'expiry_warn_days' => 30,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(?Store $store): array
    {
        $raw = is_array($store?->settings['loyalty'] ?? null)
            ? $store->settings['loyalty']
            : [];

        return array_merge(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
    }

    public static function enabled(?Store $store): bool
    {
        return (bool) self::all($store)['enabled'];
    }

    public static function rupiahPerPoint(?Store $store): int
    {
        return max(1, (int) self::all($store)['rupiah_per_point']);
    }

    public static function birthdayBonusPoints(?Store $store): int
    {
        return max(0, (int) self::all($store)['birthday_bonus_points']);
    }

    public static function referralBonusPoints(?Store $store): int
    {
        return max(0, (int) self::all($store)['referral_bonus_points']);
    }

    public static function expiryMonths(?Store $store): int
    {
        return max(0, (int) self::all($store)['expiry_months']);
    }

    public static function expiryWarnDays(?Store $store): int
    {
        return max(1, (int) self::all($store)['expiry_warn_days']);
    }

    /**
     * Hitung poin yang diperoleh dari nilai transaksi (pembulatan ke bawah).
     */
    public static function pointsForAmount(?Store $store, float $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        return (int) floor($amount / self::rupiahPerPoint($store));
    }

    /**
     * Simpan sebagian aturan ke stores.settings['loyalty'].
     *
     * @param  array<string, mixed>  $values
     */
    public static function update(Store $store, array $values): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $current = array_merge(self::DEFAULTS, is_array($settings['loyalty'] ?? null) ? $settings['loyalty'] : []);

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            if ($key === 'enabled') {
                $current[$key] = (bool) $value;
            } else {
                $current[$key] = max(0, (int) $value);
            }
        }

        $settings['loyalty'] = $current;
        $store->settings = $settings;
        $store->save();
    }
}
