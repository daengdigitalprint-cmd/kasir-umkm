import '../css/app.css';
import 'sweetalert2/dist/sweetalert2.min.css';

import '@/types/pwa-window';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { processInertiaFlash, type InertiaFlash } from './lib/swal';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.deferredPwaPrompt = e as Window['deferredPwaPrompt'];
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
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

        app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
