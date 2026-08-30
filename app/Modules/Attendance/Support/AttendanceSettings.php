<?php

namespace App\Modules\Attendance\Support;

use App\Models\Store;
use App\Services\AttendancePayrollAddOnService;

/**
 * Konfigurasi modul absensi per toko.
 * Disimpan di kolom stores.settings['attendance'] agar modul tetap terpisah
 * dari skema aplikasi utama (konsisten dengan pola LoyaltySettings).
 */
final class AttendanceSettings
{
    /** Nilai default konfigurasi absensi. */
    public const DEFAULTS = [
        'enabled' => false,
        'latitude' => null,
        'longitude' => null,
        'radius_meters' => 100,
        'require_selfie' => true,
        'require_geolocation' => true,
        'grace_minutes' => 15,
        'overtime_requires_approval' => true,
        'late_deduction_method' => 'none', // none | per_minute | per_day
        'late_deduction_rate' => 0,
        'auto_generate_from_cash_shift' => true,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(?Store $store): array
    {
        $raw = is_array($store?->settings['attendance'] ?? null)
            ? $store->settings['attendance']
            : [];

        return array_merge(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));
    }

    public static function purchased(?Store $store): bool
    {
        return AttendancePayrollAddOnService::isPurchased($store);
    }

    public static function toggleEnabled(?Store $store): bool
    {
        return (bool) self::all($store)['enabled'];
    }

    public static function enabled(?Store $store): bool
    {
        return self::purchased($store) && self::toggleEnabled($store);
    }

    public static function requireSelfie(?Store $store): bool
    {
        return (bool) self::all($store)['require_selfie'];
    }

    public static function requireGeolocation(?Store $store): bool
    {
        return (bool) self::all($store)['require_geolocation'];
    }

    public static function radiusMeters(?Store $store): int
    {
        return max(1, (int) self::all($store)['radius_meters']);
    }

    public static function graceMinutes(?Store $store): int
    {
        return max(0, (int) self::all($store)['grace_minutes']);
    }

    public static function overtimeRequiresApproval(?Store $store): bool
    {
        return (bool) self::all($store)['overtime_requires_approval'];
    }

    public static function autoGenerateFromCashShift(?Store $store): bool
    {
        return (bool) self::all($store)['auto_generate_from_cash_shift'];
    }

    public static function officeLatitude(?Store $store): ?float
    {
        $latitude = self::all($store)['latitude'];

        return is_numeric($latitude) ? (float) $latitude : null;
    }

    public static function officeLongitude(?Store $store): ?float
    {
        $longitude = self::all($store)['longitude'];

        return is_numeric($longitude) ? (float) $longitude : null;
    }

    public static function hasOfficeLocation(?Store $store): bool
    {
        return self::officeLatitude($store) !== null && self::officeLongitude($store) !== null;
    }

    /**
     * Ringkasan yang aman dibagikan ke frontend.
     *
     * @return array<string, mixed>
     */
    public static function forFrontend(?Store $store): array
    {
        $all = self::all($store);

        return [
            'enabled' => self::enabled($store),
            'require_selfie' => (bool) $all['require_selfie'],
            'require_geolocation' => (bool) $all['require_geolocation'],
            'radius_meters' => (int) $all['radius_meters'],
            'grace_minutes' => (int) $all['grace_minutes'],
            'has_office_location' => self::hasOfficeLocation($store),
        ];
    }

    /**
     * Simpan sebagian konfigurasi ke stores.settings['attendance'].
     *
     * @param  array<string, mixed>  $values
     */
    public static function update(Store $store, array $values): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $current = array_merge(
            self::DEFAULTS,
            is_array($settings['attendance'] ?? null) ? $settings['attendance'] : [],
        );

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $current[$key] = match ($key) {
                'enabled', 'require_selfie', 'require_geolocation', 'overtime_requires_approval', 'auto_generate_from_cash_shift' => (bool) $value,
                'radius_meters', 'grace_minutes' => max(0, (int) $value),
                'latitude', 'longitude' => is_numeric($value) ? (float) $value : null,
                'late_deduction_rate' => max(0, (float) $value),
                default => $value,
            };
        }

        $settings['attendance'] = $current;
        $store->settings = $settings;
        $store->save();
    }
}
