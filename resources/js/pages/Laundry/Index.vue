<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Shirt, Plus, Loader2, PackageCheck, Wallet, ChevronDown } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

interface Row {
    id: number;
    order_number: string;
    status: string;
    payment_status: string;
    customer_name?: string | null;
    customer_phone?: string | null;
    received_at: string | null;
    estimated_ready_at: string | null;
    ready_at: string | null;
    completed_at: string | null;
    total_amount: number;
    amount_paid: number;
    payment_method?: string | null;
    notes?: string | null;
    user_name?: string | null;
    lines_count: number;
    created_at: string | null;
}

const props = defineProps<{
    orders: Row[];
    statuses: string[];
    store: { id: number; name: string; currency: string };
}>();

// Kolom papan status (tanpa 'cancelled' — ditampilkan terpisah).
const boardColumns: { key: string; label: string; accent: string }[] = [
    { key: 'received', label: 'Diterima', accent: 'border-t-sky-500' },
    { key: 'processing', label: 'Dicuci', accent: 'border-t-amber-500' },
    { key: 'ready', label: 'Siap Diambil', accent: 'border-t-emerald-500' },
    { key: 'completed', label: 'Selesai', accent: 'border-t-slate-400' },
];

const grouped = computed(() => {
    const map: Record<string, Row[]> = {};
    for (const col of boardColumns) map[col.key] = [];
    map['cancelled'] = [];
    for (const o of props.orders) {
        (map[o.status] ??= []).push(o);
    }
    return map;
});

// Jumlah order yang tampil sebelum tombol "Lihat lainnya".
const PREVIEW_LIMIT = 3;

// State collapse per kolom (default: terbuka) dan "tampilkan semua" per kolom.
const collapsed = reactive<Record<string, boolean>>({});
const expanded = reactive<Record<string, boolean>>({});

function toggleCollapse(key: string) {
    collapsed[key] = !collapsed[key];
}

function toggleExpand(key: string) {
    expanded[key] = !expanded[key];
}

// Daftar order yang ditampilkan pada kolom: dibatasi PREVIEW_LIMIT kecuali sedang "tampilkan semua".
function visibleOrders(key: string): Row[] {
    const list = grouped.value[key] ?? [];
    return expanded[key] ? list : list.slice(0, PREVIEW_LIMIT);
}

function hiddenCount(key: string): number {
    return Math.max(0, (grouped.value[key]?.length ?? 0) - PREVIEW_LIMIT);
}

// Ringkasan untuk kartu statistik di atas papan.
const stats = computed(() => {
    const active = props.orders.filter((o) => ['received', 'processing'].includes(o.status));
    const ready = props.orders.filter((o) => o.status === 'ready');
    const outstanding = props.orders
        .filter((o) => o.status !== 'cancelled')
        .reduce((sum, o) => sum + Math.max(0, (o.total_amount || 0) - (o.amount_paid || 0)), 0);
    return {
        active: active.length,
        ready: ready.length,
        outstanding,
    };
});

function paymentBadge(status: string) {
    switch (status) {
        case 'paid':
            return { variant: 'default' as const, label: 'Lunas' };
        case 'partial':
            return { variant: 'secondary' as const, label: 'DP' };
        default:
            return { variant: 'outline' as const, label: 'Belum bayar' };
    }
}

// Status berikutnya untuk tombol maju cepat.
function nextStatus(status: string): string | null {
    const flow = ['received', 'processing', 'ready', 'completed'];
    const i = flow.indexOf(status);
    if (i === -1 || i >= flow.length - 1) return null;
    return flow[i + 1];
}

function nextLabel(status: string): string {
    switch (status) {
        case 'received':
            return 'Mulai cuci';
        case 'processing':
            return 'Tandai siap';
        case 'ready':
            return 'Selesai (diambil)';
        default:
            return 'Lanjut';
    }
}

function advance(o: Row) {
    const next = nextStatus(o.status);
    if (!next) return;
    router.post(
        `/laundry-orders/${o.id}/status`,
        { status: next },
        { preserveScroll: true },
    );
}

function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatDateTime(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <AdminLayout title="Order Laundry">
        <Head title="Order Laundry" />

        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                    <Shirt class="h-7 w-7 text-cyan-600" />
                    Order Laundry
                </h1>
                <p class="text-sm text-slate-600 mt-1">{{ store.name }} — papan status pengerjaan cucian.</p>
            </div>
            <Button as="a" href="/laundry-orders/create" class="rounded-xl inline-flex items-center gap-2 w-fit">
                <Plus class="h-4 w-4" />
                Order baru
            </Button>
        </div>

        <!-- Kartu statistik ringkas (selaras dengan gaya Dashboard) -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-6 mb-6">
            <div class="p-[2px] bg-gradient-to-br from-sky-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                <Card class="bg-white rounded-[2rem] border-0 h-full">
                    <CardContent class="p-4 md:p-6">
                        <div class="flex items-center justify-between">
                            <div class="p-3 bg-sky-500/10 rounded-2xl">
                                <Loader2 class="h-6 w-6 text-sky-600" />
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-medium text-slate-500 mb-1">Sedang dikerjakan</p>
                                <p class="text-2xl font-black text-foreground font-display">{{ stats.active }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="p-[2px] bg-gradient-to-br from-emerald-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                <Card class="bg-white rounded-[2rem] border-0 h-full">
                    <CardContent class="p-4 md:p-6">
                        <div class="flex items-center justify-between">
                            <div class="p-3 bg-emerald-500/10 rounded-2xl">
                                <PackageCheck class="h-6 w-6 text-emerald-600" />
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-medium text-slate-500 mb-1">Siap diambil</p>
                                <p class="text-2xl font-black text-foreground font-display">{{ stats.ready }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="p-[2px] bg-gradient-to-br from-amber-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                <Card class="bg-white rounded-[2rem] border-0 h-full">
                    <CardContent class="p-4 md:p-6">
                        <div class="flex items-center justify-between">
                            <div class="p-3 bg-amber-500/10 rounded-2xl">
                                <Wallet class="h-6 w-6 text-amber-600" />
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-medium text-slate-500 mb-1">Sisa tagihan</p>
                                <p class="text-2xl font-black text-foreground font-display">Rp {{ formatMoney(stats.outstanding) }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="col in boardColumns"
                :key="col.key"
                class="rounded-2xl border-t-4 bg-slate-50 p-3"
                :class="col.accent"
            >
                <button
                    type="button"
                    class="mb-3 flex w-full items-center justify-between gap-2 px-1"
                    @click="toggleCollapse(col.key)"
                >
                    <span class="flex items-center gap-1.5 font-semibold text-slate-700">
                        <ChevronDown
                            class="h-4 w-4 text-slate-400 transition-transform"
                            :class="collapsed[col.key] ? '-rotate-90' : ''"
                        />
                        {{ col.label }}
                    </span>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-500 shadow-sm">
                        {{ grouped[col.key].length }}
                    </span>
                </button>

                <div v-show="!collapsed[col.key]" class="max-h-[60vh] space-y-3 overflow-y-auto pr-0.5">
                    <Card
                        v-for="o in visibleOrders(col.key)"
                        :key="o.id"
                        class="border-0 shadow-sm"
                    >
                        <CardContent class="p-2 space-y-1">
                            <div class="flex items-center justify-between gap-1.5">
                                <Link
                                    :href="`/laundry-orders/${o.id}`"
                                    class="truncate font-mono text-[11px] text-primary font-semibold hover:underline"
                                >
                                    {{ o.order_number }}
                                </Link>
                                <Badge :variant="paymentBadge(o.payment_status).variant" class="shrink-0 text-[9px] px-1.5 py-0">
                                    {{ paymentBadge(o.payment_status).label }}
                                </Badge>
                            </div>
                            <div class="flex items-center justify-between gap-1.5">
                                <span class="truncate text-xs font-medium text-slate-800">{{ o.customer_name || 'Tanpa nama' }}</span>
                                <span class="shrink-0 tabular-nums text-xs font-semibold text-slate-700">Rp {{ formatMoney(o.total_amount) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span>{{ o.lines_count }} item</span>
                                <span>{{ formatDateTime(o.received_at) }}</span>
                            </div>
                            <Button
                                v-if="nextStatus(o.status)"
                                type="button"
                                size="sm"
                                class="h-7 w-full rounded-lg text-[11px]"
                                @click="advance(o)"
                            >
                                {{ nextLabel(o.status) }}
                            </Button>
                        </CardContent>
                    </Card>

                    <button
                        v-if="hiddenCount(col.key) > 0"
                        type="button"
                        class="w-full rounded-lg border border-dashed border-slate-300 py-2 text-xs font-medium text-slate-500 hover:bg-white"
                        @click="toggleExpand(col.key)"
                    >
                        {{ expanded[col.key] ? 'Tampilkan lebih sedikit' : `Lihat ${hiddenCount(col.key)} orderan lainnya` }}
                    </button>

                    <p v-if="grouped[col.key].length === 0" class="px-1 py-6 text-center text-xs text-slate-400">
                        Kosong
                    </p>
                </div>
            </div>
        </div>

        <Card v-if="grouped['cancelled'].length" class="border-0 shadow-lg mt-6">
            <CardHeader>
                <CardTitle class="text-base">Dibatalkan</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <Link
                    v-for="o in grouped['cancelled']"
                    :key="o.id"
                    :href="`/laundry-orders/${o.id}`"
                    class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-mono text-slate-500 hover:bg-slate-50"
                >
                    {{ o.order_number }}
                </Link>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
