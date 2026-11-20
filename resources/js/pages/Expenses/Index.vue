<script setup lang="ts">
import ReportExportButton from '@/components/ReportExportButton.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PAYMENT_METHODS, paymentMethodLabel, rupiah } from '@/composables/useReceivables';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete, toastSuccess } from '@/lib/swal';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Copy,
    Droplet,
    Home,
    MoreHorizontal,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
    TrendingDown,
    TrendingUp,
    Truck,
    Users,
    Wallet,
    Wifi,
    X,
    Zap,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';

interface ExpenseRow {
    id: number;
    category: string;
    description: string;
    amount: number;
    expense_date: string;
    payment_method: string;
    notes: string | null;
    user: { id: number; name: string } | null;
}

const props = defineProps<{
    expenses: {
        data: ExpenseRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    categories: Record<string, string>;
    summary: { total: number; count: number; previous_total: number; previous_start: string; previous_end: string; days: number };
    byCategory: { category: string; total: number; count: number }[];
    dailyTotals: Record<string, number>;
    filters: { start_date: string; end_date: string; category: string | null; q: string };
    today: string;
}>();

// ── Kategori: ikon & warna ──
const CATEGORY_ICON: Record<string, Component> = {
    sewa: Home,
    gaji: Users,
    listrik: Zap,
    air: Droplet,
    internet: Wifi,
    transport: Truck,
    lainnya: MoreHorizontal,
};
const CATEGORY_TONE: Record<string, string> = {
    sewa: 'bg-blue-50 text-blue-700',
    gaji: 'bg-emerald-50 text-emerald-700',
    listrik: 'bg-amber-50 text-amber-700',
    air: 'bg-cyan-50 text-cyan-700',
    internet: 'bg-violet-50 text-violet-700',
    transport: 'bg-orange-50 text-orange-700',
    lainnya: 'bg-slate-100 text-slate-600',
};
const iconOf = (c: string) => CATEGORY_ICON[c] ?? MoreHorizontal;
const toneOf = (c: string) => CATEGORY_TONE[c] ?? 'bg-slate-100 text-slate-600';
const labelOf = (c: string) => props.categories[c] ?? c;

// ── Tanggal (berbasis tanggal toko dari server, bukan jam UTC browser) ──
const parse = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fmtShort = (s: string) => parse(s).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
const fmtDay = (s: string) => {
    if (s === props.today) return 'Hari ini';
    const y = parse(props.today);
    y.setDate(y.getDate() - 1);
    if (s === iso(y)) return 'Kemarin';
    return parse(s).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};

type PresetKey = 'today' | 'week' | 'month' | 'last_month' | 'custom';
const presets = computed<Record<Exclude<PresetKey, 'custom'>, { label: string; start: string; end: string }>>(() => {
    const t = parse(props.today);
    const weekStart = new Date(t);
    weekStart.setDate(t.getDate() - ((t.getDay() + 6) % 7)); // Senin
    const monthStart = new Date(t.getFullYear(), t.getMonth(), 1);
    const lastStart = new Date(t.getFullYear(), t.getMonth() - 1, 1);
    const lastEnd = new Date(t.getFullYear(), t.getMonth(), 0);
    return {
        today: { label: 'Hari ini', start: props.today, end: props.today },
        week: { label: 'Minggu ini', start: iso(weekStart), end: props.today },
        month: { label: 'Bulan ini', start: iso(monthStart), end: props.today },
        last_month: { label: 'Bulan lalu', start: iso(lastStart), end: iso(lastEnd) },
    };
});
const activePreset = computed<PresetKey>(() => {
    const hit = Object.entries(presets.value).find(([, p]) => p.start === props.filters.start_date && p.end === props.filters.end_date);
    return (hit?.[0] as PresetKey) ?? 'custom';
});
const showCustom = ref(activePreset.value === 'custom');
const customStart = ref(props.filters.start_date);
const customEnd = ref(props.filters.end_date);
const periodText = computed(() =>
    props.filters.start_date === props.filters.end_date
        ? fmtDay(props.filters.start_date)
        : `${fmtShort(props.filters.start_date)} – ${fmtShort(props.filters.end_date)} ${parse(props.filters.end_date).getFullYear()}`,
);

// ── Filter ──
const search = ref(props.filters.q ?? '');
function visit(overrides: Record<string, string | null | undefined> = {}) {
    const params: Record<string, string> = {};
    const merged = {
        start_date: props.filters.start_date,
        end_date: props.filters.end_date,
        category: props.filters.category,
        q: search.value,
        ...overrides,
    };
    for (const [k, v] of Object.entries(merged)) if (v) params[k] = v;
    router.get('/expenses', params, { preserveState: true, preserveScroll: true, replace: true });
}
function choosePreset(key: Exclude<PresetKey, 'custom'>) {
    showCustom.value = false;
    visit({ start_date: presets.value[key].start, end_date: presets.value[key].end });
}
function applyCustom() {
    if (customStart.value && customEnd.value) visit({ start_date: customStart.value, end_date: customEnd.value });
}
function toggleCategory(c: string) {
    visit({ category: props.filters.category === c ? null : c });
}
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(), 400);
});
const goToPage = (page: number) => visit({ page: String(page) });

// ── Ringkasan ──
const change = computed(() => {
    const prev = props.summary.previous_total;
    if (prev <= 0) return null;
    return ((props.summary.total - prev) / prev) * 100;
});
const topCategory = computed(() => props.byCategory[0] ?? null);
const categoryGrand = computed(() => props.byCategory.reduce((s, c) => s + c.total, 0));
const share = (v: number) => (categoryGrand.value > 0 ? (v / categoryGrand.value) * 100 : 0);

// ── Daftar dikelompokkan per tanggal ──
const groups = computed(() => {
    const out: { date: string; items: ExpenseRow[] }[] = [];
    for (const e of props.expenses.data) {
        const last = out[out.length - 1];
        if (last && last.date === e.expense_date) last.items.push(e);
        else out.push({ date: e.expense_date, items: [e] });
    }
    return out;
});

// ── Form tambah / ubah / catat lagi ──
const showDialog = ref(false);
const editing = ref<ExpenseRow | null>(null);
const form = useForm({
    category: '',
    description: '',
    amount: '' as number | '',
    expense_date: props.today,
    payment_method: 'cash',
    notes: '',
});
const amountDisplay = ref('');
const QUICK_AMOUNTS = [50000, 100000, 250000, 500000, 1000000];

function setAmount(v: number | '') {
    form.amount = v;
    amountDisplay.value = v === '' ? '' : Number(v).toLocaleString('id-ID');
}
function onAmountInput(e: Event) {
    const raw = (e.target as HTMLInputElement).value.replace(/[^0-9]/g, '');
    setAmount(raw ? Number(raw) : '');
}

function openForm(mode: 'create' | 'edit' | 'repeat', row?: ExpenseRow) {
    form.clearErrors();
    editing.value = mode === 'edit' && row ? row : null;
    form.category = row?.category ?? '';
    form.description = row?.description ?? '';
    form.payment_method = row ? (row.payment_method === 'transfer' ? 'other' : row.payment_method) : 'cash';
    form.notes = mode === 'edit' ? (row?.notes ?? '') : '';
    form.expense_date = mode === 'edit' && row ? row.expense_date : props.today;
    setAmount(row ? row.amount : '');
    showDialog.value = true;
}

function submit() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            showDialog.value = false;
            void toastSuccess(editing.value ? 'Biaya diperbarui.' : 'Biaya dicatat.');
        },
    };
    if (editing.value) form.patch(`/expenses/${editing.value.id}`, opts);
    else form.post('/expenses', opts);
}

async function destroyExpense(e: ExpenseRow) {
    const ok = await confirmDelete(
        'Hapus biaya ini?',
        `${e.description} · ${rupiah(e.amount)} · ${fmtShort(e.expense_date)}. Jurnal terkait ikut dihapus.`,
    );
    if (!ok) return;
    router.delete(`/expenses/${e.id}`, { preserveScroll: true, onSuccess: () => void toastSuccess('Biaya dihapus.') });
}
</script>

<template>
    <AdminLayout title="Biaya Usaha">
        <Head title="Biaya Usaha" />

        <div class="mx-auto max-w-5xl space-y-4 pb-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Biaya Usaha</h1>
                    <p class="mt-1 text-sm text-slate-600">Sewa, gaji, listrik, dan pengeluaran rutin lain di luar belanja bahan.</p>
                </div>
                <div class="flex items-center gap-2">
                    <ReportExportButton
                        report="beban"
                        :params="{ category: filters.category, start_date: filters.start_date, end_date: filters.end_date }"
                    />
                    <Button class="gap-2 rounded-xl" @click="openForm('create')">
                        <Plus class="h-4 w-4" />
                        Catat biaya
                    </Button>
                </div>
            </div>

            <!-- Periode -->
            <div class="space-y-2">
                <div class="flex gap-1.5 overflow-x-auto [scrollbar-width:none]">
                    <button
                        v-for="(p, key) in presets"
                        :key="key"
                        type="button"
                        class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            activePreset === key && !showCustom
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                        "
                        @click="choosePreset(key)"
                    >
                        {{ p.label }}
                    </button>
                    <button
                        type="button"
                        class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            activePreset === 'custom' || showCustom
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                        "
                        @click="showCustom = !showCustom"
                    >
                        Pilih tanggal
                    </button>
                </div>
                <form v-if="showCustom" class="flex flex-wrap items-center gap-2" @submit.prevent="applyCustom">
                    <Input v-model="customStart" type="date" class="w-40 rounded-xl" aria-label="Dari tanggal" />
                    <span class="text-sm text-slate-500">sampai</span>
                    <Input v-model="customEnd" type="date" class="w-40 rounded-xl" aria-label="Sampai tanggal" />
                    <Button type="submit" variant="outline" class="rounded-xl">Tampilkan</Button>
                </form>
            </div>

            <!-- Ringkasan -->
            <div class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-3 sm:p-5">
                <div>
                    <p class="text-xs text-slate-500">Total biaya · {{ periodText }}</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ rupiah(summary.total) }}</p>
                    <p
                        v-if="change !== null"
                        class="mt-1 inline-flex items-center gap-1 text-xs"
                        :class="change > 0 ? 'text-red-600' : 'text-emerald-700'"
                    >
                        <TrendingUp v-if="change > 0" class="h-3.5 w-3.5" />
                        <TrendingDown v-else class="h-3.5 w-3.5" />
                        {{ change > 0 ? 'Naik' : 'Turun' }} {{ Math.abs(change).toLocaleString('id-ID', { maximumFractionDigits: 0 }) }}% dari
                        {{ fmtShort(summary.previous_start) }}–{{ fmtShort(summary.previous_end) }}
                    </p>
                    <p v-else-if="summary.previous_total === 0 && summary.total > 0" class="mt-1 text-xs text-slate-500">
                        Periode sebelumnya belum ada biaya.
                    </p>
                </div>
                <div class="sm:border-l sm:border-slate-100 sm:pl-4">
                    <p class="text-xs text-slate-500">Jumlah pencatatan</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ summary.count }} biaya</p>
                    <p v-if="summary.count > 0" class="mt-0.5 text-xs text-slate-500">
                        Rata-rata {{ rupiah(summary.total / Math.max(summary.days, 1)) }}/hari
                    </p>
                </div>
                <div class="sm:border-l sm:border-slate-100 sm:pl-4">
                    <p class="text-xs text-slate-500">Biaya terbesar</p>
                    <template v-if="topCategory">
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ labelOf(topCategory.category) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ rupiah(topCategory.total) }} · {{ share(topCategory.total).toLocaleString('id-ID', { maximumFractionDigits: 0 }) }}%
                            dari total
                        </p>
                    </template>
                    <p v-else class="mt-1 text-sm text-slate-400">—</p>
                </div>
            </div>

            <!-- Rincian per kategori -->
            <div v-if="byCategory.length > 0" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-900">Per kategori</h2>
                    <span class="text-xs text-slate-500">Klik untuk menyaring daftar</span>
                </div>
                <div class="space-y-1">
                    <button
                        v-for="c in byCategory"
                        :key="c.category"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors"
                        :class="filters.category === c.category ? 'bg-primary/5 ring-1 ring-primary/30' : 'hover:bg-slate-50'"
                        @click="toggleCategory(c.category)"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="toneOf(c.category)">
                            <component :is="iconOf(c.category)" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="truncate font-medium text-slate-800"
                                    >{{ labelOf(c.category) }} <span class="text-xs font-normal text-slate-400">· {{ c.count }}×</span></span
                                >
                                <span class="shrink-0 font-semibold text-slate-900 tabular-nums">{{ rupiah(c.total) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-primary/70" :style="{ width: `${Math.max(share(c.total), 2)}%` }" />
                            </div>
                        </div>
                        <span class="w-10 shrink-0 text-right text-xs text-slate-500 tabular-nums"
                            >{{ share(c.total).toLocaleString('id-ID', { maximumFractionDigits: 0 }) }}%</span
                        >
                    </button>
                </div>
            </div>

            <!-- Daftar -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                    <div class="relative min-w-0 flex-1">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input v-model="search" type="search" placeholder="Cari keterangan biaya" class="rounded-xl pl-9" />
                    </div>
                    <button
                        v-if="filters.category"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary"
                        @click="toggleCategory(filters.category)"
                    >
                        {{ labelOf(filters.category) }}
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <div v-if="expenses.data.length === 0" class="px-6 py-12 text-center">
                    <Wallet class="mx-auto h-10 w-10 text-slate-300" />
                    <p class="mt-3 font-semibold text-slate-800">Belum ada biaya di periode ini</p>
                    <p class="mt-1 text-sm text-slate-500">Catat sewa, gaji, atau listrik agar laba usaha terlihat lebih jujur.</p>
                    <Button class="mt-4 gap-2 rounded-xl" @click="openForm('create')">
                        <Plus class="h-4 w-4" />
                        Catat biaya
                    </Button>
                </div>

                <template v-for="g in groups" :key="g.date">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2 text-xs">
                        <span class="font-semibold text-slate-600">{{ fmtDay(g.date) }}</span>
                        <span class="font-semibold text-slate-600 tabular-nums">{{
                            rupiah(dailyTotals[g.date] ?? g.items.reduce((s, i) => s + i.amount, 0))
                        }}</span>
                    </div>
                    <div v-for="e in g.items" :key="e.id" class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-0">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="toneOf(e.category)">
                            <component :is="iconOf(e.category)" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-900">{{ e.description }}</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ labelOf(e.category) }} · {{ paymentMethodLabel(e.payment_method)
                                }}<template v-if="e.user"> · {{ e.user.name }}</template>
                                <template v-if="e.notes"> · {{ e.notes }}</template>
                            </p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-slate-900 tabular-nums">{{ rupiah(e.amount) }}</span>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
                                    :aria-label="`Menu ${e.description}`"
                                >
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @click="openForm('repeat', e)"><Copy class="h-4 w-4" /> Catat lagi</DropdownMenuItem>
                                <DropdownMenuItem @click="openForm('edit', e)"><Pencil class="h-4 w-4" /> Ubah</DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="text-red-600" @click="destroyExpense(e)"><Trash2 class="h-4 w-4" /> Hapus</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </template>

                <div v-if="expenses.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
                    <p class="text-xs text-slate-500">Halaman {{ expenses.current_page }} dari {{ expenses.last_page }}</p>
                    <div class="flex gap-1">
                        <Button
                            v-if="expenses.current_page > 1"
                            variant="outline"
                            size="sm"
                            class="rounded-lg"
                            @click="goToPage(expenses.current_page - 1)"
                            >Sebelumnya</Button
                        >
                        <Button
                            v-if="expenses.current_page < expenses.last_page"
                            variant="outline"
                            size="sm"
                            class="rounded-lg"
                            @click="goToPage(expenses.current_page + 1)"
                            >Selanjutnya</Button
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Form -->
        <Dialog v-model:open="showDialog">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Ubah biaya' : 'Catat biaya' }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Perubahan ikut memperbarui jurnal bila modul akuntansi aktif.'
                                : 'Biaya di luar belanja bahan, misalnya sewa, gaji, atau listrik.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <Label>Kategori</Label>
                        <div class="grid grid-cols-4 gap-2" role="radiogroup" aria-label="Kategori biaya">
                            <button
                                v-for="(label, key) in categories"
                                :key="key"
                                type="button"
                                role="radio"
                                :aria-checked="form.category === key"
                                class="flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-[11px] font-medium transition-colors"
                                :class="
                                    form.category === key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                "
                                @click="form.category = String(key)"
                            >
                                <component :is="iconOf(String(key))" class="h-4 w-4" />
                                <span class="w-full truncate text-center">{{ label.replace(' Karyawan', '').replace(' Tempat', '') }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.category" class="text-xs text-red-600">{{ form.errors.category }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="exp-amount">Jumlah</Label>
                        <div class="flex items-center rounded-xl border border-slate-200 bg-white focus-within:ring-2 focus-within:ring-primary/30">
                            <span class="pl-3 text-base text-slate-500">Rp</span>
                            <input
                                id="exp-amount"
                                :value="amountDisplay"
                                type="text"
                                inputmode="numeric"
                                placeholder="0"
                                class="h-12 w-full min-w-0 bg-transparent px-2 text-xl font-semibold outline-none"
                                @input="onAmountInput"
                            />
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="q in QUICK_AMOUNTS"
                                :key="q"
                                type="button"
                                class="rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50"
                                @click="setAmount(q)"
                            >
                                {{ q >= 1000000 ? `${q / 1000000} jt` : `${q / 1000} rb` }}
                            </button>
                        </div>
                        <p v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="exp-desc">Keterangan</Label>
                        <Input id="exp-desc" v-model="form.description" class="rounded-xl" placeholder="Contoh: Sewa ruko bulan November" />
                        <p v-if="form.errors.description" class="text-xs text-red-600">{{ form.errors.description }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="exp-date">Tanggal</Label>
                            <Input id="exp-date" v-model="form.expense_date" type="date" class="rounded-xl" />
                            <p v-if="form.errors.expense_date" class="text-xs text-red-600">{{ form.errors.expense_date }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label>Dibayar dengan</Label>
                            <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1" role="radiogroup" aria-label="Metode bayar">
                                <button
                                    v-for="m in PAYMENT_METHODS"
                                    :key="m.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.payment_method === m.value"
                                    class="rounded-lg py-1.5 text-xs font-medium transition-colors"
                                    :class="form.payment_method === m.value ? 'bg-white text-slate-900' : 'text-slate-600'"
                                    @click="form.payment_method = m.value"
                                >
                                    {{ m.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="exp-notes">Catatan <span class="font-normal text-slate-400">(opsional)</span></Label>
                        <Textarea id="exp-notes" v-model="form.notes" :rows="2" class="rounded-xl" />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" class="rounded-xl" @click="showDialog = false">Batal</Button>
                        <Button type="submit" class="rounded-xl" :disabled="form.processing">
                            {{ form.processing ? 'Menyimpan…' : editing ? 'Simpan perubahan' : 'Simpan' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
