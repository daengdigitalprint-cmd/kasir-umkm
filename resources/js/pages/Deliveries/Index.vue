<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Banknote,
    Bike,
    Check,
    CheckCircle2,
    CircleDollarSign,
    Copy,
    ExternalLink,
    Link2,
    MapPin,
    MessageCircle,
    Package,
    Settings2,
    Smartphone,
    Wallet,
    X,
    XCircle,
} from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { confirmDialog, toastSuccess } from '@/lib/swal';

type Status = 'pending' | 'preparing' | 'on_the_way' | 'delivered' | 'cancelled';

interface OrderRow {
    id: number;
    status: Status;
    status_label: string;
    next_statuses: Status[];
    /** Dibuat pelanggan sendiri lewat tautan pesan antar (bukan dari kasir). */
    is_self_order: boolean;
    recipient_name: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string;
    notes: string | null;
    delivery_fee: number;
    /** Nama kurir (teks; kurir tidak perlu punya akun). */
    courier: string | null;
    transaction: { id: number; number: string; total: number; payment_status: string; payment_method: string | null } | null;
    items: { name: string; quantity: number; note: string | null }[];
    created_at: string | null;
    dispatched_at: string | null;
    delivered_at: string | null;
}

const props = defineProps<{
    orders: { data: OrderRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status: string };
    counts: Record<string, number>;
    labels: Record<Status, string>;
    /** Nama-nama kurir toko dari pengaturan. Kosong = pengantaran hanya berketerangan "Kurir toko". */
    couriers: string[];
    enabled: boolean;
    defaultFee: number;
    /** Pemesanan mandiri lewat tautan toko. */
    selfOrder: { enabled: boolean; url: string };
    canManage: boolean;
    currentUserId: number;
}>();

const tabs = [
    { key: 'pending', label: 'Pesanan masuk' },
    { key: 'active', label: 'Aktif' },
    { key: 'delivered', label: 'Selesai' },
    { key: 'cancelled', label: 'Dibatalkan' },
];
/** Tab "Pesanan masuk" hanya relevan bila pemesanan lewat tautan dipakai. */
const showTab = (key: string) => key !== 'pending' || props.selfOrder.enabled || (props.counts.pending ?? 0) > 0 || props.filters.status === 'pending';

const statusTone: Record<Status, string> = {
    pending: 'bg-orange-100 text-orange-800',
    preparing: 'bg-amber-100 text-amber-800',
    on_the_way: 'bg-blue-100 text-blue-800',
    delivered: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-slate-100 text-slate-500',
};

function go(params: Record<string, unknown>) {
    router.get('/deliveries', { status: props.filters.status, ...params }, { preserveState: true, preserveScroll: true, replace: true });
}

const actionLabel: Record<Status, string> = {
    pending: 'Menunggu konfirmasi',
    preparing: 'Kembalikan ke Disiapkan',
    on_the_way: 'Antar sekarang',
    delivered: 'Sudah sampai',
    cancelled: 'Batalkan',
};

const isUnpaid = (o: OrderRow) => !!o.transaction && o.transaction.payment_status !== 'paid';

async function move(order: OrderRow, status: Status) {
    if (status === 'cancelled') {
        // Pesanan dari tautan yang belum dibayar ikut batal; pesanan kasir sudah dibayar di POS.
        const ok =
            order.is_self_order && isUnpaid(order)
                ? await confirmDialog('Batalkan pesanan ini?', 'Pesanan belum dibayar, jadi ikut dibatalkan. Pelanggan akan melihatnya di halaman status.')
                : await confirmDialog('Batalkan pengantaran?', 'Transaksinya tidak ikut batal. Kembalikan uang lewat Retur Penjualan bila perlu.');
        if (!ok) return;
    }
    router.patch(`/deliveries/${order.id}/status`, { status }, { preserveScroll: true });
}

function assign(order: OrderRow, courierName: string) {
    router.patch(`/deliveries/${order.id}/courier`, { courier_name: courierName === '' ? null : courierName }, { preserveScroll: true });
}

const mapsUrl = (address: string) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
const waUrl = (o: OrderRow) => {
    const text =
        o.status === 'pending'
            ? `Halo ${o.recipient_name ?? ''}, kami dari toko ingin mengonfirmasi pesanan Anda (${o.transaction?.number ?? ''}).`
            : `Halo ${o.recipient_name ?? ''}, pesanan Anda (${o.transaction?.number ?? ''}) sedang kami antar.`;
    return `https://wa.me/${o.whatsapp}?text=${encodeURIComponent(text)}`;
};
const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
const qty = (n: number) => Number(n).toLocaleString('id-ID');
const time = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const paymentLabel = (method: string | null | undefined) =>
    ({
        cash: 'Bayar di tempat',
        qris: 'QRIS',
        non_cash: 'Non-tunai',
        card: 'Kartu',
        ewallet: 'E-Wallet',
        transfer: 'Transfer',
        bank_transfer: 'Transfer bank',
        other: 'Lainnya',
    })[method ?? 'cash'] ??
    method ??
    '';

// ── Aksi pesanan dari tautan (sama seperti Pesanan Masuk dari meja) ──
const busyId = ref<number | null>(null);
const post = (order: OrderRow, action: string, data: Record<string, string | null> = {}, after?: () => void) =>
    router.post(`/deliveries/${order.id}/${action}`, data, {
        preserveScroll: true,
        onStart: () => (busyId.value = order.id),
        onFinish: () => (busyId.value = null),
        // Server membalas dengan flash error (mis. shift belum dibuka) → dialog tetap terbuka.
        onSuccess: (page) => {
            if (!(page.props as { flash?: { error?: string } }).flash?.error) after?.();
        },
    });

const accept = (order: OrderRow) => post(order, 'accept');

// Tolak dengan alasan (terlihat oleh pelanggan di halaman status)
const rejectDialog = ref(false);
const rejectTarget = ref<OrderRow | null>(null);
const rejectReason = ref('');
const rejectReasons = ['Menu habis', 'Di luar jangkauan antar', 'Toko sudah tutup', 'Pesanan tidak valid'];
const openReject = (order: OrderRow) => {
    rejectTarget.value = order;
    rejectReason.value = '';
    rejectDialog.value = true;
};
const submitReject = () => {
    if (!rejectTarget.value) return;
    post(rejectTarget.value, 'reject', { reason: rejectReason.value || null }, () => (rejectDialog.value = false));
};

// Bayar: metode bawaan dari pilihan pelanggan, kasir boleh menggantinya
const payMethods = [
    { value: 'cash', label: 'Tunai', icon: Banknote },
    { value: 'ewallet', label: 'E-Wallet', icon: Smartphone },
    { value: 'other', label: 'Lainnya', icon: CircleDollarSign },
];
const payDialog = ref(false);
const payTarget = ref<OrderRow | null>(null);
const payMethod = ref('cash');
const openPay = (order: OrderRow) => {
    payTarget.value = order;
    const m = order.transaction?.payment_method ?? 'cash';
    payMethod.value = m === 'cash' ? 'cash' : ['qris', 'ewallet'].includes(m) ? 'ewallet' : 'other';
    payDialog.value = true;
};
const submitPay = () => {
    if (!payTarget.value) return;
    post(payTarget.value, 'pay', { payment_method: payMethod.value }, () => (payDialog.value = false));
};

// ── Pembaruan otomatis: pesanan baru dari tautan muncul tanpa muat ulang ──
let pollTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible' && !rejectDialog.value && !payDialog.value && !showSettings.value) {
            router.reload({ only: ['orders', 'counts'] });
        }
    }, 20000);
});
onBeforeUnmount(() => clearInterval(pollTimer));

// ── Pengaturan modul ──
const showSettings = ref(false);
const settingsEnabled = ref(props.enabled);
const settingsFee = ref(props.defaultFee);
const settingsSelfOrder = ref(props.selfOrder.enabled);
/** Nama-nama kurir toko (tanpa akun). Kosong = pengantaran hanya berketerangan "Kurir toko". */
const settingsCourierNames = ref<string[]>([...props.couriers]);
const newCourierName = ref('');
function addCourierName() {
    const name = newCourierName.value.replace(/\s+/g, ' ').trim().slice(0, 60);
    newCourierName.value = '';
    if (!name || settingsCourierNames.value.some((n) => n.toLowerCase() === name.toLowerCase())) return;
    settingsCourierNames.value.push(name);
}
function removeCourierName(name: string) {
    settingsCourierNames.value = settingsCourierNames.value.filter((n) => n !== name);
}
function saveSettings() {
    // Nama yang sudah diketik tetapi belum ditekan "Tambah" ikut disimpan.
    addCourierName();
    router.post(
        '/deliveries/settings',
        {
            enabled: settingsEnabled.value,
            default_fee: settingsFee.value,
            self_order_enabled: settingsSelfOrder.value,
            courier_names: settingsCourierNames.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (showSettings.value = false),
        },
    );
}

/** Pilihan kurir per pesanan: kurir yang sudah ditugaskan tetap tampil walau namanya dihapus dari daftar. */
const courierOptions = (o: OrderRow) => (o.courier && !props.couriers.includes(o.courier) ? [...props.couriers, o.courier] : props.couriers);

const copyLink = () => {
    navigator.clipboard
        ?.writeText(props.selfOrder.url)
        .then(() => toastSuccess('Tautan pesan antar disalin.'))
        .catch(() => {});
};
</script>

<template>
    <Head title="Pengantaran" />

    <AdminLayout title="Pengantaran">
        <div class="mx-auto max-w-5xl space-y-6 pb-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Pengantaran</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Pesanan yang diantar kurir sendiri. Buat dari kasir dengan pilihan "Antar ke alamat", atau terima pesanan pelanggan lewat tautan pesan antar.
                    </p>
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
                    <label class="flex items-start justify-between gap-4" :class="{ 'opacity-50': !settingsEnabled }">
                        <span>
                            <span class="block font-semibold text-slate-900">Terima pesanan lewat tautan</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                Pelanggan memesan sendiri dari tautan toko dan mengisi alamatnya. Pesanan masuk ke sini untuk Anda terima atau tolak. Cara bayarnya mengikuti "Pembayaran menu digital" di Pengaturan → Kasir.
                            </span>
                        </span>
                        <input v-model="settingsSelfOrder" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded" :disabled="!settingsEnabled" data-testid="self-order-toggle" />
                    </label>
                    <div class="border-t border-slate-100 pt-4" :class="{ 'opacity-50': !settingsEnabled }">
                        <p class="font-semibold text-slate-900">Kurir toko</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Kurir tidak perlu punya akun. Cukup tulis namanya di sini bila ingin mencatat siapa yang mengantar. Bila dikosongkan, pengantaran hanya diberi keterangan "Kurir toko".
                        </p>
                        <div v-if="settingsCourierNames.length" class="mt-3 flex flex-wrap gap-2" data-testid="courier-names">
                            <span
                                v-for="name in settingsCourierNames"
                                :key="name"
                                class="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50/60 py-1 pr-1 pl-3 text-sm text-slate-900"
                            >
                                {{ name }}
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-full text-slate-500 hover:bg-violet-100 hover:text-red-600"
                                    :aria-label="`Hapus kurir ${name}`"
                                    :disabled="!settingsEnabled"
                                    @click="removeCourierName(name)"
                                >
                                    <X class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                        <div class="mt-3 flex max-w-sm gap-2">
                            <input
                                v-model="newCourierName"
                                type="text"
                                maxlength="60"
                                placeholder="Nama kurir, mis. Joko"
                                aria-label="Nama kurir baru"
                                class="h-9 min-w-0 flex-1 rounded-lg border border-slate-200 px-3"
                                :disabled="!settingsEnabled"
                                @keydown.enter.prevent="addCourierName"
                            />
                            <Button type="button" variant="outline" size="sm" class="h-9 rounded-lg" :disabled="!settingsEnabled || !newCourierName.trim()" @click="addCourierName">
                                Tambah
                            </Button>
                        </div>
                    </div>
                    <Button class="rounded-xl" @click="saveSettings">Simpan</Button>
                </CardContent>
            </Card>

            <!-- Tautan pesan antar untuk dibagikan ke pelanggan -->
            <div v-if="enabled && selfOrder.enabled" class="flex flex-wrap items-center gap-3 rounded-2xl border border-violet-200 bg-violet-50/60 px-4 py-3" data-testid="self-order-link">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-violet-600"><Link2 class="h-4 w-4" /></div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-bold text-violet-900">Tautan pesan antar</p>
                    <p class="truncate font-mono text-sm text-slate-700">{{ selfOrder.url }}</p>
                </div>
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" class="rounded-lg bg-white" @click="copyLink"><Copy class="mr-1 h-4 w-4" /> Salin</Button>
                    <Button size="sm" variant="outline" class="rounded-lg bg-white" as-child>
                        <a :href="selfOrder.url" target="_blank" rel="noopener"><ExternalLink class="mr-1 h-4 w-4" /> Buka</a>
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex flex-wrap rounded-xl bg-slate-100 p-1">
                    <template v-for="t in tabs" :key="t.key">
                        <button
                            v-if="showTab(t.key)"
                            type="button"
                            class="rounded-lg px-4 py-2 text-sm font-semibold"
                            :class="filters.status === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'"
                            @click="go({ status: t.key, page: undefined })"
                        >
                            {{ t.label }}
                            <span
                                class="ml-1 text-xs"
                                :class="t.key === 'pending' && (counts.pending ?? 0) > 0 ? 'rounded-full bg-red-600 px-1.5 py-0.5 font-bold text-white' : 'text-slate-400'"
                                >{{ counts[t.key] ?? 0 }}</span
                            >
                        </button>
                    </template>
                </div>
            </div>

            <div v-if="orders.data.length === 0" class="rounded-2xl border border-dashed border-slate-200 py-12 text-center text-sm text-slate-500">
                <Package class="mx-auto mb-2 h-8 w-8 text-slate-300" />
                {{ filters.status === 'pending' ? 'Belum ada pesanan baru dari tautan.' : 'Tidak ada pesanan di sini.' }}
            </div>

            <div v-else class="space-y-3">
                <Card v-for="o in orders.data" :key="o.id" class="rounded-2xl border-slate-200 shadow-sm" :class="{ 'border-orange-300': o.status === 'pending' }">
                    <CardContent class="space-y-3 pt-5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-bold text-slate-900">
                                    {{ o.recipient_name || 'Tanpa nama' }}
                                    <span v-if="o.is_self_order" class="ml-1 rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-semibold text-violet-700">Dari tautan</span>
                                </p>
                                <p class="text-xs text-slate-500">
                                    <Link v-if="o.transaction" :href="`/invoice/${o.transaction.id}`" class="font-mono hover:underline">{{ o.transaction.number }}</Link>
                                    · {{ time(o.created_at) }}
                                </p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusTone[o.status]">{{ o.status_label }}</span>
                        </div>

                        <!-- Isi pesanan: kasir perlu melihatnya sebelum menerima -->
                        <ul v-if="o.is_self_order && o.items.length" class="divide-y divide-slate-100 rounded-xl border border-slate-100 px-3 text-sm">
                            <li v-for="(item, i) in o.items" :key="i" class="flex gap-2 py-1.5">
                                <span class="w-8 shrink-0 font-bold text-slate-900">{{ qty(item.quantity) }}×</span>
                                <span class="min-w-0">
                                    <span class="text-slate-800">{{ item.name }}</span>
                                    <span v-if="item.note" class="block text-xs text-amber-700">{{ item.note }}</span>
                                </span>
                            </li>
                        </ul>

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
                            <span v-if="o.is_self_order" class="text-slate-600">Bayar: {{ paymentLabel(o.transaction?.payment_method) }}</span>
                            <span v-if="isUnpaid(o) && o.status !== 'cancelled'" class="rounded bg-red-50 px-2 text-xs font-semibold text-red-700">Belum lunas</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                            <!-- Toko yang mengisi nama kurir bisa memilihnya; selain itu cukup keterangan "Kurir toko". -->
                            <label v-if="courierOptions(o).length" class="inline-flex items-center gap-2 text-xs text-slate-600">
                                <Bike class="h-4 w-4" />
                                <select
                                    class="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                                    :value="o.courier ?? ''"
                                    :disabled="o.status === 'delivered' || o.status === 'cancelled'"
                                    aria-label="Kurir"
                                    @change="assign(o, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Kurir toko</option>
                                    <option v-for="name in courierOptions(o)" :key="name" :value="name">{{ name }}</option>
                                </select>
                            </label>
                            <span v-else class="inline-flex items-center gap-2 text-xs text-slate-600" data-testid="courier-label">
                                <Bike class="h-4 w-4" /> Kurir toko
                            </span>
                            <a v-if="o.whatsapp" :href="waUrl(o)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                <MessageCircle class="h-3.5 w-3.5" /> {{ o.phone }}
                            </a>
                            <div class="ml-auto flex flex-wrap gap-2">
                                <!-- Pesanan dari tautan yang menunggu konfirmasi -->
                                <template v-if="o.status === 'pending'">
                                    <Button size="sm" variant="outline" class="rounded-lg text-red-600" :disabled="busyId === o.id" @click="openReject(o)">
                                        <X class="mr-1 h-4 w-4" /> Tolak
                                    </Button>
                                    <Button size="sm" class="rounded-lg" :disabled="busyId === o.id" @click="accept(o)">
                                        <Check class="mr-1 h-4 w-4" /> Terima
                                    </Button>
                                </template>
                                <Button
                                    v-if="o.is_self_order && isUnpaid(o) && o.status !== 'pending' && o.status !== 'cancelled'"
                                    size="sm"
                                    variant="outline"
                                    class="rounded-lg"
                                    :disabled="busyId === o.id"
                                    @click="openPay(o)"
                                >
                                    <Wallet class="mr-1 h-4 w-4" /> Terima pembayaran
                                </Button>
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

        <!-- Dialog tolak -->
        <Dialog v-model:open="rejectDialog">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Tolak pesanan {{ rejectTarget?.recipient_name }}?</DialogTitle>
                    <DialogDescription>Pelanggan akan melihat bahwa pesanannya ditolak. Alasan dicatat di pesanan.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="r in rejectReasons"
                            :key="r"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                            :class="rejectReason === r ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                            @click="rejectReason = r"
                        >
                            {{ r }}
                        </button>
                    </div>
                    <div class="space-y-2">
                        <Label for="reject_reason">Alasan</Label>
                        <textarea
                            id="reject_reason"
                            v-model="rejectReason"
                            rows="2"
                            maxlength="500"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder="Opsional"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="rejectDialog = false">Batal</Button>
                    <Button variant="destructive" :disabled="busyId !== null" @click="submitReject">Tolak pesanan</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Dialog pembayaran -->
        <Dialog v-model:open="payDialog">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Terima pembayaran {{ payTarget?.recipient_name }}</DialogTitle>
                    <DialogDescription>
                        Pelanggan memilih <b>{{ paymentLabel(payTarget?.transaction?.payment_method) }}</b
                        >. Ganti bila pelanggan membayar dengan cara lain.
                    </DialogDescription>
                </DialogHeader>
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-xs text-slate-500">Total dibayar (termasuk ongkir)</p>
                    <p class="text-3xl font-black text-slate-900 tabular-nums">Rp{{ fmt(payTarget?.transaction?.total ?? 0) }}</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <Button v-for="m in payMethods" :key="m.value" :variant="payMethod === m.value ? 'default' : 'outline'" size="sm" class="h-10" @click="payMethod = m.value">
                        <component :is="m.icon" class="mr-2 h-4 w-4" />
                        {{ m.label }}
                    </Button>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="payDialog = false">Batal</Button>
                    <Button class="gap-1.5" :disabled="busyId !== null" @click="submitPay"><Wallet class="h-4 w-4" /> Konfirmasi lunas</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
