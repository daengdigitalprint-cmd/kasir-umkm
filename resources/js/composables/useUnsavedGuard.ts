import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

const LEAVE_MESSAGE = 'Ada perubahan yang belum disimpan. Tinggalkan halaman ini? Perubahan akan hilang.';

/**
 * Cegah perubahan hilang: konfirmasi saat pindah halaman (kunjungan GET Inertia)
 * atau menutup/memuat ulang tab, selama `isDirty()` bernilai true.
 * Kiriman form (POST/PUT/PATCH) tidak dihalangi.
 */
export function useUnsavedGuard(isDirty: () => boolean, isBusy: () => boolean = () => false) {
    let removeBefore: (() => void) | null = null;

    const onBeforeUnload = (e: BeforeUnloadEvent) => {
        if (!isDirty() || isBusy()) return;
        e.preventDefault();
        e.returnValue = '';
    };

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        removeBefore = router.on('before', (event) => {
            if (!isDirty() || isBusy() || event.detail.visit.method !== 'get') return;
            if (!window.confirm(LEAVE_MESSAGE)) return false;
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeBefore?.();
    });
}
