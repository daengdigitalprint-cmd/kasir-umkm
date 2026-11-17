<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Bike, CheckCircle2, MapPin, MessageCircle, Package, Settings2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { confirmDialog } from '@/lib/swal';

type Status = 'preparing' | 'on_the_way' | 'delivered' | 'cancelled';

interface OrderRow {
    id: number;
    status: Status;
    status_label: string;
    next_statuses: Status[];
    recipient_name: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string;
    notes: string | null;
    delivery_fee: number;
    courier: { id: number; name: string } | null;
    transaction: { id: number; number: string; total: number; payment_status: string } | null;
    created_at: string | null;
    dispatched_at: string | null;
    delivered_at: string | null;
}

const props = defineProps<{
    orders: { data: OrderRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string; mine: boolean };
    counts: Record<string, number>;
    labels: Record<Status, string>;
    couriers: { id: number; name: string }[];
    enabled: boolean;
    defaultFee: number;
    canManage: boolean;
    currentUserId: number;
}>();

const tabs = [
    { key: 'active', label: 'Aktif' },
    { key: 'delivered', label: 'Selesai' },
    { key: 'cancelled', label: 'Dibatalkan' },
];

const statusTone: Record<Status, string> = {
    preparing: 'bg-amber-100 text-amber-800',
    on_the_way: 'bg-blue-100 text-blue-800',
    delivered: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-slate-100 text-slate-500',
};

function go(params: Record<string, unknown>) {
    router.get('/deliveries', { status: props.filters.status, ...(props.filters.mine ? { mine: 1 } : {}), ...params }, { preserveState: true, preserveScroll: true, replace: true });
}

const actionLabel: Record<Status, string> = {
    preparing: 'Kembalikan ke Disiapkan',
    on_the_way: 'Antar sekarang',
    delivered: 'Sudah sampai',
    cancelled: 'Batalkan',
};

async function move(order: OrderRow, status: Status) {
    if (status === 'cancelled') {
        const ok = await confirmDialog('Batalkan pengantaran?', 'Transaksinya tidak ikut batal. Kembalikan uang lewat Retur Penjualan bila perlu.');
        if (!ok) return;
    }
    router.patch(`/deliveries/${order.id}/status`, { status }, { preserveScroll: true });
}

function assign(order: OrderRow, courierId: string) {
    router.patch(`/deliveries/${order.id}/courier`, { courier_user_id: courierId === '' ? null : Number(courierId) }, { preserveScroll: true });
}

const mapsUrl = (address: string) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
const waUrl = (o: OrderRow) =>
    `https://wa.me/${o.whatsapp}?text=${encodeURIComponent(`Halo ${o.recipient_name ?? ''}, pesanan Anda (${o.transaction?.number ?? ''}) sedang kami antar.`)}`;
const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
const time = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

// ── Pengaturan modul ──
const showSettings = ref(false);
const settingsEnabled = ref(props.enabled);
const settingsFee = ref(props.defaultFee);
function saveSettings() {
    router.post('/deliveries/settings', { enabled: settingsEnabled.value, default_fee: settingsFee.value }, {
        preserveScroll: true,
        onSuccess: () => (showSettings.value = false),
    });
}
</script>

<template>
    <Head title="Pengantaran" />

    <AdminLayout title="Pengantaran">
        <div class="mx-auto max-w-5xl space-y-6 pb-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Pengantaran</h1>
                    <p class="mt-1 text-sm text-slate-600">Pesanan yang diantar kurir sendiri. Buat dari kasir dengan pilihan "Antar ke alamat".</p>
                </div>
                <Button v-if="canManage" variant="outline" class="rounded-xl" @click="showSettings = !showSettings">
                    <Settings2 class="mr-2 h-4 w-4" /> Pengaturan
                </Button>
            </div>

            <Card v-if="showSettings || (!enabled && canManage)" class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6 text-sm">
                    <p v-if="!enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900">
                        Pesanan Antar belum aktif. Nyalakan agar kasir bisa mengisi alamat, ongkir, dan kurir.
                    </p>
                    <label class="flex items-center justify-between gap-4">
                        <span class="font-semibold text-slate-900">Aktifkan Pesanan Antar di kasir</span>
                        <input v-model="settingsEnabled" type="checkbox" class="h-5 w-5 rounded" />
                    </label>
                    <label class="flex items-center justify-between gap-4">
                        <span class="font-semibold text-slate-900">Ongkir bawaan (Rp)</span>
                        <input v-model.number="settingsFee" type="number" min="0" step="500" class="h-9 w-36 rounded-lg border border-slate-200 px-2 text-right" />
                    </label>
                    <Button class="rounded-xl" @click="saveSettings">Simpan</Button>
                </CardContent>
            </Card>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-xl bg-slate-100 p-1">
                    <button
                        v-for="t in tabs"
                        :key="t.key"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-semibold"
                        :class="filters.status === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                        @click="go({ status: t.key, page: undefined })"
                    >
                        {{ t.label }} <span class="ml-1 text-xs text-slate-400">{{ counts[t.key] ?? 0 }}</span>
                    </button>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" class="h-4 w-4 rounded" :checked="filters.mine" @change="go({ mine: ($event.target as HTMLInputElement).checked ? 1 : undefined })" />
                    Hanya pesanan saya
                </label>
            </div>

            <div v-if="orders.data.length === 0" class="rounded-2xl border border-dashed border-slate-200 py-12 text-center text-sm text-slate-500">
                <Package class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                Tidak ada pesanan di sini.
            </div>

            <div v-else class="space-y-3">
                <Card v-for="o in orders.data" :key="o.id" class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="space-y-3 pt-5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-bold text-slate-900">{{ o.recipient_name || 'Tanpa nama' }}</p>
                                <p class="text-xs text-slate-500">
                                    <Link v-if="o.transaction" :href="`/invoice/${o.transaction.id}`" class="font-mono hover:underline">{{ o.transaction.number }}</Link>
                                    · {{ time(o.created_at) }}
                                </p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusTone[o.status]">{{ o.status_label }}</span>
                        </div>

                        <div class="flex items-start gap-2 text-sm text-slate-700">
                            <MapPin class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                            <span>
                                {{ o.address }}
                                <a :href="mapsUrl(o.address)" target="_blank" rel="noopener" class="ml-1 text-xs font-semibold text-teal-700 hover:underline">Buka peta</a>
                            </span>
                        </div>
                        <p v-if="o.notes" class="rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600">Catatan: {{ o.notes }}</p>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="text-slate-600">Total <strong class="text-slate-900">Rp{{ fmt(o.transaction?.total ?? 0) }}</strong></span>
                            <span class="text-slate-600">Ongkir Rp{{ fmt(o.delivery_fee) }}</span>
                            <span v-if="o.transaction && o.transaction.payment_status !== 'paid'" class="rounded bg-red-50 px-2 text-xs font-semibold text-red-700">Belum lunas</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                            <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                                <Bike class="h-4 w-4" />
                                <select
                                    class="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                                    :value="o.courier?.id ?? ''"
                                    :disabled="o.status === 'delivered' || o.status === 'cancelled'"
                                    aria-label="Kurir"
                                    @change="assign(o, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Belum ada kurir</option>
                                    <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <a v-if="o.whatsapp" :href="waUrl(o)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                <MessageCircle class="h-3.5 w-3.5" /> {{ o.phone }}
                            </a>
                            <div class="ml-auto flex flex-wrap gap-2">
                                <Button
                                    v-for="next in o.next_statuses"
                                    :key="next"
                                    size="sm"
                                    :variant="next === 'cancelled' || next === 'preparing' ? 'outline' : 'default'"
                                    class="rounded-lg"
                                    :class="next === 'cancelled' ? 'text-red-600' : ''"
                                    @click="move(o, next)"
                                >
                                    <XCircle v-if="next === 'cancelled'" class="mr-1 h-4 w-4" />
                                    <CheckCircle2 v-else-if="next === 'delivered'" class="mr-1 h-4 w-4" />
                                    <Bike v-else-if="next === 'on_the_way'" class="mr-1 h-4 w-4" />
                                    {{ actionLabel[next] }}
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div v-if="orders.links.length > 3" class="flex flex-wrap gap-1">
                <Link
                    v-for="(link, i) in orders.links"
                    :key="i"
                    :href="link.url ?? ''"
                    class="rounded-lg px-3 py-1.5 text-xs"
                    :class="[link.active ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700', !link.url ? 'pointer-events-none opacity-40' : '']"
                    preserve-scroll
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
