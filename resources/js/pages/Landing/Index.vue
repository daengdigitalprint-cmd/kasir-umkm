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
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/** PWA standalone / iOS "Add to Home Screen": langsung ke login, bukan landing. */
if (typeof window !== 'undefined') {
    const path = window.location.pathname;
    if (path === '/' || path === '') {
        const standalone =
            window.matchMedia('(display-mode: standalone)').matches || (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
        if (standalone) {
            window.location.replace('/login');
        }
    }
}

const props = withDefaults(
    defineProps<{
        maskotUrl?: string;
        canonicalUrl?: string;
        ogImageUrl?: string;
        ogImageType?: string;
        ogImageWidth?: number | null;
        ogImageHeight?: number | null;
        /** Hash filemtime aset sastik (LandingSeo::landingAssetVersion) untuk cache-busting. */
        landingAssetVersion?: string;
        /** false bila aset teroptimasi (landing.min.css, gsap-core.min.js, ...) belum ter-deploy. */
        landingOptimized?: boolean;
    }>(),
    {
        // Wajib eksplisit: Vue meng-cast prop Boolean yang tidak dikirim menjadi false.
        landingOptimized: true,
    },
);

/** Harus sama dengan LandingSeo::HOME_TITLE (judul server untuk crawler). */
const pageTitle = 'Aplikasi Kasir Online Gratis untuk UMKM | UMKM Kasir';

const maskotUrl = props.maskotUrl ?? '/logo-macca.png';

/** Aset template Sastik (disalin ke public/sastik-assets). */
const assetsBase = '/sastik-assets';

/**
 * Query versi untuk aset sastik. Tanpa ini browser bisa memakai main.js lama
 * dari cache bersama Index.vue baru (mis. main.js lama memanggil `new WOW()`
 * padahal wow.min.js sudah tidak dimuat -> error di callback GSAP).
 */
const assetQuery = props.landingAssetVersion ? `?v=${props.landingAssetVersion}` : '';
const withVersion = (url: string) => `${url}${assetQuery}`;

/** Aset teroptimasi tersedia di server? (default true bila prop tidak dikirim) */
const optimized = props.landingOptimized;

/** CSS asli (urutan cascade sama dengan bundle) — dipakai jika bundle belum ter-deploy. */
const fallbackStylesheets = [
    `${assetsBase}/css/bootstrap.min.css`,
    `${assetsBase}/css/fontawesome.css`,
    `${assetsBase}/css/main.css`,
    '/css/landing-custom.css',
    `${assetsBase}/css/animate.css`,
];

/** Fase 1: jQuery (harus duluan — semua plugin bergantung). */
const jqueryScript = withVersion(`${assetsBase}/js/jquery-3.7.1.min.js`);

/** Fase 2: Plugin yang benar-benar dipakai landing ini.
 * - bootstrap: tab dashboard (data-bs-toggle="pill")
 * - marquee: .marquee-left / .marquee-right
 * - lenis: smooth scroll (main.js)
 * - gsap-core.min.js: GSAP core saja (animasi reveal). plugin.js asli = GSAP +
 *   ScrollTrigger + SplitText + plugin lain (127 KB) yang tidak dipakai main.js;
 *   dipakai hanya sebagai fallback bila gsap-core.min.js belum ter-deploy.
 * Plugin template lain (swiper, wow, appear, isotope, imagesloaded,
 * nice-select, magnific-popup, jquery-ui, odometer, easing, scrollspy,
 * parallaxie, parallax) tidak dipakai — main.js sudah di-guard dan WOW/appear
 * diganti IntersectionObserver (menghindari forced reflow saat scroll).
 */
const pluginScripts: string[] = [
    `${assetsBase}/js/bootstrap.bundle.min.js`,
    `${assetsBase}/js/jquery.marquee.min.js`,
    `${assetsBase}/js/lenis.js`,
    optimized ? `${assetsBase}/js/gsap-core.min.js` : `${assetsBase}/js/plugin.js`,
].map(withVersion);

/** Fase 3: main.js (harus terakhir — depends on semua plugin inti). */
const mainScript = withVersion(`${assetsBase}/js/main.js`);

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

/**
 * Selama stylesheet/script landing belum siap, CSS transition dimatikan
 * (class `lp-no-transition`, aturannya inline di app.blade.php). Tanpa ini,
 * saat stylesheet masuk ratusan elemen ber-`transition` ikut "beranimasi"
 * (Lighthouse: "Menghindari animasi yang tidak digabungkan").
 */
const NO_TRANSITION_CLASS = 'lp-no-transition';
if (typeof document !== 'undefined') {
    document.documentElement.classList.add(NO_TRANSITION_CLASS);
}

function waitForStylesheets(): Promise<void> {
    const links = Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')).filter((l) => l.media !== 'print');
    return Promise.all(
        links.map((l) =>
            l.sheet
                ? Promise.resolve()
                : new Promise<void>((resolve) => {
                      l.addEventListener('load', () => resolve(), { once: true });
                      l.addEventListener('error', () => resolve(), { once: true });
                  }),
        ),
    ).then(() => undefined);
}

/**
 * Transition baru berguna saat pengguna berinteraksi (hover, klik, scroll).
 * Jadi diaktifkan saat: stylesheet siap DAN (interaksi pertama ATAU 10 dtk).
 * Ini juga mencegah transisi `visibility` berantai saat animasi reveal GSAP
 * di awal. Melepas class tidak memicu transisi apa pun (nilai tidak berubah).
 */
const INTERACTION_EVENTS = ['pointermove', 'pointerdown', 'touchstart', 'keydown', 'wheel', 'scroll'] as const;
let stylesReady = false;
let interacted = false;
let transitionsEnabled = false;
let transitionFallbackTimer: number | undefined;

function tryEnableTransitions(): void {
    if (transitionsEnabled || !stylesReady || !interacted) return;
    transitionsEnabled = true;
    // Dua frame: pastikan gaya akhir sudah ter-commit sebelum transition aktif.
    requestAnimationFrame(() => requestAnimationFrame(() => document.documentElement.classList.remove(NO_TRANSITION_CLASS)));
}

function onFirstInteraction(): void {
    INTERACTION_EVENTS.forEach((ev) => window.removeEventListener(ev, onFirstInteraction));
    interacted = true;
    tryEnableTransitions();
}

function markStylesReady(): void {
    stylesReady = true;
    tryEnableTransitions();
}

/**
 * Bila app.css (Tailwind aplikasi) sengaja tidak dimuat di landing
 * (atribut data-no-app-css dari app.blade.php), halaman lain yang dibuka lewat
 * <Link> Inertia akan tampil tanpa gaya. Maka navigasi GET keluar landing
 * dijadikan full page load: server merender halaman tujuan lengkap dengan app.css.
 */
let removeLeaveGuard: (() => void) | undefined;
function installLeaveGuard(): void {
    if (!document.documentElement.hasAttribute('data-no-app-css')) return;
    removeLeaveGuard = router.on('before', (event) => {
        const visit = event.detail.visit;
        if (visit.method !== 'get') return;
        const url = new URL(visit.url.toString(), window.location.href);
        if (url.origin !== window.location.origin) return;
        // Anchor di halaman yang sama (#fitur, dst.) tetap biasa.
        if (url.pathname === window.location.pathname && url.search === window.location.search) return;
        event.preventDefault();
        window.location.assign(url.toString());
    });
}

onMounted(() => {
    installLeaveGuard();
    document.documentElement.classList.add('lp-landing');
    document.body.classList.add('lp-landing', 'fintech-saas');

    INTERACTION_EVENTS.forEach((ev) => window.addEventListener(ev, onFirstInteraction, { passive: true }));
    // Pengaman: tanpa interaksi pun transition aktif setelah 10 dtk
    // (stylesheet dianggap siap juga, kalau-kalau event load tidak datang).
    transitionFallbackTimer = window.setTimeout(() => {
        stylesReady = true;
        onFirstInteraction();
    }, 10000);

    // Preloader & animasi ditangani oleh main.js (Sastik).
    // main.js menyembunyikan elemen animasi via gsap.set() lalu mengungkapnya
    // di dalam $(window).on('load', preloader). Karena script di-load dinamis
    // SETELAH event load asli, handler tersebut tidak pernah dipanggil.
    // Setelah semua script inti termuat, dispatch synthetic load event untuk
    // memicu preloader() → xb_reveal_animation() → wowAnimation().
    void loadScriptsOptimized().then(() => {
        // Dispatch synthetic load (hanya jika load asli belum terjadi /
        // sudah melampui saat main.js termuat).
        if (document.readyState === 'complete') {
            window.dispatchEvent(new Event('load'));
        }

        void waitForStylesheets().then(markStylesReady);

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
    removeLeaveGuard?.();
    window.clearTimeout(transitionFallbackTimer);
    INTERACTION_EVENTS.forEach((ev) => window.removeEventListener(ev, onFirstInteraction));
    document.documentElement.classList.remove('lp-landing', NO_TRANSITION_CLASS);
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

            <!-- Satu file CSS hasil bundle + purge + minify dari bootstrap.min.css,
                 fontawesome-landing.css, main.css, landing-custom.css, animate.css
                 (±748 KB -> lihat ukuran di output skrip). Setelah mengubah komponen
                 landing atau CSS sumber, jalankan: node scripts/build-landing-css.mjs
                 Ikon baru? jalankan dulu: python scripts/build-landing-icons.py -->
            <link v-if="optimized" rel="stylesheet" :href="withVersion(`${assetsBase}/css/landing.min.css`)" />
            <template v-else>
                <link v-for="href in fallbackStylesheets" :key="href" rel="stylesheet" :href="href" />
            </template>

            <!-- Caveat hanya untuk teks coretan "Hemat 10% Tahunan" di pricing:
                 dimuat non-blocking & di-subset ke karakter itu saja (text=). -->
            <link
                rel="stylesheet"
                href="https://fonts.googleapis.com/css2?family=Caveat&text=Hemat%2010%25Tahunan&display=swap"
                media="print"
                onload="this.media='all'"
            />

            <!-- Meta SEO (description, canonical, Open Graph, Twitter, JSON-LD) dirender SERVER
                 di app.blade.php via App\Support\LandingSeo — terbaca crawler tanpa JavaScript.
                 Jangan diduplikasi di sini (sebelumnya setiap tag muncul 2x). -->
        </Head>

        <!-- backtotop -->
        <div class="xb-backtotop" style="right: auto !important; left: 30px !important">
            <a href="#" class="scroll" aria-label="Kembali ke atas">
                <i class="far fa-arrow-up" aria-hidden="true"></i>
            </a>
        </div>

        <!-- Preloader -->
        <div id="preloader" class="xb-loader">
            <div class="xb-loader-inner">
                <div class="xb-loader-logo">
                    <img :src="maskotUrl" alt="" width="180" height="58" />
                </div>
            </div>
        </div>

        <div class="body_wrap">
            <div class="body-container mxw-1460 m-auto">
                <div class="xb-line-pattern">
                    <img :src="`${assetsBase}/img/shape/line-pattern.png`" alt="" aria-hidden="true" width="64" height="1200" />
                    <img :src="`${assetsBase}/img/shape/line-pattern.png`" alt="" aria-hidden="true" width="64" height="1200" />
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
