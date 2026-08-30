<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingNav from '@/components/landing/LandingNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps<{
    maskotUrl?: string;
    canonicalUrl?: string;
    ogImageUrl?: string;
    ogImageType?: string;
    ogImageWidth?: number | null;
    ogImageHeight?: number | null;
}>();

const pageTitle = 'Syarat & Ketentuan — UMKM Kasir';
const pageDescription =
    'Syarat dan ketentuan penggunaan layanan UMKM Kasir — aturan penggunaan aplikasi kasir untuk UMKM.';

const maskotUrl = props.maskotUrl ?? '/logo-macca.png';
const canonicalUrl = props.canonicalUrl ?? '';

const shareImageUrl = computed(() => props.ogImageUrl ?? maskotUrl);
const shareImageType = computed(() => props.ogImageType ?? 'image/webp');

const lastUpdated = '25 Agustus 2026';

const toc = [
    { id: 'penerimaan', title: 'Penerimaan Ketentuan' },
    { id: 'layanan', title: 'Deskripsi Layanan' },
    { id: 'akun', title: 'Pendaftaran & Akun' },
    { id: 'kewajiban', title: 'Kewajiban Pengguna' },
    { id: 'larangan', title: 'Larangan Penggunaan' },
    { id: 'langganan', title: 'Langganan & Pembayaran' },
    { id: 'hak-kekayaan', title: 'Hak Kekayaan Intelektual' },
    { id: 'pembatasan', title: 'Pembatasan Tanggung Jawab' },
    { id: 'penghentian', title: 'Penghentian Layanan' },
    { id: 'hukum', title: 'Hukum yang Berlaku' },
    { id: 'perubahan', title: 'Perubahan Ketentuan' },
    { id: 'kontak', title: 'Kontak Kami' },
];

/** Aset template Sastik */
const buyerAssetsBase = '/sastik-assets';

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
            <link rel="preload" as="style" :href="`${buyerAssetsBase}/css/bootstrap.min.css`" />
            <link rel="preload" as="style" :href="`${buyerAssetsBase}/css/main.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/bootstrap.min.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/fontawesome.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/main.css`" />
            <link rel="stylesheet" href="/css/landing-custom.css" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/animate.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/swiper.min.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/odometer.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/nice-select.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/custom-fonts.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/magnific-popup.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/jquery-ui.css`" media="print" onload="this.media='all'" />

            <link rel="canonical" :href="canonicalUrl" />
            <meta name="description" :content="pageDescription" />
            <meta name="robots" content="index, follow" />

            <meta property="og:type" content="website" />
            <meta property="og:site_name" content="UMKM Kasir" />
            <meta property="og:locale" content="id_ID" />
            <meta property="og:title" :content="pageTitle" />
            <meta property="og:description" :content="pageDescription" />
            <meta property="og:url" :content="canonicalUrl" />
            <meta property="og:image" :content="shareImageUrl" />
            <meta property="og:image:type" :content="shareImageType" />
            <meta v-if="ogImageWidth && ogImageHeight" property="og:image:width" :content="String(ogImageWidth)" />
            <meta v-if="ogImageWidth && ogImageHeight" property="og:image:height" :content="String(ogImageHeight)" />
            <meta v-if="shareImageUrl.startsWith('https://')" property="og:image:secure_url" :content="shareImageUrl" />
            <meta property="og:image:alt" content="Logo UMKM Kasir — aplikasi kasir untuk UMKM" />

            <meta name="twitter:card" content="summary_large_image" />
            <meta name="twitter:title" :content="pageTitle" />
            <meta name="twitter:description" :content="pageDescription" />
            <meta name="twitter:image" :content="shareImageUrl" />
        </Head>

        <div id="smooth-wrapper">
            <div id="smooth-content" class="lp-panduan-root">
                <LandingNav :maskot-url="maskotUrl" :assets-base="buyerAssetsBase" />

                <section class="lp-panduan-hero gt-hero-3">
                    <div class="hero-circle-shape">
                        <img :src="`${buyerAssetsBase}/img/shape/hero-circle.png`" alt="" />
                    </div>
                    <div class="container">
                        <div class="gt-hero-content text-center">
                            <h1 class="char-animation lp-panduan-hero-title">
                                Syarat & Ketentuan
                                <br />
                                UMKM Kasir
                            </h1>
                            <p class="wow fadeInUp" data-wow-delay=".3s">
                                Terakhir diperbarui: <strong>{{ lastUpdated }}</strong>
                            </p>
                            <p class="wow fadeInUp" data-wow-delay=".4s" style="max-width: 700px; margin: 0 auto;">
                                Syarat dan ketentuan ini berlaku untuk penggunaan seluruh layanan UMKM Kasir.
                                Dengan mengakses atau menggunakan layanan kami, Anda menyetujui ketentuan yang berlaku.
                            </p>
                            <div class="lp-panduan-hero-cta wow fadeInUp" data-wow-delay=".5s">
                                <Link href="/register" class="gt-theme-btn style-3 bg-theme">Buat akun</Link>
                                <Link href="/login" class="gt-theme-btn style-3 bg-border">Masuk</Link>
                            </div>
                            <ul class="wow fadeInUp lp-panduan-hero-list" data-wow-delay=".7s">
                                <li><i class="fa-regular fa-circle-check"></i>Sesuai hukum Indonesia</li>
                                <li><i class="fa-regular fa-circle-check"></i>Transparan & adil</li>
                                <li><i class="fa-regular fa-circle-check"></i>Lindungi hak pengguna</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <section class="lp-panduan-content">
                    <div class="container">
                        <div class="row g-4 lp-panduan-grid">
                            <aside class="col-lg-4">
                                <div class="lp-panduan-toc">
                                    <div class="gt-section-title style-3 mb-3">
                                        <h6 class="tt-capitalize wow fadeInUp">Navigasi</h6>
                                        <h2 class="lp-panduan-toc-title">Lompat ke topik</h2>
                                    </div>
                                    <nav aria-label="Daftar isi syarat dan ketentuan">
                                        <ul class="lp-panduan-toc-list">
                                            <li v-for="(item, idx) in toc" :key="item.id">
                                                <a :href="`#${item.id}`" class="lp-panduan-toc-link">
                                                    <span class="lp-panduan-toc-num">{{ String(idx + 1).padStart(2, '0') }}</span>
                                                    <span>{{ item.title }}</span>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            </aside>

                            <div class="col-lg-8">
                                <div class="lp-panduan-sections">

                                    <article id="penerimaan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">01</span>
                                        <h2 class="lp-panduan-card-title">Penerimaan Ketentuan</h2>
                                        <p class="lp-panduan-card-intro">
                                            Dengan mengakses atau menggunakan layanan UMKM Kasir ("layanan"), Anda menyetujui
                                            untuk terikat oleh Syarat dan Ketentuan ini. Jika Anda tidak menyetujui ketentuan ini,
                                            mohon untuk tidak menggunakan layanan kami.
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Ketentuan ini merupakan perjanjian antara Anda dan UMKM Kasir.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Penggunaan layanan tunduk pada hukum Negara Kesatuan Republik Indonesia.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Kami dapat memperbarui ketentuan ini sewaktu-waktu dengan pemberitahuan yang wajar.</span></li>
                                        </ul>
                                    </article>

                                    <article id="layanan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">02</span>
                                        <h2 class="lp-panduan-card-title">Deskripsi Layanan</h2>
                                        <p class="lp-panduan-card-intro">
                                            UMKM Kasir adalah platform aplikasi kasir berbasis web yang dirancang untuk membantu
                                            usaha kecil dan menengah dalam mengelola penjualan, inventaris, dan laporan keuangan.
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Layanan tersedia dalam beberapa paket: Starter (gratis), Pro, dan paket premium lainnya.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Fitur dapat berubah sewaktu-waktu sesuai pengembangan produk.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Kami berhak memodifikasi, menangguhkan, atau menghentikan layanan dengan pemberitahuan yang wajar.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Ketersediaan layanan tidak dijamin 100% tanpa gangguan (slA berlaku untuk paket tertentu).</span></li>
                                        </ul>
                                    </article>

                                    <article id="akun" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">03</span>
                                        <h2 class="lp-panduan-card-title">Pendaftaran & Akun</h2>
                                        <p class="lp-panduan-card-intro">Untuk menggunakan layanan kami, Anda perlu membuat akun:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Anda wajib memberikan informasi yang akurat dan terkini saat mendaftar.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Anda bertanggung jawab menjaga kerahasiaan kredensial akun Anda.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Anda bertanggung jawab atas seluruh aktivitas yang terjadi di bawah akun Anda.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Segera laporkan kepada kami jika terjadi penggunaan akun tanpa otorisasi.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Satu akun hanya boleh digunakan oleh satu entitas bisnis (toko) kecuali dalam paket multi-outlet.</span></li>
                                        </ul>
                                    </article>

                                    <article id="kewajiban" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">04</span>
                                        <h2 class="lp-panduan-card-title">Kewajiban Pengguna</h2>
                                        <p class="lp-panduan-card-intro">Sebagai pengguna layanan, Anda wajib:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menggunakan layanan hanya untuk tujuan yang sah dan sesuai hukum.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Tidak melanggar hak kekayaan intelektual pihak lain.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Mematuhi seluruh ketentuan hukum yang berlaku terkait penggunaan data dan transaksi.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menjaga integritas data yang dimasukkan ke dalam sistem.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Bertanggung jawab atas kepatuhan pajak dari transaksi yang dicatat melalui layanan kami.</span></li>
                                        </ul>
                                    </article>

                                    <article id="larangan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">05</span>
                                        <h2 class="lp-panduan-card-title">Larangan Penggunaan</h2>
                                        <p class="lp-panduan-card-intro">Anda dilarang untuk:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menggunakan layanan untuk aktivitas ilegal, penipuan, atau pencucian uang.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Merusak, melemahkan, atau mengganggu infrastruktur atau keamanan layanan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Mencoba mengakses data pengguna lain tanpa otorisasi.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menggunakan bot, scraper, atau cara otomatis lain untuk mengakses layanan tanpa izin tertulis.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menggunakan layanan untuk menyebarkan malware atau konten berbahaya.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Menjual, menyewakan, atau mentransfer akun kepada pihak lain tanpa izin kami.</span></li>
                                        </ul>
                                    </article>

                                    <article id="langganan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">06</span>
                                        <h2 class="lp-panduan-card-title">Langganan & Pembayaran</h2>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Paket gratis:</strong> paket Starter tersedia gratis selamanya dengan fitur dasar.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Paket berbayar:</strong> langganan paket premium dikenakan biaya sesuai harga yang tertera di halaman harga.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Perpanjangan otomatis:</strong> langganan akan diperpanjang otomatis kecuali dibatalkan sebelum masa aktif berakhir.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Pembatalan:</strong> Anda dapat membatalkan langganan kapan saja melalui halaman pengaturan atau dengan menghubungi kami.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kebijakan refund:</strong> untuk detail pengembalian dana, lihat <Link href="/refund" style="color:#6366f1;font-weight:600;">Kebijakan Refund</Link> kami.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Perubahan harga:</strong> kami dapat mengubah harga langganan dengan pemberitahuan minimal 30 hari sebelum berlaku.</span></li>
                                        </ul>
                                    </article>

                                    <article id="hak-kekayaan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">07</span>
                                        <h2 class="lp-panduan-card-title">Hak Kekayaan Intelektual</h2>
                                        <p class="lp-panduan-card-intro">
                                            Seluruh konten, kode sumber, desain, logo, dan materi lainnya dalam layanan ini adalah
                                            milik UMKM Kasir atau pemberi lisensinya dan dilindungi oleh hukum hak cipta Indonesia dan internasional.
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Anda tidak memiliki hak atas kekayaan intelektual kami kecuali diberikan lisensi secara eksplisit.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Data yang Anda masukkan ke layanan tetap menjadi milik Anda. Kami tidak mengklaim kepemilikan atas data Anda.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Anda memberikan kami lisensi terbatas untuk memproses data Anda sejauh yang diperlukan untuk menyediakan layanan.</span></li>
                                        </ul>
                                    </article>

                                    <article id="pembatasan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">08</span>
                                        <h2 class="lp-panduan-card-title">Pembatasan Tanggung Jawab</h2>
                                        <p class="lp-panduan-card-intro">
                                            Sejauh diizinkan oleh hukum yang berlaku, UMKM Kasir tidak bertanggung jawab atas:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Kerugian tidak langsung, insidental, atau konsekuensial yang timbul dari penggunaan layanan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Kehilangan data atau profit akibat gangguan layanan atau kesalahan pengguna.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Kerugian yang melebihi jumlah yang dibayarkan pengguna untuk layanan dalam 12 bulan terakhir.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Ketidakakuratan data keuangan yang dihasilkan dari input yang tidak benar oleh pengguna.</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Kami berkomitmen untuk menyediakan layanan terbaik, namun tidak dapat menjamin
                                            layanan akan selalu tanpa gangguan atau error.
                                        </p>
                                    </article>

                                    <article id="penghentian" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">09</span>
                                        <h2 class="lp-panduan-card-title">Penghentian Layanan</h2>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Oleh pengguna:</strong> Anda dapat menghentikan penggunaan layanan dan menghapus akun kapan saja.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Oleh kami:</strong> kami dapat menangguhkan atau menghapus akun Anda jika melanggar ketentuan ini.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Ekspor data:</strong> sebelum penghentian, Anda dapat mengekspor data Anda dalam format CSV/Excel.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Penghapusan data:</strong> setelah penghentian, data akan dihapus sesuai kebijakan retensi data kami.</span></li>
                                        </ul>
                                    </article>

                                    <article id="hukum" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">10</span>
                                        <h2 class="lp-panduan-card-title">Hukum yang Berlaku</h2>
                                        <p class="lp-panduan-card-intro">
                                            Ketentuan ini tunduk pada dan ditafsirkan sesuai hukum Negara Kesatuan Republik Indonesia.
                                            Setiap sengketa yang timbul dari ketentuan ini akan diselesaikan melalui musyawarah
                                            untuk mufakat. Jika tidak tercapai kesepakatan, sengketa akan diselesaikan di pengadilan
                                            yang berwenang di wilayah yurisdksi UMKM Kasir.
                                        </p>
                                    </article>

                                    <article id="perubahan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">11</span>
                                        <h2 class="lp-panduan-card-title">Perubahan Ketentuan</h2>
                                        <p class="lp-panduan-card-intro">
                                            Kami berhak memperbarui Syarat dan Ketentuan ini sewaktu-waktu. Perubahan signifikan
                                            akan diberitahukan melalui email atau pemberitahuan di dalam aplikasi minimal 30 hari
                                            sebelum berlaku efektif. Penggunaan layanan yang berlanjut setelah perubahan berlaku
                                            merupakan penerimaan Anda terhadap ketentuan yang diperbarui.
                                        </p>
                                    </article>

                                    <article id="kontak" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">12</span>
                                        <h2 class="lp-panduan-card-title">Kontak Kami</h2>
                                        <p class="lp-panduan-card-intro">
                                            Jika Anda memiliki pertanyaan terkait Syarat dan Ketentuan ini, silakan hubungi kami:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-regular fa-envelope"></i><span><strong>Email:</strong> <a href="mailto:support@umkmkasir.com">support@umkmkasir.com</a></span></li>
                                            <li><i class="fa-brands fa-whatsapp"></i><span><strong>WhatsApp:</strong> <a href="https://wa.me/6285242766676" target="_blank" rel="noopener noreferrer">+62 852-4276-6676</a></span></li>
                                            <li><i class="fa-regular fa-clock"></i><span><strong>Jam operasional:</strong> Senin–Jumat, 08.00–17.00 WIB</span></li>
                                        </ul>
                                    </article>
                                </div>

                                <div class="lp-panduan-cta text-center">
                                    <h2 class="lp-panduan-cta-title">Ada pertanyaan?</h2>
                                    <p class="lp-panduan-cta-text">
                                        Jika ada yang kurang jelas tentang syarat dan ketentuan kami, jangan ragu untuk menghubungi tim.
                                    </p>
                                    <div class="lp-panduan-cta-actions">
                                        <Link href="/register" class="gt-theme-btn style-3 bg-theme">Buat akun gratis</Link>
                                        <Link href="/" class="gt-theme-btn style-3 bg-border">&larr; Kembali ke beranda</Link>
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
.lp-panduan-root { background: var(--lp-surface, #f8fafc); }
.lp-panduan-hero { padding: 80px 0 70px !important; margin-top: 4rem !important; color: var(--gt-white, #ffffff); overflow: hidden; position: relative; isolation: isolate; background: #7c3aed !important; }
.lp-panduan-hero::before, .lp-panduan-hero::after { display: none !important; }
.lp-panduan-hero .hero-circle-shape { display: none !important; }
.lp-panduan-hero .gt-hero-content { position: relative; z-index: 1; max-width: 880px; margin: 0 auto; }
.lp-panduan-hero .gt-hero-content h1, .lp-panduan-hero .lp-panduan-hero-title { color: var(--gt-white, #ffffff) !important; margin-bottom: 18px; font-size: clamp(2rem, 4vw, 3.25rem); line-height: 1.15; font-weight: 800; font-family: 'Plus Jakarta Sans', sans-serif; }
.lp-panduan-hero .gt-hero-content p { color: rgba(255,255,255,0.88) !important; max-width: 720px; margin-left: auto; margin-right: auto; font-size: 1.05rem; line-height: 1.7; font-weight: 500; }
.lp-panduan-hero-cta { margin-top: 1.75rem; display: flex; justify-content: center; flex-wrap: wrap; gap: 0.75rem; }
.lp-panduan-hero-cta .gt-theme-btn { display: inline-flex; align-items: center; padding: 0.95rem 1.9rem; border-radius: 999px; font-weight: 700; font-size: 0.95rem; text-decoration: none; transition: transform 0.15s ease, box-shadow 0.2s ease; }
.lp-panduan-hero-cta .gt-theme-btn:hover { transform: translateY(-2px); }
.lp-panduan-hero-cta .gt-theme-btn.bg-theme { background: #ffffff !important; color: #173401 !important; box-shadow: 0 12px 30px -10px rgba(255,255,255,0.45); }
.lp-panduan-hero-cta .gt-theme-btn.bg-theme::before { background-color: #173401 !important; }
.lp-panduan-hero-cta .gt-theme-btn.bg-theme:hover { color: #ffffff !important; }
.lp-panduan-hero-cta .gt-theme-btn.bg-border { background: transparent !important; border: 1.5px solid rgba(255,255,255,0.7) !important; color: #ffffff !important; }
.lp-panduan-hero-cta .gt-theme-btn.bg-border::before { background-color: #ffffff !important; }
.lp-panduan-hero-cta .gt-theme-btn.bg-border:hover { color: #0f172a !important; }
.lp-panduan-hero-list { margin-top: 1.5rem; display: flex; justify-content: center; flex-wrap: wrap; gap: 1.5rem; font-weight: 600; color: #ffffff; }
.lp-panduan-hero-list li { display: inline-flex; align-items: center; gap: 0.5rem; color: #ffffff; }
.lp-panduan-hero-list i { color: #ffffff; }
.lp-panduan-content { padding-top: 2rem; padding-bottom: 5rem; }
.lp-panduan-grid { align-items: flex-start; }
.lp-panduan-toc { position: sticky; top: 120px; padding: 1.75rem; background: #ffffff; border-radius: 1.25rem; border: 1px solid rgba(160,234,113,0.25); box-shadow: 0 14px 40px -20px rgba(160,234,113,0.3); }
.lp-panduan-toc-title { font-size: 1.25rem !important; margin-bottom: 1rem !important; }
.lp-panduan-toc-list { display: flex; flex-direction: column; gap: 0.25rem; list-style: none; padding: 0; margin: 0; }
.lp-panduan-toc-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0.875rem; border-radius: 0.75rem; color: #3e1f00; font-weight: 600; text-decoration: none; transition: background-color 0.15s ease, color 0.15s ease; }
.lp-panduan-toc-link:hover { background: rgba(160,234,113,0.15); color: #A0EA71; }
.lp-panduan-toc-num { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 999px; background: rgba(160,234,113,0.15); color: #173401; font-size: 0.75rem; font-weight: 800; flex-shrink: 0; }
.lp-panduan-sections { display: flex; flex-direction: column; gap: 1.5rem; }
.lp-panduan-card { position: relative; padding: 2rem; background: #ffffff; border-radius: 1.25rem; border: 1px solid rgba(160,234,113,0.15); box-shadow: 0 14px 40px -28px rgba(15,23,42,0.2); }
.lp-panduan-card-num { position: absolute; top: 1.5rem; right: 1.75rem; font-size: 2.75rem; font-weight: 900; color: rgba(160,234,113,0.15); font-family: 'Plus Jakarta Sans', sans-serif; line-height: 1; }
.lp-panduan-card-title { margin: 0 0 0.75rem; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.35rem; font-weight: 800; color: #3e1f00; }
.lp-panduan-card-intro { margin: 0 0 1rem; color: #64748b; font-weight: 500; line-height: 1.7; }
.lp-panduan-bullets { display: flex; flex-direction: column; gap: 0.625rem; padding: 0; margin: 0; list-style: none; }
.lp-panduan-bullets li { display: flex; align-items: flex-start; gap: 0.625rem; color: #0f172a; font-weight: 500; line-height: 1.7; }
.lp-panduan-bullets i { color: #A0EA71; margin-top: 0.35rem; flex-shrink: 0; }
.lp-panduan-footnote { margin: 1rem 0 0; padding: 0.875rem 1rem; background: rgba(249,115,22,0.08); border-radius: 0.75rem; color: #64748b; font-size: 0.85rem; font-weight: 500; line-height: 1.7; }
.lp-panduan-cta { margin-top: 2rem; padding: 2.5rem 1.75rem; background: linear-gradient(135deg, rgba(160,234,113,0.15) 0%, rgba(160,234,113,0.08) 100%); border-radius: 1.5rem; }
.lp-panduan-cta-title { margin: 0 0 0.5rem; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 1.5rem; font-weight: 800; color: #3e1f00; }
.lp-panduan-cta-text { margin: 0 auto 1.5rem; max-width: 32rem; color: #64748b; font-weight: 500; }
.lp-panduan-cta-actions { display: flex; justify-content: center; flex-wrap: wrap; gap: 0.75rem; }
.lp-panduan-cta-actions .gt-theme-btn { display: inline-flex; align-items: center; padding: 0.875rem 1.75rem; border-radius: 999px; font-weight: 700; text-decoration: none; }
.lp-panduan-card ul.lp-panduan-bullets li i.fa-regular.fa-envelope,
.lp-panduan-card ul.lp-panduan-bullets li i.fa-brands.fa-whatsapp,
.lp-panduan-card ul.lp-panduan-bullets li i.fa-regular.fa-clock { color: #A0EA71; margin-top: 0.35rem; flex-shrink: 0; font-size: 1rem; }
.lp-panduan-card ul.lp-panduan-bullets li a { color: #6366f1; font-weight: 600; text-decoration: none; }
.lp-panduan-card ul.lp-panduan-bullets li a:hover { text-decoration: underline; }
@media (max-width: 991px) { .lp-panduan-toc { position: static; } }
</style>
