<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowDownCircle,
    ArrowLeft,
    ArrowUpCircle,
    Ban,
    BarChart3,
    Camera,
    KeyRound,
    Plus,
    Receipt,
    Settings2,
    Trash2,
    TriangleAlert,
    Wallet,
    X,
    Zap,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

type Dir = 'in' | 'out';

interface TransactionRow {
    id: number;
    type: Dir;
    category: string;
    category_label: string;
    amount: string;
    party_name: string | null;
    notes: string;
    recorded_by: string | null;
    recorded_by_id: number | null;
    created_at: string;
    receipt_url: string | null;
    approved_with_pin: boolean;
    voided_at: string | null;
    voided_by: string | null;
    void_reason: string | null;
}

interface Preset {
    type: Dir;
    category: string;
    label: string;
    notes: string;
    amount: number | null;
}

const props = defineProps<{
    shift: { id: number; shift_number: string; opened_at?: string | null; opening_cash: number; expected_cash: number };
    summary: { opening_cash: number; sales_cash: number; cash_in: number; cash_out: number; expected_cash: number };
    transactions: TransactionRow[];
    canViewReport?: boolean;
    canManage?: boolean;
    drawerSettings?: { approval_limit: number; has_approval_pin: boolean; cashier_discount_limit?: number; presets: Preset[] };
}>();

const settings = computed(() => props.drawerSettings ?? { approval_limit: 0, has_approval_pin: false, presets: [] });

const rupiah = (v: number | string) => 'Rp ' + Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
const time = (d: string) => new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

const categories: Record<Dir, { id: string; name: string; desc: string }[]> = {
    in: [
        { id: 'non_revenue', name: 'Non pendapatan', desc: 'Tidak menambah laba. Contoh: tambah modal kembalian.' },
        { id: 'revenue', name: 'Pendapatan lain', desc: 'Menambah laba. Contoh: jual kardus bekas.' },
    ],
    out: [
        { id: 'expense', name: 'Biaya', desc: 'Mengurangi laba. Contoh: beli galon, bayar parkir.' },
        { id: 'non_expense', name: 'Non biaya', desc: 'Tidak mengurangi laba. Contoh: setor uang ke owner.' },
    ],
};

// ── Formulir ──
const dir = ref<Dir>('out');
const form = useForm({
    type: 'out' as Dir,
    category: 'expense',
    amount: '',
    party_name: '',
    notes: '',
    receipt: null as File | null,
    approval_pin: '',
    confirm_overdraw: false,
});
const receiptPreview = ref<string | null>(null);
const clearReceipt = () => {
    if (receiptPreview.value) URL.revokeObjectURL(receiptPreview.value);
    receiptPreview.value = null;
    form.receipt = null;
};
onBeforeUnmount(clearReceipt);

const setDir = (d: Dir) => {
    dir.value = d;
    form.clearErrors();
    form.type = d;
    form.category = categories[d][0].id;
    form.confirm_overdraw = false;
    form.approval_pin = '';
};

const amountDisplay = computed({
    get: () => (form.amount ? Number(form.amount).toLocaleString('id-ID') : ''),
    set: (v: string) => {
        form.amount = v.replace(/[^0-9]/g, '').slice(0, 12);
        form.confirm_overdraw = false;
    },
});
const amountNum = computed(() => Number(form.amount || 0));
const quickAmounts = [10000, 20000, 50000, 100000];

const balance = computed(() => props.summary.expected_cash);
const overdraw = computed(() => dir.value === 'out' && amountNum.value > 0 && amountNum.value > balance.value);
const needsPin = computed(
    () =>
        dir.value === 'out' &&
        !props.canManage &&
        settings.value.has_approval_pin &&
        settings.value.approval_limit > 0 &&
        amountNum.value > settings.value.approval_limit,
);
const balanceAfter = computed(() => balance.value + (dir.value === 'in' ? amountNum.value : -amountNum.value));

const presetsForDir = computed(() => settings.value.presets.filter((p) => p.type === dir.value));
const applyPreset = (p: Preset) => {
    form.clearErrors();
    form.category = p.category;
    form.notes = p.notes;
    if (p.amount) form.amount = String(p.amount);
};

const pickReceipt = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    (e.target as HTMLInputElement).value = '';
    if (!file) return;
    clearReceipt();
    form.receipt = file;
    receiptPreview.value = URL.createObjectURL(file);
};

const canSubmit = computed(
    () =>
        amountNum.value > 0 &&
        form.notes.trim() &&
        form.category &&
        (!overdraw.value || form.confirm_overdraw) &&
        (!needsPin.value || form.approval_pin.length >= 4),
);
const submit = () => {
    form.transform((d) => ({ ...d, approval_pin: needsPin.value ? d.approval_pin : '' })).post('/cash-shifts/transactions', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            if ((page.props as { flash?: { error?: string } }).flash?.error) return;
            const keep = dir.value;
            form.reset();
            clearReceipt();
            setDir(keep);
        },
    });
};

// ── Riwayat ──
const historyFilter = ref<'all' | Dir>('all');
const visible = computed(() => props.transactions.filter((t) => historyFilter.value === 'all' || t.type === historyFilter.value));
const activeRows = computed(() => props.transactions.filter((t) => !t.voided_at));
const categoryTotals = computed(() => {
    const map = new Map<string, { label: string; type: Dir; total: number }>();
    for (const t of activeRows.value) {
        const cur = map.get(t.category) ?? { label: t.category_label, type: t.type, total: 0 };
        cur.total += Number(t.amount);
        map.set(t.category, cur);
    }
    return [...map.values()];
});

// ── Batalkan entri ──
const voidTarget = ref<TransactionRow | null>(null);
const voidForm = useForm({ reason: '', approval_pin: '' });
const voidNeedsPin = computed(() => !props.canManage && settings.value.has_approval_pin);
const openVoid = (t: TransactionRow) => {
    voidTarget.value = t;
    voidForm.reset();
    voidForm.clearErrors();
};
const submitVoid = () => {
    if (!voidTarget.value) return;
    voidForm.post(`/cash-shifts/transactions/${voidTarget.value.id}/void`, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!(page.props as { flash?: { error?: string } }).flash?.error) voidTarget.value = null;
        },
    });
};

// ── Lihat foto bukti ──
const photoUrl = ref<string | null>(null);

// ── Pengaturan (owner/admin) ──
const showSettings = ref(false);
const settingsForm = useForm({
    approval_limit: 0 as number,
    cashier_discount_limit: 20 as number,
    approval_pin: '',
    remove_approval_pin: false,
    presets: [] as Preset[],
});
const openSettings = () => {
    settingsForm.clearErrors();
    settingsForm.approval_limit = settings.value.approval_limit;
    settingsForm.cashier_discount_limit = settings.value.cashier_discount_limit ?? 20;
    settingsForm.approval_pin = '';
    settingsForm.remove_approval_pin = false;
    settingsForm.presets = settings.value.presets.map((p) => ({ ...p }));
    showSettings.value = true;
};
const addPreset = (type: Dir) => settingsForm.presets.push({ type, category: categories[type][0].id, label: '', notes: '', amount: null });
const presetDirChanged = (p: Preset) => {
    if (!categories[p.type].some((c) => c.id === p.category)) p.category = categories[p.type][0].id;
};
const saveSettings = () => {
    settingsForm
        .transform((d) => ({
            ...d,
            presets: d.presets.map((p) => ({ ...p, notes: p.notes || p.label, amount: p.amount ? Number(p.amount) : null })),
        }))
        .put('/cash-shifts/transactions/settings', {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) showSettings.value = false;
            },
        });
};
const presetError = (i: number) =>
    (['label', 'notes', 'category', 'amount'] as const)
        .map((f) => (settingsForm.errors as Record<string, string>)[`presets.${i}.${f}`])
        .find(Boolean);

const fieldClass = 'flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <Head title="Manajemen Kas" />
    <AdminLayout title="Manajemen Laci Kasir">
        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <!-- Kepala halaman: aksi selalu terlihat, juga di HP -->
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Kas masuk & keluar</h2>
                    <p class="text-sm text-slate-500">
                        Shift <b class="text-slate-700">{{ shift.shift_number }}</b>
                        <template v-if="shift.opened_at"> · dibuka {{ time(shift.opened_at) }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/cash-shifts"
                        ><Button variant="outline" size="sm" class="gap-1.5 rounded-xl"><ArrowLeft class="h-4 w-4" /> Shift</Button></Link
                    >
                    <Link v-if="canViewReport" href="/reports/cash-transactions">
                        <Button variant="outline" size="sm" class="gap-1.5 rounded-xl"><BarChart3 class="h-4 w-4" /> Laporan</Button>
                    </Link>
                    <Button v-if="canManage" variant="outline" size="sm" class="gap-1.5 rounded-xl" @click="openSettings"
                        ><Settings2 class="h-4 w-4" /> Pengaturan</Button
                    >
                </div>
            </div>

            <!-- Saldo laci (satu-satunya tempat saldo ditampilkan) -->
            <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[auto_1fr] sm:items-center sm:gap-6">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-xl"
                        :class="balance >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'"
                    >
                        <Wallet class="h-6 w-6" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Saldo laci saat ini</p>
                        <p class="text-2xl font-black tabular-nums" :class="balance >= 0 ? 'text-slate-900' : 'text-red-600'">
                            {{ rupiah(balance) }}
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <p class="text-[11px] text-slate-500">Kas awal</p>
                        <p class="font-semibold text-slate-800 tabular-nums">{{ rupiah(summary.opening_cash) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <p class="text-[11px] text-slate-500">Penjualan tunai</p>
                        <p class="font-semibold text-sky-700 tabular-nums">+ {{ rupiah(summary.sales_cash) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <p class="text-[11px] text-slate-500">Kas masuk</p>
                        <p class="font-semibold text-emerald-700 tabular-nums">+ {{ rupiah(summary.cash_in) }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <p class="text-[11px] text-slate-500">Kas keluar</p>
                        <p class="font-semibold text-red-700 tabular-nums">− {{ rupiah(summary.cash_out) }}</p>
                    </div>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,26rem)_1fr]">
                <!-- ===== Formulir (di HP tampil paling atas) ===== -->
                <form class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" @submit.prevent="submit">
                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                        <button
                            type="button"
                            class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold transition"
                            :class="dir === 'out' ? 'bg-white text-red-700' : 'text-slate-500'"
                            @click="setDir('out')"
                        >
                            <ArrowUpCircle class="h-4 w-4" /> Uang keluar
                        </button>
                        <button
                            type="button"
                            class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold transition"
                            :class="dir === 'in' ? 'bg-white text-emerald-700' : 'text-slate-500'"
                            @click="setDir('in')"
                        >
                            <ArrowDownCircle class="h-4 w-4" /> Uang masuk
                        </button>
                    </div>

                    <!-- Tombol cepat -->
                    <div v-if="presetsForDir.length">
                        <p class="mb-1.5 flex items-center gap-1 text-xs font-semibold text-slate-500"><Zap class="h-3.5 w-3.5" /> Cepat</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="(p, i) in presetsForDir"
                                :key="i"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                                :class="
                                    form.notes === p.notes
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                                "
                                @click="applyPreset(p)"
                            >
                                {{ p.label }}<template v-if="p.amount"> · {{ rupiah(p.amount) }}</template>
                            </button>
                        </div>
                    </div>

                    <!-- Jumlah -->
                    <div class="space-y-1.5">
                        <Label for="ct_amount">Jumlah *</Label>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-lg font-bold text-slate-400">Rp</span>
                            <Input
                                id="ct_amount"
                                v-model="amountDisplay"
                                inputmode="numeric"
                                placeholder="0"
                                class="h-14 pl-12 text-2xl font-black tabular-nums"
                            />
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="a in quickAmounts"
                                :key="a"
                                type="button"
                                class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                @click="amountDisplay = String(a)"
                            >
                                {{ (a / 1000).toLocaleString('id-ID') }}rb
                            </button>
                        </div>
                        <p v-if="form.errors.amount" class="text-sm text-red-600">{{ form.errors.amount }}</p>
                    </div>

                    <!-- Jenis -->
                    <div class="space-y-1.5">
                        <Label>Jenis *</Label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="c in categories[dir]"
                                :key="c.id"
                                type="button"
                                class="rounded-xl border p-3 text-left transition"
                                :class="
                                    form.category === c.id
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'border-slate-200 hover:border-slate-300'
                                "
                                @click="form.category = c.id"
                            >
                                <p class="text-sm font-bold text-slate-900">{{ c.name }}</p>
                                <p class="mt-0.5 text-[11px] leading-snug text-slate-500">{{ c.desc }}</p>
                            </button>
                        </div>
                        <p v-if="form.errors.category" class="text-sm text-red-600">{{ form.errors.category }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="ct_notes">Keterangan *</Label>
                        <Input
                            id="ct_notes"
                            v-model="form.notes"
                            :placeholder="dir === 'in' ? 'Contoh: Tambah modal kembalian' : 'Contoh: Beli 2 galon air'"
                            class="h-10"
                        />
                        <p v-if="form.errors.notes" class="text-sm text-red-600">{{ form.errors.notes }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="ct_party">{{ dir === 'in' ? 'Diterima dari' : 'Dibayar ke' }}</Label>
                        <Input id="ct_party" v-model="form.party_name" placeholder="Opsional: nama orang, toko, atau supplier" class="h-10" />
                    </div>

                    <!-- Foto bukti -->
                    <div class="space-y-1.5">
                        <Label>Foto bukti / struk</Label>
                        <div v-if="receiptPreview" class="flex items-center gap-3">
                            <img :src="receiptPreview" alt="Bukti" class="h-16 w-16 rounded-lg border border-slate-200 object-cover" />
                            <Button type="button" variant="ghost" size="sm" class="text-red-600 hover:bg-red-50" @click="clearReceipt"
                                ><X class="h-4 w-4" /> Hapus</Button
                            >
                        </div>
                        <label
                            v-else
                            class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-2.5 text-sm text-slate-600 hover:bg-slate-50"
                        >
                            <Camera class="h-4 w-4 text-slate-400" /> Ambil atau pilih foto (opsional)
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="pickReceipt" />
                        </label>
                        <p v-if="form.errors.receipt" class="text-sm text-red-600">{{ form.errors.receipt }}</p>
                    </div>

                    <!-- Peringatan saldo -->
                    <div v-if="overdraw" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        <p class="flex items-center gap-1.5 font-semibold"><TriangleAlert class="h-4 w-4" /> Melebihi saldo laci</p>
                        <p class="mt-0.5">Saldo laci {{ rupiah(balance) }}. Setelah dicatat menjadi {{ rupiah(balanceAfter) }}.</p>
                        <label class="mt-2 flex items-center gap-2 font-medium">
                            <input v-model="form.confirm_overdraw" type="checkbox" class="h-4 w-4 rounded border-amber-300" /> Saya yakin, tetap catat
                        </label>
                    </div>

                    <!-- PIN persetujuan -->
                    <div v-if="needsPin" class="space-y-1.5 rounded-xl border border-violet-200 bg-violet-50 p-3">
                        <Label for="ct_pin" class="flex items-center gap-1.5 text-violet-900"><KeyRound class="h-4 w-4" /> PIN persetujuan</Label>
                        <p class="text-xs text-violet-800">
                            Uang keluar di atas {{ rupiah(settings.approval_limit) }} perlu PIN dari owner/supervisor.
                        </p>
                        <Input
                            id="ct_pin"
                            v-model="form.approval_pin"
                            type="password"
                            inputmode="numeric"
                            maxlength="6"
                            autocomplete="off"
                            class="h-10 bg-white tracking-[0.4em]"
                        />
                        <p v-if="form.errors.approval_pin" class="text-sm text-red-600">{{ form.errors.approval_pin }}</p>
                    </div>

                    <Button type="submit" class="h-11 w-full rounded-xl text-base" :disabled="form.processing || !canSubmit">
                        {{
                            form.processing
                                ? 'Menyimpan…'
                                : `Catat uang ${dir === 'in' ? 'masuk' : 'keluar'}${amountNum ? ' · ' + rupiah(amountNum) : ''}`
                        }}
                    </Button>
                    <p v-if="!amountNum || !form.notes.trim()" class="-mt-2 text-center text-xs text-slate-400">
                        Isi jumlah dan keterangan untuk menyimpan.
                    </p>
                </form>

                <!-- ===== Riwayat shift ini ===== -->
                <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-bold text-slate-900">Riwayat shift ini</h3>
                        <div class="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm">
                            <button
                                v-for="f in [
                                    { key: 'all', label: 'Semua' },
                                    { key: 'in', label: 'Masuk' },
                                    { key: 'out', label: 'Keluar' },
                                ] as const"
                                :key="f.key"
                                type="button"
                                class="rounded-lg px-3 py-1 font-semibold transition"
                                :class="historyFilter === f.key ? 'bg-white text-slate-900' : 'text-slate-500'"
                                @click="historyFilter = f.key"
                            >
                                {{ f.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Total per jenis -->
                    <div v-if="categoryTotals.length" class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="c in categoryTotals"
                            :key="c.label"
                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="c.type === 'in' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"
                        >
                            {{ c.label }} · {{ rupiah(c.total) }}
                        </span>
                    </div>

                    <ul v-if="visible.length" class="mt-3 divide-y divide-slate-100">
                        <li v-for="t in visible" :key="t.id" class="flex gap-3 py-3" :class="{ 'opacity-60': t.voided_at }">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    t.voided_at
                                        ? 'bg-slate-100 text-slate-400'
                                        : t.type === 'in'
                                          ? 'bg-emerald-50 text-emerald-600'
                                          : 'bg-red-50 text-red-600'
                                "
                            >
                                <component :is="t.type === 'in' ? ArrowDownCircle : ArrowUpCircle" class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-semibold text-slate-900" :class="{ 'line-through': t.voided_at }">{{ t.notes }}</p>
                                    <p
                                        class="shrink-0 text-sm font-bold tabular-nums"
                                        :class="[t.voided_at ? 'text-slate-400 line-through' : t.type === 'in' ? 'text-emerald-700' : 'text-red-700']"
                                    >
                                        {{ t.type === 'in' ? '+' : '−' }} {{ rupiah(t.amount) }}
                                    </p>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{{ t.category_label }}</span>
                                    <span v-if="t.party_name">{{ t.party_name }}</span>
                                    <span>{{ time(t.created_at) }} · {{ t.recorded_by ?? '-' }}</span>
                                    <span v-if="t.approved_with_pin" class="inline-flex items-center gap-0.5 text-violet-600"
                                        ><KeyRound class="h-3 w-3" /> disetujui PIN</span
                                    >
                                    <button
                                        v-if="t.receipt_url"
                                        type="button"
                                        class="inline-flex items-center gap-0.5 font-semibold text-primary hover:underline"
                                        @click="photoUrl = t.receipt_url"
                                    >
                                        <Receipt class="h-3 w-3" /> Bukti
                                    </button>
                                </div>
                                <p v-if="t.voided_at" class="mt-1 text-xs text-red-600">
                                    Dibatalkan {{ t.voided_by ? 'oleh ' + t.voided_by : '' }} · {{ t.void_reason }}
                                </p>
                            </div>
                            <button
                                v-if="!t.voided_at"
                                type="button"
                                class="h-8 shrink-0 self-center rounded-lg px-2 text-xs font-semibold text-slate-400 hover:bg-red-50 hover:text-red-600"
                                title="Batalkan entri"
                                @click="openVoid(t)"
                            >
                                <Ban class="h-4 w-4" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="mt-6 py-6 text-center text-sm text-slate-500">
                        Belum ada {{ historyFilter === 'in' ? 'uang masuk' : historyFilter === 'out' ? 'uang keluar' : 'catatan kas' }} pada shift
                        ini.
                    </p>
                </section>
            </div>
        </div>

        <!-- Dialog batal -->
        <Dialog :open="!!voidTarget" @update:open="(v) => !v && (voidTarget = null)">
            <DialogContent class="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Batalkan entri kas?</DialogTitle>
                    <DialogDescription>
                        "{{ voidTarget?.notes }}" · {{ rupiah(voidTarget?.amount ?? 0) }}. Entri tetap tercatat (dicoret), tidak dihitung di saldo
                        laci, dan jurnalnya dibalik.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="space-y-1.5">
                        <Label for="void_reason">Alasan *</Label>
                        <Input id="void_reason" v-model="voidForm.reason" placeholder="Contoh: salah ketik jumlah" class="h-10" />
                        <p v-if="voidForm.errors.reason" class="text-sm text-red-600">{{ voidForm.errors.reason }}</p>
                    </div>
                    <div v-if="voidNeedsPin" class="space-y-1.5">
                        <Label for="void_pin">PIN persetujuan *</Label>
                        <Input
                            id="void_pin"
                            v-model="voidForm.approval_pin"
                            type="password"
                            inputmode="numeric"
                            maxlength="6"
                            autocomplete="off"
                            class="h-10 tracking-[0.4em]"
                        />
                        <p v-if="voidForm.errors.approval_pin" class="text-sm text-red-600">{{ voidForm.errors.approval_pin }}</p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="voidTarget = null">Batal</Button>
                    <Button
                        variant="destructive"
                        :disabled="voidForm.processing || !voidForm.reason.trim() || (voidNeedsPin && voidForm.approval_pin.length < 4)"
                        @click="submitVoid"
                    >
                        Batalkan entri
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Dialog foto bukti -->
        <Dialog :open="!!photoUrl" @update:open="(v) => !v && (photoUrl = null)">
            <DialogContent class="sm:max-w-[520px]">
                <DialogHeader><DialogTitle>Foto bukti</DialogTitle></DialogHeader>
                <img v-if="photoUrl" :src="photoUrl" alt="Bukti" class="max-h-[70vh] w-full rounded-xl object-contain" />
            </DialogContent>
        </Dialog>

        <!-- Dialog pengaturan -->
        <Dialog v-model:open="showSettings">
            <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>Pengaturan laci kasir</DialogTitle>
                    <DialogDescription>Batas persetujuan uang keluar dan tombol cepat untuk kasir.</DialogDescription>
                </DialogHeader>

                <div class="space-y-6">
                    <section class="space-y-3">
                        <h4 class="flex items-center gap-1.5 text-sm font-bold text-slate-900">
                            <KeyRound class="h-4 w-4 text-primary" /> Persetujuan PIN
                        </h4>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="set_limit">Uang keluar di atas</Label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-500">Rp</span>
                                    <Input id="set_limit" v-model.number="settingsForm.approval_limit" type="number" min="0" class="h-10 pl-9" />
                                </div>
                                <p class="text-xs text-slate-500">Isi 0 untuk mematikan. Owner/admin tidak perlu PIN.</p>
                                <p v-if="settingsForm.errors.approval_limit" class="text-sm text-red-600">{{ settingsForm.errors.approval_limit }}</p>
                            </div>
                            <div class="space-y-1.5">
                                <Label for="set_disc_limit">Diskon kasir per barang di atas</Label>
                                <div class="relative">
                                    <Input id="set_disc_limit" v-model.number="settingsForm.cashier_discount_limit" type="number" min="0" max="100" class="h-10 pr-9" />
                                    <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">%</span>
                                </div>
                                <p class="text-xs text-slate-500">Di atas batas ini kasir perlu PIN. Pembatalan (void) oleh kasir selalu perlu PIN.</p>
                                <p v-if="settingsForm.errors.cashier_discount_limit" class="text-sm text-red-600">{{ settingsForm.errors.cashier_discount_limit }}</p>
                            </div>
                            <div class="space-y-1.5">
                                <Label for="set_pin">{{ settings.has_approval_pin ? 'Ganti PIN persetujuan' : 'PIN persetujuan' }}</Label>
                                <Input
                                    id="set_pin"
                                    v-model="settingsForm.approval_pin"
                                    type="password"
                                    inputmode="numeric"
                                    maxlength="6"
                                    autocomplete="new-password"
                                    :placeholder="settings.has_approval_pin ? 'Kosongkan bila tidak diganti' : '4–6 angka'"
                                    :disabled="settingsForm.remove_approval_pin"
                                    class="h-10"
                                />
                                <label v-if="settings.has_approval_pin" class="flex items-center gap-2 text-xs text-slate-600">
                                    <input v-model="settingsForm.remove_approval_pin" type="checkbox" class="h-3.5 w-3.5" /> Hapus PIN (persetujuan
                                    mati)
                                </label>
                                <p v-if="settingsForm.errors.approval_pin" class="text-sm text-red-600">{{ settingsForm.errors.approval_pin }}</p>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">PIN ini juga dipakai kasir untuk membatalkan entri kas.</p>
                    </section>

                    <section class="space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="flex items-center gap-1.5 text-sm font-bold text-slate-900">
                                <Zap class="h-4 w-4 text-primary" /> Tombol cepat
                            </h4>
                            <div class="flex gap-1.5">
                                <Button type="button" variant="outline" size="sm" class="h-8 gap-1 rounded-lg text-xs" @click="addPreset('out')"
                                    ><Plus class="h-3.5 w-3.5" /> Keluar</Button
                                >
                                <Button type="button" variant="outline" size="sm" class="h-8 gap-1 rounded-lg text-xs" @click="addPreset('in')"
                                    ><Plus class="h-3.5 w-3.5" /> Masuk</Button
                                >
                            </div>
                        </div>
                        <div v-if="settingsForm.presets.length" class="space-y-2">
                            <div v-for="(p, i) in settingsForm.presets" :key="i" class="rounded-xl border border-slate-200 p-3">
                                <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                                    <select v-model="p.type" :class="fieldClass" @change="presetDirChanged(p)">
                                        <option value="out">Uang keluar</option>
                                        <option value="in">Uang masuk</option>
                                    </select>
                                    <select v-model="p.category" :class="fieldClass">
                                        <option v-for="c in categories[p.type]" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-10 w-10 p-0 text-red-600 hover:bg-red-50"
                                        title="Hapus"
                                        @click="settingsForm.presets.splice(i, 1)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                                <div class="mt-2 grid grid-cols-[1fr_8rem] gap-2">
                                    <Input v-model="p.label" placeholder="Nama tombol, mis. Beli galon" maxlength="40" class="h-10" />
                                    <div class="relative">
                                        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-slate-400">Rp</span>
                                        <Input
                                            :model-value="p.amount ?? ''"
                                            type="number"
                                            min="1"
                                            placeholder="Nominal"
                                            class="h-10 pl-9"
                                            @update:model-value="(v) => (p.amount = v === '' || v === null ? null : Number(v))"
                                        />
                                    </div>
                                </div>
                                <Input
                                    v-model="p.notes"
                                    placeholder="Keterangan otomatis (opsional, default = nama tombol)"
                                    maxlength="255"
                                    class="mt-2 h-9 text-sm"
                                />
                                <p v-if="presetError(i)" class="mt-1 text-sm text-red-600">{{ presetError(i) }}</p>
                            </div>
                        </div>
                        <p v-else class="rounded-xl border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">
                            Belum ada tombol cepat.
                        </p>
                    </section>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="showSettings = false">Batal</Button>
                    <Button :disabled="settingsForm.processing || settingsForm.presets.some((p) => !p.label.trim())" @click="saveSettings">
                        {{ settingsForm.processing ? 'Menyimpan…' : 'Simpan pengaturan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
