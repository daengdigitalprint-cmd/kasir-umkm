<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CalendarClock, Inbox, Plus, Send } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

interface RequestRow {
    id: number;
    request_number: string;
    status: string;
    status_label: string;
    from_store: { id: number; name: string } | null;
    to_store: { id: number; name: string } | null;
    requester: string | null;
    is_automatic?: boolean;
    lines_count: number;
    transfer: { id: number; transfer_number: string; status: string } | null;
    created_at: string | null;
}

const props = defineProps<{
    requests: { data: RequestRow[]; links: { url: string | null; label: string; active: boolean }[] };
    tab: 'incoming' | 'outgoing';
    store: { id: number; name: string; is_central_warehouse: boolean };
    pendingIncoming: number;
    canCreate: boolean;
    /** Order otomatis ke CK (hanya outlet). */
    autoRequest?: {
        enabled: boolean;
        time: string;
        supplier_store_id: number | null;
        supplier_name: string | null;
        last_run_date: string | null;
        pending_items: number;
        timezone: string;
        can_manage: boolean;
    } | null;
}>();

// ── Order otomatis ──
const autoEnabled = ref(props.autoRequest?.enabled ?? false);
const autoTime = ref(props.autoRequest?.time ?? '21:00');
const savingAuto = ref(false);
function saveAuto(enabled = autoEnabled.value) {
    savingAuto.value = true;
    router.put(
        '/stock-requests/auto-settings',
        { enabled, time: autoTime.value, supplier_store_id: props.autoRequest?.supplier_store_id ?? null },
        { preserveScroll: true, onFinish: () => (savingAuto.value = false) },
    );
}
function runAutoNow() {
    router.post('/stock-requests/auto-run', {}, { preserveScroll: true });
}

const statusClass: Record<string, string> = {
    requested: 'bg-amber-100 text-amber-800',
    approved: 'bg-blue-100 text-blue-800',
    completed: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-700',
    cancelled: 'bg-slate-100 text-slate-600',
};

const formatWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
        : '—';

function switchTab(tab: 'incoming' | 'outgoing') {
    router.get('/stock-requests', { tab }, { preserveState: true, replace: true });
}

// Realtime: permintaan baru/berubah langsung muncul tanpa muat ulang manual.
let leave: (() => void) | null = null;
let pollId: ReturnType<typeof setInterval> | null = null;
const reload = () => router.reload({ only: ['requests', 'pendingIncoming'] });

onMounted(() => {
    const echo = typeof window !== 'undefined' ? window.Echo : undefined;
    if (echo) {
        const channel = `stock-requests.${props.store.id}`;
        echo.private(channel).listen('.stock-request.updated', reload);
        leave = () => {
            try {
                echo.leave(channel);
            } catch {
                /* noop */
            }
        };
    } else {
        pollId = setInterval(reload, 30000);
    }
});

onBeforeUnmount(() => {
    leave?.();
    if (pollId) clearInterval(pollId);
});
</script>

<template>
    <Head title="Permintaan Stok" />

    <AdminLayout title="Permintaan Stok">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Permintaan Stok</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Minta barang ke lokasi lain dalam grup. Setelah disetujui, permintaan otomatis menjadi Transfer Stok yang dikirim.
                    </p>
                </div>
                <Button v-if="canCreate" as-child class="rounded-xl">
                    <Link href="/stock-requests/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Buat permintaan
                    </Link>
                </Button>
            </div>

            <!-- Order otomatis ke Gudang Pusat -->
            <div v-if="autoRequest" class="rounded-2xl border border-violet-200 bg-violet-50/60 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-600 text-white">
                            <CalendarClock class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="font-bold text-slate-900">Permintaan otomatis ke {{ autoRequest.supplier_name ?? 'Gudang Pusat' }}</p>
                            <p class="text-sm text-slate-600">
                                Setiap hari pada jam di bawah, barang di Daftar Belanja (di bawah Stok Minimum) dikirim sebagai permintaan.
                                Barang yang masih ada di permintaan terbuka tidak dikirim ulang.
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                Saat ini {{ autoRequest.pending_items }} barang siap diminta
                                <span v-if="autoRequest.last_run_date"> · terakhir jalan {{ autoRequest.last_run_date }}</span>
                                · zona waktu {{ autoRequest.timezone }}
                            </p>
                        </div>
                    </div>
                    <label v-if="autoRequest.can_manage" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <input
                            v-model="autoEnabled"
                            type="checkbox"
                            class="h-5 w-5 rounded"
                            :disabled="savingAuto || !autoRequest.supplier_name"
                            @change="saveAuto(autoEnabled)"
                        />
                        Aktif
                    </label>
                    <span v-else class="rounded-full px-3 py-1 text-xs font-semibold" :class="autoRequest.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'">
                        {{ autoRequest.enabled ? 'Aktif jam ' + autoRequest.time : 'Tidak aktif' }}
                    </span>
                </div>
                <div v-if="autoRequest.can_manage" class="mt-3 flex flex-wrap items-center gap-2 border-t border-violet-200/70 pt-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        Jam kirim
                        <input v-model="autoTime" type="time" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm" aria-label="Jam kirim otomatis" />
                    </label>
                    <Button size="sm" variant="outline" class="rounded-lg" :disabled="savingAuto" @click="saveAuto()">Simpan jam</Button>
                    <Button size="sm" class="ml-auto rounded-lg" :disabled="!autoRequest.supplier_name" @click="runAutoNow">
                        <Send class="mr-1 h-4 w-4" /> Buat sekarang
                    </Button>
                </div>
            </div>

            <div class="inline-flex rounded-xl bg-slate-100 p-1">
                <button
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
                    :class="tab === 'incoming' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    @click="switchTab('incoming')"
                >
                    <Inbox class="h-4 w-4" />
                    Masuk
                    <span v-if="pendingIncoming > 0" class="rounded-full bg-amber-500 px-2 py-0.5 text-xs text-white">{{ pendingIncoming }}</span>
                </button>
                <button
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
                    :class="tab === 'outgoing' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                    @click="switchTab('outgoing')"
                >
                    <Send class="h-4 w-4" />
                    Keluar
                </button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <div v-if="requests.data.length === 0" class="py-12 text-center text-slate-500">
                        <p v-if="tab === 'incoming'">Belum ada permintaan dari lokasi lain.</p>
                        <p v-else>Belum ada permintaan yang Anda kirim.</p>
                    </div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="p-3">Nomor</th>
                                    <th class="p-3">{{ tab === 'incoming' ? 'Dari' : 'Kepada' }}</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Barang</th>
                                    <th class="p-3">Diminta</th>
                                    <th class="p-3">Transfer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in requests.data"
                                    :key="r.id"
                                    class="border-b border-slate-100 last:border-0 hover:bg-slate-50/80"
                                    :class="r.status === 'requested' && tab === 'incoming' ? 'bg-amber-50/40' : ''"
                                >
                                    <td class="p-3 font-mono font-semibold">
                                        <Link :href="`/stock-requests/${r.id}`" class="text-teal-700 hover:underline">{{ r.request_number }}</Link>
                                    </td>
                                    <td class="p-3">{{ tab === 'incoming' ? r.from_store?.name : r.to_store?.name }}</td>
                                    <td class="p-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass[r.status] ?? 'bg-slate-100'">
                                            {{ r.status_label }}
                                        </span>
                                    </td>
                                    <td class="p-3">{{ r.lines_count }}</td>
                                    <td class="p-3 text-slate-600">
                                        {{ formatWhen(r.created_at) }}
                                        <span v-if="r.is_automatic" class="mt-0.5 inline-block rounded bg-violet-100 px-1.5 text-[11px] font-semibold text-violet-700">Otomatis</span>
                                        <span v-else-if="r.requester" class="block text-xs text-slate-400">{{ r.requester }}</span>
                                    </td>
                                    <td class="p-3">
                                        <Link v-if="r.transfer" :href="`/stock-transfers/${r.transfer.id}`" class="font-mono text-xs text-violet-700 hover:underline">
                                            {{ r.transfer.transfer_number }}
                                        </Link>
                                        <span v-else class="text-slate-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="requests.links.length > 3" class="mt-4 flex flex-wrap gap-1">
                        <Link
                            v-for="(link, i) in requests.links"
                            :key="i"
                            :href="link.url ?? ''"
                            class="rounded-lg px-3 py-1.5 text-xs"
                            :class="[link.active ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700', !link.url ? 'pointer-events-none opacity-40' : '']"
                            preserve-scroll
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
