import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { confirmLeave } from '@/lib/swal';

/**
 * Cegah perubahan hilang: konfirmasi saat pindah halaman (kunjungan GET Inertia)
 * atau menutup/memuat ulang tab, selama `isDirty()` bernilai true.
 * Kiriman form (POST/PUT/PATCH) tidak dihalangi.
 */
export function useUnsavedGuard(isDirty: () => boolean, isBusy: () => boolean = () => false) {
    let removeBefore: (() => void) | null = null;
    // Lewati penjagaan untuk kunjungan ulang setelah pengguna memilih keluar.
    let bypass = false;

    const onBeforeUnload = (e: BeforeUnloadEvent) => {
        if (!isDirty() || isBusy()) return;
        e.preventDefault();
        e.returnValue = '';
    };

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        removeBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (!isDirty() || isBusy() || visit.method !== 'get') return;
            // Reload parsial (mis. polling pesanan meja di AdminLayout), prefetch, atau
            // kunjungan ke halaman yang sama tidak meninggalkan halaman — jangan ditanya.
            if (visit.only?.length || visit.except?.length || visit.prefetch) return;
            if (visit.url.pathname === window.location.pathname) return;
            if (bypass) return;

            // Tahan kunjungan, tanyakan lewat popup bertema aplikasi, lalu ulangi bila setuju.
            confirmLeave().then((leave) => {
                if (!leave) return;
                bypass = true;
                router.visit(visit.url.href, {
                    replace: visit.replace,
                    preserveScroll: visit.preserveScroll,
                    preserveState: visit.preserveState,
                    onFinish: () => {
                        bypass = false;
                    },
                });
            });
            return false;
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeBefore?.();
    });
}
