<script setup lang="ts">
import LandingAudience from '@/components/landing/LandingAudience.vue';
import LandingBrand from '@/components/landing/LandingBrand.vue';
import LandingFaq from '@/components/landing/LandingFaq.vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingNav from '@/components/landing/LandingNav.vue';
import LandingPricing from '@/components/landing/LandingPricing.vue';
import LandingUseCase from '@/components/landing/LandingUseCase.vue';
import LandingValue from '@/components/landing/LandingValue.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

/** PWA standalone / iOS "Add to Home Screen": langsung ke login, bukan landing. */
if (typeof window !== 'undefined') {
    const path = window.location.pathname;
    if (path === '/' || path === '') {
        const standalone =
            window.matchMedia('(display-mode: standalone)').matches ||
            (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
        if (standalone) {
            window.location.replace('/login');
        }
    }
}

const props = defineProps<{
    maskotUrl?: string;
    canonicalUrl?: string;
    ogImageUrl?: string;
    ogImageType?: string;
    ogImageWidth?: number | null;
    ogImageHeight?: number | null;
}>();

const pageTitle = 'UMKM Kasir — Kasir Modern untuk Usaha Anda';
const pageDescription =
    'Kasir modern untuk UMKM — kelola produk, stok, transaksi POS, shift kasir, dan laporan dalam satu aplikasi web.';

const maskotUrl = props.maskotUrl ?? '/logo-macca.png';
const canonicalUrl = props.canonicalUrl ?? '';

const shareImageUrl = computed(() => props.ogImageUrl ?? maskotUrl);
const shareImageType = computed(() => props.ogImageType ?? 'image/webp');

/** Aset template Sastik (disalin ke public/sastik-assets). */
const assetsBase = '/sastik-assets';

/** Fase 1: jQuery (harus duluan — semua plugin bergantung). */
const jqueryScript = `${assetsBase}/js/jquery-3.7.1.min.js`;

/** Fase 2: Plugin inti yang menjalankan layout/interaksi saat DOM ready.
 * lenis.js termasuk di sini karena main.js langsung memuat
 * `new Lenis()` di tahap inti — tidak boleh ditunda.
 * parallaxie.js juga wajib di fase ini karena main.js memanggil
 * `$('.parallaxie').parallaxie()` di tahap inti.
 */
const pluginScripts: string[] = [
    `${assetsBase}/js/bootstrap.bundle.min.js`,
    `${assetsBase}/js/swiper.min.js`,
    `${assetsBase}/js/wow.min.js`,
    `${assetsBase}/js/appear.js`,
    `${assetsBase}/js/imagesloaded.pkgd.min.js`,
    `${assetsBase}/js/isotope.pkgd.min.js`,
    `${assetsBase}/js/jquery.nice-select.min.js`,
    `${assetsBase}/js/jquery.marquee.min.js`,
    `${assetsBase}/js/jquery.magnific-popup.min.js`,
    `${assetsBase}/js/jqueryui.js`,
    `${assetsBase}/js/odometer.min.js`,
    `${assetsBase}/js/easing.min.js`,
    `${assetsBase}/js/scrollspy.js`,
    `${assetsBase}/js/lenis.js`,
    `${assetsBase}/js/parallaxie.js`,
    `${assetsBase}/js/plugin.js`,
];

/**
 * Fase 2b: Skrip non-kritis (tidak dipanggil main.js).
 * Dimuat via idle callback setelah `main.js` selesai, sehingga tidak
 * menambah hierarki dependensi kritis (LCP/FCP).
 */
const deferredScripts: string[] = [
    `${assetsBase}/js/parallax.min.js`,
];

/** Fase 3: main.js (harus terakhir — depends on semua plugin inti). */
const mainScript = `${assetsBase}/js/main.js`;

const loadedScripts: HTMLScriptElement[] = [];

function loadScript(url: string): Promise<void> {
    return new Promise<void>((resolve) => {
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
    });
}

async function loadScriptsOptimized(): Promise<void> {
    // Fase 1: jQuery (blocking)
    await loadScript(jqueryScript);
    // Fase 2: semua plugin inti paralel
    await Promise.all(pluginScripts.map(loadScript));
    // Fase 3: main.js (setelah semua plugin inti siap)
    await loadScript(mainScript);
}

async function loadDeferredScripts(): Promise<void> {
    // parallax.min.js tidak dipanggil main.js; dimuat belakangan saja.
    await Promise.all(deferredScripts.map(loadScript));
}

onMounted(() => {
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'fintech-saas');

    // Preloader & animasi ditangani oleh main.js (Sastik).
    // main.js menyembunyikan elemen animasi via gsap.set() lalu mengungkapnya
    // di dalam $(window).on('load', preloader). Karena script di-load dinamis
    // SETELAH event load asli, handler tersebut tidak pernah dipanggil.
    // Setelah semua script inti termuat, dispatch synthetic load event untuk
    // memicu preloader() → xb_reveal_animation() → wowAnimation().
    void loadScriptsOptimized().then(async () => {
        // Dispatch synthetic load (hanya jika load asli belum terjadi /
        // sudah melampui saat main.js termuat).
        if (document.readyState === 'complete') {
            window.dispatchEvent(new Event('load'));
        }

        // Skrip parallax dimuat via idle callback — setelah load +
        // main.js selesai berjalan, tidak memblokir FCP/LCP.
        const loadDeferred = () => {
            void loadDeferredScripts();
        };
        if ('requestIdleCallback' in window) {
            (window as unknown as { requestIdleCallback: (cb: () => void) => number }).requestIdleCallback(loadDeferred, { timeout: 2000 });
        } else {
            window.setTimeout(loadDeferred, 500);
        }

        // Fallback: jika preloader masih terlihat setelah 5s, sembunyikan manual
        const preloader = document.getElementById('preloader');
        if (preloader) {
            setTimeout(() => {
                if (preloader.style.display !== 'none' && !preloader.classList.contains('preloader-hidden')) {
                    preloader.classList.add('preloader-hidden', 'xb-loader-hidden');
                    preloader.style.display = 'none';
                }
            }, 5000);
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
            <!-- Preconnect gstatic (font file) — googleapis sudah di app.blade.php -->
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

            <!-- Preload CSS kritis untuk rendering cepat -->
            <link rel="preload" as="style" :href="`${assetsBase}/css/bootstrap.min.css`" />
            <link rel="preload" as="style" :href="`${assetsBase}/css/main.css`" />

            <!-- Load CSS kritis secara blocking -->
            <link rel="stylesheet" :href="`${assetsBase}/css/bootstrap.min.css`" />
            <link rel="stylesheet" :href="`${assetsBase}/css/fontawesome.css`" />
            <link rel="stylesheet" :href="`${assetsBase}/css/main.css`" />

            <!-- Custom spacing overrides -->
            <link rel="stylesheet" href="/css/landing-custom.css" />

            <!-- Load CSS non-kritis secara async -->
            <link rel="stylesheet" :href="`${assetsBase}/css/animate.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${assetsBase}/css/swiper.min.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${assetsBase}/css/odometer.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${assetsBase}/css/nice-select.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${assetsBase}/css/magnific-popup.css`" media="print" onload="this.media='all'" />
            <link rel="stylesheet" :href="`${assetsBase}/css/jquery-ui.css`" media="print" onload="this.media='all'" />

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
            <meta property="og:image:alt" content="Logo UMKM Kasir — aplikasi kasir untuk UMKM" />

            <meta name="twitter:card" content="summary_large_image" />
            <meta name="twitter:title" :content="pageTitle" />
            <meta name="twitter:description" :content="pageDescription" />
            <meta name="twitter:image" :content="shareImageUrl" />
        </Head>

        <!-- backtotop -->
        <div class="xb-backtotop" style="right: auto !important; left: 30px !important;">
            <a href="#" class="scroll">
                <i class="far fa-arrow-up"></i>
            </a>
        </div>

        <!-- Preloader -->
        <div id="preloader" class="xb-loader">
            <div class="xb-loader-inner">
                <div class="xb-loader-logo">
                    <img :src="maskotUrl" alt="logo" width="3194" height="1037" />
                </div>
            </div>
        </div>

        <div class="body_wrap">
            <div class="body-container m-auto mxw-1460">
                <div class="xb-line-pattern">
                    <img :src="`${assetsBase}/img/shape/line-pattern.png`" alt="" aria-hidden="true" width="64" height="1200">
                    <img :src="`${assetsBase}/img/shape/line-pattern.png`" alt="" aria-hidden="true" width="64" height="1200">
                </div>
                <div class="body-inner" data-bg-color="#fff">
                    <LandingNav :maskot-url="maskotUrl" />
                    <div class="body-overlay"></div>

                    <main>
                        <LandingHero :assets-base="assetsBase" />
                        <LandingValue :assets-base="assetsBase" />
                        <LandingBrand :assets-base="assetsBase" />
                        <LandingAudience :assets-base="assetsBase" />
                        <LandingUseCase :assets-base="assetsBase" />
                        <LandingPricing :assets-base="assetsBase" />
                        <LandingFaq :assets-base="assetsBase" />
                    </main>

                    <LandingFooter :assets-base="assetsBase" />
                </div>
            </div>
        </div>
    </div>
</template>
