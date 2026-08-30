<?php

namespace App\Modules\Attendance\Services;

use App\Models\Store;
use App\Modules\Attendance\Support\AttendanceSettings;

/**
 * Validasi jarak geolokasi antara karyawan dan lokasi kantor/outlet.
 * Perhitungan memakai rumus Haversine (jarak lingkaran besar) dalam meter.
 */
class GeolocationService
{
    /** Radius bumi rata-rata dalam meter. */
    private const EARTH_RADIUS_METERS = 6371000;

    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function isWithinRadius(Store $store, float $latitude, float $longitude): bool
    {
        $officeLat = AttendanceSettings::officeLatitude($store);
        $officeLon = AttendanceSettings::officeLongitude($store);

        if ($officeLat === null || $officeLon === null) {
            return false;
        }

        $distance = $this->distanceMeters($officeLat, $officeLon, $latitude, $longitude);

        return $distance <= AttendanceSettings::radiusMeters($store);
    }
}
