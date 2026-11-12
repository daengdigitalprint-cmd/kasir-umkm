import { router } from '@inertiajs/vue3';

/**
 * Animasi loading saat pindah halaman (navigasi Inertia).
 *
 * - Bar progres di atas (bawaan Inertia, lihat `progress` di app.ts) muncul segera.
 * - Bila halaman tujuan belum siap setelah SHOW_DELAY_MS, tampil overlay ringan
 *   (spinner + "Memuat halaman…") di atas konten lama, supaya pengguna tahu
 *   aplikasi sedang bekerja — bukan layar diam/putih.
 * - Navigasi cepat (< SHOW_DELAY_MS) tidak menampilkan overlay agar tidak berkedip.
 * - Request latar (polling, reload sebagian `only`, prefetch, POST form) diabaikan.
 */

const SHOW_DELAY_MS = 350;
const OVERLAY_ID = 'app-nav-loader';
const STYLE_ID = 'app-nav-loader-style';

let showTimer: number | undefined;
let installed = false;

function ensureStyle(): void {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
#${OVERLAY_ID}{position:fixed;inset:0;z-index:9990;display:flex;align-items:center;justify-content:center;
  background:rgba(248,250,252,.72);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);
  opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease;pointer-events:none}
#${OVERLAY_ID}.is-visible{opacity:1;visibility:visible;pointer-events:auto}
#${OVERLAY_ID} .anl-box{display:flex;flex-direction:column;align-items:center;gap:12px;padding:20px 26px;border-radius:18px;
  background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.10),0 1px 3px rgba(15,23,42,.06)}
#${OVERLAY_ID} .anl-spin{width:38px;height:38px;border-radius:50%;border:3.5px solid rgba(121,67,229,.18);
  border-top-color:var(--primary,#7943e5);animation:anl-spin .8s linear infinite}
#${OVERLAY_ID} .anl-text{font:600 13px/1.2 var(--font-sans,system-ui,sans-serif);color:#475569;letter-spacing:.01em}
@keyframes anl-spin{to{transform:rotate(360deg)}}
.dark #${OVERLAY_ID}{background:rgba(2,6,23,.6)}
.dark #${OVERLAY_ID} .anl-box{background:#0f172a}
.dark #${OVERLAY_ID} .anl-text{color:#cbd5e1}
@media (prefers-reduced-motion:reduce){#${OVERLAY_ID} .anl-spin{animation-duration:2s}}
`;
    document.head.appendChild(style);
}

function overlay(): HTMLElement {
    let el = document.getElementById(OVERLAY_ID);
    if (el) return el;
    ensureStyle();
    el = document.createElement('div');
    el.id = OVERLAY_ID;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = '<div class="anl-box"><div class="anl-spin" aria-hidden="true"></div><div class="anl-text">Memuat halaman…</div></div>';
    document.body.appendChild(el);
    return el;
}

function show(): void {
    overlay().classList.add('is-visible');
}

function hide(): void {
    window.clearTimeout(showTimer);
    showTimer = undefined;
    document.getElementById(OVERLAY_ID)?.classList.remove('is-visible');
}

type VisitLike = {
    method?: string;
    only?: string[];
    except?: string[];
    async?: boolean;
    prefetch?: boolean;
};

/** Hanya navigasi pindah halaman yang pantas diberi overlay. */
function isPageNavigation(visit: VisitLike): boolean {
    if ((visit.method ?? 'get').toLowerCase() !== 'get') return false;
    if (visit.async || visit.prefetch) return false;
    if ((visit.only?.length ?? 0) > 0 || (visit.except?.length ?? 0) > 0) return false;
    return true;
}

export function installNavigationLoader(): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    router.on('start', (event) => {
        if (!isPageNavigation(event.detail.visit as VisitLike)) return;
        window.clearTimeout(showTimer);
        showTimer = window.setTimeout(show, SHOW_DELAY_MS);
    });

    // finish = selesai, batal, atau gagal — overlay selalu dilepas.
    router.on('finish', hide);
}

/** Sembunyikan preloader awal dari app.blade.php setelah Vue ter-mount. */
export function hideInitialPreloader(): void {
    const el = document.getElementById('lp-preloader');
    if (!el) return;
    el.classList.add('lp-preloader-hidden');
    window.setTimeout(() => {
        el.style.display = 'none';
    }, 350);
}
