<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Pengolah konten artikel: markdown ke HTML sekaligus melengkapi atribut
 * gambar yang penting untuk SEO & aksesibilitas (alt, lazy loading).
 *
 * Gambar tanpa `alt` adalah salah satu temuan SEO paling umum. Karena
 * penulis sering lupa mengisinya, alt disusun otomatis dari konteks
 * terdekat: judul bagian (H2) tempat gambar berada, lalu judul artikel.
 */
class ArticleContentService
{
    /** Panjang maksimum alt yang masih dibaca utuh oleh pembaca layar. */
    private const ALT_MAX = 125;

    /**
     * Render markdown artikel menjadi HTML siap tayang.
     */
    public static function render(string $markdown, ?string $title = null, ?string $keyword = null): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return self::fillImageAttributes($html, $title, $keyword);
    }

    /**
     * Lengkapi setiap <img> dalam HTML: isi `alt` bila kosong, dan tambahkan
     * lazy loading agar tidak memberatkan Core Web Vitals.
     */
    public static function fillImageAttributes(string $html, ?string $title = null, ?string $keyword = null): string
    {
        if ($html === '' || ! str_contains($html, '<img')) {
            return $html;
        }

        $heading = null;      // judul H2 terdekat sebelum gambar
        $seenInSection = 0;   // nomor gambar dalam bagian yang sama

        $result = preg_replace_callback(
            '/<h2[^>]*>(.*?)<\/h2>|<img\b[^>]*>/is',
            function (array $m) use (&$heading, &$seenInSection, $title, $keyword) {
                // Blok heading: catat sebagai konteks untuk gambar berikutnya.
                if (stripos($m[0], '<h2') === 0) {
                    $heading = trim(strip_tags($m[1])) ?: null;
                    $seenInSection = 0;

                    return $m[0];
                }

                $tag = $m[0];
                $seenInSection++;

                if (! self::hasMeaningfulAlt($tag)) {
                    $alt = self::buildAlt($title, $keyword, $heading, $seenInSection);
                    $tag = self::setAttribute($tag, 'alt', $alt);
                }

                if (! preg_match('/\bloading\s*=/i', $tag)) {
                    $tag = (string) preg_replace('/<img\b/i', '<img loading="lazy" decoding="async"', $tag, 1);
                }

                return $tag;
            },
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Alt untuk thumbnail artikel. Dipakai saat admin mengunggah gambar
     * tetapi membiarkan kolom alt kosong.
     */
    public static function thumbnailAlt(string $title, ?string $keyword = null): string
    {
        $alt = trim($title);

        // Sisipkan kata kunci hanya bila belum terwakili di judul,
        // supaya tidak terbaca sebagai keyword stuffing.
        if ($keyword !== null && trim($keyword) !== '' && ! Str::contains(Str::lower($alt), Str::lower(trim($keyword)))) {
            $alt .= ' - '.trim($keyword);
        }

        return self::trimAlt($alt);
    }

    /** Apakah tag <img> sudah punya alt yang berisi (bukan kosong). */
    private static function hasMeaningfulAlt(string $tag): bool
    {
        if (! preg_match('/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
            return false;
        }

        return trim($m[2] ?? $m[3] ?? '') !== '';
    }

    /** Susun alt dari konteks: judul bagian, lalu judul artikel. */
    private static function buildAlt(?string $title, ?string $keyword, ?string $heading, int $index): string
    {
        $title = $title !== null ? trim($title) : '';
        $heading = $heading !== null ? trim($heading) : '';

        if ($heading !== '' && $title !== '' && Str::lower($heading) !== Str::lower($title)) {
            $alt = $heading.' - '.$title;
        } elseif ($heading !== '') {
            $alt = $heading;
        } elseif ($title !== '') {
            $alt = $title;
        } else {
            $alt = trim((string) $keyword) ?: 'Ilustrasi artikel';
        }

        // Bedakan bila ada beberapa gambar dalam satu bagian.
        if ($index > 1) {
            $alt .= ' (gambar '.$index.')';
        }

        return self::trimAlt($alt);
    }

    /** Ganti atau tambahkan satu atribut pada tag <img>. */
    private static function setAttribute(string $tag, string $name, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        if (preg_match('/\b'.preg_quote($name, '/').'\s*=/i', $tag)) {
            return (string) preg_replace(
                '/\b'.preg_quote($name, '/').'\s*=\s*("[^"]*"|\'[^\']*\')/i',
                $name.'="'.$escaped.'"',
                $tag,
                1,
            );
        }

        return (string) preg_replace('/<img\b/i', '<img '.$name.'="'.$escaped.'"', $tag, 1);
    }

    private static function trimAlt(string $alt): string
    {
        $alt = (string) preg_replace('/\s+/', ' ', trim($alt));

        return Str::limit($alt, self::ALT_MAX, '');
    }
}
