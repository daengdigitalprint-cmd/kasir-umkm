<script setup lang="ts">
/**
 * Pemberitahuan di layar kasir: pesanan yang sudah "Siap" di Papan Dapur.
 * Hanya aktif untuk toko yang menyalakan Antrian Dapur (server menjawab enabled=false bila tidak).
 */
import { BellRing, Check, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toastError, toastSuccess } from '@/lib/swal';

interface ReadyEntry {
    id: number;
    number: number;
    table_reference: string | null;
    transaction_number: string | null;
    order_type?: string | null;
    ready_at?: string | null;
    items: Array<{ product_name: string; quantity: number | string; variant?: string | null }>;
}

const entries = ref<ReadyEntry[]>([]);
const open = ref(true);
const busy = ref<Set<number>>(new Set());
const now = ref(Date.now());
let seen = new Set<number>();
let firstLoad = true;
let pollId: ReturnType<typeof setInterval> | null = null;
let clockId: ReturnType<typeof setInterval> | null = null;
let audio: AudioContext | null = null;

const chime = () => {
    try {
        audio ??= new AudioContext();
        if (audio.state === 'suspended') audio.resume();
        [0, 0.18].forEach((delay) => {
            const osc = audio!.createOscillator();
            const gain = audio!.createGain();
            osc.frequency.value = 1046;
            gain.gain.setValueAtTime(0.0001, audio!.currentTime + delay);
            gain.gain.exponentialRampToValueAtTime(0.15, audio!.currentTime + delay + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audio!.currentTime + delay + 0.25);
            osc.connect(gain).connect(audio!.destination);
            osc.start(audio!.currentTime + delay);
            osc.stop(audio!.currentTime + delay + 0.26);
        });
    } catch {
        /* bunyi tidak tersedia */
    }
};

const load = async () => {
    try {
        const resp = await fetch('/kitchen/ready', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!resp.ok) return;
        const data = (await resp.json()) as { enabled: boolean; entries: ReadyEntry[] };
        if (!data.enabled) {
            // Toko tanpa Antrian Dapur: berhenti mengecek.
            if (pollId) clearInterval(pollId);
            pollId = null;
            entries.value = [];
            return;
        }
        const fresh = data.entries.filter((e) => !seen.has(e.id));
        if (!firstLoad && fresh.length > 0) {
            chime();
            open.value = true;
        }
        seen = new Set(data.entries.map((e) => e.id));
        firstLoad = false;
        entries.value = data.entries;
    } catch {
        /* coba lagi pada putaran berikutnya */
    }
};

const label = (e: ReadyEntry) => (e.table_reference ? `Meja ${e.table_reference}` : e.transaction_number ?? `#${e.number}`);
const waited = (e: ReadyEntry) => {
    if (!e.ready_at) return '';
    const m = Math.floor((now.value - new Date(e.ready_at).getTime()) / 60000);
    return m < 1 ? 'baru saja' : `${m} mnt lalu`;
};
const summary = (e: ReadyEntry) =>
    e.items.map((i) => `${i.quantity}× ${i.product_name}${i.variant ? ` (${i.variant})` : ''}`).join(', ');

const handOver = async (e: ReadyEntry) => {
    if (busy.value.has(e.id)) return;
    busy.value = new Set([...busy.value, e.id]);
    try {
        const resp = await fetch(`/queue-entries/${e.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ status: 'completed' }),
        });
        if (!resp.ok) throw new Error();
        entries.value = entries.value.filter((x) => x.id !== e.id);
        toastSuccess(`${label(e)} diserahkan`, 1500);
    } catch {
        toastError('Gagal menandai pesanan diserahkan');
        load();
    } finally {
        const next = new Set(busy.value);
        next.delete(e.id);
        busy.value = next;
    }
};

const count = computed(() => entries.value.length);

onMounted(() => {
    load();
    pollId = setInterval(load, 10000);
    clockId = setInterval(() => (now.value = Date.now()), 30000);
});
onUnmounted(() => {
    if (pollId) clearInterval(pollId);
    if (clockId) clearInterval(clockId);
    audio?.close();
});
</script>

<template>
    <div v-if="count > 0" class="fixed bottom-4 left-4 z-40 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-emerald-200 bg-white shadow-xl">
        <button type="button" class="flex w-full items-center justify-between rounded-t-2xl bg-emerald-600 px-4 py-2.5 text-white" @click="open = !open">
            <span class="flex items-center gap-2 text-sm font-bold">
                <BellRing class="h-4 w-4" /> {{ count }} pesanan siap diserahkan
            </span>
            <component :is="open ? ChevronDown : ChevronUp" class="h-4 w-4" />
        </button>
        <div v-if="open" class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
            <div v-for="e in entries" :key="e.id" class="flex items-center gap-3 px-4 py-2.5">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-900">
                        #{{ e.number }} · {{ label(e) }}
                        <span class="text-xs font-normal text-slate-400">{{ waited(e) }}</span>
                    </p>
                    <p class="truncate text-xs text-slate-500">{{ summary(e) }}</p>
                </div>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-bold text-white disabled:opacity-50"
                    :disabled="busy.has(e.id)"
                    @click="handOver(e)"
                >
                    <Check class="h-3.5 w-3.5" /> Diserahkan
                </button>
            </div>
        </div>
    </div>
</template>
