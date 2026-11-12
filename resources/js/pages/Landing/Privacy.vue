<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingNav from '@/components/landing/LandingNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, provide } from 'vue';

const props = defineProps<{
    maskotUrl?: string;
    canonicalUrl?: string;
    ogImageUrl?: string;
    ogImageType?: string;
    ogImageWidth?: number | null;
    ogImageHeight?: number | null;
}>();

const pageTitle = 'Kebijakan Privasi — Kasir UMKM by MaccaPOS';
const pageDescription =
    'Kebijakan privasi Kasir UMKM by MaccaPOS — bagaimana kami mengumpulkan, menggunakan, dan melindungi data pribadi Anda sesuai standar Google Play dan peraturan perlindungan data Indonesia.';

const maskotUrl = props.maskotUrl ?? '/logo-macca.png';
const canonicalUrl = props.canonicalUrl ?? '';

const shareImageUrl = computed(() => props.ogImageUrl ?? maskotUrl);
const shareImageType = computed(() => props.ogImageType ?? 'image/webp');

provide('landingMaskotUrl', maskotUrl);

/** Aset template Sastik (disalin ke public/sastik-assets). */
const buyerAssetsBase = '/sastik-assets';

/** Urutan skrip template Sastik — jQuery dulu, baru plugin lain. */
const templateScripts: string[] = [
    `${buyerAssetsBase}/js/jquery-3.7.1.min.js`,
    `${buyerAssetsBase}/js/bootstrap.bundle.min.js`,
    `${buyerAssetsBase}/js/swiper.min.js`,
    `${buyerAssetsBase}/js/wow.min.js`,
    `${buyerAssetsBase}/js/appear.js`,
    `${buyerAssetsBase}/js/imagesloaded.pkgd.min.js`,
    `${buyerAssetsBase}/js/isotope.pkgd.min.js`,
    `${buyerAssetsBase}/js/jquery.nice-select.min.js`,
    `${buyerAssetsBase}/js/jquery.marquee.min.js`,
    `${buyerAssetsBase}/js/jquery.magnific-popup.min.js`,
    `${buyerAssetsBase}/js/jqueryui.js`,
    `${buyerAssetsBase}/js/odometer.min.js`,
    `${buyerAssetsBase}/js/parallaxie.js`,
    `${buyerAssetsBase}/js/parallax.min.js`,
    `${buyerAssetsBase}/js/easing.min.js`,
    `${buyerAssetsBase}/js/scrollspy.js`,
    `${buyerAssetsBase}/js/plugin.js`,
    `${buyerAssetsBase}/js/lenis.js`,
    `${buyerAssetsBase}/js/main.js`,
];

const loadedScripts: HTMLScriptElement[] = [];

function loadScriptsSequentially(urls: string[]): Promise<void> {
    return urls.reduce<Promise<void>>((acc, url) => {
        return acc.then(
            () =>
                new Promise<void>((resolve) => {
                    const existing = document.querySelector<HTMLScriptElement>(`script[data-lp-src="${url}"]`);
                    if (existing) {
                        resolve();
                        return;
                    }
                    const s = document.createElement('script');
                    s.src = url;
                    s.async = false;
                    s.defer = true;
                    s.dataset.lpSrc = url;
                    s.onload = () => resolve();
                    s.onerror = () => resolve();
                    document.body.appendChild(s);
                    loadedScripts.push(s);
                }),
        );
    }, Promise.resolve());
}

const lastUpdated = '25 Maret 2026';

const toc = [
    { id: 'pendahuluan', title: 'Pendahuluan' },
    { id: 'data-dikumpulkan', title: 'Data yang Dikumpulkan' },
    { id: 'cara-penggunaan', title: 'Cara Kami Menggunakan Data' },
    { id: 'dasar-hukum', title: 'Dasar Hukum Pemrosesan' },
    { id: 'penyimpanan', title: 'Penyimpanan & Retensi Data' },
    { id: 'berbagi-data', title: 'Berbagi & Pengungkapan Data' },
    { id: 'hak-pengguna', title: 'Hak Pengguna' },
    { id: 'keamanan', title: 'Keamanan Data' },
    { id: 'pihak-ketiga', title: 'Layanan Pihak Ketiga' },
    { id: 'data-anak', title: 'Privasi Anak' },
    { id: 'perubahan', title: 'Perubahan Kebijakan' },
    { id: 'kontak', title: 'Kontak Kami' },
];

onMounted(() => {
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'fintech-saas');
    void loadScriptsSequentially(templateScripts).then(() => {
        if (document.readyState === 'complete') {
            window.dispatchEvent(new Event('load'));
        }
    });
});

onUnmounted(() => {
    document.documentElement.classList.remove('lp-landing');
    document.body.classList.remove('lp-landing', 'fintech-saas');
    loadedScripts.forEach((s) => {
        if (s.parentNode) {
            s.parentNode.removeChild(s);
        }
    });
    loadedScripts.length = 0;
});
</script>

<template>
    <div>
        <Head :title="pageTitle">
            <!-- Preload CSS -->
            <link rel="preload" as="style" :href="`${buyerAssetsBase}/css/bootstrap.min.css`" />
            <link rel="preload" as="style" :href="`${buyerAssetsBase}/css/main.css`" />

            <!-- Load CSS blocking -->
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/bootstrap.min.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/fontawesome.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/main.css`" />

            <!-- Custom CSS -->
            <link rel="stylesheet" href="/css/landing-custom.css" />

            <!-- CSS non-kritis async -->
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/animate.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/swiper.min.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/odometer.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/nice-select.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/magnific-popup.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/jquery-ui.css`" media="print" onload="this.media='all'" />

            <link rel="canonical" :href="canonicalUrl" />
            <meta name="description" :content="pageDescription" />
            <meta name="robots" content="index, follow" />

            <meta property="og:type" content="website" />
            <meta property="og:site_name" content="Kasir UMKM by MaccaPOS" />
            <meta property="og:locale" content="id_ID" />
            <meta property="og:title" :content="pageTitle" />
            <meta property="og:description" :content="pageDescription" />
            <meta property="og:url" :content="canonicalUrl" />
            <meta property="og:image" :content="shareImageUrl" />
            <meta property="og:image:type" :content="shareImageType" />
            <meta
                v-if="ogImageWidth && ogImageHeight"
                property="og:image:width"
                :content="String(ogImageWidth)"
            />
            <meta
                v-if="ogImageWidth && ogImageHeight"
                property="og:image:height"
                :content="String(ogImageHeight)"
            />
            <meta
                v-if="shareImageUrl.startsWith('https://')"
                property="og:image:secure_url"
                :content="shareImageUrl"
            />
            <meta property="og:image:alt" content="Logo Kasir UMKM by MaccaPOS — aplikasi kasir untuk UMKM" />

            <meta name="twitter:card" content="summary_large_image" />
            <meta name="twitter:title" :content="pageTitle" />
            <meta name="twitter:description" :content="pageDescription" />
            <meta name="twitter:image" :content="shareImageUrl" />
        </Head>

        <div id="smooth-wrapper">
            <div id="smooth-content" class="lp-panduan-root">
                <LandingNav :maskot-url="maskotUrl" />

                <!-- Hero ala landing (gradient gelap) -->
                <section class="lp-panduan-hero gt-hero-3">
                    <div class="hero-circle-shape">
                        <img :src="`${buyerAssetsBase}/img/shape/hero-circle.png`" alt="" />
                    </div>
                    <div class="container">
                        <div class="gt-hero-content text-center">
                            <h1 class="char-animation lp-panduan-hero-title">
                                Kebijakan Privasi
                                <br />
                                Kasir UMKM by MaccaPOS
                            </h1>
                            <p class="wow fadeInUp" data-wow-delay=".3s">
                                Terakhir diperbarui: <strong>{{ lastUpdated }}</strong>
                            </p>
                            <p class="wow fadeInUp" data-wow-delay=".4s" style="max-width: 700px; margin: 0 auto;">
                                Kami menghormati privasi Anda. Kebijakan ini menjelaskan bagaimana kami mengumpulkan,
                                menggunakan, menyimpan, dan melindungi data pribadi Anda saat menggunakan aplikasi
                                Kasir UMKM by MaccaPOS, sesuai dengan Undang-Undang Perlindungan Data Pribadi Indonesia (UU PDP)
                                dan standar Google Play.
                            </p>
                            <div class="lp-panduan-hero-cta wow fadeInUp" data-wow-delay=".5s">
                                <Link href="/register" class="gt-theme-btn style-3 bg-theme">
                                    Buat akun
                                </Link>
                                <Link href="/login" class="gt-theme-btn style-3 bg-border">
                                    Masuk
                                </Link>
                            </div>
                            <ul class="wow fadeInUp lp-panduan-hero-list" data-wow-delay=".7s">
                                <li>
                                    <i class="fa-regular fa-circle-check"></i>
                                    Lindungi data Anda
                                </li>
                                <li>
                                    <i class="fa-regular fa-circle-check"></i>
                                    Transparan & sesuai UU PDP
                                </li>
                                <li>
                                    <i class="fa-regular fa-circle-check"></i>
                                    Standar keamanan Google Play
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Content Section — layout sama persis dengan panduan -->
                <section class="lp-panduan-content section-padding pt-0">
                    <div class="container">
                        <div class="row g-4 lp-panduan-grid">
                            <!-- TOC sidebar -->
                            <aside class="col-lg-4">
                                <div class="lp-panduan-toc">
                                    <div class="gt-section-title style-3 mb-3">
                                        <h6 class="tt-capitalize wow fadeInUp">Navigasi</h6>
                                        <h2 class="lp-panduan-toc-title">Lompat ke topik</h2>
                                    </div>
                                    <nav aria-label="Daftar isi kebijakan privasi">
                                        <ul class="lp-panduan-toc-list">
                                            <li v-for="(item, idx) in toc" :key="item.id">
                                                <a :href="`#${item.id}`" class="lp-panduan-toc-link">
                                                    <span class="lp-panduan-toc-num">
                                                        {{ String(idx + 1).padStart(2, '0') }}
                                                    </span>
                                                    <span>{{ item.title }}</span>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </aside>

                            <!-- Section konten -->
                            <div class="col-lg-8">
                                <div class="lp-panduan-sections">

                                    <!-- 1. Pendahuluan -->
                                    <article id="pendahuluan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">01</span>
                                        <h2 class="lp-panduan-card-title">Pendahuluan</h2>
                                        <p class="lp-panduan-card-intro">
                                            Selamat datang di Kasir UMKM by MaccaPOS ("kami", "kita", atau "aplikasi"). Kebijakan Privasi
                                            ini dirancang untuk membantu Anda memahami bagaimana kami mengelola informasi
                                            pribadi yang dikumpulkan dari pengguna aplikasi kasir berbasis web ini.
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li>
                                                <i class="fa-solid fa-circle-check"></i>
                                                <span>Kasir UMKM by MaccaPOS adalah aplikasi kasir web yang dikembangkan oleh RisenAI.</span>
                                            </li>
                                            <li>
                                                <i class="fa-solid fa-circle-check"></i>
                                                <span>Dengan menggunakan Kasir UMKM by MaccaPOS, Anda menyetujui praktik pengumpulan dan penggunaan informasi yang dijelaskan dalam kebijakan ini.</span>
                                            </li>
                                            <li>
                                                <i class="fa-solid fa-circle-check"></i>
                                                <span>Kami berkomitmen melindungi privasi pengguna sesuai UU PDP Indonesia dan standar Google Play.</span>
                                            </li>
                                        </ul>
                                    </article>

                                    <!-- 2. Data yang Dikumpulkan -->
                                    <article id="data-dikumpulkan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">02</span>
                                        <h2 class="lp-panduan-card-title">Data yang Kami Kumpulkan</h2>
                                        <p class="lp-panduan-card-intro">Kami mengumpulkan data berikut untuk menyediakan dan meningkatkan layanan kami:</p>

                                        <div class="lp-panduan-blocks">
                                            <div class="lp-panduan-block">
                                                <h3 class="lp-panduan-block-title">a. Data Akun</h3>
                                                <ul class="lp-panduan-bullets">
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Informasi pendaftaran:</strong> nama lengkap, alamat email, dan nomor telepon.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Informasi toko:</strong> nama toko, alamat, jenis usaha, logo, dan kontak toko.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Kredensial akun:</strong> kata sandi yang dienkripsi (tidak disimpan dalam teks biasa).</span></li>
                                                </ul>
                                            </div>
                                            <div class="lp-panduan-block">
                                                <h3 class="lp-panduan-block-title">b. Data Transaksi & Bisnis</h3>
                                                <ul class="lp-panduan-bullets">
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Data produk:</strong> nama, SKU, harga, stok, kategori, dan deskripsi produk.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Data transaksi:</strong> detail penjualan, pembelian, retur, dan laporan keuangan toko.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Data pelanggan:</strong> nama, kontak, dan riwayat pembelian pelanggan.</span></li>
                                                </ul>
                                            </div>
                                            <div class="lp-panduan-block">
                                                <h3 class="lp-panduan-block-title">c. Data Penggunaan & Teknis</h3>
                                                <ul class="lp-panduan-bullets">
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Log aktivitas:</strong> catatan akses, IP address, jenis peramban, dan waktu akses.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Data sesi:</strong> cookie fungsional untuk menjaga sesi login Anda.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Data perangkat:</strong> jenis perangkat, sistem operasi, dan resolusi layar.</span></li>
                                                </ul>
                                            </div>
                                            <div class="lp-panduan-block">
                                                <h3 class="lp-panduan-block-title">d. Data Pembayaran</h3>
                                                <ul class="lp-panduan-bullets">
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Bukti pembayaran:</strong> gambar bukti transfer untuk konfirmasi langganan.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><strong>Catatan langganan:</strong> riwayat paket, masa aktif, dan status pembayaran.</span></li>
                                                    <li><i class="fa-solid fa-circle-check"></i><span><em>Kami tidak menyimpan data kartu kredit atau rekening bank penuh.</em></span></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </article>

                                    <!-- 3. Cara Kami Menggunakan Data -->
                                    <article id="cara-penggunaan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">03</span>
                                        <h2 class="lp-panduan-card-title">Cara Kami Menggunakan Data</h2>
                                        <p class="lp-panduan-card-intro">Data yang kami kumpulkan digunakan untuk berbagai keperluan berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Menyediakan layanan:</strong> menjalankan fitur POS, stok, laporan, dan modul aplikasi lainnya.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Personalisasi:</strong> menyesuaikan tampilan aplikasi dengan preferensi dan jenis usaha Anda.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Dukungan teknis:</strong> merespons pertanyaan, debug, dan bantuan teknis yang Anda ajukan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Peningkatan layanan:</strong> menganalisis pola penggunaan untuk mengembangkan fitur baru.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Komunikasi:</strong> informasi terkait pembaruan fitur, tagihan, atau perubahan kebijakan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Keamanan:</strong> mendeteksi dan mencegah aktivitas mencurigakan atau pelanggaran ketentuan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kepatuhan hukum:</strong> memenuhi kewajiban hukum yang berlaku di Indonesia.</span></li>
                                        </ul>
                                    </article>

                                    <!-- 4. Dasar Hukum Pemrosesan -->
                                    <article id="dasar-hukum" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">04</span>
                                        <h2 class="lp-panduan-card-title">Dasar Hukum Pemrosesan</h2>
                                        <p class="lp-panduan-card-intro">
                                            Kami memproses data pribadi Anda berdasarkan dasar hukum berikut sesuai UU PDP Indonesia:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Persetujuan (consent):</strong> Anda memberikan persetujuan eksplisit saat mendaftar.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Pelaksanaan kontrak:</strong> pemrosesan diperlukan untuk menyediakan layanan yang Anda minta.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kewajiban hukum:</strong> pemrosesan diperlukan untuk mematuhi peraturan perundang-undangan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kepentingan sah:</strong> untuk keamanan sistem dan pencegahan fraud.</span></li>
                                        </ul>
                                    </article>

                                    <!-- 5. Penyimpanan & Retensi Data -->
                                    <article id="penyimpanan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">05</span>
                                        <h2 class="lp-panduan-card-title">Penyimpanan & Retensi Data</h2>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Lokasi penyimpanan:</strong> Server berlokasi di Indonesia dan/atau wilayah dengan standar perlindungan data setara.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Periode retensi:</strong> Data disimpan selama akun aktif. Setelah dihapus, data dihapus dalam 90 hari, kecuali yang wajib disimpan 5 tahun sesuai UU KUP.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Data cadangan (backup):</strong> Dihapus dalam 30 hari setelah penghapusan utama.</span></li>
                                        </ul>
                                    </article>

                                    <!-- 6. Berbagi Data -->
                                    <article id="berbagi-data" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">06</span>
                                        <h2 class="lp-panduan-card-title">Berbagi & Pengungkapan Data</h2>
                                        <p class="lp-panduan-card-intro">Kami tidak menjual data pribadi Anda kepada pihak mana pun. Kami hanya dapat membagikan data dalam situasi berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Penyedia layanan:</strong> vendor hosting, email, dan payment gateway yang terikat kontrak kerahasiaan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kepatuhan hukum:</strong> jika diwajibkan oleh hukum atau permintaan resmi instansi berwenang Indonesia.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Konsolidasi bisnis:</strong> dalam hal merger, akuisisi, atau penjualan aset — dengan pemberitahuan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Dengan persetujuan Anda:</strong> untuk tujuan lain yang Anda setujui secara eksplisit.</span></li>
                                        </ul>
                                    </article>

                                    <!-- 7. Hak Pengguna -->
                                    <article id="hak-pengguna" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">07</span>
                                        <h2 class="lp-panduan-card-title">Hak Pengguna (Sesuai UU PDP)</h2>
                                        <p class="lp-panduan-card-intro">Sebagai pengguna, Anda memiliki hak-hak berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak akses:</strong> meminta informasi tentang data pribadi yang kami simpan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak perbaikan:</strong> memperbarui data yang tidak akurat melalui halaman profil.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak penghapusan:</strong> meminta penghapusan data dengan batasan hukum tertentu.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak pembatasan:</strong> meminta pembatasan pemrosesan dalam kondisi tertentu.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak portabilitas:</strong> meminta salinan data dalam format CSV/Excel.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak keberatan:</strong> menolak penggunaan data untuk pemasaran langsung.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hak pencabutan persetujuan:</strong> menarik persetujuan kapan saja.</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Untuk menggunakan hak-hak di atas, hubungi kami melalui kontak di bagian bawah halaman ini.
                                        </p>
                                    </article>

                                    <!-- 8. Keamanan Data -->
                                    <article id="keamanan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">08</span>
                                        <h2 class="lp-panduan-card-title">Keamanan Data</h2>
                                        <p class="lp-panduan-card-intro">Kami menerapkan langkah-langkah keamanan untuk melindungi data Anda:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Enkripsi:</strong> SSL/TLS untuk data in-transit; bcrypt untuk kata sandi; enkripsi at-rest untuk data sensitif.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kontrol akses:</strong> akses data dibatasi berdasarkan peran pengguna (kasir, gudang, manajer, admin).</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Audit log:</strong> semua aktivitas penting dicatat untuk audit keamanan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Backup rutin:</strong> data dicadangkan secara berkala untuk mencegah kehilangan data.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Review keamanan:</strong> praktik keamanan ditinjau dan diperbarui secara berkala.</span></li>
                                        </ul>
                                    </article>

                                    <!-- 9. Layanan Pihak Ketiga -->
                                    <article id="pihak-ketiga" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">09</span>
                                        <h2 class="lp-panduan-card-title">Layanan Pihak Ketiga</h2>
                                        <p class="lp-panduan-card-intro">Aplikasi kami menggunakan layanan pihak ketiga berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Hosting & infrastruktur:</strong> penyedia server standar keamanan industri.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Layanan notifikasi email:</strong> untuk mengirim email transaksional.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Analytics:</strong> alat analitik anonim untuk pola penggunaan agregat.</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Setiap pihak ketiga terikat perjanjian pemrosesan data sesuai standar perlindungan data yang berlaku.
                                        </p>
                                    </article>

                                    <!-- 10. Privasi Anak -->
                                    <article id="data-anak" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">10</span>
                                        <h2 class="lp-panduan-card-title">Privasi Anak</h2>
                                        <p class="lp-panduan-card-intro">
                                            Kasir UMKM by MaccaPOS tidak ditujukan untuk anak-anak di bawah usia 18 tahun. Kami tidak
                                            dengan sengaja mengumpulkan data pribadi dari anak-anak. Jika kami mengetahui
                                            hal tersebut terjadi, data akan segera dihapus. Jika Anda orang tua/wali dan
                                            yakin anak Anda telah memberikan data, hubungi kami segera.
                                        </p>
                                    </article>

                                    <!-- 11. Perubahan Kebijakan -->
                                    <article id="perubahan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">11</span>
                                        <h2 class="lp-panduan-card-title">Perubahan Kebijakan Privasi</h2>
                                        <p class="lp-panduan-card-intro">
                                            Kami dapat memperbarui kebijakan ini dari waktu ke waktu. Perubahan signifikan akan diberitahukan melalui:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Pemberitahuan di dalam aplikasi (dashboard atau notifikasi).</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Email ke alamat yang terdaftar (jika perubahan bersifat material).</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Tanggal "Terakhir diperbarui" di hero halaman ini menunjukkan kapan perubahan terakhir dilakukan.
                                        </p>
                                    </article>

                                    <!-- 12. Kontak -->
                                    <article id="kontak" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">12</span>
                                        <h2 class="lp-panduan-card-title">Kontak Kami</h2>
                                        <p class="lp-panduan-card-intro">
                                            Jika Anda memiliki pertanyaan, keluhan, atau ingin menggunakan hak-hak Anda
                                            terkait data pribadi, silakan hubungi kami melalui:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-regular fa-envelope"></i><span><strong>Email:</strong> <a href="mailto:support@umkmkasir.com">support@umkmkasir.com</a></span></li>
                                            <li><i class="fa-brands fa-whatsapp"></i><span><strong>WhatsApp:</strong> <a href="https://wa.me/6285242766676" target="_blank" rel="noopener noreferrer">+62 852-4276-6676</a></span></li>
                                            <li><i class="fa-regular fa-clock"></i><span><strong>Jam operasional:</strong> Senin–Jumat, 08.00–17.00 WIB</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Kami akan merespons permintaan Anda dalam waktu maksimal 2x24 jam kerja.
                                        </p>
                                    </article>
                                </div>

                                <!-- CTA Section -->
                                <div class="lp-panduan-cta text-center">
                                    <h2 class="lp-panduan-cta-title">Punya pertanyaan?</h2>
                                    <p class="lp-panduan-cta-text">
                                        Jika ada yang kurang jelas tentang kebijakan privasi kami, jangan ragu untuk menghubungi tim kami.
                                    </p>
                                    <div class="lp-panduan-cta-actions">
                                        <Link href="/register" class="gt-theme-btn style-3 bg-theme">
                                            Buat akun gratis
                                        </Link>
                                        <Link href="/" class="gt-theme-btn style-3 bg-border">
                                            ← Kembali ke beranda
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <LandingFooter :assets-base="buyerAssetsBase" />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ===== Privacy Policy — seluruh styling reused dari Panduan ===== */

.lp-panduan-root {
    background: var(--lp-surface, #f8fafc);
}

/* Hero */
.lp-panduan-hero {
    padding: 80px 0 70px !important;
    margin-top: 4rem !important;
    color: var(--gt-white, #ffffff);
    overflow: hidden;
    position: relative;
    isolation: isolate;
    background: #7c3aed !important;
}
.lp-panduan-hero::before,
.lp-panduan-hero::after {
    display: none !important;
}
.lp-panduan-hero .hero-circle-shape {
    display: none !important;
}
.lp-panduan-hero .gt-hero-content {
    position: relative;
    z-index: 1;
    max-width: 880px;
    margin: 0 auto;
}
.lp-panduan-hero .gt-hero-content h1,
.lp-panduan-hero .lp-panduan-hero-title {
    color: var(--gt-white, #ffffff) !important;
    margin-bottom: 18px;
    font-size: clamp(2rem, 4vw, 3.25rem);
    line-height: 1.15;
    font-weight: 800;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.lp-panduan-hero .gt-hero-content p {
    color: rgba(255, 255, 255, 0.88) !important;
    max-width: 720px;
    margin-left: auto;
    margin-right: auto;
    font-size: 1.05rem;
    line-height: 1.7;
    font-weight: 500;
}
.lp-panduan-hero-cta {
    margin-top: 1.75rem;
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.lp-panduan-hero-cta .gt-theme-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.95rem 1.9rem;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.95rem;
    text-decoration: none;
    transition: transform 0.15s ease, box-shadow 0.2s ease;
}
.lp-panduan-hero-cta .gt-theme-btn:hover {
    transform: translateY(-2px);
}
.lp-panduan-hero-cta .gt-theme-btn.bg-theme {
    background: #ffffff !important;
    color: #173401 !important;
    box-shadow: 0 12px 30px -10px rgba(255, 255, 255, 0.45);
}
.lp-panduan-hero-cta .gt-theme-btn.bg-theme::before {
    background-color: #173401 !important;
}
.lp-panduan-hero-cta .gt-theme-btn.bg-theme:hover {
    color: #ffffff !important;
}
.lp-panduan-hero-cta .gt-theme-btn.bg-border {
    background: transparent !important;
    border: 1.5px solid rgba(255, 255, 255, 0.7) !important;
    color: #ffffff !important;
}
.lp-panduan-hero-cta .gt-theme-btn.bg-border::before {
    background-color: #ffffff !important;
}
.lp-panduan-hero-cta .gt-theme-btn.bg-border:hover {
    color: #0f172a !important;
}
.lp-panduan-hero-list {
    margin-top: 1.5rem;
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 1.5rem;
    font-weight: 600;
    color: #ffffff;
}
.lp-panduan-hero-list li {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #ffffff;
}
.lp-panduan-hero-list i {
    color: #ffffff;
}

/* Content area */
.lp-panduan-content {
    padding-top: 2rem;
    padding-bottom: 5rem;
}
.lp-panduan-grid {
    align-items: flex-start;
}

/* TOC sidebar */
.lp-panduan-toc {
    position: sticky;
    top: 120px;
    padding: 1.75rem;
    background: #ffffff;
    border-radius: 1.25rem;
    border: 1px solid rgba(160, 234, 113, 0.25);
    box-shadow: 0 14px 40px -20px rgba(160, 234, 113, 0.3);
}
.lp-panduan-toc-title {
    font-size: 1.25rem !important;
    margin-bottom: 1rem !important;
}
.lp-panduan-toc-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    list-style: none;
    padding: 0;
    margin: 0;
}
.lp-panduan-toc-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 0.875rem;
    border-radius: 0.75rem;
    color: #3e1f00;
    font-weight: 600;
    text-decoration: none;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.lp-panduan-toc-link:hover {
    background: rgba(160, 234, 113, 0.15);
    color: #A0EA71;
}
.lp-panduan-toc-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: rgba(160, 234, 113, 0.15);
    color: #173401;
    font-size: 0.75rem;
    font-weight: 800;
    flex-shrink: 0;
}

/* Cards */
.lp-panduan-sections {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.lp-panduan-card {
    position: relative;
    padding: 2rem;
    background: #ffffff;
    border-radius: 1.25rem;
    border: 1px solid rgba(160, 234, 113, 0.15);
    box-shadow: 0 14px 40px -28px rgba(15, 23, 42, 0.2);
}
.lp-panduan-card-num {
    position: absolute;
    top: 1.5rem;
    right: 1.75rem;
    font-size: 2.75rem;
    font-weight: 900;
    color: rgba(160, 234, 113, 0.15);
    font-family: 'Plus Jakarta Sans', sans-serif;
    line-height: 1;
}
.lp-panduan-card-title {
    margin: 0 0 0.75rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 1.35rem;
    font-weight: 800;
    color: #3e1f00;
}
.lp-panduan-card-intro {
    margin: 0 0 1rem;
    color: #64748b;
    font-weight: 500;
    line-height: 1.7;
}

/* Bullets */
.lp-panduan-bullets {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 0;
    margin: 0;
    list-style: none;
}
.lp-panduan-bullets li {
    display: flex;
    align-items: flex-start;
    gap: 0.625rem;
    color: #0f172a;
    font-weight: 500;
    line-height: 1.7;
}
.lp-panduan-bullets i {
    color: #A0EA71;
    margin-top: 0.35rem;
    flex-shrink: 0;
}

/* Blocks */
.lp-panduan-blocks {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    margin-top: 1.25rem;
}
.lp-panduan-block {
    padding: 1.25rem;
    background: #f8fafc;
    border-radius: 1rem;
    border-left: 3px solid #7c3aed;
}
.lp-panduan-block-title {
    margin: 0 0 0.5rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: #173401;
}

/* Footnote */
.lp-panduan-footnote {
    margin: 1rem 0 0;
    padding: 0.875rem 1rem;
    background: rgba(249, 115, 22, 0.08);
    border-radius: 0.75rem;
    color: #64748b;
    font-size: 0.85rem;
    font-weight: 500;
    line-height: 1.7;
}

/* CTA */
.lp-panduan-cta {
    margin-top: 2rem;
    padding: 2.5rem 1.75rem;
    background: linear-gradient(135deg, rgba(160, 234, 113, 0.15) 0%, rgba(160, 234, 113, 0.08) 100%);
    border-radius: 1.5rem;
}
.lp-panduan-cta-title {
    margin: 0 0 0.5rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    color: #3e1f00;
}
.lp-panduan-cta-text {
    margin: 0 auto 1.5rem;
    max-width: 32rem;
    color: #64748b;
    font-weight: 500;
}
.lp-panduan-cta-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.lp-panduan-cta-actions .gt-theme-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.875rem 1.75rem;
    border-radius: 999px;
    font-weight: 700;
    text-decoration: none;
}

/* Overrides untuk icons kontak */
.lp-panduan-card ul.lp-panduan-bullets li i.fa-regular.fa-envelope,
.lp-panduan-card ul.lp-panduan-bullets li i.fa-brands.fa-whatsapp,
.lp-panduan-card ul.lp-panduan-bullets li i.fa-regular.fa-clock {
    color: #A0EA71;
    margin-top: 0.35rem;
    flex-shrink: 0;
    font-size: 1rem;
}
.lp-panduan-card ul.lp-panduan-bullets li a {
    color: #6366f1;
    font-weight: 600;
    text-decoration: none;
}
.lp-panduan-card ul.lp-panduan-bullets li a:hover {
    text-decoration: underline;
}

@media (max-width: 991px) {
    .lp-panduan-toc {
        position: static;
    }
}
</style>
