<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowRightLeft, ArrowUpRight, ChevronRight, PackageCheck, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

interface StoreTiny {
    id: number;
    name: string;
}

interface TransferRow {
    id: number;
    transfer_number: string;
    status: string;
    created_at: string;
    sent_at: string | null;
    received_at: string | null;
    notes: string | null;
    lines_count: number;
    discrepancy_lines_count?: number;
    open_discrepancy_lines_count?: number;
    pending_corrections_count?: number;
    from_store: StoreTiny;
    to_store: StoreTiny;
    user: { id: number; name: string } | null;
}

const props = withDefaults(
    defineProps<{
        transfers: { data: TransferRow[]; links: { url: string | null; label: string; active: boolean }[] };
        currentStore: { id: number; name: string };
        canCreateTransfer?: boolean;
        statusFilter?: string | null;
        directionFilter?: string | null;
        discrepancyFilter?: boolean;
        needsResolutionFilter?: boolean;
        needsResolutionCount?: number;
        incomingCount?: number;
    }>(),
    {
        canCreateTransfer: true,
        statusFilter: null,
        directionFilter: null,
        discrepancyFilter: false,
        needsResolutionFilter: false,
        incomingCount: 0,
        needsResolutionCount: 0,
    },
);

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const isIncoming = (t: TransferRow) => t.to_store.id === props.currentStore.id;

/** Status dari sudut pandang toko aktif. */
function statusOf(t: TransferRow): { label: string; class: string } {
    if (t.status === 'sent') {
        return isIncoming(t)
            ? { label: 'Menunggu diterima', class: 'bg-amber-100 text-amber-800' }
            : { label: 'Dalam perjalanan', class: 'bg-sky-100 text-sky-800' };
    }
    return (
        (
            {
                draft: { label: 'Draft', class: 'bg-slate-100 text-slate-600' },
                received: { label: 'Diterima', class: 'bg-emerald-100 text-emerald-800' },
                cancelled: { label: 'Dibatalkan', class: 'bg-red-50 text-red-700' },
            } as Record<string, { label: string; class: string }>
        )[t.status] ?? { label: t.status, class: 'bg-slate-100 text-slate-600' }
    );
}

type FilterKey = 'all' | 'incoming' | 'in' | 'out' | 'draft' | 'discrepancy' | 'resolve';
const activeFilter = computed<FilterKey>(() => {
    if (props.needsResolutionFilter) return 'resolve';
    if (props.discrepancyFilter) return 'discrepancy';
    if (props.directionFilter === 'incoming') return 'incoming';
    if (props.directionFilter === 'in') return 'in';
    if (props.directionFilter === 'out') return 'out';
    if (props.statusFilter === 'draft') return 'draft';
    return 'all';
});

function setFilter(key: FilterKey) {
    const params: Record<string, string> = {};
    if (key === 'incoming' || key === 'in' || key === 'out') params.direction = key;
    if (key === 'draft') params.status = 'draft';
    if (key === 'discrepancy') params.discrepancy = '1';
    if (key === 'resolve') params.needs_resolution = '1';
    router.get('/stock-transfers', params, { preserveState: true, preserveScroll: true, replace: true });
}

const FILTERS = computed(() => [
    { key: 'all' as const, label: 'Semua' },
    { key: 'incoming' as const, label: 'Perlu diterima', count: props.incomingCount },
    { key: 'in' as const, label: 'Masuk' },
    { key: 'out' as const, label: 'Keluar' },
    { key: 'draft' as const, label: 'Draft' },
    { key: 'discrepancy' as const, label: 'Ada selisih' },
    { key: 'resolve' as const, label: 'Perlu diputuskan', count: props.needsResolutionCount },
]);
</script>

<template>
    <Head title="Transfer stok" />

    <AdminLayout title="Transfer stok">
        <div class="mx-auto max-w-5xl space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-500">
                    Kirim stok antar outlet dalam satu grup. Stok berkurang saat dikirim dan bertambah saat diterima outlet tujuan.
                </p>
                <Link
                    v-if="canCreateTransfer"
                    href="/stock-transfers/create"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90"
                >
                    <Plus class="h-4 w-4" /> Transfer baru
                </Link>
                <p v-else class="text-sm text-slate-600">
                    Transfer antar outlet tersedia di paket GrowUp.
                    <Link href="/subscription" class="font-semibold text-primary hover:underline">Lihat paket</Link>
                </p>
            </div>

            <!-- Kiriman yang menunggu diterima di toko ini -->
            <button
                v-if="incomingCount > 0 && activeFilter !== 'incoming'"
                type="button"
                class="flex w-full items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-left hover:bg-amber-100/60"
                @click="setFilter('incoming')"
            >
                <PackageCheck class="h-5 w-5 shrink-0 text-amber-600" />
                <span class="flex-1 text-sm text-amber-900">
                    <strong>{{ incomingCount }} kiriman menunggu diterima</strong> di {{ currentStore.name }}. Terima agar stoknya bertambah.
                </span>
                <ChevronRight class="h-4 w-4 text-amber-500" />
            </button>

            <!-- F8: selisih penerimaan yang menunggu keputusan toko ini sebagai pengirim -->
            <button
                v-if="needsResolutionCount > 0 && activeFilter !== 'resolve'"
                type="button"
                class="flex w-full items-center gap-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-left hover:bg-red-100/60"
                @click="setFilter('resolve')"
            >
                <PackageCheck class="h-5 w-5 shrink-0 text-red-600" />
                <span class="flex-1 text-sm text-red-900">
                    <strong>{{ needsResolutionCount }} kiriman menunggu keputusan Anda.</strong>
                    Ada selisih penerimaan atau pengajuan koreksi yang belum diputuskan.
                </span>
                <ChevronRight class="h-4 w-4 text-red-500" />
            </button>

            <section class="rounded-2xl border border-slate-200 bg-white">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button
                        v-for="f in FILTERS"
                        :key="f.key"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                        :class="activeFilter === f.key ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'"
                        @click="setFilter(f.key)"
                    >
                        {{ f.label }}
                        <span
                            v-if="f.count"
                            class="rounded-full px-1.5 text-xs"
                            :class="activeFilter === f.key ? 'bg-white/20' : 'bg-amber-100 text-amber-700'"
                            >{{ f.count }}</span
                        >
                    </button>
                </div>

                <div v-if="!transfers.data.length" class="px-6 py-14 text-center">
                    <ArrowRightLeft class="mx-auto mb-3 h-8 w-8 text-slate-300" />
                    <p class="font-semibold text-slate-700">
                        {{ activeFilter === 'all' ? 'Belum ada transfer' : 'Tidak ada transfer di daftar ini' }}
                    </p>
                    <Link
                        v-if="activeFilter === 'all' && canCreateTransfer"
                        href="/stock-transfers/create"
                        class="mt-3 inline-block text-sm font-semibold text-primary hover:underline"
                    >
                        Buat transfer pertama
                    </Link>
                </div>

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="t in transfers.data" :key="t.id">
                        <Link
                            :href="`/stock-transfers/${t.id}`"
                            class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50"
                            :class="{ 'bg-amber-50/40': t.status === 'sent' && isIncoming(t) }"
                        >
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                :class="isIncoming(t) ? 'bg-emerald-50 text-emerald-600' : 'bg-sky-50 text-sky-600'"
                                :title="isIncoming(t) ? 'Masuk' : 'Keluar'"
                            >
                                <ArrowDownLeft v-if="isIncoming(t)" class="h-4 w-4" />
                                <ArrowUpRight v-else class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-900">
                                    {{ isIncoming(t) ? `Dari ${t.from_store.name}` : `Ke ${t.to_store.name}` }}
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span class="font-mono">{{ t.transfer_number }}</span>
                                    <span>· {{ formatWhen(t.created_at) }}</span>
                                    <span>· {{ t.lines_count }} barang</span>
                                    <span v-if="t.user">· {{ t.user.name }}</span>
                                </span>
                            </span>
                            <span class="flex shrink-0 flex-col items-end gap-1">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusOf(t).class">{{ statusOf(t).label }}</span>
                                <span
                                    v-if="t.pending_corrections_count"
                                    class="rounded-full bg-amber-500 px-2 py-0.5 text-[11px] font-semibold text-white"
                                >
                                    Koreksi menunggu
                                </span>
                                <span
                                    v-if="t.open_discrepancy_lines_count"
                                    class="rounded-full bg-red-600 px-2 py-0.5 text-[11px] font-semibold text-white"
                                >
                                    {{ t.open_discrepancy_lines_count }} selisih belum diputuskan
                                </span>
                                <span
                                    v-else-if="t.discrepancy_lines_count"
                                    class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700"
                                >
                                    {{ t.discrepancy_lines_count }} selisih
                                </span>
                            </span>
                            <ChevronRight class="h-4 w-4 shrink-0 text-slate-300" />
                        </Link>
                    </li>
                </ul>

                <div v-if="transfers.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 px-4 py-3">
                    <Link
                        v-for="(l, i) in transfers.links"
                        :key="i"
                        :href="l.url || '#'"
                        class="min-w-8 rounded-lg px-2.5 py-1 text-sm"
                        :class="
                            l.active ? 'bg-primary text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'cursor-not-allowed text-slate-300'
                        "
                        preserve-state
                        preserve-scroll
                    >
                        <span v-html="l.label" />
                    </Link>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
