<script lang="ts">
/**
 * Posisi indikator & scroll disimpan di level modul: setiap pindah tab, Inertia memasang ulang
 * komponen ini di halaman baru, jadi indikator melanjutkan animasi dari posisi tab sebelumnya.
 */
const memory: { back: number | null; front: number; scrollLeft: number } = { back: null, front: 0, scrollLeft: 0 };
</script>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';

const props = defineProps<{
    activeTab: string;
}>();

const allowsAdvancedRetail = useAllowsAdvancedRetail();
const page = usePage();
const pharmacyEnabled = computed(() => Boolean((page.props as { pharmacy_enabled?: boolean }).pharmacy_enabled));
const accountingEnabled = computed(() => Boolean((page.props as { accounting_enabled?: boolean }).accounting_enabled));
const barbershopEnabled = computed(() => Boolean((page.props as { barbershop_enabled?: boolean }).barbershop_enabled));

type Tab = { label: string; href: string; key: string; financial?: boolean; advanced?: boolean; pharmacy?: boolean; barbershop?: boolean };

const tabs: Tab[] = [
    { label: 'Ringkasan', href: '/reports', key: 'ringkasan' },
    { label: 'Penjualan', href: '/reports/sales', key: 'penjualan' },
    { label: 'Komisi Barber', href: '/reports/commissions', key: 'komisi', advanced: true, barbershop: true },
    { label: 'Pembelian', href: '/reports/purchases', key: 'pembelian' },
    { label: 'Stok', href: '/reports/inventory', key: 'stok' },
    { label: 'Pemusnahan', href: '/reports/destructions', key: 'pemusnahan', pharmacy: true },
    { label: 'Selisih stok', href: '/reports/stock-variance', key: 'selisih-stok', advanced: true },
    { label: 'Kartu stok', href: '/reports/stock-card', key: 'kartu-stok', advanced: true },
    { label: 'Produksi', href: '/reports/production', key: 'produksi' },
    { label: 'Shift kasir', href: '/reports/cash-shifts', key: 'shift', advanced: true },
    { label: 'Neraca', href: '/financial/balance-sheet', key: 'neraca', financial: true },
    { label: 'Rekonsiliasi', href: '/financial/reconciliation', key: 'rekonsiliasi', financial: true },
    { label: 'Perubahan Ekuitas', href: '/financial/statement-of-equity-changes', key: 'perubahan-ekuitas', financial: true },
    { label: 'Arus Kas Akuntansi', href: '/financial/cash-flow', key: 'arus-kas-akuntansi', financial: true },
    { label: 'Periode', href: '/financial/accounting-periods', key: 'periode-akuntansi', financial: true },
    { label: 'Jurnal', href: '/financial/journal', key: 'jurnal', financial: true },
    { label: 'Neraca saldo', href: '/financial/trial-balance', key: 'neraca-saldo', financial: true },
    { label: 'Laba rugi', href: '/financial/income-statement', key: 'laba-rugi', financial: true },
    { label: 'Rekap PPN', href: '/reports/tax/ppn-summary', key: 'ppn', financial: true },
    { label: 'CaLK', href: '/financial/calk', key: 'calk', financial: true },
    { label: 'Arus Kas', href: '/reports/cash-flow', key: 'cash-flow', financial: true },
    { label: 'Kas Masuk/Keluar', href: '/reports/cash-transactions', key: 'cash-transactions', advanced: true },
    { label: 'Utang Usaha', href: '/reports/aging-payables', key: 'aging-payables', financial: true },
];

const visibleTabs = computed(() =>
    tabs.filter(
        (tab) =>
            (!tab.advanced || allowsAdvancedRetail.value) &&
            (!tab.pharmacy || pharmacyEnabled.value) &&
            (!tab.financial || accountingEnabled.value) &&
            (!tab.barbershop || barbershopEnabled.value),
    ),
);

/** Tab yang baru diklik — indikator langsung bergerak tanpa menunggu halaman berikutnya selesai dimuat. */
const pendingKey = ref<string | null>(null);
const currentKey = computed(() => pendingKey.value ?? props.activeTab);

/** ── Indikator elastis (Elastic Tabs) ── */
const scrollRef = ref<HTMLElement | null>(null);
const trackRef = ref<HTMLElement | null>(null);
const indRef = ref<HTMLElement | null>(null);
const itemEls = new Map<string, HTMLElement>();

const setItemRef = (key: string, el: unknown) => {
    const node = (el as { $el?: HTMLElement } | null)?.$el ?? (el as HTMLElement | null);
    if (node) {
        itemEls.set(key, node);
    } else {
        itemEls.delete(key);
    }
};

const SPEED = 18;
const LAG = 0.3;
const BOUNCE = 0.5;

const prefersReducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let front = 0;
let back = 0;
let vF = 0;
let vB = 0;
let rafId = 0;
let resizeObserver: ResizeObserver | null = null;
let removeStartListener: (() => void) | null = null;
let removeFinishListener: (() => void) | null = null;

const measure = (): [number, number] | null => {
    const el = itemEls.get(currentKey.value);
    const track = trackRef.value;
    if (!el || !track) {
        return null;
    }
    const r = el.getBoundingClientRect();
    const w = track.getBoundingClientRect();
    return [r.left - w.left, r.right - w.left];
};

const paint = () => {
    const ind = indRef.value;
    if (!ind) {
        return;
    }
    ind.style.transform = `translateX(${back.toFixed(1)}px)`;
    ind.style.width = `${Math.max(4, front - back).toFixed(1)}px`;
};

const frame = () => {
    const t = measure();
    if (!t) {
        rafId = 0;
        return;
    }
    const [tl, tr] = t;
    const k = SPEED * 0.012;
    vF += (tr - front) * k;
    vF *= BOUNCE;
    front += vF;
    vB += (tl - back) * (k * LAG);
    vB *= BOUNCE;
    back += vB;
    paint();
    if (Math.abs(tl - back) < 0.1 && Math.abs(tr - front) < 0.1 && Math.abs(vB) < 0.05 && Math.abs(vF) < 0.05) {
        back = tl;
        front = tr;
        vB = 0;
        vF = 0;
        paint();
        rafId = 0;
        return;
    }
    rafId = requestAnimationFrame(frame);
};

const kick = () => {
    if (prefersReducedMotion) {
        const t = measure();
        if (t) {
            back = t[0];
            front = t[1];
            paint();
        }
        return;
    }
    if (!rafId) {
        rafId = requestAnimationFrame(frame);
    }
};

/** Geser track supaya tab aktif terlihat (berguna di layar sempit / tab di ujung kanan). */
const revealActive = (smooth: boolean) => {
    const scroller = scrollRef.value;
    const el = itemEls.get(currentKey.value);
    if (!scroller || !el) {
        return;
    }
    const s = scroller.getBoundingClientRect();
    const r = el.getBoundingClientRect();
    const pad = 24;
    let delta = 0;
    if (r.left < s.left + pad) {
        delta = r.left - s.left - pad;
    } else if (r.right > s.right - pad) {
        delta = r.right - s.right + pad;
    }
    if (delta !== 0) {
        scroller.scrollTo({ left: scroller.scrollLeft + delta, behavior: smooth && !prefersReducedMotion ? 'smooth' : 'auto' });
    }
};

const pathOf = (url: string | URL) => {
    try {
        return new URL(url.toString(), window.location.origin).pathname.replace(/\/+$/, '') || '/';
    } catch {
        return '';
    }
};

onMounted(async () => {
    await nextTick();
    if (scrollRef.value) {
        scrollRef.value.scrollLeft = memory.scrollLeft;
    }
    const t = measure();
    if (memory.back !== null && !prefersReducedMotion) {
        back = memory.back;
        front = memory.front;
        paint();
    } else if (t) {
        back = t[0];
        front = t[1];
        paint();
    }
    revealActive(true);
    kick();

    window.addEventListener('resize', kick);
    if (trackRef.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => kick());
        resizeObserver.observe(trackRef.value);
    }

    removeStartListener = router.on('start', (event) => {
        const path = pathOf(event.detail.visit.url);
        const tab = visibleTabs.value.find((item) => item.href === path);
        if (tab && tab.key !== currentKey.value) {
            pendingKey.value = tab.key;
        }
    });
    // Kunjungan batal/gagal: kembalikan indikator ke menu halaman yang sedang tampil.
    removeFinishListener = router.on('finish', () => {
        pendingKey.value = null;
    });
});

onBeforeUnmount(() => {
    if (rafId) {
        cancelAnimationFrame(rafId);
    }
    memory.back = back;
    memory.front = front;
    memory.scrollLeft = scrollRef.value?.scrollLeft ?? 0;
    window.removeEventListener('resize', kick);
    resizeObserver?.disconnect();
    removeStartListener?.();
    removeFinishListener?.();
});

watch([currentKey, visibleTabs], async () => {
    await nextTick();
    revealActive(true);
    kick();
});
</script>

<template>
    <div class="mb-5 min-w-0 max-w-full">
        <div ref="scrollRef" class="overflow-x-auto pb-1 [scrollbar-width:thin]">
            <div
                ref="trackRef"
                class="relative inline-flex gap-1 rounded-full border border-slate-200 bg-white p-1.5 shadow-sm"
            >
                <!-- Indikator tab aktif yang bergerak secara elastis -->
                <div
                    ref="indRef"
                    class="pointer-events-none absolute top-1.5 bottom-1.5 left-0 rounded-full bg-violet-600 shadow-md shadow-violet-200 transition-opacity duration-200"
                    :style="{ opacity: visibleTabs.some((tab) => tab.key === currentKey) ? 1 : 0 }"
                ></div>
                <Link
                    v-for="tab in visibleTabs"
                    :key="tab.key"
                    :ref="(el) => setItemRef(tab.key, el)"
                    :href="tab.href"
                    class="relative z-10 inline-flex items-center whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition-colors duration-200 outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
                    :class="currentKey === tab.key
                        ? 'text-white'
                        : tab.financial
                            ? 'text-violet-700/80 hover:text-violet-900'
                            : 'text-slate-500 hover:text-slate-900'"
                    :aria-current="currentKey === tab.key ? 'page' : undefined"
                >
                    {{ tab.label }}
                </Link>
            </div>
        </div>
    </div>
</template>
