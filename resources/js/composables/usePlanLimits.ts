import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/** Paket GrowUp: fitur lanjutan (shift kasir, diskon, pembelian, dll.). */
export function useAllowsAdvancedRetail() {
    const page = usePage();
    return computed(() => {
        const pl = page.props.plan_limits as { allows_advanced_retail?: boolean } | null | undefined;
        if (pl == null) return true;
        return pl.allows_advanced_retail === true;
    });
}
