<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { usePrinter } from '@/composables/usePrinter';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';
import { buildKotPlainLines } from '@/lib/receiptPlainText';
import { toastError, toastSuccess } from '@/lib/swal';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    Check,
    ChefHat,
    CircleCheck,
    CircleX,
    MessageSquareText,
    Printer,
    QrCode,
    RefreshCw,
    UtensilsCrossed,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface OrderItem {
    id: number;
    product_name: string;
    quantity: number | string;
    total_price: number;
    modifiers: Array<{ name?: string; label?: string; value?: string }> | null;
}

interface Order {
    id: number;
    transaction_number: string;
    status: string;
    payment_status: string;
    table_reference: string | null;
    payment_method: string | null;
    total_amount: number;
    notes: string | null;
    payment_details: { customer_name?: string | null } | null;
    created_at: string | null;
    completed_at: string | null;
    items: OrderItem[];
}

const props = defineProps<{
    orders: {
        data: Order[];
        links?: { url: string | null; label: string; active: boolean }[];
    };
    stats: { active: number; pending: number; accepted: number; serving: number; ready: number; completed: number; cancelled: number };
    statusFilter: string;
    store?: { id: number; name: string; currency: string };
}>();

// --- Tab status ---
const tabs = computed(() => [
    { key: 'pending', label: 'Baru', hint: 'Perlu dikonfirmasi', count: props.stats.pending, icon: BellRing, tone: 'text-blue-600 bg-blue-50' },
    {
        key: 'accepted',
        label: 'Diproses',
        hint: 'Dimasak / menunggu bayar',
        count: props.stats.accepted,
        icon: ChefHat,
        tone: 'text-orange-600 bg-orange-50',
    },
    {
        key: 'completed',
        label: 'Selesai',
        hint: 'Jumlah hari ini',
        count: props.stats.completed,
        icon: CircleCheck,
        tone: 'text-emerald-600 bg-emerald-50',
    },
    { key: 'cancelled', label: 'Ditolak', hint: 'Semua waktu', count: props.stats.cancelled, icon: CircleX, tone: 'text-red-600 bg-red-50' },
]);
const filter = (status: string) => router.get('/table-orders', { status }, { preserveState: true, preserveScroll: true });

// --- Label & format ---
const statusMeta: Record<string, { label: string; tone: string }> = {
    pending: { label: 'Baru', tone: 'bg-blue-50 text-blue-700' },
    accepted: { label: 'Diproses dapur', tone: 'bg-orange-50 text-orange-700' },
    serving: { label: 'Disajikan', tone: 'bg-orange-50 text-orange-700' },
    ready: { label: 'Siap diantar', tone: 'bg-violet-50 text-violet-700' },
    completed: { label: 'Selesai', tone: 'bg-emerald-50 text-emerald-700' },
    cancelled: { label: 'Ditolak', tone: 'bg-red-50 text-red-700' },
};
const statusOf = (s: string) => statusMeta[s] ?? { label: s, tone: 'bg-slate-100 text-slate-600' };

const paymentMethods = [
    { value: 'cash', label: 'Tunai' },
    { value: 'qris', label: 'QRIS' },
    { value: 'bank_transfer', label: 'Transfer bank' },
    { value: 'card', label: 'Kartu' },
    { value: 'ewallet', label: 'E-Wallet' },
];
const paymentLabel = (method: string | null) =>
    ({
        cash: 'Tunai',
        qris: 'QRIS',
        non_cash: 'Non-tunai',
        card: 'Kartu',
        ewallet: 'E-Wallet',
        transfer: 'Transfer',
        bank_transfer: 'Transfer bank',
        other: 'Lainnya',
    })[method || ''] ||
    method ||
    'Belum dipilih';
const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const qty = (q: number | string) => Number(q).toLocaleString('id-ID');

// Waktu relatif agar kasir tahu pesanan mana yang paling lama menunggu.
const now = ref(Date.now());
const ago = (d: string | null) => {
    if (!d) return '';
    const min = Math.max(0, Math.floor((now.value - new Date(d).getTime()) / 60000));
    if (min < 1) return 'baru saja';
    if (min < 60) return `${min} menit lalu`;
    const h = Math.floor(min / 60);
    if (h < 24) return `${h} jam lalu`;
    return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
};
const waitingLong = (o: Order) => o.status === 'pending' && !!o.created_at && now.value - new Date(o.created_at).getTime() > 5 * 60000;

// Catatan bawaan dari sistem ("Pesanan dari Meja X (Scan Meja)") tidak perlu ditampilkan.
const customerNote = (o: Order) => {
    const n = (o.notes ?? '')
        .split('\n')
        .filter((line) => !/^Pesanan dari .* \(Scan Meja\)$/.test(line.trim()))
        .join('\n')
        .trim();
    return n || null;
};
const itemNote = (i: OrderItem) =>
    (i.modifiers ?? [])
        .map((m) => (m.value ? m.value : (m.label ?? m.name)))
        .filter(Boolean)
        .join(', ');

// --- Pembaruan otomatis (pesanan baru masuk tanpa perlu muat ulang) ---
const refreshing = ref(false);
const refresh = (silent = true) => {
    if (refreshing.value) return;
    router.reload({
        only: ['orders', 'stats'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
        ...(silent ? {} : { onSuccess: () => toastSuccess('Daftar pesanan diperbarui.') }),
    });
};
let pollTimer: ReturnType<typeof setInterval> | undefined;
let clockTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible' && !rejectDialog.value && !payDialog.value) refresh();
    }, 20000);
    clockTimer = setInterval(() => (now.value = Date.now()), 30000);
});
onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
});

// --- Aksi ---
const busyId = ref<number | null>(null);
const post = (url: string, data: Record<string, string | null> = {}, after?: () => void) =>
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => (busyId.value = Number(url.split('/')[2])),
        onFinish: () => (busyId.value = null),
        // Server membalas dengan flash error (mis. shift belum dibuka) → dialog tetap terbuka.
        onSuccess: (page) => {
            if (!(page.props as { flash?: { error?: string } }).flash?.error) after?.();
        },
    });

const accept = (order: Order) => post(`/table-orders/${order.id}/accept`);
const complete = (order: Order) => post(`/table-orders/${order.id}/complete`);

// Tolak dengan alasan (dicatat di pesanan)
const rejectDialog = ref(false);
const rejectTarget = ref<Order | null>(null);
const rejectReason = ref('');
const rejectReasons = ['Menu habis', 'Dapur sudah tutup', 'Pesanan ganda', 'Pesanan tidak valid'];
const openReject = (order: Order) => {
    rejectTarget.value = order;
    rejectReason.value = '';
    rejectDialog.value = true;
};
const submitReject = () => {
    if (!rejectTarget.value) return;
    post(`/table-orders/${rejectTarget.value.id}/reject`, { reason: rejectReason.value || null }, () => (rejectDialog.value = false));
};

// Bayar: metode bawaan dari pilihan pelanggan, kasir boleh menggantinya
const payDialog = ref(false);
const payTarget = ref<Order | null>(null);
const payMethod = ref('cash');
const openPay = (order: Order) => {
    payTarget.value = order;
    const m = order.payment_method === 'transfer' ? 'bank_transfer' : order.payment_method;
    payMethod.value = paymentMethods.some((p) => p.value === m) ? (m as string) : 'cash';
    payDialog.value = true;
};
const submitPay = () => {
    if (!payTarget.value) return;
    post(`/table-orders/${payTarget.value.id}/pay`, { payment_method: payMethod.value }, () => (payDialog.value = false));
};

// Cetak KOT ke printer dapur
const { isPrinting: isPrinterPrinting, initialize: initializePrinter, printEscPosBytes } = usePrinter();
const printKot = async (order: Order) => {
    const lines = buildKotPlainLines({
        storeName: props.store?.name || 'Kasir UMKM by MaccaPOS',
        transactionNumber: order.transaction_number,
        tableReference: order.table_reference,
        items: order.items.map((item) => ({ product_name: item.product_name, quantity: item.quantity })),
        paperWidthMm: 58,
    });
    const result = await printEscPosBytes(receiptLinesToEscPos(lines), 'kitchen');
    if (result.success) toastSuccess('KOT berhasil dicetak ke printer dapur.');
    else toastError(result.error || 'KOT gagal dicetak.');
};
initializePrinter();

const pageLabel = (label: string) => (label.includes('laquo') ? '‹' : label.includes('raquo') ? '›' : label);
const emptyText = computed(
    () =>
        ({
            pending: {
                title: 'Belum ada pesanan baru',
                body: 'Pesanan dari scan QR meja akan muncul di sini. Halaman diperbarui otomatis setiap 20 detik.',
            },
            accepted: { title: 'Tidak ada pesanan yang sedang diproses', body: 'Pesanan yang Anda terima akan pindah ke sini sampai dibayar.' },
            completed: { title: 'Belum ada pesanan selesai hari ini', body: 'Pesanan yang sudah dibayar akan tercatat di sini.' },
            cancelled: { title: 'Tidak ada pesanan ditolak', body: 'Pesanan yang Anda tolak akan tercatat di sini beserta alasannya.' },
        })[props.statusFilter] ?? { title: 'Tidak ada pesanan', body: '' },
);
</script>

<template>
    <Head title="Pesanan Masuk" />
    <AdminLayout title="Pesanan Masuk">
        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <!-- Kepala halaman -->
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Pesanan dari scan meja</h2>
                    <p class="text-sm text-slate-500">Terima pesanan baru, teruskan ke dapur, lalu catat pembayarannya. Diperbarui otomatis.</p>
                </div>
                <div class="flex gap-2">
                    <Link href="/tables">
                        <Button variant="outline" class="gap-1.5 rounded-xl"><QrCode class="h-4 w-4" /> Kelola meja</Button>
                    </Link>
                    <Button variant="outline" class="gap-1.5 rounded-xl" :disabled="refreshing" @click="refresh(false)">
                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshing }" /> Muat ulang
                    </Button>
                </div>
            </div>

            <!-- Tab status sekaligus ringkasan -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="flex items-center gap-3 rounded-2xl border bg-white p-4 text-left shadow-sm transition"
                    :class="statusFilter === t.key ? 'border-primary ring-2 ring-primary/20' : 'border-slate-200 hover:border-slate-300'"
                    @click="filter(t.key)"
                >
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="t.tone">
                        <component :is="t.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-500">{{ t.label }}</p>
                        <p class="text-2xl font-black text-slate-900 tabular-nums">{{ t.count }}</p>
                        <p class="truncate text-[11px] text-slate-400">{{ t.hint }}</p>
                    </div>
                    <span v-if="t.key === 'pending' && t.count > 0" class="ml-auto h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-blue-500" />
                </button>
            </div>

            <!-- Daftar pesanan -->
            <div v-if="orders.data.length" class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                <div
                    v-for="order in orders.data"
                    :key="order.id"
                    class="flex flex-col rounded-2xl border bg-white shadow-sm"
                    :class="waitingLong(order) ? 'border-amber-300' : 'border-slate-200'"
                >
                    <!-- Kepala kartu: meja besar agar mudah dicari -->
                    <div class="flex items-start gap-3 border-b border-slate-100 p-4">
                        <div
                            class="flex h-12 min-w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 px-2 text-center text-sm leading-tight font-black text-primary"
                        >
                            {{ order.table_reference || '—' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusOf(order.status).tone">{{
                                    statusOf(order.status).label
                                }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="order.payment_status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                                >
                                    {{ order.payment_status === 'paid' ? 'Lunas' : 'Belum bayar' }}
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm text-slate-500">
                                <span v-if="order.payment_details?.customer_name" class="font-semibold text-slate-700"
                                    >{{ order.payment_details.customer_name }} ·
                                </span>
                                <span class="font-mono text-xs">{{ order.transaction_number }}</span>
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-xs whitespace-nowrap"
                            :class="waitingLong(order) ? 'font-semibold text-amber-600' : 'text-slate-400'"
                        >
                            {{ ago(order.created_at) }}
                        </span>
                    </div>

                    <!-- Isi pesanan -->
                    <div class="flex-1 space-y-3 p-4">
                        <ul class="space-y-1.5">
                            <li v-for="item in order.items" :key="item.id" class="flex gap-3 text-sm">
                                <span class="w-8 shrink-0 font-bold text-slate-900 tabular-nums">{{ qty(item.quantity) }}×</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-slate-800">{{ item.product_name }}</p>
                                    <p v-if="itemNote(item)" class="text-xs text-amber-700">{{ itemNote(item) }}</p>
                                </div>
                                <span class="shrink-0 text-slate-500 tabular-nums">{{ money(item.total_price) }}</span>
                            </li>
                        </ul>
                        <p v-if="customerNote(order)" class="flex gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <MessageSquareText class="mt-0.5 h-4 w-4 shrink-0" />
                            <span class="whitespace-pre-line">{{ customerNote(order) }}</span>
                        </p>
                        <div class="flex items-center justify-between border-t border-dashed border-slate-200 pt-3">
                            <span class="text-sm text-slate-500">Total · bayar {{ paymentLabel(order.payment_method) }}</span>
                            <span class="text-lg font-black text-slate-900 tabular-nums">{{ money(order.total_amount) }}</span>
                        </div>
                    </div>

                    <!-- Aksi sesuai status -->
                    <div v-if="order.status === 'pending'" class="grid grid-cols-3 gap-2 border-t border-slate-100 p-3">
                        <Button
                            variant="outline"
                            class="gap-1.5 rounded-xl text-red-600 hover:bg-red-50 hover:text-red-700"
                            :disabled="busyId === order.id"
                            @click="openReject(order)"
                        >
                            <X class="h-4 w-4" /> Tolak
                        </Button>
                        <Button class="col-span-2 gap-1.5 rounded-xl" :disabled="busyId === order.id" @click="accept(order)">
                            <Check class="h-4 w-4" /> Terima & kirim ke dapur
                        </Button>
                    </div>
                    <div
                        v-else-if="['accepted', 'serving', 'ready'].includes(order.status)"
                        class="flex flex-wrap gap-2 border-t border-slate-100 p-3"
                    >
                        <Button
                            variant="outline"
                            class="gap-1.5 rounded-xl"
                            :disabled="isPrinterPrinting"
                            title="Cetak tiket dapur"
                            @click="printKot(order)"
                        >
                            <Printer class="h-4 w-4" /> KOT
                        </Button>
                        <Button
                            v-if="order.payment_status !== 'paid'"
                            class="flex-1 gap-1.5 rounded-xl"
                            :disabled="busyId === order.id"
                            @click="openPay(order)"
                        >
                            <Wallet class="h-4 w-4" /> Terima pembayaran
                        </Button>
                        <Button v-else class="flex-1 gap-1.5 rounded-xl" :disabled="busyId === order.id" @click="complete(order)">
                            <UtensilsCrossed class="h-4 w-4" /> Tandai sudah diantar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Kosong -->
            <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10">
                    <BellRing class="h-6 w-6 text-primary" />
                </div>
                <p class="mt-3 font-bold text-slate-900">{{ emptyText.title }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ emptyText.body }}</p>
            </div>

            <!-- Paginasi -->
            <div v-if="(orders.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-1">
                <template v-for="(link, i) in orders.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm"
                        :class="link.active ? 'border-primary bg-primary text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                    >
                        {{ pageLabel(link.label) }}
                    </Link>
                    <span v-else class="min-w-9 rounded-lg border border-slate-100 px-3 py-1.5 text-center text-sm text-slate-300">{{
                        pageLabel(link.label)
                    }}</span>
                </template>
            </div>
        </div>

        <!-- Dialog tolak -->
        <Dialog v-model:open="rejectDialog">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Tolak pesanan {{ rejectTarget?.table_reference }}?</DialogTitle>
                    <DialogDescription>Pelanggan akan melihat bahwa pesanannya ditolak. Alasan dicatat di pesanan.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="r in rejectReasons"
                            :key="r"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                            :class="
                                rejectReason === r
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                            "
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
                    <DialogTitle>Terima pembayaran {{ payTarget?.table_reference }}</DialogTitle>
                    <DialogDescription>
                        Pelanggan memilih <b>{{ paymentLabel(payTarget?.payment_method ?? null) }}</b
                        >. Ganti bila pelanggan membayar dengan cara lain.
                    </DialogDescription>
                </DialogHeader>
                <div class="rounded-xl bg-slate-50 p-4 text-center">
                    <p class="text-xs text-slate-500">Total dibayar</p>
                    <p class="text-3xl font-black text-slate-900 tabular-nums">{{ money(payTarget?.total_amount ?? 0) }}</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="m in paymentMethods"
                        :key="m.value"
                        type="button"
                        class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
                        :class="
                            payMethod === m.value
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="payMethod = m.value"
                    >
                        {{ m.label }}
                    </button>
                </div>
                <p class="text-xs text-slate-500">Pembayaran dicatat ke shift kasir Anda yang sedang buka, dan pesanan ditandai selesai.</p>
                <DialogFooter>
                    <Button variant="outline" @click="payDialog = false">Batal</Button>
                    <Button class="gap-1.5" :disabled="busyId !== null" @click="submitPay"><Wallet class="h-4 w-4" /> Konfirmasi lunas</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
