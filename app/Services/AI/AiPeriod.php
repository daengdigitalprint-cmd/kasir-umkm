<?php

namespace App\Services\AI;

use App\Models\Store;
use Illuminate\Support\Carbon;

/**
 * Satu sumber kebenaran untuk rentang waktu yang dipakai tool AI.
 *
 * Batas hari dihitung di zona waktu TOKO (stores.timezone), lalu dikonversi
 * ke zona waktu aplikasi untuk query — karena created_at disimpan di zona
 * aplikasi. Tanpa ini, "hari ini" di server UTC baru mulai pukul 07.00/08.00
 * waktu toko, sehingga penjualan pagi hilang dari jawaban.
 *
 * Periode berjalan (hari ini / minggu ini / bulan ini / tahun ini) berakhir
 * SEKARANG, bukan di akhir periode, agar pembandingnya setara.
 */
class AiPeriod
{
    public const PERIODS = [
        'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'this_year', 'last_7_days', 'last_30_days',
    ];

    protected const LABELS = [
        'today' => 'hari ini',
        'yesterday' => 'kemarin',
        'this_week' => 'minggu ini',
        'last_week' => 'minggu lalu',
        'this_month' => 'bulan ini',
        'last_month' => 'bulan lalu',
        'this_year' => 'tahun ini',
        'last_7_days' => '7 hari terakhir',
        'last_30_days' => '30 hari terakhir',
        'custom' => 'rentang tanggal',
    ];

    public static function timezone(?Store $store): string
    {
        $timezone = $store?->timezone;

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    /** Waktu sekarang menurut jam toko. */
    public static function now(?Store $store): Carbon
    {
        return Carbon::now(self::timezone($store));
    }

    /** Singkatan zona waktu Indonesia untuk ditampilkan ke user/model. */
    public static function timezoneLabel(?Store $store): string
    {
        return match (self::timezone($store)) {
            'Asia/Jakarta', 'Asia/Pontianak' => 'WIB',
            'Asia/Makassar' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => self::timezone($store),
        };
    }

    /** Konversi ke zona aplikasi agar cocok dengan kolom created_at. */
    public static function toDb(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Tampilkan waktu tersimpan (zona aplikasi) menurut jam toko. */
    public static function toStore(mixed $moment, ?Store $store): Carbon
    {
        return Carbon::parse($moment, (string) config('app.timezone', 'UTC'))->setTimezone(self::timezone($store));
    }

    /**
     * Rentang dari argumen tool (`period` atau `date_from` + `date_to`).
     *
     * @param  array<string, mixed>  $args
     * @return array{key: string, label: string, from: Carbon, to: Carbon, local_from: Carbon, local_to: Carbon, display_from: string, display_to: string, is_running: bool, days: int}
     */
    public static function resolve(array $args, ?Store $store, string $default = 'today'): array
    {
        $timezone = self::timezone($store);

        if (! empty($args['date_from']) && ! empty($args['date_to'])) {
            try {
                $from = Carbon::parse((string) $args['date_from'], $timezone)->startOfDay();
                $to = Carbon::parse((string) $args['date_to'], $timezone)->endOfDay();

                if ($from->gt($to)) {
                    [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
                }

                return self::build('custom', $from, $to, false);
            } catch (\Throwable) {
                // Tanggal tidak terbaca: jatuh ke periode bawaan di bawah.
            }
        }

        $period = (string) ($args['period'] ?? $default);

        if (! in_array($period, self::PERIODS, true)) {
            $period = $default;
        }

        return self::forPeriod($period, $store);
    }

    /**
     * @return array{key: string, label: string, from: Carbon, to: Carbon, local_from: Carbon, local_to: Carbon, display_from: string, display_to: string, is_running: bool, days: int}
     */
    public static function forPeriod(string $period, ?Store $store): array
    {
        $now = self::now($store);
        $endOfToday = $now->copy()->endOfDay();

        return match ($period) {
            'yesterday' => self::build($period, $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), false),
            'this_week' => self::build($period, $now->copy()->startOfWeek(), $endOfToday, true),
            'last_week' => self::build($period, $now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek(), false),
            'this_month' => self::build($period, $now->copy()->startOfMonth(), $endOfToday, true),
            'last_month' => self::build($period, $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), false),
            'this_year' => self::build($period, $now->copy()->startOfYear(), $endOfToday, true),
            'last_7_days' => self::build($period, $now->copy()->subDays(6)->startOfDay(), $endOfToday, true),
            'last_30_days' => self::build($period, $now->copy()->subDays(29)->startOfDay(), $endOfToday, true),
            default => self::build('today', $now->copy()->startOfDay(), $endOfToday, true),
        };
    }

    /**
     * Periode pembanding yang SETARA.
     *
     * Periode berjalan dibandingkan dengan rentang yang sama panjang di
     * periode sebelumnya (mis. 1–14 bulan ini vs 1–14 bulan lalu), bukan
     * dengan periode penuh — kalau tidak, awal minggu/bulan selalu tampak
     * "turun drastis".
     *
     * @param  array{key: string, local_from: Carbon, local_to: Carbon, days: int}  $range
     * @return array{key: string, label: string, from: Carbon, to: Carbon, local_from: Carbon, local_to: Carbon, display_from: string, display_to: string, is_running: bool, days: int}
     */
    public static function previous(array $range): array
    {
        $from = $range['local_from'];
        $days = $range['days'];

        $previousFrom = match ($range['key']) {
            'this_month' => $from->copy()->subMonthNoOverflow()->startOfMonth(),
            'last_month' => $from->copy()->subMonthNoOverflow()->startOfMonth(),
            'this_year' => $from->copy()->subYearNoOverflow()->startOfYear(),
            'this_week' => $from->copy()->subWeek(),
            default => $from->copy()->subDays($days),
        };

        $previousTo = match ($range['key']) {
            // Hari ini baru berjalan sebagian: bandingkan dengan kemarin
            // sampai jam yang sama, bukan kemarin sehari penuh.
            'today' => Carbon::now($from->getTimezone())->subDay(),
            'last_month' => $previousFrom->copy()->endOfMonth(),
            'this_month' => $previousFrom->copy()->addDays($days - 1)->min($previousFrom->copy()->endOfMonth())->endOfDay(),
            default => $previousFrom->copy()->addDays($days - 1)->endOfDay(),
        };

        return self::build('previous', $previousFrom, $previousTo, false);
    }

    /**
     * @return array{key: string, label: string, from: Carbon, to: Carbon, local_from: Carbon, local_to: Carbon, display_from: string, display_to: string, is_running: bool, days: int}
     */
    protected static function build(string $key, Carbon $from, Carbon $to, bool $isRunning): array
    {
        return [
            'key' => $key,
            'label' => self::LABELS[$key] ?? $key,
            'from' => self::toDb($from),
            'to' => self::toDb($to),
            'local_from' => $from,
            'local_to' => $to,
            'display_from' => $from->format('d/m/Y'),
            'display_to' => $to->format('d/m/Y'),
            'is_running' => $isRunning,
            'days' => (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1,
        ];
    }

    /**
     * Terjemahkan frasa waktu bahasa Indonesia ke kunci periode.
     * Mengembalikan null bila pesan tidak menyebut periode apa pun.
     */
    public static function detect(string $text): ?string
    {
        $text = mb_strtolower($text);

        return match (true) {
            (bool) preg_match('/\b30\s*hari\s*(?:terakhir|kebelakang|ke\s*belakang)|sebulan\s+terakhir/u', $text) => 'last_30_days',
            (bool) preg_match('/\b7\s*hari\s*(?:terakhir|kebelakang|ke\s*belakang)|seminggu\s+terakhir/u', $text) => 'last_7_days',
            // "minggu/bulan kemarin" harus dicek sebelum "kemarin" saja.
            (bool) preg_match('/\b(?:minggu|pekan)\s+(?:lalu|kemarin|kemaren|sebelumnya)/u', $text) => 'last_week',
            (bool) preg_match('/\bbulan\s+(?:lalu|kemarin|kemaren|sebelumnya)/u', $text) => 'last_month',
            (bool) preg_match('/\bkemarin|\bkemaren/u', $text) => 'yesterday',
            (bool) preg_match('/\bhari\s+ini|\bhr\s+ini|\bsekarang\b/u', $text) => 'today',
            (bool) preg_match('/\b(?:minggu|pekan)\s+ini|\bmingguan\b|\bseminggu\b/u', $text) => 'this_week',
            (bool) preg_match('/\bbulan\s+ini|\bbulanan\b|\bsebulan\b/u', $text) => 'this_month',
            (bool) preg_match('/\btahun\s+ini|\btahunan\b|\bsetahun\b/u', $text) => 'this_year',
            (bool) preg_match('/\b(?:minggu|pekan)\b/u', $text) => 'this_week',
            (bool) preg_match('/\bbulan\b/u', $text) => 'this_month',
            (bool) preg_match('/\btahun\b/u', $text) => 'this_year',
            default => null,
        };
    }
}
