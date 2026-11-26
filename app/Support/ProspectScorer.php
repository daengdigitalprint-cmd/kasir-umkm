<?php

namespace App\Support;

/**
 * Skor prioritas prospek (0–10): makin mudah dihubungi dan makin terlihat
 * "aktif tapi belum punya sistem sendiri", makin tinggi.
 */
class ProspectScorer
{
    public const TIER_LABELS = [
        'high' => 'Prioritas tinggi',
        'medium' => 'Sedang',
        'low' => 'Rendah',
    ];

    /** Tautan yang bukan situs milik sendiri: profil sosial, marketplace, pengumpul tautan. */
    private const NOT_OWN_SITE = [
        'instagram.com', 'facebook.com', 'fb.com', 'tiktok.com', 'wa.me', 'whatsapp.com',
        'linktr.ee', 'lynk.id', 'taplink.cc', 'bio.link', 'gofood.co.id', 'gofood.link',
        'grab.com', 'shopee.co.id', 'tokopedia.com', 'google.com', 'goo.gl',
    ];

    /**
     * @param  array{phone?: ?string, phone_is_mobile?: bool, website?: ?string, instagram?: ?string, address?: ?string, has_hours?: bool, rating?: ?float, reviews_count?: ?int}  $data
     */
    public static function score(array $data): int
    {
        $score = 0;

        // Bisa dihubungi: syarat utama.
        if (filled($data['phone'] ?? null)) {
            $score += 3;
            // Nomor seluler hampir selalu WhatsApp pemilik/kasir.
            if ($data['phone_is_mobile'] ?? false) {
                $score += 2;
            }
        }

        // Belum punya situs sendiri = kemungkinan belum punya sistem pemesanan/kasir.
        if (! self::hasOwnWebsite($data['website'] ?? null)) {
            $score += 2;
        }

        // Tanda usaha aktif dan datanya terawat.
        if (filled($data['instagram'] ?? null)) {
            $score += 1;
        }
        if (filled($data['address'] ?? null)) {
            $score += 1;
        }
        if ($data['has_hours'] ?? false) {
            $score += 1;
        }

        // Hanya ada bila sumbernya menyediakan ulasan (Google Places).
        $reviews = (int) ($data['reviews_count'] ?? 0);
        if ($reviews >= 100) {
            $score += 2;
        } elseif ($reviews >= 20) {
            $score += 1;
        }

        return min(10, $score);
    }

    public static function tier(int $score): string
    {
        return match (true) {
            $score >= 7 => 'high',
            $score >= 4 => 'medium',
            default => 'low',
        };
    }

    public static function tierLabel(int $score): string
    {
        return self::TIER_LABELS[self::tier($score)];
    }

    public static function hasOwnWebsite(?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST));

        foreach (self::NOT_OWN_SITE as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return false;
            }
        }

        return $host !== '';
    }
}
