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

const pageTitle = 'Kebijakan Refund — UMKM Kasir';
const pageDescription =
    'Kebijakan pengembalian dana (refund) UMKM Ketentuan pengembalian dana untuk langganan UMKM Kasir.';

const maskotUrl = props.maskotUrl ?? '/logo-macca.png';
const canonicalUrl = props.canonicalUrl ?? '';

const shareImageUrl = computed(() => props.ogImageUrl ?? maskotUrl);
const shareImageType = computed(() => props.ogImageType ?? 'image/webp');

const lastUpdated = '25 Agustus 2026';

const toc = [
    { id: 'umum', title: 'Ketentuan Umum' },
    { id: 'kelayakan', title: 'Kelayakan Refund' },
    { id: 'prosedur', title: 'Prosedur Pengajuan' },
    { id: 'durasi', title: 'Durasi Pemrosesan' },
    { id: 'pengecualian', title: 'Pengecualian' },
    { id: 'sengketa', title: 'Penyelesaian Sengketa' },
    { id: 'perubahan', title: 'Perubahan Kebijakan' },
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
                                Kebijakan Refund
                                <br />
                                UMKM Kasir
                            </h1>
                            <p class="wow fadeInUp" data-wow-delay=".3s">
                                Terakhir diperbarui: <strong>{{ lastUpdated }}</strong>
                            </p>
                            <p class="wow fadeInUp" data-wow-delay=".4s" style="max-width: 700px; margin: 0 auto;">
                                Kebijakan ini menjelaskan ketentuan dan prosedur pengembalian dana untuk layanan
                                langganan UMKM Kasir.
                            </p>
                            <div class="lp-panduan-hero-cta wow fadeInUp" data-wow-delay=".5s">
                                <Link href="/register" class="gt-theme-btn style-3 bg-theme">Buat akun</Link>
                                <Link href="/login" class="gt-theme-btn style-3 bg-border">Masuk</Link>
                            </div>
                            <ul class="wow fadeInUp lp-panduan-hero-list" data-wow-delay=".7s">
                                <li><i class="fa-regular fa-circle-check"></i>Transparan & adil</li>
                                <li><i class="fa-regular fa-circle-check"></i>Proses refund mudah</li>
                                <li><i class="fa-regular fa-circle-check"></i>Dukungan responsif</li>
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
                                    <nav aria-label="Daftar isi kebijakan refund">
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

                                    <article id="umum" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">01</span>
                                        <h2 class="lp-panduan-card-title">Ketentuan Umum</h2>
                                        <p class="lp-panduan-card-intro">
                                            Kebijakan refund ini berlaku untuk semua pengguna yang telah melakukan pembayaran
                                            langganan pada layanan UMKM Kasir, baik paket Starter, Pro, maupun paket lainnya.
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Pengembalian dana hanya berlaku untuk pembayaran langganan yang belum digunakan atau belum aktif.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Permintaan refund harus diajukan dalam waktu 7 (tujuh) hari kalender sejak pembayaran dikonfirmasi.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>UMKM Kasir berhak menolak permintaan refund yang tidak memenuhi syarat yang ditetapkan.</span></li>
                                        </ul>
                                    </article>

                                    <article id="kelayakan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">02</span>
                                        <h2 class="lp-panduan-card-title">Kelayakan Refund</h2>
                                        <p class="lp-panduan-card-intro">Pengembalian dana dapat dilakukan dalam situasi berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Pembayaran ganda:</strong> terdapat bukti pembayaran dua kali untuk periode langganan yang sama.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Layanan tidak tersedia:</strong> layanan utama mengalami downtime lebih dari 48 jam berturut-turut akibat kesalahan di pihak kami.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Kesalahan tagihan:</strong> jumlah yang dibebankan tidak sesuai dengan paket langganan yang dipilih.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Pembatalan dalam masa uji coba:</strong> pengguna membatalkan selama masa trial sebelum langganan berbayar aktif.</span></li>
                                        </ul>
                                    </article>

                                    <article id="prosedur" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">03</span>
                                        <h2 class="lp-panduan-card-title">Prosedur Pengajuan Refund</h2>
                                        <p class="lp-panduan-card-intro">Untuk mengajukan pengembalian dana, silakan ikuti langkah-langkah berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Langkah 1:</strong> Hubungi tim support kami via email ke <a href="mailto:support@umkmkasir.com">support@umkmkasir.com</a> atau WhatsApp <a href="https://wa.me/6285242766676" target="_blank" rel="noopener noreferrer">+62 852-4276-6676</a>.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Langkah 2:</strong> Sertakan informasi: nama akun, email terdaftar, tanggal pembayaran, bukti transfer, dan alasan refund.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Langkah 3:</strong> Tim kami akan memverifikasi dalam 1x24 jam kerja dan memberikan konfirmasi via email.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Langkah 4:</strong> Jika disetujui, dana akan dikembalikan ke rekening/asal pembayaran dalam waktu maksimal 7 hari kerja.</span></li>
                                        </ul>
                                    </article>

                                    <article id="durasi" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">04</span>
                                        <h2 class="lp-panduan-card-title">Durasi Pemrosesan</h2>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Verifikasi:</strong> 1x24 jam kerja sejak permintaan diterima lengkap.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Transfer refund:</strong> maksimal 7 hari kerja setelah verifikasi disetujui.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span><strong>Transfer bank lokal:</strong> biasanya sampai dalam 1-3 hari kerja setelah diproses.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Pending refund dapat dipantau dengan menghubungi tim support.</span></li>
                                        </ul>
                                    </article>

                                    <article id="pengecualian" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">05</span>
                                        <h2 class="lp-panduan-card-title">Pengecualian</h2>
                                        <p class="lp-panduan-card-intro">Refund <strong>tidak</strong> dapat dilakukan dalam kasus berikut:</p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-solid fa-circle-check"></i><span>Masa langganan sudah lebih dari 50% terpakai.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Pengguna melanggar Ketentuan Layanan kami.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Permintaan diajukan melebihi batas waktu 7 hari kalender.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Promo, diskon, atau voucher yang sudah digunakan.</span></li>
                                            <li><i class="fa-solid fa-circle-check"></i><span>Add-on atau fitur tambahan yang sudah diaktifkan.</span></li>
                                        </ul>
                                        <p class="lp-panduan-footnote">
                                            Dalam kasus khusus di luar daftar di atas, keputusan akhir berada di tangan UMKM Kasir
                                            setelah meninjau seluruh informasi yang relevan.
                                        </p>
                                    </article>

                                    <article id="sengketa" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">06</span>
                                        <h2 class="lp-panduan-card-title">Penyelesaian Sengketa</h2>
                                        <p class="lp-panduan-card-intro">
                                            Jika Anda tidak puas dengan keputusan refund, Anda dapat mengajukan banding
                                            dengan mengirimkan email berisi penjelasan detail ke <a href="mailto:support@umkmkasir.com">support@umkmkasir.com</a>.
                                            Kami berkomitmen untuk menyelesaikan setiap sengketa secara musyawarah dalam waktu 14 hari kerja.
                                        </p>
                                    </article>

                                    <article id="perubahan" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">07</span>
                                        <h2 class="lp-panduan-card-title">Perubahan Kebijakan</h2>
                                        <p class="lp-panduan-card-intro">
                                            UMKM Kasir berhak mengubah kebijakan refund ini kapan saja. Perubahan akan
                                            diberitahukan melalui email ke pengguna terdaftar dan/atau pemberitahuan
                                            di dalam aplikasi minimal 30 hari sebelum berlaku efektif.
                                        </p>
                                    </article>

                                    <article id="kontak" class="lp-panduan-card scroll-mt-28 wow fadeInUp">
                                        <span class="lp-panduan-card-num">08</span>
                                        <h2 class="lp-panduan-card-title">Kontak Kami</h2>
                                        <p class="lp-panduan-card-intro">
                                            Untuk pertanyaan terkait kebijakan refund, silakan hubungi kami:
                                        </p>
                                        <ul class="lp-panduan-bullets">
                                            <li><i class="fa-regular fa-envelope"></i><span><strong>Email:</strong> <a href="mailto:support@umkmkasir.com">support@umkmkasir.com</a></span></li>
                                            <li><i class="fa-brands fa-whatsapp"></i><span><strong>WhatsApp:</strong> <a href="https://wa.me/6285242766676" target="_blank" rel="noopener noreferrer">+62 852-4276-6676</a></span></li>
                                            <li><i class="fa-regular fa-clock"></i><span><strong>Jam operasional:</strong> Senin–Jumat, 08.00–17.00 WIB</span></li>
                                        </ul>
                                    </article>
                                </div>

                                <div class="lp-panduan-cta text-center">
                                    <h2 class="lp-panduan-cta-title">Butuh bantuan refund?</h2>
                                    <p class="lp-panduan-cta-text">
                                        Hubungi tim kami untuk pertanyaan terkait pengembalian dana. Kami siap membantu.
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
