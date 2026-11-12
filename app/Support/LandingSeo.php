<?php

namespace App\Support;

final class LandingSeo
{
    private const SITE_NAME = 'Kasir UMKM by MaccaPOS';

    /** Judul & deskripsi beranda (juga dipakai Landing/Index.vue lewat props agar selalu sama). */
    public const HOME_TITLE = 'Aplikasi Kasir Online Gratis untuk UMKM | Kasir UMKM by MaccaPOS';

    public const HOME_DESCRIPTION = 'Aplikasi kasir (POS) online untuk UMKM: catat penjualan, kelola stok, cetak struk, shift kasir, dan laporan keuangan dalam satu aplikasi. Mulai gratis dengan paket Starter.';

    /**
     * URL maskot untuk UI (utama WebP + cache-bust jika ada).
     */
    public static function maskotAssetUrl(): string
    {
        // Logo tampil ±150–180px; versi 360px WebP (15 KB) menggantikan PNG
        // 3194px (260 KB). Dibuat oleh scripts/optimize-landing-images.py.
        $small = public_path('logo-macca-sm.webp');
        if (is_file($small)) {
            return asset('logo-macca-sm.webp').'?v='.filemtime($small);
        }

        $path = public_path('logo-macca.png');
        $url = asset('logo-macca.png');

        return is_file($path) ? $url.'?v='.filemtime($path) : $url;
    }

    /**
     * File JS/CSS template landing (public/sastik-assets) yang dimuat Index.vue.
     * Dipakai untuk versi cache-busting: begitu salah satu berubah saat deploy,
     * browser mengambil ulang semuanya (mencegah main.js lama tercampur
     * dengan Index.vue baru).
     */
    private const LANDING_ASSET_FILES = [
        'sastik-assets/css/landing.min.css',
        'sastik-assets/js/main.js',
        'sastik-assets/js/plugin.js',
        'sastik-assets/js/gsap-core.min.js',
        'sastik-assets/js/lenis.js',
        'sastik-assets/js/jquery.marquee.min.js',
        'sastik-assets/js/bootstrap.bundle.min.js',
        'sastik-assets/js/jquery-3.7.1.min.js',
    ];

    public static function landingAssetVersion(): string
    {
        $parts = [];
        foreach (self::LANDING_ASSET_FILES as $file) {
            $abs = public_path($file);
            $parts[] = is_file($abs) ? $file.':'.filemtime($abs).':'.filesize($abs) : $file.':missing';
        }

        return substr(md5(implode('|', $parts)), 0, 10);
    }

    /**
     * true jika aset landing teroptimasi tersedia di server:
     *  - landing.min.css + font ikon subset (scripts/build-landing-css.mjs, build-landing-icons.py)
     *  - gsap-core.min.js (GSAP core saja, tanpa ScrollTrigger/SplitText dari plugin.js)
     * Jika belum ter-deploy, Index.vue kembali memuat CSS asli & plugin.js
     * sehingga halaman tidak rusak (404 / gsap undefined).
     */
    public static function landingOptimized(): bool
    {
        foreach ([
            'sastik-assets/css/landing.min.css',
            'sastik-assets/fonts/landing/fa-solid-900.woff2',
            'sastik-assets/fonts/landing/fa-regular-400.woff2',
            'sastik-assets/fonts/landing/fa-brands-400.woff2',
            'sastik-assets/js/gsap-core.min.js',
        ] as $file) {
            if (! is_file(public_path($file))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stylesheet render-blocking yang dimuat SERVER di <head> untuk halaman publik.
     *
     * Halaman-halaman ini memasang CSS lewat <Head> Inertia, yang baru berjalan
     * SETELAH Vue merender — akibatnya header/footer sempat tampil tanpa gaya
     * (±3 dtk di 4G). Dengan link di <head>, CSS sudah siap sebelum render.
     * Urutan HARUS sama dengan urutan di <Head> masing-masing halaman (cascade);
     * link yang sama di <Head> tetap ada (file identik hanya diunduh sekali).
     *
     * @return list<string>
     */
    public static function serverStylesheets(string $component): array
    {
        $sastik = '/sastik-assets/css/';

        return match ($component) {
            'Landing/Index' => self::landingOptimized()
                ? ['/sastik-assets/css/landing.min.css?v='.self::landingAssetVersion()]
                : [],
            // Lihat <Head> di resources/js/pages/Landing/Panduan.vue
            'Landing/Panduan' => [
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,700,0..1,0&display=swap',
                $sastik.'bootstrap.min.css',
                $sastik.'fontawesome.css',
                $sastik.'animate.css',
                $sastik.'swiper.min.css',
                $sastik.'odometer.css',
                $sastik.'nice-select.css',
                $sastik.'main.css',
            ],
            // Lihat <Head> di Privacy.vue / Terms.vue / RefundPolicy.vue (CSS lain di sana async).
            'Landing/Privacy', 'Landing/Terms', 'Landing/RefundPolicy' => [
                $sastik.'bootstrap.min.css',
                $sastik.'fontawesome.css',
                $sastik.'main.css',
                '/css/landing-custom.css',
            ],
            default => [],
        };
    }

    /** Lokasi HTML prerender landing (dibuat scripts/prerender-landing.mjs). */
    public static function landingPrerenderPath(): string
    {
        return resource_path('prerender/landing.html');
    }

    /**
     * HTML prerender landing untuk disisipkan ke <div id="app"> (crawler tanpa JS).
     * null bila file belum ada/kosong -> blade memakai @inertia biasa.
     */
    public static function landingPrerenderHtml(): ?string
    {
        $path = self::landingPrerenderPath();
        if (! is_file($path)) {
            return null;
        }
        $html = trim((string) file_get_contents($path));

        return $html !== '' ? $html : null;
    }

    /**
     * Gambar untuk og:image (WhatsApp, Facebook, Telegram, dll.).
     *
     * @return array{url: string, mime: string, width: ?int, height: ?int}
     */
    public static function openGraphImage(): array
    {
        $candidates = [
            // Gambar pratinjau sosial 1200x630 (rasio standar og:image / twitter summary_large_image).
            ['path' => public_path('og-share.jpg'), 'asset' => 'og-share.jpg', 'mime' => 'image/jpeg'],
            ['path' => public_path('og-share.png'), 'asset' => 'og-share.png', 'mime' => 'image/png'],
            ['path' => public_path('logo.png'), 'asset' => 'logo.png', 'mime' => 'image/png'],
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
        $title = self::HOME_TITLE;
        $description = self::HOME_DESCRIPTION;

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
        $description = 'Panduan fitur Kasir UMKM by MaccaPOS: POS, stok, laporan, langganan, peran pengguna, dan multi-outlet.';

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
        $description = 'Kebijakan privasi Kasir UMKM by MaccaPOS: data yang dikumpulkan, penggunaan, keamanan, dan hak pengguna.';

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
                self::organizationNode($orgId, $siteRoot, self::logoUrl()),
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
                        [
                            '@type' => 'Offer',
                            'name' => 'Paket Starter',
                            'price' => '0',
                            'priceCurrency' => 'IDR',
                            'url' => url('/register'),
                            'availability' => 'https://schema.org/InStock',
                            'description' => 'Gratis selamanya untuk coba dan toko kecil.',
                        ],
                        [
                            '@type' => 'Offer',
                            'name' => 'Paket GrowUp',
                            'price' => '99000',
                            'priceCurrency' => 'IDR',
                            'url' => url('/register'),
                            'availability' => 'https://schema.org/InStock',
                            'description' => 'Langganan bulanan untuk operasional penuh, laporan mendalam, dan multi-outlet.',
                        ],
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
            'publisher' => self::organizationNode($siteRoot.'/#organization', $siteRoot, self::logoUrl()),
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
            'publisher' => self::organizationNode($siteRoot.'/#organization', $siteRoot, self::logoUrl()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /** Logo persegi untuk data terstruktur Organization (Google: min. 112x112). */
    private static function logoUrl(): string
    {
        $path = public_path('logo.png');

        return asset('logo.png').(is_file($path) ? '?v='.filemtime($path) : '');
    }

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
                'question' => 'Apakah Kasir UMKM by MaccaPOS benar-benar gratis?',
                'answer' => 'Ya. Paket Starter gratis selamanya dengan transaksi tanpa batas, untuk 1 outlet dengan hingga 30 produk dan 50 pelanggan. Fitur lanjutan seperti shift kasir, pembelian, diskon, dan multi-outlet tersedia di paket GrowUp seharga Rp99 ribu per bulan.',
            ],
            [
                'question' => 'Bisa dipakai di HP Android, tablet, atau laptop?',
                'answer' => 'Bisa. Kasir UMKM by MaccaPOS berjalan di browser HP, tablet, maupun laptop, dan tersedia juga aplikasi Android di Google Play. Data tersinkron otomatis di semua perangkat.',
            ],
            [
                'question' => 'Metode pembayaran apa saja yang bisa dicatat?',
                'answer' => 'Transaksi bisa dibayar tunai, QRIS, transfer bank, atau kombinasi beberapa metode sekaligus. Struk bisa dicetak ke printer thermal atau printer Bluetooth.',
            ],
            [
                'question' => 'Bisa dipakai beberapa kasir sekaligus?',
                'answer' => 'Ya. Beberapa pengguna dapat login pada waktu bersamaan di perangkat berbeda. Setiap transaksi tercatat dengan waktu dan identitas kasir, dan hak akses kasir, manajer, serta owner bisa dibedakan.',
            ],
            [
                'question' => 'Apakah mendukung varian produk dan stok?',
                'answer' => 'Ya. Varian produk (ukuran, warna, rasa) dan stok per SKU tersedia. Stok berkurang otomatis setiap ada penjualan, dan stok minus dapat diizinkan atau diblokir.',
            ],
            [
                'question' => 'Apakah data saya aman?',
                'answer' => 'Data Anda tersimpan di server dengan komunikasi terenkripsi (HTTPS), pembatasan akses per akun, dan backup rutin setiap hari.',
            ],
            [
                'question' => 'Bagaimana pindah dari pencatatan manual atau aplikasi lama?',
                'answer' => 'Produk dan stok bisa diimpor sekaligus dari file Excel. Tim kami juga siap membantu memindahkan data awal dari spreadsheet atau aplikasi lama.',
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
