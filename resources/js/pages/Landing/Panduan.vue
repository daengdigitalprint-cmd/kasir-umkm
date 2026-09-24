<script setup lang="ts">
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingNav from '@/components/landing/LandingNav.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, provide } from 'vue';

type Block = { title: string; items: string[] };

type Section = {
    id: string;
    title: string;
    intro?: string;
    bullets?: string[];
    blocks?: Block[];
    footnote?: string;
};

const props = defineProps<{
    maskotUrl?: string;
    canonicalUrl?: string;
    ogImageUrl?: string;
    ogImageType?: string;
    ogImageWidth?: number | null;
    ogImageHeight?: number | null;
}>();

const pageTitle = 'Panduan pengguna — UMKM Kasir';
const pageDescription =
    'Panduan fitur UMKM Kasir: POS, stok, laporan, langganan, peran pengguna, dan multi-outlet.';

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

const toc = [
    { id: 'pengantar', title: 'Pengantar' },
    { id: 'peran-akses', title: 'Peran & akses menu' },
    { id: 'paket', title: 'Paket langganan' },
    { id: 'dashboard', title: 'Dashboard' },
    { id: 'pos', title: 'POS & transaksi' },
    { id: 'shift-dapur', title: 'Shift kasir & dapur' },
    { id: 'produk', title: 'Produk & inventori' },
    { id: 'stok-beli', title: 'Stok, transfer & pembelian' },
    { id: 'preorder-retur', title: 'Pre-order & retur' },
    { id: 'diskon-pelanggan', title: 'Diskon & pelanggan' },
    { id: 'laporan', title: 'Laporan & ekspor' },
    { id: 'langganan', title: 'Halaman langganan' },
    { id: 'pengaturan', title: 'Pengaturan profil & toko' },
    { id: 'multi-outlet', title: 'Grup usaha & multi-outlet' },
] as const;

const sections: Section[] = [
    {
        id: 'pengantar',
        title: 'Pengantar',
        intro:
            'UMKM Kasir adalah aplikasi kasir berbasis web untuk satu atau banyak outlet dalam satu grup usaha. Panduan ini merangkum fitur sesuai menu dan alur di aplikasi (POS, inventori, laporan, langganan, dan pengaturan).',
        bullets: [
            'Anda perlu akun terdaftar, toko aktif, dan email terverifikasi untuk memakai fitur di dalam aplikasi.',
            'Beberapa menu disembunyikan menurut peran pengguna (kasir, gudang, manajer/backoffice, admin toko).',
            'Fitur lanjutan seperti diskon tetap, transfer stok antar outlet, dan pembelian mengikuti paket langganan (Starter vs GrowUp).',
        ],
    },
    {
        id: 'peran-akses',
        title: 'Peran & akses menu',
        blocks: [
            {
                title: 'Kasir (POS)',
                items: [
                    'Mengakses POS untuk menjual barang dan mencetak / membuka struk.',
                    'Shift kasir: membuka dan menutup shift dengan saldo awal & akhir.',
                    'Antrian dapur muncul jika tipe toko mendukung modul F&B dan fitur diaktifkan.',
                ],
            },
            {
                title: 'Gudang / inventori',
                items: [
                    'Produk, riwayat stok, transfer stok (GrowUp), pembelian, retur pembelian, dan pre-order.',
                ],
            },
            {
                title: 'Backoffice (manajemen)',
                items: [
                    'Diskon, pelanggan, dan seluruh jenis laporan (ringkasan penjualan, inventori, shift kasir) termasuk unduhan PDF & Excel di mana tersedia.',
                ],
            },
            {
                title: 'Admin toko',
                items: [
                    'Mengelola pengaturan toko (dari halaman profil/pengaturan), anggota tim, pajak struk, identitas struk, serta batas paket dan upgrade langganan.',
                ],
            },
        ],
    },
    {
        id: 'paket',
        title: 'Paket langganan (Starter & GrowUp)',
        blocks: [
            {
                title: 'Starter',
                items: [
                    'Batas produk & pelanggan sesuai definisi paket di database.',
                    'Umumnya satu outlet per grup usaha; multi-outlet penuh setelah upgrade ke GrowUp.',
                    'Fitur “ritel lanjutan” (diskon terkelola, transfer antar outlet, dll.) mengikuti penandaan paket di aplikasi.',
                ],
            },
            {
                title: 'GrowUp',
                items: [
                    'Produk & pelanggan tanpa batas nominal yang dijelaskan di paket.',
                    'Hingga lima outlet per grup usaha (ditegakkan saat membuat toko baru; kapasitas mengikuti paket tertinggi di grup).',
                    'Akses fitur seperti diskon, transfer stok antar outlet, split nota, bundle, pre-order, pembelian & retur, laporan mendalam, dan lainnya sebagaimana diaktifkan di toko Anda.',
                ],
            },
        ],
        footnote:
            'Detail harga dan daftar fitur per paket juga tercantum di halaman penawaran (landing) dan di menu Langganan setelah masuk.',
    },
    {
        id: 'dashboard',
        title: 'Dashboard',
        bullets: [
            'Ringkasan penjualan hari ini dan jumlah transaksi selesai.',
            'Daftar transaksi terbaru (dengan pelanggan jika ada).',
            'Peringatan produk di bawah stok minimum (untuk SKU yang dipantau).',
            'Jika aktif, ringkatan batch mendekati kedaluwarsa sesuai pengaturan toko.',
            'Notifikasi pembayaran langganan yang menunggu konfirmasi (jika ada).',
        ],
    },
    {
        id: 'pos',
        title: 'POS (kasir) & transaksi',
        blocks: [
            {
                title: 'Menjual',
                items: [
                    'Mencari produk, menambahkan ke keranjang, menerapkan diskon transaksi bila diizinkan paket.',
                    'Mendukung SKU dengan varian, opsi/modifier (misalnya untuk F&B), dan kuantitas desimal jika produk mengizinkan.',
                    'Mendukung produk paket (bundle) dengan komponen yang mengurangi stok sesuai aturan sistem.',
                    'Pembayaran: tunai dan metode lain yang dikonfigurasi; struk dapat dicetak atau dibuka lewat halaman invoice.',
                ],
            },
            {
                title: 'Setelah transaksi',
                items: [
                    'Invoice / nomor transaksi untuk bukti pembayaran.',
                    'Stok dan riwayat stok diperbarui otomatis untuk barang yang dipantau stoknya.',
                ],
            },
        ],
    },
    {
        id: 'shift-dapur',
        title: 'Shift kasir & antrian dapur',
        blocks: [
            {
                title: 'Shift kasir',
                items: [
                    'Membuka shift dengan saldo awal kas; menutup shift dengan saldo akhir dan ringkasan.',
                    'Laporan khusus shift tersedia di modul laporan untuk analisis per kasir/periode.',
                ],
            },
            {
                title: 'Antrian dapur',
                items: [
                    'Untuk toko dengan modul dapur, pesanan dari POS dapat masuk antrian produksi.',
                    'Status siap / selesai membantu koordinasi dapur–kasir.',
                ],
            },
        ],
    },
    {
        id: 'produk',
        title: 'Produk & inventori',
        blocks: [
            {
                title: 'Data produk',
                items: [
                    'Nama, SKU, kategori, satuan, harga, gambar, barcode (jika digunakan).',
                    'Varian: beberapa kombinasi harga & stok per varian.',
                    'Opsi tambahan (modifier) relevan untuk jenis usaha tertentu.',
                    'Produk paket: menjual satu SKU komposit dengan komponen yang mengonsumsi stok.',
                    'Preset kategori & satuan mengikuti jenis usaha yang dipilih saat pendaftaran / pengaturan toko.',
                ],
            },
            {
                title: 'Stok & batch',
                items: [
                    'Produk dapat dipantau stoknya atau tidak; stok minimum untuk peringatan di dasbor.',
                    'Jika pelacakan batch & tanggal kedaluwarsa diaktifkan di toko, pemasukkan stok dapat memakai batch; penjualan mengikuti aturan FIFO yang diterapkan sistem.',
                    'Riwayat stok memuat penjualan, pembelian, penyesuaian, retur, transfer, dll., dengan filter periode.',
                ],
            },
        ],
    },
    {
        id: 'stok-beli',
        title: 'Transfer stok, pembelian & retur',
        blocks: [
            {
                title: 'Transfer antar outlet',
                items: [
                    'Menyusun transfer dari toko sumber ke toko tujuan dalam grup usaha (memerlukan GrowUp dan izin inventori).',
                    'Pencocokan SKU antar toko membantu menyamakan produk di kedua sisi.',
                    'Status transfer dan detail baris dapat dilacak; stok bergerak sesuai penerimaan.',
                ],
            },
            {
                title: 'Pembelian',
                items: [
                    'Pencatatan pembelian dari pemasok: menambah stok dan jejak di riwayat stok.',
                    'Pemasok dapat ditambahkan saat input dari halaman pembelian.',
                ],
            },
            {
                title: 'Retur pembelian',
                items: [
                    'Mencatat pengembalian ke pemasok terkait pembelian yang ada, menyesuaikan stok dan catatan keuangan inventori.',
                ],
            },
        ],
    },
    {
        id: 'preorder-retur',
        title: 'Pre-order pelanggan',
        bullets: [
            'Membuat pesanan terjadwal / belum lunas dengan daftar baris produk.',
            'Memperbarui status (misalnya menunggu, siap diambil, selesai) dan menyelesaikan ke transaksi POS jika diintegrasikan ke alur toko Anda.',
            'Pembatalan pre-order tersedia sesuai aturan di halaman detail.',
        ],
    },
    {
        id: 'diskon-pelanggan',
        title: 'Diskon & pelanggan',
        blocks: [
            {
                title: 'Diskon',
                items: [
                    'Tipe diskon dan aturan penggunaan dikelola di menu Diskon (untuk peran backoffice dan paket yang mengizinkan).',
                    'POS dapat menerapkan diskon yang diizinkan pada transaksi.',
                ],
            },
            {
                title: 'Pelanggan',
                items: [
                    'Database pelanggan: nama, kontak, catatan; dipakai di transaksi dan laporan.',
                    'Batas jumlah pelanggan dapat berlaku pada paket Starter.',
                ],
            },
        ],
    },
    {
        id: 'laporan',
        title: 'Laporan & ekspor',
        bullets: [
            'Halaman utama laporan: grafik tren penjualan, metode bayar, kategori, produk terlaris, dan ringkasan periode (dengan perbandingan periode sebelumnya jika tersedia).',
            'Laporan penjualan detail, laporan inventori/stok, dan laporan shift kasir di submenu.',
            'Ekspor PDF dan Excel untuk laporan tertentu (unduhan melalui tautan ekspor di halaman laporan).',
        ],
    },
    {
        id: 'langganan',
        title: 'Langganan & pembayaran paket',
        bullets: [
            'Melihat paket aktif dan status langganan (paket Starter gratis tanpa hitungan masa aktif; paket berbayar menampilkan sisa hari bila berlaku).',
            'Checkout paket (Starter / GrowUp, bulanan atau tahunan), kode voucher, dan unggah bukti transfer.',
            'Riwayat pembayaran menunggu konfirmasi atau sudah dikonfirmasi admin.',
        ],
    },
    {
        id: 'pengaturan',
        title: 'Pengaturan profil, toko & preferensi',
        blocks: [
            {
                title: 'Profil akun',
                items: [
                    'Nama, email, kata sandi; penghapusan akun sesuai kebijakan di formulir profil.',
                    'Tema tampilan (mode terang/gelap) di submenu penampilan.',
                ],
            },
            {
                title: 'Toko & struk',
                items: [
                    'Identitas toko, alamat, kontak, jenis usaha, logo & teks struk, pengaturan tampilan kontak di struk.',
                    'Pajak: mengaktifkan pajak persentase di struk dan mengubah tarif (per admin toko).',
                    'Pengaturan batch/kedaluwarsa dan peringatan email (jika fitur tersedia di formulir Anda).',
                    'Service charge default untuk skenario F&B jika diaktifkan.',
                ],
            },
            {
                title: 'Tim & toko',
                items: [
                    'Mengundang atau mengelola akses anggota ke toko (per admin toko).',
                    'Membuat toko baru atau bergabung ke grup usaha dari daftar toko (batas outlet mengikuti paket grup).',
                ],
            },
        ],
    },
    {
        id: 'multi-outlet',
        title: 'Grup usaha & multi-outlet',
        bullets: [
            'Organisasi (grup usaha) mengelompokkan beberapa toko agar transfer stok dan kebijakan langganan konsisten.',
            'Batas jumlah outlet mengacu pada nilai tertinggi batas outlet di antara paket toko-toko dalam grup (misalnya GrowUp hingga lima outlet per grup).',
            'Menu dan data tetap per toko aktif; pengguna dapat memiliki akses ke lebih dari satu toko dan berganti konteks lewat fitur ganti toko di aplikasi.',
        ],
    },
];

onMounted(() => {
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'fintech-saas');

    // Load template scripts Sastik
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
            <link
                rel="stylesheet"
                href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,700,0..1,0&display=swap"
            />

            <link
                rel="stylesheet"
                :href="`${buyerAssetsBase}/css/bootstrap.min.css`"
            />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/fontawesome.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/animate.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/swiper.min.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/odometer.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/nice-select.css`" />
            <link rel="stylesheet" :href="`${buyerAssetsBase}/css/main.css`" />

            <link rel="canonical" :href="canonicalUrl" />
            <meta name="description" :content="pageDescription" />
            <meta name="robots" content="index, follow" />

            <meta property="og:type" content="article" />
            <meta property="og:site_name" content="UMKM Kasir" />
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
            <meta property="og:image:alt" content="Logo UMKM Kasir — panduan pengguna" />

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
                    <div class="container mxw-1330">
                        <div class="gt-hero-content text-center">
                            <h1 class="char-animation lp-panduan-hero-title">
                                Panduan pengguna
                                <br />
                                UMKM Kasir
                            </h1>
                            <p class="wow fadeInUp" data-wow-delay=".3s">
                                Dokumentasi ringkas modul di dalam aplikasi — POS, inventori, laporan, langganan,
                                dan pengaturan — disusun berdasarkan menu, peran, dan alur yang ada di sistem.
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
                                    14 modul dibahas
                                </li>
                                <li>
                                    <i class="fa-regular fa-circle-check"></i>
                                    Berdasarkan peran pengguna
                                </li>
                                <li>
                                    <i class="fa-regular fa-circle-check"></i>
                                    Selaras dengan paket langganan
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Daftar isi + konten panduan -->
                <section class="lp-panduan-content section-padding pt-0">
                    <div class="container">
                        <div class="row g-4 lp-panduan-grid">
                            <!-- TOC sidebar -->
                            <aside class="col-lg-4">
                                <div class="lp-panduan-toc">
                                    <div class="gt-section-title style-3 mb-3">
                                        <h6 class="tt-capitalize wow fadeInUp">Daftar Isi</h6>
                                        <h2 class="lp-panduan-toc-title">Lompat ke topik</h2>
                                    </div>
                                    <nav aria-label="Isi panduan">
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
                                    <article
                                        v-for="(section, sIdx) in sections"
                                        :id="section.id"
                                        :key="section.id"
                                        class="lp-panduan-card scroll-mt-28 wow fadeInUp"
                                    >
                                        <span class="lp-panduan-card-num">
                                            {{ String(sIdx + 1).padStart(2, '0') }}
                                        </span>
                                        <h2 class="lp-panduan-card-title">{{ section.title }}</h2>
                                        <p v-if="section.intro" class="lp-panduan-card-intro">
                                            {{ section.intro }}
                                        </p>
                                        <ul
                                            v-if="section.bullets?.length"
                                            class="lp-panduan-bullets"
                                        >
                                            <li v-for="(b, i) in section.bullets" :key="i">
                                                <i class="fa-solid fa-circle-check"></i>
                                                <span>{{ b }}</span>
                                            </li>
                                        </ul>
                                        <div v-if="section.blocks?.length" class="lp-panduan-blocks">
                                            <div v-for="(block, bi) in section.blocks" :key="bi" class="lp-panduan-block">
                                                <h3 class="lp-panduan-block-title">{{ block.title }}</h3>
                                                <ul class="lp-panduan-bullets">
                                                    <li v-for="(item, ii) in block.items" :key="ii">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                        <span>{{ item }}</span>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <p v-if="section.footnote" class="lp-panduan-footnote">
                                            {{ section.footnote }}
                                        </p>
                                    </article>
                                </div>

                                <div class="lp-panduan-cta text-center">
                                    <h2 class="lp-panduan-cta-title">Siap mencoba?</h2>
                                    <p class="lp-panduan-cta-text">
                                        Daftar gratis, buat toko pertama, lalu lacak langkah di menu sesuai peran Anda.
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
.lp-panduan-root {
    background: var(--lp-surface, #f8fafc);
}

/* Override padding dari .gt-hero-3 (120px 0 0) supaya tidak berlebihan */
.lp-panduan-hero {
    padding: 80px 0 70px !important;
    margin-top: 4rem !important;
    color: var(--gt-white, #ffffff);
    overflow: hidden;
    position: relative;
    isolation: isolate;
    background: #7c3aed !important;
}

/* Matikan pseudo-element dari template (.gt-hero-3::before / ::after) */
.lp-panduan-hero::before,
.lp-panduan-hero::after {
    display: none !important;
}

/* Hapus hero-circle-shape dekorasi — tidak perlu untuk halaman panduan */
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

/* Tombol primer (Buat akun) — orange segar, kontras di atas ungu */
.lp-panduan-hero-cta .gt-theme-btn.bg-theme {
    background: #f97316 !important;
    color: #ffffff !important;
    box-shadow: 0 12px 30px -10px rgba(249, 115, 22, 0.45);
}
.lp-panduan-hero-cta .gt-theme-btn.bg-theme::before {
    background-color: #ea580c !important;
}
.lp-panduan-hero-cta .gt-theme-btn.bg-theme:hover {
    color: #ffffff !important;
}

/* Tombol sekunder (Masuk) — outline putih, transparan */
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

.lp-panduan-content {
    padding-top: 2rem;
    padding-bottom: 5rem;
}

.lp-panduan-grid {
    align-items: flex-start;
}

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

@media (max-width: 991px) {
    .lp-panduan-toc {
        position: static;
    }
}
</style>
