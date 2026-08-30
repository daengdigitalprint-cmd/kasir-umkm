import '../css/app.css';
import 'sweetalert2/dist/sweetalert2.min.css';

import '@/types/pwa-window';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h, type App as VueApp } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { processInertiaFlash, type InertiaFlash } from './lib/swal';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPwaPrompt = e as Window['deferredPwaPrompt'];
    });
}

/**
 * Global error boundary — mencegah white screen (WSOD) total ketika
 * error tak tertangkap terjadi di computed, watcher, atau render.
 * Menampilkan placeholder pertahankan UX tetap responsif.
 */
function installErrorBoundary(app: VueApp) {
    app.config.errorHandler = (err, instance, info) => {
        console.error('Vue Error:', err, 'info:', info, 'instance:', instance);

        const msg = (err instanceof Error ? err.message : String(err)) || 'Unexpected error';

        // Jika lagi di halaman POS, coba perlihatkan notifikasi non-blocking
        const isFatal = info?.includes('render') || info?.includes('setup') || msg.includes('props.store');
        if (typeof Swal !== 'undefined' && Swal.isFire) {
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
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });
        app.use(plugin);

        const initial = (props as { initialPage?: { props?: { flash?: InertiaFlash } } }).initialPage?.props
            ?.flash;
        processInertiaFlash(initial ?? null);

        router.on('success', (event) => {
            const page = (event as CustomEvent<{ page: { props: { flash?: InertiaFlash } } }>).detail
                ?.page;
            processInertiaFlash(page?.props?.flash ?? null);
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
