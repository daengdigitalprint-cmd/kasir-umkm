<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Clock, Banknote, LockKeyhole, Calculator, Printer, AlertTriangle, CheckCircle2, ChevronDown, Receipt } from 'lucide-vue-next';
import { confirmDialog } from '@/lib/swal';

interface ShiftRow {
    id: number;
    shift_number: string;
    user_name: string | null;
    opened_at: string | null;
    closed_at: string | null;
    opening_cash: number;
    /** null untuk staf (hitung buta). */
    expected_cash: number | null;
    closing_cash_counted: number | null;
    /** null untuk staf (hitung buta) atau shift yang belum ditutup. */
    difference_amount: number | null;
    closing_notes: string | null;
    transactions_count: number;
}

interface PaymentRow {
    method: string;
    label: string;
    amount: number;
}

interface OpenShift {
    id: number;
    shift_number: string;
    owner_name: string;
    /** false bila pengelola sedang melihat shift milik orang lain. */
    is_own: boolean;
    opened_at: string | null;
    hours_open: number;
    opening_cash: number;
    /** null untuk kasir (hitung buta). */
    expected_cash: number | null;
    transactions_count: number;
    sales_total: number | null;
    cash_in: number | null;
    cash_out: number | null;
    payments: PaymentRow[];
}

/** Shift aktif milik kasir lain di outlet yang sama. */
interface ActiveShiftByOther {
    shift_number: string;
    user_name: string;
    opened_at: string | null;
    hours_open: number;
}

/** Data popup pemblokiran (dari flash `shift_blocked`). */
interface ShiftBlockedInfo {
    shift_number: string;
    user_name: string;
    opened_at: string | null;
    store_name?: string;
}

interface JustClosed {
    id: number;
    shift_number: string;
    closing_cash_counted: number;
    difference_amount: number | null;
    print_url: string;
}

const props = defineProps<{
    shifts: ShiftRow[];
    openShift: OpenShift | null;
    activeShiftByOther?: ActiveShiftByOther | null;
    timezone?: string;
    canViewAllShifts?: boolean;
    showsOutletShifts?: boolean;
    historyLimit?: number;
    suggestedOpeningCash?: number | null;
    longOpenHours?: number;
    justClosed?: JustClosed | null;
    signatureEnabled?: boolean;
}>();

const page = usePage();
const tz = computed(() => props.timezone || 'Asia/Jakarta');
const longOpen = computed(() => props.longOpenHours ?? 16);

// Tanda tangan digital diatur di Pengaturan toko → Fitur (bawaan nonaktif).
const signatureOn = computed(() => props.signatureEnabled === true);

const flashBlocked = computed(
    () => (page.props as { flash?: { shift_blocked?: ShiftBlockedInfo | null } }).flash?.shift_blocked ?? null,
);

// Popup pemblokiran: muncul otomatis saat upaya buka shift diblokir karena
// sudah ada shift aktif oleh kasir lain di outlet yang sama.
const blockedModalOpen = ref(false);
const blockedInfo = ref<ShiftBlockedInfo | null>(null);

watch(
    flashBlocked,
    (info) => {
        if (info) {
            blockedInfo.value = info;
            blockedModalOpen.value = true;
        }
    },
    { immediate: true },
);

function confirmBlocked() {
    blockedModalOpen.value = false;
    blockedInfo.value = null;
}

// ── Buka shift ─────────────────────────────────────────────
const openForm = useForm({
    opening_cash: props.suggestedOpeningCash != null ? String(Math.round(props.suggestedOpeningCash)) : '',
});

const openingCashDisplay = computed({
    get() {
        const raw = openForm.opening_cash;
        if (raw === '') return '';
        return Number(raw).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    set(val: string) {
        openForm.opening_cash = val.replace(/[^0-9]/g, '');
    },
});

function submitOpen() {
    if (openForm.opening_cash === '') openForm.opening_cash = '0';
    openForm.post('/cash-shifts/open', { preserveScroll: true });
}

// ── Tutup shift ────────────────────────────────────────────
const closeForm = useForm({
    closing_cash_counted: '',
    closing_notes: '',
});

const closingCashDisplay = computed({
    get() {
        const raw = closeForm.closing_cash_counted;
        if (raw === '') return '';
        return Number(raw).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    },
    set(val: string) {
        closeForm.closing_cash_counted = val.replace(/[^0-9]/g, '');
        calcOpen.value = false;
    },
});

// Kalkulator pecahan: kasir isi jumlah lembar/keping, total otomatis masuk ke kolom kas dihitung.
const DENOMINATIONS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];
const calcOpen = ref(false);
const pieces = reactive<Record<number, string>>(Object.fromEntries(DENOMINATIONS.map((d) => [d, ''])));
const calcTotal = computed(() => DENOMINATIONS.reduce((sum, d) => sum + d * (parseInt(pieces[d] || '0', 10) || 0), 0));

function setPieces(d: number, val: string) {
    pieces[d] = val.replace(/[^0-9]/g, '').slice(0, 5);
    closeForm.closing_cash_counted = String(calcTotal.value);
}

function toggleCalc() {
    calcOpen.value = !calcOpen.value;
}

async function submitClose() {
    if (!props.openShift) return;
    const counted = Number(closeForm.closing_cash_counted || 0);
    const whose = props.openShift.is_own ? '' : ` milik ${props.openShift.owner_name}`;
    const ok = await confirmDialog(
        `Tutup shift${whose}?`,
        `Uang di laci yang dicatat: Rp ${formatMoney(counted)}. Setelah ditutup, penjualan tunai berikutnya butuh shift baru.`,
        { confirmText: 'Ya, tutup shift', cancelText: 'Periksa lagi', confirmColor: '#dc2626' },
    );
    if (!ok) return;
    closeForm.post(`/cash-shifts/${props.openShift.id}/close`, { preserveScroll: true });
}

// ── Format ─────────────────────────────────────────────────
function formatMoney(n: number) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
}

function formatDt(iso: string | null) {
    if (!iso) return '—';
    const normalized = iso.trim();
    const hasTz = /[Zz]|[+-]\d{2}:?\d{2}$/.test(normalized);
    const d = new Date(hasTz ? normalized : normalized + 'Z');
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('id-ID', {
        timeZone: tz.value,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function durationLabel(hours: number) {
    if (hours < 1) return 'kurang dari 1 jam';
    if (hours < 48) return `${hours} jam`;
    return `${Math.floor(hours / 24)} hari`;
}

function diffLabel(n: number) {
    if (n === 0) return 'Pas';
    return n < 0 ? `Kurang Rp ${formatMoney(Math.abs(n))}` : `Lebih Rp ${formatMoney(n)}`;
}

function diffClass(n: number) {
    if (n === 0) return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    return n < 0 ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-700 ring-amber-200';
}
</script>

<template>
    <AdminLayout title="Shift kasir">
        <Head title="Shift kasir" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <Clock class="h-7 w-7 text-primary" />
                Shift kasir
            </h1>
            <p class="text-sm text-slate-600 mt-1">
                Buka shift sebelum melayani pembayaran tunai, tutup shift setelah menghitung uang di laci. Satu outlet hanya punya satu shift aktif.
            </p>
        </div>

        <!-- Ringkasan shift yang baru ditutup -->
        <Card v-if="justClosed" class="border-0 shadow-lg border-l-4 border-l-emerald-500 mb-6">
            <CardContent class="py-5 flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="flex items-start gap-3 flex-1">
                    <CheckCircle2 class="h-6 w-6 text-emerald-600 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-semibold text-slate-900">Shift {{ justClosed.shift_number }} sudah ditutup</p>
                        <p class="text-sm text-slate-600">
                            Uang dihitung Rp {{ formatMoney(justClosed.closing_cash_counted) }}
                            <template v-if="justClosed.difference_amount !== null">
                                ·
                                <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-semibold ring-1', diffClass(justClosed.difference_amount)]">
                                    {{ diffLabel(justClosed.difference_amount) }}
                                </span>
                            </template>
                        </p>
                    </div>
                </div>
                <Button as="a" :href="justClosed.print_url" target="_blank" variant="outline" class="rounded-xl">
                    <Printer class="h-4 w-4 mr-2" />
                    {{ signatureOn ? 'Cetak & tanda tangan' : 'Cetak laporan shift' }}
                </Button>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Buka shift -->
            <Card v-if="!openShift && !activeShiftByOther" class="border-0 shadow-lg border-l-4 border-l-amber-400">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Banknote class="h-5 w-5 text-amber-600" />
                        Buka shift
                    </CardTitle>
                    <CardDescription>Hitung uang di laci sebelum mulai melayani, lalu isi jumlahnya di sini.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submitOpen">
                        <div class="space-y-2">
                            <Label for="opening_cash">Kas awal (Rp)</Label>
                            <Input
                                id="opening_cash"
                                v-model="openingCashDisplay"
                                type="text"
                                inputmode="numeric"
                                placeholder="0"
                                class="rounded-xl text-lg font-semibold tabular-nums"
                            />
                            <p v-if="suggestedOpeningCash != null" class="text-xs text-slate-500">
                                Terisi otomatis dari uang yang dihitung saat shift terakhir ditutup (Rp {{ formatMoney(suggestedOpeningCash) }}). Ubah bila isi laci berbeda.
                            </p>
                            <p v-if="openForm.errors.opening_cash" class="text-sm text-red-600">{{ openForm.errors.opening_cash }}</p>
                        </div>
                        <Button type="submit" class="rounded-xl w-full sm:w-auto" :disabled="openForm.processing">Buka shift &amp; mulai jualan</Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Diblokir shift orang lain (kasir) -->
            <Card v-else-if="activeShiftByOther && !openShift" class="border-0 shadow-lg border-l-4 border-l-red-400 bg-red-50/40">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <LockKeyhole class="h-5 w-5 text-red-600" />
                        Shift masih dipakai {{ activeShiftByOther.user_name }}
                    </CardTitle>
                    <CardDescription>
                        Shift <span class="font-mono font-semibold">{{ activeShiftByOther.shift_number }}</span> dibuka
                        {{ formatDt(activeShiftByOther.opened_at) }} ({{ durationLabel(activeShiftByOther.hours_open) }} lalu).
                        Shift baru bisa dibuka setelah shift ini ditutup.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3 text-sm text-slate-700">
                    <p v-if="activeShiftByOther.hours_open >= longOpen" class="flex items-start gap-2 text-amber-800 bg-amber-50 rounded-xl p-3">
                        <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
                        Shift ini sudah terbuka lama, kemungkinan lupa ditutup.
                    </p>
                    <p>
                        Minta <span class="font-semibold">{{ activeShiftByOther.user_name }}</span> menutup shift-nya, atau minta pemilik/admin toko
                        menutupnya lewat menu <span class="font-semibold">Shift kasir</span>.
                    </p>
                    <Link href="/pos" class="inline-block text-primary font-medium hover:underline">Kembali ke POS</Link>
                </CardContent>
            </Card>

            <!-- Shift aktif -->
            <Card v-else-if="openShift" class="border-0 shadow-lg border-l-4" :class="openShift.is_own ? 'border-l-emerald-500' : 'border-l-amber-500'">
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        <span>Shift aktif {{ openShift.shift_number }}</span>
                        <span class="text-sm font-medium text-slate-500">· {{ openShift.owner_name }}</span>
                    </CardTitle>
                    <CardDescription>
                        Dibuka {{ formatDt(openShift.opened_at) }} ({{ durationLabel(openShift.hours_open) }} lalu) · Kas awal Rp
                        {{ formatMoney(openShift.opening_cash) }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div v-if="!openShift.is_own" class="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 rounded-xl p-3">
                        <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
                        <span>
                            Ini shift milik <span class="font-semibold">{{ openShift.owner_name }}</span>. Selama shift ini terbuka, kasir lain di outlet ini
                            tidak bisa membuka shift. Tutup bila memang sudah selesai.
                        </span>
                    </div>
                    <div
                        v-else-if="openShift.hours_open >= longOpen"
                        class="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 rounded-xl p-3"
                    >
                        <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
                        <span>Shift ini sudah terbuka {{ durationLabel(openShift.hours_open) }}. Jangan lupa tutup di akhir hari agar kasir berikutnya bisa membuka shift.</span>
                    </div>

                    <!-- Ringkasan berjalan -->
                    <div class="rounded-xl bg-slate-50 p-4 space-y-2 text-sm">
                        <p class="flex items-center gap-2 font-semibold text-slate-800">
                            <Receipt class="h-4 w-4 text-primary" />
                            Ringkasan shift ini
                        </p>
                        <div class="flex justify-between"><span class="text-slate-600">Transaksi selesai</span><span class="font-semibold tabular-nums">{{ openShift.transactions_count }}</span></div>
                        <div v-if="openShift.sales_total !== null" class="flex justify-between">
                            <span class="text-slate-600">Total penjualan</span>
                            <span class="font-semibold tabular-nums">Rp {{ formatMoney(openShift.sales_total) }}</span>
                        </div>
                        <div v-for="p in openShift.payments" :key="p.method" class="flex justify-between pl-3">
                            <span class="text-slate-500">{{ p.label }}</span>
                            <span class="tabular-nums">Rp {{ formatMoney(p.amount) }}</span>
                        </div>
                        <template v-if="openShift.cash_in !== null">
                            <div class="flex justify-between"><span class="text-slate-600">Kas masuk</span><span class="tabular-nums">Rp {{ formatMoney(openShift.cash_in) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">Kas keluar</span><span class="tabular-nums">− Rp {{ formatMoney(openShift.cash_out ?? 0) }}</span></div>
                        </template>
                        <div v-if="openShift.expected_cash !== null" class="flex justify-between border-t border-slate-200 pt-2">
                            <span class="font-semibold text-slate-800">Seharusnya di laci</span>
                            <span class="font-bold tabular-nums">Rp {{ formatMoney(openShift.expected_cash) }}</span>
                        </div>
                        <p v-if="openShift.expected_cash === null && openShift.payments.length" class="text-xs text-slate-500 pt-1">
                            Cocokkan angka non-tunai dengan mesin EDC, QRIS, atau mutasi rekening.
                        </p>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitClose">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <Label for="closing_cash_counted">Uang tunai di laci saat tutup (Rp)</Label>
                                <button type="button" class="text-xs font-semibold text-primary flex items-center gap-1" @click="toggleCalc">
                                    <Calculator class="h-3.5 w-3.5" />
                                    Hitung per pecahan
                                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="calcOpen ? 'rotate-180' : ''" />
                                </button>
                            </div>
                            <Input
                                id="closing_cash_counted"
                                v-model="closingCashDisplay"
                                type="text"
                                inputmode="numeric"
                                class="rounded-xl text-lg font-semibold tabular-nums"
                                placeholder="Hitung uang di laci"
                                required
                            />
                            <p v-if="openShift.expected_cash === null" class="text-xs text-slate-500">
                                Hitung semua uang tunai di laci, lalu isi jumlahnya. Selisih diperiksa oleh pengelola.
                            </p>
                            <p v-if="closeForm.errors.closing_cash_counted" class="text-sm text-red-600">{{ closeForm.errors.closing_cash_counted }}</p>

                            <div v-if="calcOpen" class="rounded-xl border border-slate-200 p-3">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                                    <label v-for="d in DENOMINATIONS" :key="d" class="flex items-center gap-2 text-sm">
                                        <span class="w-16 shrink-0 text-right tabular-nums text-slate-600">{{ formatMoney(d) }}</span>
                                        <span class="text-slate-400">×</span>
                                        <input
                                            :value="pieces[d]"
                                            type="text"
                                            inputmode="numeric"
                                            placeholder="0"
                                            class="w-full min-w-0 rounded-lg border border-slate-200 px-2 py-1.5 text-right tabular-nums focus:border-primary focus:outline-none"
                                            @input="setPieces(d, ($event.target as HTMLInputElement).value)"
                                        />
                                    </label>
                                </div>
                                <div class="mt-3 flex justify-between border-t border-slate-100 pt-2 text-sm font-semibold">
                                    <span>Total</span>
                                    <span class="tabular-nums">Rp {{ formatMoney(calcTotal) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="closing_notes">Catatan (wajib bila ada selisih besar)</Label>
                            <Input
                                id="closing_notes"
                                v-model="closeForm.closing_notes"
                                type="text"
                                class="rounded-xl"
                                maxlength="2000"
                                placeholder="Mis. uang dipakai beli es batu Rp 20.000"
                            />
                            <p v-if="closeForm.errors.closing_notes" class="text-sm text-red-600">{{ closeForm.errors.closing_notes }}</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <Button type="submit" variant="destructive" class="rounded-xl" :disabled="closeForm.processing">Tutup shift</Button>
                            <Button as="a" :href="`/cash-shifts/${openShift.id}/print`" target="_blank" variant="outline" class="rounded-xl">
                                <Printer class="h-4 w-4 mr-2" />
                                Cetak ringkasan
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <!-- Riwayat -->
        <Card class="border-0 shadow-lg mt-8">
            <CardHeader class="flex flex-row flex-wrap items-start justify-between gap-3">
                <div>
                    <CardTitle>{{ showsOutletShifts ? 'Riwayat shift outlet' : 'Riwayat shift Anda' }}</CardTitle>
                    <CardDescription>{{ historyLimit ?? 100 }} shift terakhir{{ showsOutletShifts ? ' dari semua kasir di outlet ini' : '' }}.</CardDescription>
                </div>
                <Link v-if="canViewAllShifts" href="/reports/cash-shifts" class="text-sm font-medium text-primary hover:underline">
                    Lihat semua shift kasir →
                </Link>
            </CardHeader>
            <CardContent>
                <!-- HP: kartu -->
                <div class="space-y-3 md:hidden">
                    <div v-for="s in shifts" :key="s.id" class="rounded-xl border border-slate-200 p-3 text-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-slate-900">{{ s.shift_number }}</p>
                                <p class="text-xs text-slate-500 truncate">{{ s.user_name || 'Kasir' }}</p>
                            </div>
                            <span
                                v-if="s.difference_amount !== null"
                                :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ring-1', diffClass(s.difference_amount)]"
                            >
                                {{ diffLabel(s.difference_amount) }}
                            </span>
                            <span v-else-if="!s.closed_at" class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Berjalan</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-600">{{ formatDt(s.opened_at) }} → {{ s.closed_at ? formatDt(s.closed_at) : 'belum ditutup' }}</p>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-xs">
                            <div><p class="text-slate-500">Kas awal</p><p class="font-semibold tabular-nums">Rp {{ formatMoney(s.opening_cash) }}</p></div>
                            <div>
                                <p class="text-slate-500">Dihitung</p>
                                <p class="font-semibold tabular-nums">{{ s.closing_cash_counted !== null ? `Rp ${formatMoney(s.closing_cash_counted)}` : '—' }}</p>
                            </div>
                            <div><p class="text-slate-500">Transaksi</p><p class="font-semibold tabular-nums">{{ s.transactions_count }}</p></div>
                        </div>
                        <a v-if="s.closed_at" :href="`/cash-shifts/${s.id}/print`" target="_blank" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary">
                            <Printer class="h-3.5 w-3.5" /> Cetak
                        </a>
                    </div>
                    <p v-if="shifts.length === 0" class="py-10 text-center text-slate-500">Belum ada riwayat shift.</p>
                </div>

                <!-- Desktop: tabel -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b text-slate-500">
                                <th class="pb-3 pr-3 font-medium">Shift</th>
                                <th class="pb-3 pr-3 font-medium">Buka</th>
                                <th class="pb-3 pr-3 font-medium">Tutup</th>
                                <th class="pb-3 pr-3 font-medium text-right">Kas awal</th>
                                <th v-if="canViewAllShifts" class="pb-3 pr-3 font-medium text-right">Seharusnya</th>
                                <th class="pb-3 pr-3 font-medium text-right">Dihitung</th>
                                <th v-if="canViewAllShifts" class="pb-3 pr-3 font-medium text-right">Selisih</th>
                                <th class="pb-3 pr-3 font-medium text-right">Trx</th>
                                <th class="pb-3 font-medium"><span class="sr-only">Cetak</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in shifts" :key="s.id" class="border-b border-slate-100">
                                <td class="py-3 pr-3">
                                    <p class="font-mono text-xs font-semibold text-slate-900">{{ s.shift_number }}</p>
                                    <p class="text-xs text-slate-500">{{ s.user_name || 'Kasir' }}</p>
                                </td>
                                <td class="py-3 pr-3 whitespace-nowrap">{{ formatDt(s.opened_at) }}</td>
                                <td class="py-3 pr-3 whitespace-nowrap">
                                    <template v-if="s.closed_at">{{ formatDt(s.closed_at) }}</template>
                                    <span v-else class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Berjalan</span>
                                </td>
                                <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(s.opening_cash) }}</td>
                                <td v-if="canViewAllShifts" class="py-3 pr-3 text-right tabular-nums">
                                    {{ s.expected_cash !== null ? `Rp ${formatMoney(s.expected_cash)}` : '—' }}
                                </td>
                                <td class="py-3 pr-3 text-right tabular-nums">
                                    {{ s.closing_cash_counted !== null ? `Rp ${formatMoney(s.closing_cash_counted)}` : '—' }}
                                </td>
                                <td v-if="canViewAllShifts" class="py-3 pr-3 text-right">
                                    <span
                                        v-if="s.difference_amount !== null"
                                        :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ring-1', diffClass(s.difference_amount)]"
                                    >
                                        {{ diffLabel(s.difference_amount) }}
                                    </span>
                                    <template v-else>—</template>
                                </td>
                                <td class="py-3 pr-3 text-right tabular-nums">{{ s.transactions_count }}</td>
                                <td class="py-3 text-right">
                                    <a v-if="s.closed_at" :href="`/cash-shifts/${s.id}/print`" target="_blank" class="text-primary" title="Cetak laporan shift">
                                        <Printer class="h-4 w-4 inline" />
                                    </a>
                                </td>
                            </tr>
                            <tr v-if="shifts.length === 0">
                                <td :colspan="canViewAllShifts ? 9 : 7" class="py-12 text-center text-slate-500">Belum ada riwayat shift.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-xs text-slate-500">
                    <Link href="/pos" class="text-primary font-medium hover:underline">Kembali ke POS</Link>
                </p>
            </CardContent>
        </Card>

        <!-- Popup konfirmasi: shift sudah dibuka oleh kasir lain di outlet yang sama.
             Hanya bisa ditutup lewat tombol "Mengerti" (tanpa X, backdrop, atau ESC). -->
        <Teleport to="body">
            <div
                v-if="blockedModalOpen"
                class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
                role="dialog"
                aria-modal="true"
                aria-labelledby="blocked-shift-title"
                aria-describedby="blocked-shift-desc"
            >
                <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full border border-slate-200 p-6">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-red-100 flex-shrink-0">
                            <LockKeyhole class="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h2 id="blocked-shift-title" class="text-lg font-black text-slate-900">Shift sudah dibuka</h2>
                            <p id="blocked-shift-desc" class="text-sm text-slate-600 mt-1">
                                Shift <span class="font-mono font-semibold">{{ blockedInfo?.shift_number }}</span> sudah dibuka oleh
                                <span class="font-semibold">{{ blockedInfo?.user_name }}</span> pada
                                <span class="font-semibold">{{ formatDt(blockedInfo?.opened_at ?? null) }}</span>.
                                Minta kasir tersebut, pemilik, atau admin toko menutupnya terlebih dahulu.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <Button class="rounded-xl" @click="confirmBlocked">Mengerti</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>
