// app.css (Tailwind) TIDAK di-import di sini: dimuat sebagai entry Vite terpisah
// lewat @vite di app.blade.php, supaya landing (Landing/Index) bisa melewatinya.
// Landing memakai subset-nya yang sudah digabung ke landing.min.css
// (scripts/build-landing-css.mjs).
import 'sweetalert2/dist/sweetalert2.min.css';

import '@/types/pwa-window';
import { createInertiaApp, router } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import Pusher from 'pusher-js';
import Swal from 'sweetalert2';
import type { DefineComponent } from 'vue';
import { createApp, h, type App as VueApp } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { processInertiaFlash, type InertiaFlash } from './lib/swal';

// Expose Swal ke window agar script inline (mis. promptPwaVersionReload di app.blade.php)
// tetap berfungsi tanpa perlu CDN terpisah yang bisa menyebabkan dual-instance conflict.
if (typeof window !== 'undefined') {
    (window as unknown as { Swal: typeof Swal }).Swal = Swal;
}

// ─── Laravel Echo + Reverb (WebSocket Real-Time) ─────────────────────────
declare global {
    interface Window {
        Echo?: Echo;
        Pusher?: typeof Pusher;
    }
}

if (typeof window !== 'undefined') {
    window.Pusher = Pusher;

    const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
    const reverbEnabled = import.meta.env.VITE_REVERB_ENABLED === 'true';
    if (reverbEnabled && reverbKey) {
        try {
            const envHost = (import.meta.env.VITE_REVERB_HOST ?? '').trim();
            const envPort = (import.meta.env.VITE_REVERB_PORT ?? '').trim();
            const envScheme = (import.meta.env.VITE_REVERB_SCHEME ?? '').trim().toLowerCase();

            const isLocalEnvHost = envHost === '' || envHost === 'localhost' || envHost === '127.0.0.1';

            const scheme = envScheme ? envScheme : window.location.protocol === 'https:' ? 'https' : 'http';

            const host = isLocalEnvHost ? window.location.hostname : envHost;

            let wsPort: number;
            let wssPort: number;
            if (envPort) {
                wsPort = Number(envPort);
                wssPort = Number(envPort);
            } else {
                const currentPort = window.location.port ? Number(window.location.port) : null;
                if (scheme === 'https') {
                    wsPort = currentPort ?? 443;
                    wssPort = currentPort ?? 443;
                } else {
                    wsPort = currentPort ?? 80;
                    wssPort = 443;
                }
            }

            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: reverbKey,
                wsHost: host,
                wsPort,
                wssPort,
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
            });
        } catch (err) {
            console.warn('[Echo] Gagal inisialisasi WebSocket Reverb:', err);
        }
    }
}

/** Nama merek untuk judul tab (APP_NAME di .env sengaja tidak diubah: dipakai prefix cookie/cache). */
const appName = 'Kasir UMKM by MaccaPOS';

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPwaPrompt = e as Window['deferredPwaPrompt'];
    });

    // ─── Cleanup overlay/modal yang stuck di DOM ──────────────────────────
    // SweetAlert2 dapat meninggalkan container kosong di DOM (overlay putih
    // full-screen) jika toast/modal di-close saat animasi sedang berjalan
    // atau jika terjadi error JavaScript di tengah timer.
    // Reka-ui (Dialog/Sheet) juga dapat meninggalkan portal kosong.
    // Guard ini memastikan container yang visible tapi kosong dihapus.
    setInterval(() => {
        // 1. Cleanup SweetAlert2 container kosong
        const swalContainers = document.querySelectorAll<HTMLElement>('.swal2-container');
        swalContainers.forEach((container) => {
            const popup = container.querySelector('.swal2-popup');
            if (!popup && container.style.display !== 'none') {
                container.remove();
            }
        });

        // 2. Cleanup reka-ui Dialog portal yang kosong
        // Reka-ui me-render [data-reka-portal] ke body. Jika dialog di-unmount
        // secara tidak normal, portal bisa tertinggal sebagai overlay kosong.
        const portals = document.querySelectorAll<HTMLElement>('[data-reka-portal]');
        portals.forEach((portal) => {
            // Cek apakah portal ini kosong (tidak ada konten dialog)
            if (portal.children.length === 0) {
                portal.remove();
            }
        });

        // 3. Bersihkan style yang mungkin tertinggal oleh Swal/reka-ui di <body>
        const hasSwal = document.querySelectorAll('.swal2-container').length > 0;
        const hasRekaDialog = document.querySelectorAll('[data-reka-portal] [role="dialog"]').length > 0;
        if (!hasSwal && !hasRekaDialog) {
            if (document.body.style.overflow === 'hidden') {
                document.body.style.removeProperty('overflow');
            }
            if (document.body.style.pointerEvents === 'none') {
                document.body.style.removeProperty('pointer-events');
            }
            document.body.classList.remove('swal2-shown', 'swal2-toast-shown');
        }
    }, 5000);
}

/**
 * Global error boundary — mencegah white screen (WSOD) total ketika
 * error tak tertangkap terjadi di computed, watcher, atau render.
 * Menampilkan placeholder pertahankan UX tetap responsif.
 */
function installErrorBoundary(app: VueApp) {
    const showRecovery = (message: string): void => {
        const existing = document.getElementById('app-recovery-panel');
        if (existing) return;

        const panel = document.createElement('div');
        panel.id = 'app-recovery-panel';
        panel.style.cssText =
            'position:fixed;inset:0;z-index:99998;display:flex;align-items:center;justify-content:center;padding:24px;background:#f8fafc;color:#0f172a;font-family:system-ui,sans-serif;text-align:center';
        panel.innerHTML = `<div style="max-width:420px"><h1 style="font-size:20px;font-weight:700;margin:0 0 8px">Aplikasi perlu dipulihkan</h1><p style="margin:0 0 20px;color:#475569">${message}</p><button id="app-recovery-action" type="button" style="border:0;border-radius:8px;background:#0891b2;color:white;padding:10px 18px;font-weight:600">Coba lagi</button></div>`;
        document.body.appendChild(panel);
        document.getElementById('app-recovery-action')?.addEventListener('click', () => {
            if (navigator.onLine) {
                window.location.reload();
            } else {
                panel.remove();
            }
        });
        window.addEventListener('online', () => document.getElementById('app-recovery-panel')?.remove(), { once: true });
    };

    app.config.errorHandler = (err, instance, info) => {
        console.error('Vue Error:', err, 'info:', info, 'instance:', instance);

        const msg = (err instanceof Error ? err.message : String(err)) || 'Unexpected error';

        // Jangan reload saat offline karena halaman Inertia tidak dapat diambil ulang.
        const isFatal = info?.includes('render') || info?.includes('setup') || msg.includes('props.store');
        if (isFatal && !navigator.onLine) {
            showRecovery('Tidak ada koneksi internet. Data lokal tetap tersimpan, sambungkan internet lalu coba lagi.');
            return;
        }

        // Jika lagi di halaman POS, coba perlihatkan notifikasi non-blocking
        if (typeof Swal !== 'undefined' && typeof Swal.isVisible === 'function' && Swal.isVisible()) {
            Swal.fire({
                title: 'Terjadi Kesalahan',
                text: msg.length < 200 ? msg : msg.slice(0, 200) + '...',
                icon: 'error',
                confirmButtonText: 'Muat Ulang Halaman',
                showCancelButton: !isFatal,
                cancelButtonText: 'Lanjutkan',
            }).then((r) => {
                if (isFatal || r.isConfirmed) {
                    window.location.reload();
                }
            });
        } else if (isFatal) {
            window.location.reload();
        }
    };

    // Tangkap promise rejection yang bocor ke global
    window.addEventListener('unhandledrejection', (event) => {
        console.error('Unhandled Promise Rejection:', event.reason);
    });
}

createInertiaApp({
    // Judul yang sudah memuat merek ("… | Kasir UMKM by MaccaPOS") tidak diberi akhiran APP_NAME lagi
    // (sebelumnya jadi "… Kasir UMKM by MaccaPOS - kasirumkm"; judul halaman lain tidak berubah).
    title: (title) => (!title ? appName : title.includes('Kasir UMKM by MaccaPOS') ? title : `${title} - ${appName}`),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });
        app.use(plugin);

        const initial = (props as { initialPage?: { props?: { flash?: InertiaFlash } } }).initialPage?.props?.flash;
        processInertiaFlash(initial ?? null);

        router.on('success', (event) => {
            const page = (event as CustomEvent<{ page: { props: { flash?: InertiaFlash } } }>).detail?.page;
            processInertiaFlash(page?.props?.flash ?? null);
        });

        // ─── Tangani 419 (session expired) & 503 (server idle) ────
        router.on('error', (event) => {
            const detail = (event as CustomEvent<{ statusCode?: number }>).detail ?? event;
            const status =
                (detail as { statusCode?: number; response?: { status?: number } }).statusCode ??
                (detail as { response?: { status?: number } }).response?.status;

            if (status === 419) {
                window.location.reload();
            } else if (status === 503 && !document.getElementById('app-recovery-panel')) {
                const panel = document.createElement('div');
                panel.id = 'app-recovery-panel';
                panel.style.cssText =
                    'position:fixed;inset:0;z-index:99998;display:flex;align-items:center;justify-content:center;padding:24px;background:#f8fafc;color:#0f172a;font-family:system-ui,sans-serif;text-align:center';
                panel.innerHTML =
                    '<div style="max-width:420px"><h1 style="font-size:20px;font-weight:700;margin:0 0 8px">Server sedang bangun</h1><p style="margin:0 0 20px;color:#475569">Server mungkin sedang dalam mode idle. Menyambungkan kembali...</p></div>';
                document.body.appendChild(panel);
                setTimeout(() => window.location.reload(), 5000);
            }
        });

        installErrorBoundary(app);

        app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
