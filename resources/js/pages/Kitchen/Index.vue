<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { toastSuccess, toastError } from '@/lib/swal';
import { usePrinter } from '@/composables/usePrinter';
import { buildKotPlainLines } from '@/lib/receiptPlainText';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';
import { ChefHat, RefreshCw, ShoppingCart, Utensils, Hand, Printer, Wifi, WifiOff } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface EntryItem { product_name: string; quantity: number | string; }
interface Entry { id: number; number: number; status: 'waiting' | 'serving' | 'ready' | 'completed' | 'cancelled'; order_type?: string | null; table_reference: string | null; transaction_number: string | null; created_at: string | null; called_at: string | null; notes: string | null; items: EntryItem[]; }
interface BroadcastPayload { action: 'upsert' | 'delete'; entry: Entry; entryId: number; }
const props = defineProps<{ store: { id: number; name: string }; entries: Entry[] }>();

const ACTIVE_STATUSES: Entry['status'][] = ['waiting', 'serving', 'ready'];
const entries = ref<Entry[]>([...props.entries]);
watch(
    () => props.entries,
    (next) => { entries.value = [...next]; },
    { deep: true },
);

const previousWaitingIds = ref<number[]>(entries.value.filter((entry) => entry.status === 'waiting').map((entry) => entry.id));
const audioContext = ref<AudioContext | null>(null);
const unlockNotificationSound = () => {
    audioContext.value ??= new AudioContext();
    if (audioContext.value.state === 'suspended') audioContext.value.resume();
};
const playNewOrderSound = () => {
    if (!audioContext.value) return;
    const oscillator = audioContext.value.createOscillator();
    const gain = audioContext.value.createGain();
    oscillator.frequency.value = 880;
    gain.gain.setValueAtTime(0.0001, audioContext.value.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.18, audioContext.value.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.value.currentTime + 0.35);
    oscillator.connect(gain).connect(audioContext.value.destination);
    oscillator.start();
    oscillator.stop(audioContext.value.currentTime + 0.35);
};

const isWsConnected = ref(false);
const transportMode = ref<'idle' | 'ws' | 'polling'>('idle');
const refresh = () => router.reload({ only: ['entries'], preserveScroll: true, onSuccess: () => {
    const waitingIds = entries.value.filter((entry) => entry.status === 'waiting').map((entry) => entry.id);
    if (waitingIds.some((id) => !previousWaitingIds.value.includes(id))) playNewOrderSound();
    previousWaitingIds.value = waitingIds;
} });

const isTodayOrActive = (entry: Entry): boolean => {
    if (ACTIVE_STATUSES.includes(entry.status)) return true;
    if (!entry.created_at) return false;
    const today = new Date();
    const d = new Date(entry.created_at);
    return d.toDateString() === today.toDateString();
};

const applyUpsert = (payload: Entry) => {
    if (!isTodayOrActive(payload)) return;
    const idx = entries.value.findIndex((e) => e.id === payload.id);
    const wasWaitingBefore = idx >= 0 && entries.value[idx].status === 'waiting';
    if (idx >= 0) {
        entries.value[idx] = { ...entries.value[idx], ...payload };
    } else {
        entries.value.push(payload);
    }
    const isWaitingNow = ACTIVE_STATUSES.includes(payload.status) && payload.status === 'waiting';
    if (!wasWaitingBefore && isWaitingNow) {
        const waitingIds = entries.value.filter((e) => e.status === 'waiting').map((e) => e.id);
        if (waitingIds.some((id) => !previousWaitingIds.value.includes(id))) {
            playNewOrderSound();
        }
        previousWaitingIds.value = waitingIds;
    }
    entries.value = entries.value.filter((e) => ACTIVE_STATUSES.includes(e.status));
};

const applyDelete = (entryId: number) => {
    entries.value = entries.value.filter((e) => e.id !== entryId);
    previousWaitingIds.value = previousWaitingIds.value.filter((id) => id !== entryId);
};

let echoUnsubscribe: (() => void) | null = null;
let pollId: ReturnType<typeof setInterval> | null = null;

const setupFallbackPolling = () => {
    if (pollId) return;
    transportMode.value = 'polling';
    pollId = setInterval(refresh, 5000);
};

const setupEcho = () => {
    const echo = (typeof window !== 'undefined' && window.Echo) || null;
    if (!echo) { setupFallbackPolling(); return; }

    const channelName = `kitchen-store.${props.store.id}`;
    const ch = echo.channel(channelName);

    const markConnected = () => { isWsConnected.value = true; transportMode.value = 'ws'; if (pollId) { clearInterval(pollId); pollId = null; } };
    const markDisconnected = () => {
        if (isWsConnected.value) {
            isWsConnected.value = false;
            setupFallbackPolling();
        }
    };

    const pusherChannel = (ch as unknown as { subscription?: { on?: (evt: string, cb: () => void) => void; bind?: (evt: string, cb: (payload: unknown) => void) => void; pusher?: { connection?: { bind?: (evt: string, cb: () => void) => void } } } }).subscription;
    if (pusherChannel) {
        pusherChannel.on?.('pusher:subscription_succeeded', markConnected);
        pusherChannel.on?.('pusher:subscription_error', markDisconnected);
        pusherChannel.pusher?.connection?.bind?.('connected', markConnected);
        pusherChannel.pusher?.connection?.bind?.('disconnected', markDisconnected);
        pusherChannel.pusher?.connection?.bind?.('unavailable', markDisconnected);
    } else {
        setTimeout(markConnected, 300);
    }

    ch.listen('.kitchen.queue.updated', (payload: BroadcastPayload) => {
        if (!payload || !payload.entry) return;
        if (payload.action === 'delete') {
            applyDelete(payload.entryId ?? payload.entry.id);
        } else {
            applyUpsert(payload.entry);
        }
    });

    setTimeout(() => { if (!isWsConnected.value) setupFallbackPolling(); }, 2500);

    echoUnsubscribe = () => {
        try { echo.leave(channelName); } catch { /* noop */ }
    };
};

const columns = computed(() => [
    { key: 'waiting' as const, label: 'Antrian Baru', accent: 'border-t-amber-500', badge: 'bg-amber-100 text-amber-700', action: 'Mulai Buat', next: 'serving' },
    { key: 'serving' as const, label: 'Sedang Dibuat', accent: 'border-t-blue-500', badge: 'bg-blue-100 text-blue-700', action: 'Siap', next: 'ready' },
    { key: 'ready' as const, label: 'Siap Diserahkan', accent: 'border-t-emerald-500', badge: 'bg-emerald-100 text-emerald-700', action: null, next: null },
].map((column) => ({ ...column, entries: entries.value.filter((entry) => entry.status === column.key) })));

const stats = computed(() => [
    { label: 'Total Aktif', value: entries.value.length, subtitle: 'pesanan berjalan', icon: ShoppingCart, color: 'text-violet-600', bg: 'bg-violet-100' },
    { label: 'Antrian Baru', value: entries.value.filter((e) => e.status === 'waiting').length, subtitle: 'menunggu dibuat', icon: ChefHat, color: 'text-amber-600', bg: 'bg-amber-100' },
    { label: 'Sedang Dibuat', value: entries.value.filter((e) => e.status === 'serving').length, subtitle: 'sedang diproses', icon: Utensils, color: 'text-blue-600', bg: 'bg-blue-100' },
    { label: 'Siap Diserahkan', value: entries.value.filter((e) => e.status === 'ready').length, subtitle: 'siap diantar', icon: Hand, color: 'text-emerald-600', bg: 'bg-emerald-100' },
]);

const elapsed = (date: string | null) => { if (!date) return '0:00'; const seconds = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 1000)); return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`; };

const { isPrinting: isPrinterPrinting, initialize: initializePrinter, printEscPosBytes } = usePrinter();
const printEntry = async (entry: Entry) => {
    const lines = buildKotPlainLines({
        storeName: props.store.name,
        transactionNumber: entry.transaction_number || `#${entry.number}`,
        tableReference: entry.table_reference,
        items: entry.items,
        paperWidthMm: 58,
    });
    const result = await printEscPosBytes(receiptLinesToEscPos(lines), { role: 'kitchen' });
    if (result.success) toastSuccess('KOT berhasil dicetak ke printer thermal.', 2000);
    else toastError(result.error || 'KOT gagal dicetak.');
};

initializePrinter();

const advanceStatus = async (entry: Entry, status: string) => {
    try {
        const response = await fetch(`/queue-entries/${entry.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') }, body: JSON.stringify({ status }) });
        if (!response.ok) throw new Error();
        const data = await response.json();
        toastSuccess(data.message || 'Status diperbarui', 1500);
    } catch { toastError('Gagal memperbarui status'); refresh(); }
};

onMounted(() => {
    window.addEventListener('pointerdown', unlockNotificationSound, { once: true });
    setupEcho();
});
onUnmounted(() => {
    window.removeEventListener('pointerdown', unlockNotificationSound);
    if (pollId) clearInterval(pollId);
    if (echoUnsubscribe) echoUnsubscribe();
    audioContext.value?.close();
});
</script>

<template>
    <AdminLayout title="Papan Dapur"><Head title="Papan Dapur" />
        <div class="flex h-[calc(100vh-4rem)] flex-col pb-4">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-black text-slate-900">Papan Dapur</h1>
                    <span v-if="transportMode === 'ws'" class="flex items-center gap-1.5 text-xs font-bold text-emerald-600">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
                        <Wifi class="h-3 w-3" />
                        Live WS
                    </span>
                    <span v-else-if="transportMode === 'polling'" class="flex items-center gap-1.5 text-xs font-bold text-amber-600">
                        <span class="h-2 w-2 rounded-full bg-amber-400" />
                        <WifiOff class="h-3 w-3" />
                        Polling 5s
                    </span>
                    <span v-else class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-300" />
                        Menghubungkan...
                    </span>
                    <span class="text-xs text-slate-400">· sinkron pesanan</span>
                </div>
                <Button variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5" />
                </Button>
            </div>
            <div class="mt-3 grid shrink-0 grid-cols-2 gap-3 px-4 md:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="flex items-center gap-3 rounded-2xl border border-slate-100 bg-white p-3">
                    <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', stat.bg]">
                        <component :is="stat.icon" :class="['h-5 w-5', stat.color]" />
                    </div>
                    <div>
                        <p class="truncate text-[11px] font-bold text-slate-500">{{ stat.label }}</p>
                        <p :class="['text-xl font-black tabular-nums', stat.color]">{{ stat.value }}</p>
                        <p class="text-[10px] text-slate-400">{{ stat.subtitle }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-3 min-h-0 flex-1 overflow-x-auto px-4">
                <div class="flex h-full min-w-max gap-3">
                    <div v-for="column in columns" :key="column.key" :class="['flex min-w-[260px] max-w-[340px] flex-1 flex-col rounded-2xl border-t-[3px] bg-slate-50/80', column.accent]">
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-700">{{ column.label }}</span>
                                <span :class="['rounded-full px-2 py-0.5 text-[11px] font-black', column.badge]">
                                    {{ column.entries.length }}
                                </span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-2 overflow-y-auto px-3 pb-3">
                            <div v-if="!column.entries.length" class="py-8 text-center text-xs text-slate-400">Kosong</div>
                            <div v-for="entry in column.entries" :key="entry.id" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                <div class="px-4 pb-2 pt-3">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <p class="font-mono text-xs text-slate-400">#{{ entry.number }}</p>
                                            <p class="text-sm font-bold text-slate-900">{{ entry.table_reference ? `Meja ${entry.table_reference}` : entry.transaction_number }}</p>
                                        </div>
                                        <span class="font-mono text-xs text-slate-400">{{ elapsed(entry.created_at) }}</span>
                                    </div>
                                    <div class="mt-1.5 space-y-0.5">
                                        <div v-for="(item, index) in entry.items" :key="index" class="flex gap-1 text-sm">
                                            <b>{{ item.quantity }}×</b>
                                            <span class="truncate text-slate-600">{{ item.product_name }}</span>
                                        </div>
                                    </div>
                                    <p v-if="entry.notes" class="mt-2 rounded bg-yellow-50 p-2 text-xs text-yellow-800">{{ entry.notes }}</p>
                                </div>
                                <div class="flex border-t border-slate-100">
                                    <button
                                        class="flex-1 px-4 py-2 text-left text-xs font-bold text-slate-700 hover:bg-slate-50"
                                        @click="column.action && advanceStatus(entry, column.next)"
                                    >
                                        {{ column.action || 'Siap Diserahkan' }}
                                        <span v-if="column.action" class="float-right">›</span>
                                    </button>
                                    <button
                                        class="border-l border-slate-100 px-3 text-slate-500 hover:bg-slate-50"
                                        title="Cetak Ulang KOT"
                                        :disabled="isPrinterPrinting"
                                        @click="printEntry(entry)"
                                    >
                                        <Printer class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
