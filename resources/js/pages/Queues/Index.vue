<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { toastSuccess, toastError } from '@/lib/swal';
import { ListOrdered, RefreshCw, ChevronRight, Clock, Package, AlertTriangle, CheckCircle2, Timer, ShoppingCart, Utensils, Hand } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted } from 'vue';

interface Queue {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    entries_count: number;
    waiting_count: number;
    serving_count: number;
    completed_today_count: number;
}

interface QueueEntry {
    id: number;
    queue_id: number;
    queue_name: string | null;
    queue_code: string | null;
    number: number;
    status: string;
    order_type: string | null;
    table_number: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    priority: number;
    notes: string | null;
    items: Array<{ product_name: string; quantity: number }> | null;
    called_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    queues: Queue[];
    entries: QueueEntry[];
    store: { id: number; name: string };
}>();

// --- Auto refresh (15 detik) ---
const refresh = () => {
    router.reload({ only: ['queues', 'entries'] });
};

let pollId: ReturnType<typeof setInterval> | null = null;
onMounted(() => { pollId = setInterval(refresh, 15000); });
onUnmounted(() => { if (pollId !== null) clearInterval(pollId); });

// --- Stat cards ---
const stats = computed(() => {
    const list = props.entries;
    const now = Date.now();
    const TEN_MIN = 10 * 60 * 1000;

    const waiting = list.filter((e) => e.status === 'waiting');
    const serving = list.filter((e) => e.status === 'serving');
    const ready = list.filter((e) => e.status === 'ready');
    const completed = list.filter((e) => e.status === 'completed');
    const cancelled = list.filter((e) => e.status === 'cancelled');

    const active = list.length - completed.length - cancelled.length;
    const overdue = [...waiting, ...serving].filter((e) => now - new Date(e.created_at).getTime() > TEN_MIN).length;

    return [
        { label: 'Total Aktif', value: active, subtitle: 'pesanan berjalan', color: 'bg-violet-50 text-violet-600', iconBg: 'bg-violet-100' },
        { label: 'Antrian Baru', value: waiting.length, subtitle: 'menunggu dibuat', color: 'bg-amber-50 text-amber-600', iconBg: 'bg-amber-100' },
        { label: 'Sedang Dibuat', value: serving.length, subtitle: 'sedang diproses', color: 'bg-blue-50 text-blue-600', iconBg: 'bg-blue-100' },
        { label: 'Siap Diserahkan', value: ready.length, subtitle: 'siap diambil', color: 'bg-emerald-50 text-emerald-600', iconBg: 'bg-emerald-100' },
        { label: 'Selesai Hari Ini', value: completed.length, subtitle: 'hari ini', color: 'bg-slate-50 text-slate-600', iconBg: 'bg-slate-100' },
        { label: 'Terlambat', value: overdue, subtitle: 'lebih dari 10 menit', color: 'bg-red-50 text-red-600', iconBg: 'bg-red-100' },
    ];
});

const statIcons = [ShoppingCart, ListOrdered, Utensils, Hand, CheckCircle2, AlertTriangle];

// --- Kanban columns ---
const columns = computed(() => {
    const list = props.entries;
    return [
        {
            key: 'waiting',
            label: 'Antrian Baru',
            accent: 'border-t-amber-500',
            badge: 'bg-amber-100 text-amber-700',
            entries: list.filter((e) => e.status === 'waiting'),
        },
        {
            key: 'serving',
            label: 'Sedang Dibuat',
            accent: 'border-t-blue-500',
            badge: 'bg-blue-100 text-blue-700',
            entries: list.filter((e) => e.status === 'serving'),
        },
        {
            key: 'ready',
            label: 'Siap Diserahkan',
            accent: 'border-t-emerald-500',
            badge: 'bg-emerald-100 text-emerald-700',
            entries: list.filter((e) => e.status === 'ready'),
        },
        {
            key: 'completed',
            label: 'Selesai',
            accent: 'border-t-slate-400',
            badge: 'bg-slate-100 text-slate-600',
            entries: list.filter((e) => e.status === 'completed'),
        },
    ];
});

// --- Time helper ---
function elapsed(dateStr: string | null): string {
    if (!dateStr) return '0:00';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

// --- Advance status (fetch + toast, tanpa full page reload) ---
let isUpdating = false;

const advanceStatus = async (entry: QueueEntry) => {
    if (isUpdating) return;
    isUpdating = true;

    const next: Record<string, string> = {
        waiting: 'serving',
        serving: 'ready',
        ready: 'completed',
    };
    const target = next[entry.status];
    if (!target) { isUpdating = false; return; }

    try {
        const res = await fetch(`/queue-entries/${entry.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({ status: target }),
        });

        if (res.ok) {
            const data = await res.json();
            toastSuccess(data.message || 'Status diperbarui', 1500);
            router.reload({ only: ['entries', 'queues'] });
        } else {
            toastError('Gagal memperbarui status');
        }
    } catch {
        toastError('Gagal memperbarui status');
    } finally {
        isUpdating = false;
    }
};

const nextStatusLabel = (status: string): string => {
    switch (status) {
        case 'waiting': return 'Mulai Buat';
        case 'serving': return 'Siap';
        case 'ready': return 'Selesai';
        default: return '';
    }
};
</script>

<template>
    <AdminLayout title="Papan Pesanan">
        <Head title="Papan Pesanan" />

        <div class="flex flex-col h-[calc(100vh-4rem)] pb-4">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4 shrink-0">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-black text-slate-900">Papan Pesanan</h1>
                    <span class="flex items-center gap-1.5 text-xs font-bold text-emerald-600">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        Live
                    </span>
                    <span class="text-xs text-slate-400">· sinkron kasir</span>
                </div>
                <Button type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" />
                </Button>
            </div>

            <!-- Stat Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 px-4 mt-3 shrink-0">
                <div
                    v-for="(stat, i) in stats"
                    :key="stat.label"
                    :class="['rounded-2xl border border-slate-100 bg-white p-3 flex items-center gap-3']"
                >
                    <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', stat.iconBg]">
                        <component :is="statIcons[i]" :class="['h-5 w-5', stat.color.split(' ')[1]]" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold text-slate-500 truncate">{{ stat.label }}</p>
                        <p :class="['text-xl font-black tabular-nums', stat.color.split(' ')[1]]">{{ stat.value }}</p>
                        <p class="text-[10px] text-slate-400 truncate">{{ stat.subtitle }}</p>
                    </div>
                </div>
            </div>

            <!-- Kanban Board -->
            <div class="flex-1 overflow-x-auto mt-3 px-4 min-h-0">
                <div class="flex gap-3 h-full min-w-max">
                    <div
                        v-for="col in columns"
                        :key="col.key"
                        :class="['flex-1 min-w-[260px] max-w-[340px] flex flex-col rounded-2xl border-t-[3px] bg-slate-50/80', col.accent]"
                    >
                        <!-- Column header -->
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-700">{{ col.label }}</span>
                                <span :class="['text-[11px] font-black px-2 py-0.5 rounded-full', col.badge]">
                                    {{ col.entries.length }}
                                </span>
                            </div>
                        </div>

                        <!-- Entries list -->
                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-2">
                            <div v-if="col.entries.length === 0" class="text-center text-xs text-slate-400 py-8">
                                Kosong
                            </div>

                            <div
                                v-for="entry in col.entries"
                                :key="entry.id"
                                class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden"
                            >
                                <!-- Card body -->
                                <div class="px-4 pt-3 pb-2">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <p v-if="entry.customer_name" class="font-black text-lg text-slate-900">
                                                {{ entry.customer_name }}
                                            </p>
                                            <span class="font-mono text-xs text-slate-400 tabular-nums">
                                                {{ entry.queue_code }}-{{ entry.number }}
                                            </span>
                                        </div>
                                        <span class="text-xs tabular-nums text-slate-400 font-medium mt-1">
                                            {{ elapsed(entry.created_at) }}
                                        </span>
                                    </div>

                                    <!-- Items -->
                                    <div class="mt-1.5 space-y-0.5">
                                        <div v-for="(item, idx) in (entry.items || [])" :key="idx" class="flex items-baseline gap-1 text-sm">
                                            <span class="font-bold text-slate-800">{{ item.quantity }}×</span>
                                            <span class="text-slate-600 truncate">{{ item.product_name }}</span>
                                        </div>
                                        <p v-if="!entry.items || entry.items.length === 0" class="text-xs text-slate-400 italic">
                                            Tanpa item
                                        </p>
                                    </div>
                                </div>

                                <!-- Action button -->
                                <div v-if="entry.status !== 'completed'" class="border-t border-slate-100">
                                    <button
                                        type="button"
                                        @click="advanceStatus(entry)"
                                        :class="[
                                            'w-full px-4 py-2.5 text-xs font-bold flex items-center justify-center gap-1.5 transition-colors',
                                            entry.status === 'waiting'
                                                ? 'text-amber-700 hover:bg-amber-50'
                                                : entry.status === 'serving'
                                                    ? 'text-blue-700 hover:bg-blue-50'
                                                    : 'text-emerald-700 hover:bg-emerald-50',
                                        ]"
                                    >
                                        {{ nextStatusLabel(entry.status) }}
                                        <ChevronRight class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <div v-else class="border-t border-slate-100 px-3 py-2 text-center">
                                    <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider">Selesai</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
