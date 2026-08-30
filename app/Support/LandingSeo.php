<?php

namespace App\Support;

final class LandingSeo
{
    private const SITE_NAME = 'UMKM Kasir';

    /**
     * URL maskot untuk UI (utama WebP + cache-bust jika ada).
     */
    public static function maskotAssetUrl(): string
    {
        $path = public_path('logo-macca.png');
        $url = asset('logo-macca.png');

        return is_file($path) ? $url.'?v='.filemtime($path) : $url;
    }

    /**
     * Gambar untuk og:image (WhatsApp, Facebook, Telegram, dll.).
     *
     * @return array{url: string, mime: string, width: ?int, height: ?int}
     */
    public static function openGraphImage(): array
    {
        $candidates = [
            ['path' => public_path('logo.png'), 'asset' => 'logo.png', 'mime' => 'image/png'],
            ['path' => public_path('og-share.png'), 'asset' => 'og-share.png', 'mime' => 'image/png'],
            ['path' => public_path('maskot.png'), 'asset' => 'maskot.png', 'mime' => 'image/png'],
            ['path' => public_path('maskot.jpg'), 'asset' => 'maskot.jpg', 'mime' => 'image/jpeg'],
            ['path' => public_path('maskot.jpeg'), 'asset' => 'maskot.jpeg', 'mime' => 'image/jpeg'],
            ['path' => public_path('maskot.webp'), 'asset' => 'maskot.webp', 'mime' => 'image/webp'],
        ];

        foreach ($candidates as $c) {
            if (! is_file($c['path'])) {
                continue;
            }

            $url = asset($c['asset']).'?v='.filemtime($c['path']);
            $dims = @getimagesize($c['path']);
            $width = is_array($dims) ? (int) $dims[0] : null;
            $height = is_array($dims) ? (int) $dims[1] : null;

            return [
                'url' => $url,
                'mime' => $c['mime'],
                'width' => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,
            ];
        }

        $maskotFallback = public_path('maskot.webp');

        return [
            'url' => asset('maskot.webp').(is_file($maskotFallback) ? '?v='.filemtime($maskotFallback) : ''),
            'mime' => 'image/webp',
            'width' => null,
            'height' => null,
        ];
    }

    /**
     * Meta + JSON-LD untuk halaman landing publik (HTML awal, tanpa Inertia Head).
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>|null
     */
    public static function serverSeoForPage(string $component, array $props): ?array
    {
        $ogResolved = self::openGraphImage();
        $ogImage = $props['ogImageUrl'] ?? $ogResolved['url'];
        $ogImageType = $props['ogImageType'] ?? $ogResolved['mime'];
        $ogImageWidth = $props['ogImageWidth'] ?? $ogResolved['width'];
        $ogImageHeight = $props['ogImageHeight'] ?? $ogResolved['height'];

        return match ($component) {
            'Landing/Index' => self::homeServerSeo(
                $props['canonicalUrl'] ?? url('/'),
                $ogImage,
                $ogImageType,
                $ogImageWidth,
                $ogImageHeight,
            ),
            'Landing/Panduan' => self::panduanServerSeo(
                $props['canonicalUrl'] ?? url('/panduan'),
                $ogImage,
                $ogImageType,
                $ogImageWidth,
                $ogImageHeight,
            ),
            'Landing/Privacy' => self::privacyServerSeo(
                $props['canonicalUrl'] ?? url('/privacy'),
                $ogImage,
                $ogImageType,
                $ogImageWidth,
                $ogImageHeight,
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function homeServerSeo(
        string $canonical,
        string $ogImage,
        string $ogImageType,
        ?int $ogImageWidth,
        ?int $ogImageHeight,
    ): array {
        $title = self::SITE_NAME.' — Kasir Modern untuk Usaha Anda';
        $description = 'Kasir modern untuk UMKM — kelola produk, stok, transaksi POS, shift kasir, dan laporan dalam satu aplikasi web.';

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og_image' => $ogImage,
            'og_image_type' => $ogImageType,
            'og_image_width' => $ogImageWidth,
            'og_image_height' => $ogImageHeight,
            'og_type' => 'website',
            'json_ld' => self::encodeJsonLd(self::homeJsonLd($canonical, $ogImage, $description)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function panduanServerSeo(
        string $canonical,
        string $ogImage,
        string $ogImageType,
        ?int $ogImageWidth,
        ?int $ogImageHeight,
    ): array {
        $title = 'Panduan pengguna — '.self::SITE_NAME;
        $description = 'Panduan fitur UMKM Kasir: POS, stok, laporan, langganan, peran pengguna, dan multi-outlet.';

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og_image' => $ogImage,
            'og_image_type' => $ogImageType,
            'og_image_width' => $ogImageWidth,
            'og_image_height' => $ogImageHeight,
            'og_type' => 'article',
            'json_ld' => self::encodeJsonLd(self::panduanJsonLd($canonical, $ogImage, $title, $description)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function privacyServerSeo(
        string $canonical,
        string $ogImage,
        string $ogImageType,
        ?int $ogImageWidth,
        ?int $ogImageHeight,
    ): array {
        $title = 'Kebijakan Privasi — '.self::SITE_NAME;
        $description = 'Kebijakan privasi UMKM Kasir: data yang dikumpulkan, penggunaan, keamanan, dan hak pengguna.';

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og_image' => $ogImage,
            'og_image_type' => $ogImageType,
            'og_image_width' => $ogImageWidth,
            'og_image_height' => $ogImageHeight,
            'og_type' => 'article',
            'json_ld' => self::encodeJsonLd(self::privacyJsonLd($canonical, $title, $description)),
        ];
    }

    /**
     * JSON-LD beranda: WebSite + Organization + WebApplication (bukan Event).
     *
     * @return array<string, mixed>
     */
    private static function homeJsonLd(string $canonical, string $ogImage, string $description): array
    {
        $siteRoot = rtrim(url('/'), '/');
        $orgId = $siteRoot.'/#organization';
        $websiteId = $siteRoot.'/#website';
        $appId = $siteRoot.'/#software';

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                self::organizationNode($orgId, $siteRoot, $ogImage),
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'name' => self::SITE_NAME,
                    'url' => $siteRoot,
                    'description' => $description,
                    'inLanguage' => 'id-ID',
                    'publisher' => ['@id' => $orgId],
                ],
                [
                    '@type' => ['SoftwareApplication', 'WebApplication'],
                    '@id' => $appId,
                    'name' => self::SITE_NAME,
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web browser',
                    'browserRequirements' => 'Requires JavaScript. Requires HTML5.',
                    'description' => $description,
                    'url' => $siteRoot,
                    'image' => $ogImage,
                    'inLanguage' => 'id-ID',
                    'provider' => ['@id' => $orgId],
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '0',
                        'priceCurrency' => 'IDR',
                        'url' => url('/register'),
                        'availability' => 'https://schema.org/InStock',
                        'description' => 'Coba gratis; paket berlangganan tersedia di dalam aplikasi.',
                    ],
                ],
                self::faqPageNode($siteRoot),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function panduanJsonLd(
        string $canonical,
        string $ogImage,
        string $title,
        string $description,
    ): array {
        $siteRoot = rtrim(url('/'), '/');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
            'inLanguage' => 'id-ID',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::SITE_NAME,
                'url' => $siteRoot,
            ],
            'publisher' => self::organizationNode($siteRoot.'/#organization', $siteRoot, $ogImage),
            'primaryImageOfPage' => [
                '@type' => 'ImageObject',
                'url' => $ogImage,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function privacyJsonLd(string $canonical, string $title, string $description): array
    {
        $siteRoot = rtrim(url('/'), '/');
        $ogImage = self::openGraphImage()['url'];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
            'inLanguage' => 'id-ID',
            'lastReviewed' => '2026-03-25',
            'about' => [
                '@type' => 'Thing',
                'name' => 'Kebijakan Privasi',
            ],
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::SITE_NAME,
                'url' => $siteRoot,
            ],
            'publisher' => self::organizationNode($siteRoot.'/#organization', $siteRoot, $ogImage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizationNode(string $id, string $siteRoot, string $logoUrl): array
    {
        return [
            '@type' => 'Organization',
            '@id' => $id,
            'name' => self::SITE_NAME,
            'url' => $siteRoot,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function faqPageNode(string $siteRoot): array
    {
        $mainEntity = [];

        foreach (self::faqItems() as $item) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ];
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $siteRoot.'/#faq',
            'mainEntity' => $mainEntity,
        ];
    }

    /**
     * Sinkron dengan resources/js/components/landing/LandingFaq.vue
     *
     * @return list<array{question: string, answer: string}>
     */
    private static function faqItems(): array
    {
        return [
            [
                'question' => 'Apakah data tersimpan aman?',
                'answer' => 'Data Anda dikelola di infrastruktur dengan praktik keamanan standar aplikasi web: komunikasi terenkripsi (HTTPS), pembatasan akses per akun, dan cadangan rutin sesuai kebijakan server. Kami mendorong Anda memakai sandi kuat, menonaktifkan akun yang tidak dipakai, dan membatasi peran kasir agar hanya melihat yang dibutuhkan.',
            ],
            [
                'question' => 'Bisa dipakai beberapa kasir sekaligus?',
                'answer' => 'Ya. Beberapa pengguna dapat login pada waktu bersamaan di perangkat berbeda, asalkan jaringan stabil. Setiap transaksi dan mutasi stok tercatat dengan timestamp dan identitas kasir sehingga audit lebih mudah.',
            ],
            [
                'question' => 'Apakah mendukung stok minus, bundel, dan varian?',
                'answer' => 'Varian produk (ukuran, warna, rasa) dan pengaturan stok per SKU umumnya tersedia sesuai konfigurasi toko. Stok minus dapat diizinkan atau diblokir menurut kebijakan Anda. Paket bundel bisa diatur melalui komposisi SKU atau diskon bertingkat.',
            ],
            [
                'question' => 'Bagaimana jika butuh bantuan migrasi dari sistem lama?',
                'answer' => 'Tim dapat membantu merencanakan impor data awal (produk, stok, pelanggan) dari spreadsheet atau export aplikasi lama. Migrasi besar disarankan di luar jam puncak penjualan.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function encodeJsonLd(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS,
        );
    }
}
