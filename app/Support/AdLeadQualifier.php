<?php

namespace App\Support;

/**
 * Pertanyaan penyaring di landing page iklan (/mulai) dan aturan penilaiannya.
 *
 * Satu sumber untuk: pilihan yang tampil di form, validasi, label di panel
 * Super Admin, dan penentuan lead mana yang dihitung "serius".
 *
 * Tujuannya menyaring, bukan menolak: lead yang belum siap tetap disimpan dan
 * diarahkan ke paket gratis, tetapi tidak dilaporkan ke Meta sebagai Lead
 * sehingga iklan belajar mencari orang yang mirip lead serius.
 */
final class AdLeadQualifier
{
    public const TIER_HOT = 'hot';

    public const TIER_WARM = 'warm';

    public const TIER_COLD = 'cold';

    /** Nomor WhatsApp tim (sama dengan widget di landing utama). */
    public const WHATSAPP_NUMBER = '6285242766676';

    /** Jenis usaha — kunci mengikuti BusinessTypePresets agar cocok dengan registrasi. */
    public const BUSINESS_TYPES = [
        'fnb' => 'Makanan & minuman',
        'retail' => 'Toko / kelontong',
        'bakery' => 'Roti & kue',
        'pharmacy' => 'Apotek',
        'laundry' => 'Laundry',
        'barbershop' => 'Barbershop / salon',
        'general' => 'Usaha lainnya',
    ];

    /** @var array<string, array{label: string, hint: string, points: int}> */
    public const BUSINESS_STATUSES = [
        'running' => ['label' => 'Sudah berjalan', 'hint' => 'Sudah ada transaksi setiap hari', 'points' => 3],
        'opening_soon' => ['label' => 'Buka dalam 1 bulan ini', 'hint' => 'Tempat dan barang sedang disiapkan', 'points' => 2],
        'planning' => ['label' => 'Masih rencana', 'hint' => 'Belum ada tanggal buka', 'points' => 0],
    ];

    /** @var array<string, array{label: string, points: int}> */
    public const DAILY_TRANSACTIONS = [
        'lt20' => ['label' => 'Di bawah 20', 'points' => 1],
        '20to50' => ['label' => '20 – 50', 'points' => 2],
        '51to100' => ['label' => '51 – 100', 'points' => 3],
        'gt100' => ['label' => 'Di atas 100', 'points' => 3],
    ];

    /** @var array<string, array{label: string, hint: string, points: int}> */
    public const START_TIMELINES = [
        'this_week' => ['label' => 'Secepatnya, minggu ini', 'hint' => 'Ingin langsung dipakai di toko', 'points' => 3],
        'this_month' => ['label' => 'Dalam bulan ini', 'hint' => 'Mau coba dan siapkan data dulu', 'points' => 2],
        'browsing' => ['label' => 'Belum tahu, masih lihat-lihat', 'hint' => 'Sekadar membandingkan pilihan', 'points' => 0],
    ];

    /**
     * Nilai 0–9 dan tingkat keseriusan.
     *
     * Serius = usahanya nyata (berjalan / segera buka) DAN ada niat mulai
     * dalam bulan ini. Volume transaksi hanya menaikkan prioritas, tidak
     * menggugurkan: warung kecil yang siap mulai tetap lead yang baik.
     *
     * @return array{score: int, tier: string}
     */
    public static function evaluate(string $businessStatus, string $dailyTransactions, string $startTimeline): array
    {
        $statusPoints = self::BUSINESS_STATUSES[$businessStatus]['points'] ?? 0;
        $volumePoints = self::DAILY_TRANSACTIONS[$dailyTransactions]['points'] ?? 0;
        $timelinePoints = self::START_TIMELINES[$startTimeline]['points'] ?? 0;

        $score = $statusPoints + $volumePoints + $timelinePoints;

        if ($statusPoints === 0 || $timelinePoints === 0) {
            $tier = self::TIER_COLD;
        } elseif ($businessStatus === 'running' && $startTimeline === 'this_week') {
            $tier = self::TIER_HOT;
        } else {
            $tier = self::TIER_WARM;
        }

        return ['score' => $score, 'tier' => $tier];
    }

    public static function isQualified(string $tier): bool
    {
        return $tier === self::TIER_HOT || $tier === self::TIER_WARM;
    }

    /**
     * Nomor WhatsApp Indonesia ke bentuk 628xxxxxxxxx.
     * null bila bukan nomor seluler Indonesia yang masuk akal.
     */
    public static function normalizeWhatsapp(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^628\d{7,12}$/', $digits) === 1 ? $digits : null;
    }

    /** Label jawaban untuk ditampilkan (struk terima kasih, panel admin, ekspor). */
    public static function label(string $question, ?string $key): string
    {
        $options = match ($question) {
            'business_type' => self::BUSINESS_TYPES,
            'business_status' => self::BUSINESS_STATUSES,
            'daily_transactions' => self::DAILY_TRANSACTIONS,
            'start_timeline' => self::START_TIMELINES,
            default => [],
        };

        $option = $options[$key] ?? null;

        if (is_array($option)) {
            return $option['label'];
        }

        return $option ?? (string) $key;
    }

    public static function tierLabel(string $tier): string
    {
        return match ($tier) {
            self::TIER_HOT => 'Siap mulai',
            self::TIER_WARM => 'Serius',
            default => 'Belum siap',
        };
    }
}
