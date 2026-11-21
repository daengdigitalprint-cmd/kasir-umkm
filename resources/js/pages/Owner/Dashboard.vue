<script setup lang="ts">
import TrialBanner from '@/components/dashboard/TrialBanner.vue';
import OwnerHrSummary, { type HrSummary } from '@/components/owner/OwnerHrSummary.vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import {
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    CalendarRange,
    Clock,
    Download,
    Layers,
    LineChart as LineChartIcon,
    Receipt,
    RefreshCw,
    ShoppingBag,
    TriangleAlert,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Bar, Line } from 'vue-chartjs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend, Filler);

interface StoreItem {
    id: number;
    name: string;
    slug: string;
}

interface DatasetEntry {
    label: string;
    data: number[];
    borderColor: string;
    backgroundColor: string;
    store_id: number;
}

interface OutletSummary {
    name: string;
    color: string;
    total: number;
    average: number;
    growth: number | null;
    previous_total: number;
    cogs: number;
    gross_profit: number;
    margin: number | null;
    cogs_coverage: number;
}

interface RevenueTotals {
    net_revenue: number;
    previous_net_revenue?: number;
    growth?: number | null;
    transactions?: number;
    previous_transactions?: number;
    avg_ticket?: number | null;
    previous_avg_ticket?: number | null;
    busiest_hour?: number | null;
    busiest_weekday?: number | null;
    cogs: number;
    gross_profit: number;
    margin: number | null;
    collected_not_revenue: number;
    returns: number;
    cogs_coverage: number;
}

interface ChartDataResponse {
    labels: string[];
    period_keys: string[];
    datasets: DatasetEntry[];
    summary: Record<string, OutletSummary>;
    totals: RevenueTotals;
    period: string;
    start_date: string;
    end_date: string;
    comparison?: { start_date: string; end_date: string };
}

const props = defineProps<{
    stores: StoreItem[];
    chartData: ChartDataResponse;
    hr?: HrSummary;
    filters: { period: string; start_date: string; end_date: string; store_id: number | null };
}>();

// ── Format ──
const rupiah = (n: number | null | undefined) => 'Rp ' + Math.round(Number(n ?? 0)).toLocaleString('id-ID');
const rupiahShort = (n: number) => {
    const v = Math.abs(n);
    if (v >= 1_000_000_000) return `Rp ${(n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} M`;
    if (v >= 1_000_000) return `Rp ${(n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    if (v >= 1_000) return `Rp ${(n / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb`;
    return `Rp ${n.toLocaleString('id-ID')}`;
};
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${v.toFixed(1)}%`);
const growthOf = (now: number | null | undefined, prev: number | null | undefined) =>
    prev && prev > 0 && now !== null && now !== undefined ? ((now - prev) / prev) * 100 : null;

// Tanggal lokal (bukan toISOString yang memakai UTC dan bisa mundur sehari).
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s: string) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const fmtDate = (s: string, withYear = true) =>
    parse(s).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });
const fmtRange = (a: string, b: string) => {
    if (a === b) return fmtDate(a);
    const sameYear = a.slice(0, 4) === b.slice(0, 4);
    return `${fmtDate(a, !sameYear)} – ${fmtDate(b)}`;
};

// ── Filter (langsung berlaku tanpa tombol Terapkan) ──
const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const selectedPeriod = ref(props.filters.period);
const selectedStoreId = ref<number | null>(props.filters.store_id ?? null);
const loading = ref(false);
const showCustom = ref(false);

type Preset = { key: string; label: string; range: () => [Date, Date]; period: string };
const today = () => new Date();
const presets: Preset[] = [
    { key: 'today', label: 'Hari ini', range: () => [today(), today()], period: 'daily' },
    {
        key: '7d',
        label: '7 hari',
        range: () => {
            const s = today();
            s.setDate(s.getDate() - 6);
            return [s, today()];
        },
        period: 'daily',
    },
    { key: 'month', label: 'Bulan ini', range: () => [new Date(today().getFullYear(), today().getMonth(), 1), today()], period: 'daily' },
    {
        key: 'lastmonth',
        label: 'Bulan lalu',
        range: () => [new Date(today().getFullYear(), today().getMonth() - 1, 1), new Date(today().getFullYear(), today().getMonth(), 0)],
        period: 'daily',
    },
    {
        key: '3m',
        label: '3 bulan',
        range: () => [new Date(today().getFullYear(), today().getMonth() - 2, 1), today()],
        period: 'weekly',
    },
    { key: 'year', label: 'Tahun ini', range: () => [new Date(today().getFullYear(), 0, 1), today()], period: 'monthly' },
];
const activePreset = computed(
    () =>
        presets.find((p) => {
            const [a, b] = p.range();
            return ymd(a) === startDate.value && ymd(b) === endDate.value;
        })?.key ?? null,
);

const granularities = [
    { value: 'daily', label: 'Hari' },
    { value: 'weekly', label: 'Minggu' },
    { value: 'monthly', label: 'Bulan' },
    { value: 'yearly', label: 'Tahun' },
];
const granularityWord = computed(() => ({ daily: 'hari', weekly: 'minggu', monthly: 'bulan', yearly: 'tahun' })[selectedPeriod.value] ?? 'periode');

function fetchData() {
    if (!startDate.value || !endDate.value || startDate.value > endDate.value) return;
    loading.value = true;
    const params: Record<string, string | number> = { period: selectedPeriod.value, start_date: startDate.value, end_date: endDate.value };
    if (selectedStoreId.value) params.store_id = selectedStoreId.value;
    router.get('/owner/dashboard', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['chartData', 'filters'],
        onFinish: () => (loading.value = false),
    });
}

let applying = false;
function applyPreset(p: Preset) {
    const [a, b] = p.range();
    applying = true;
    startDate.value = ymd(a);
    endDate.value = ymd(b);
    selectedPeriod.value = p.period;
    showCustom.value = false;
    applying = false;
    fetchData();
}
// Perubahan manual (tanggal, outlet, satuan grafik) langsung dimuat ulang.
// flush 'sync' agar tanda `applying` masih aktif saat tombol cepat mengubah beberapa nilai sekaligus (tidak memuat dua kali).
watch(
    [startDate, endDate, selectedPeriod, selectedStoreId],
    () => {
        if (!applying) fetchData();
    },
    { flush: 'sync' },
);

function exportExcel() {
    const params = new URLSearchParams({ period: selectedPeriod.value, start_date: startDate.value, end_date: endDate.value });
    if (selectedStoreId.value) params.set('store_id', String(selectedStoreId.value));
    window.location.href = `/owner/dashboard/export?${params.toString()}`;
}

// ── Ringkasan ──
const totals = computed<RevenueTotals>(() => props.chartData.totals);
const summaryList = computed(() => Object.values(props.chartData.summary));
const multiOutlet = computed(() => summaryList.value.length > 1);
const comparisonText = computed(() =>
    props.chartData.comparison ? fmtRange(props.chartData.comparison.start_date, props.chartData.comparison.end_date) : null,
);
const marginTerpercaya = computed(() => (totals.value.cogs_coverage ?? 0) >= 80);

const kpis = computed(() => {
    const t = totals.value;
    return [
        {
            key: 'net',
            label: 'Penjualan bersih',
            value: rupiah(t.net_revenue),
            growth: t.growth ?? growthOf(t.net_revenue, t.previous_net_revenue),
            prev: t.previous_net_revenue !== undefined ? rupiah(t.previous_net_revenue) : null,
            hint:
                t.collected_not_revenue > 0
                    ? `Belum termasuk pajak, layanan, tip & ongkir ${rupiahShort(t.collected_not_revenue)}`
                    : 'Setelah diskon & retur',
            warn: null as string | null,
            icon: ShoppingBag,
            tone: 'bg-primary/10 text-primary',
            negative: false,
        },
        {
            key: 'profit',
            label: 'Laba kotor',
            value: rupiah(t.gross_profit),
            growth: null as number | null,
            prev: null as string | null,
            hint: `Margin ${t.margin === null ? '—' : t.margin.toFixed(1) + '%'}`,
            warn: !marginTerpercaya.value && t.net_revenue > 0 ? `Harga modal baru tercatat untuk ${t.cogs_coverage}% penjualan` : null,
            icon: Wallet,
            tone: t.gross_profit >= 0 ? 'bg-indigo-50 text-indigo-600' : 'bg-red-50 text-red-600',
            negative: t.gross_profit < 0,
        },
        {
            key: 'trx',
            label: 'Jumlah transaksi',
            value: (t.transactions ?? 0).toLocaleString('id-ID'),
            growth: growthOf(t.transactions, t.previous_transactions),
            prev: t.previous_transactions !== undefined ? (t.previous_transactions ?? 0).toLocaleString('id-ID') : null,
            hint: 'Transaksi selesai',
            warn: null as string | null,
            icon: Receipt,
            tone: 'bg-sky-50 text-sky-600',
            negative: false,
        },
        {
            key: 'avg',
            label: 'Rata-rata per transaksi',
            value: t.avg_ticket ? rupiah(t.avg_ticket) : '—',
            growth: growthOf(t.avg_ticket, t.previous_avg_ticket),
            prev: t.previous_avg_ticket ? rupiah(t.previous_avg_ticket) : null,
            hint: 'Nilai belanja tiap pelanggan',
            warn: null as string | null,
            icon: CalendarRange,
            tone: 'bg-amber-50 text-amber-600',
            negative: false,
        },
    ];
});

const weekdayNames = ['', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
const busiestHourText = computed(() => {
    const h = totals.value.busiest_hour;
    if (h === null || h === undefined) return null;
    const pad = (x: number) => String(x).padStart(2, '0');
    return `${pad(h)}.00 – ${pad((h + 1) % 24)}.00`;
});
const busiestDayText = computed(() => {
    const d = totals.value.busiest_weekday;
    return d ? weekdayNames[d] : null;
});

const outletShare = (total: number) => (totals.value.net_revenue > 0 ? (total / totals.value.net_revenue) * 100 : 0);

// ── Grafik (tanpa zoom roda mouse agar halaman tetap mudah digulir) ──
const chartType = ref<'line' | 'bar' | 'area'>('bar');
const chartConfig = computed(() => ({
    labels: props.chartData.labels,
    datasets: props.chartData.datasets.map((ds) => ({
        ...ds,
        borderWidth: chartType.value === 'area' ? 1 : 2,
        pointRadius: chartType.value === 'bar' ? 0 : 3,
        pointHoverRadius: chartType.value === 'bar' ? 0 : 6,
        tension: 0.3,
        borderRadius: chartType.value === 'bar' ? 6 : 0,
        fill: chartType.value === 'area' ? { target: 'origin', above: ds.backgroundColor } : false,
        backgroundColor: chartType.value === 'bar' ? ds.backgroundColor.replace('33', 'CC') : ds.backgroundColor,
    })),
}));
const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
        legend: {
            display: multiOutlet.value,
            position: 'top' as const,
            labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } },
        },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            padding: 12,
            cornerRadius: 8,
            callbacks: { label: (ctx: any) => ` ${ctx.dataset.label}: ${rupiah(ctx.parsed?.y ?? 0)}` },
        },
    },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 0, autoSkip: true } },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { font: { size: 11 }, callback: (value: any) => rupiahShort(Number(value)) },
        },
    },
}));
const chartEmpty = computed(() => props.chartData.datasets.every((d) => d.data.every((v) => !v)));

const chartTypes = [
    { key: 'bar', label: 'Batang', icon: BarChart3 },
    { key: 'line', label: 'Garis', icon: LineChartIcon },
    { key: 'area', label: 'Area', icon: Layers },
] as const;

const selectClass =
    'h-9 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary/30';
</script>

<template>
    <Head title="Dashboard Owner" />
    <AdminLayout title="Dashboard Owner">
        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <TrialBanner />
            <!-- Kepala: rentang aktif + aksi (selalu terlihat, juga di HP) -->
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Ringkasan penjualan</h2>
                    <p class="text-sm text-slate-500">
                        <b class="text-slate-700">{{ fmtRange(props.chartData.start_date, props.chartData.end_date) }}</b>
                        <template v-if="comparisonText"> · dibanding {{ comparisonText }}</template>
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" class="h-9 w-9 rounded-xl p-0" title="Muat ulang" :disabled="loading" @click="fetchData">
                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    </Button>
                    <Button variant="outline" size="sm" class="h-9 gap-1.5 rounded-xl" @click="exportExcel">
                        <Download class="h-4 w-4" /> Excel
                    </Button>
                </div>
            </div>

            <!-- Filter ringkas -->
            <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
                <div class="-mx-1 flex gap-1.5 overflow-x-auto px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <button
                        v-for="p in presets"
                        :key="p.key"
                        type="button"
                        class="shrink-0 rounded-full border px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                        :class="
                            activePreset === p.key && !showCustom
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="applyPreset(p)"
                    >
                        {{ p.label }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                        :class="
                            showCustom || !activePreset
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'
                        "
                        @click="showCustom = !showCustom"
                    >
                        <CalendarDays class="h-4 w-4" /> Atur tanggal
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="showCustom">
                        <input v-model="startDate" type="date" :max="endDate" :class="selectClass" aria-label="Dari tanggal" />
                        <span class="text-sm text-slate-400">s/d</span>
                        <input v-model="endDate" type="date" :min="startDate" :class="selectClass" aria-label="Sampai tanggal" />
                    </template>
                    <select v-if="stores.length > 1" v-model="selectedStoreId" :class="selectClass" aria-label="Outlet">
                        <option :value="null">Semua outlet</option>
                        <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1 sm:ml-auto">
                        <span class="px-1.5 text-xs font-medium text-slate-500">Grafik per</span>
                        <button
                            v-for="g in granularities"
                            :key="g.value"
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                            :class="selectedPeriod === g.value ? 'bg-white text-primary' : 'text-slate-500 hover:text-slate-800'"
                            @click="selectedPeriod = g.value"
                        >
                            {{ g.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Angka utama -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" :class="{ 'opacity-60': loading }">
                <div v-for="k in kpis" :key="k.key" class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-slate-500">{{ k.label }}</p>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="k.tone"
                            ><component :is="k.icon" class="h-5 w-5"
                        /></span>
                    </div>
                    <p class="mt-1 text-2xl font-black tabular-nums" :class="k.negative ? 'text-red-600' : 'text-slate-900'">{{ k.value }}</p>
                    <div v-if="k.key !== 'profit'" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                        <template v-if="k.growth !== null && k.growth !== undefined">
                            <span
                                class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-bold"
                                :class="k.growth >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"
                            >
                                <component :is="k.growth >= 0 ? ArrowUpRight : ArrowDownRight" class="h-3.5 w-3.5" />{{ pct(k.growth) }}
                            </span>
                            <span v-if="k.prev" class="text-slate-400">dari {{ k.prev }}</span>
                        </template>
                        <span v-else class="text-slate-400">Belum ada data pembanding</span>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">{{ k.hint }}</p>
                    <p v-if="k.warn" class="mt-1 flex items-center gap-1 text-xs font-medium text-amber-700">
                        <TriangleAlert class="h-3.5 w-3.5 shrink-0" /> {{ k.warn }}
                    </p>
                </div>
            </div>

            <!-- Waktu tersibuk -->
            <div v-if="busiestHourText || busiestDayText" class="grid grid-cols-2 gap-3">
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600"
                        ><Clock class="h-5 w-5"
                    /></span>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">Jam paling ramai</p>
                        <p class="truncate font-bold text-slate-900">{{ busiestHourText ?? '—' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600"
                        ><CalendarDays class="h-5 w-5"
                    /></span>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-500">Hari paling ramai</p>
                        <p class="truncate font-bold text-slate-900">{{ busiestDayText ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- Per outlet -->
            <div v-if="multiOutlet" class="rounded-2xl border border-slate-200 bg-white p-4">
                <h3 class="font-bold text-slate-900">Per outlet</h3>
                <ul class="mt-3 divide-y divide-slate-100">
                    <li v-for="item in summaryList" :key="item.name" class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5">
                        <span class="flex min-w-0 flex-1 items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: item.color }" />
                            <span class="truncate text-sm font-semibold text-slate-800">{{ item.name }}</span>
                        </span>
                        <span class="text-xs text-slate-500 tabular-nums sm:w-24 sm:text-right"
                            >{{ outletShare(item.total).toFixed(0) }}% dari total</span
                        >
                        <span class="text-sm font-bold text-slate-900 tabular-nums sm:w-32 sm:text-right">{{ rupiah(item.total) }}</span>
                        <span
                            class="text-xs font-bold tabular-nums sm:w-16 sm:text-right"
                            :class="item.growth === null ? 'text-slate-300' : item.growth >= 0 ? 'text-emerald-600' : 'text-red-600'"
                        >
                            {{ pct(item.growth) }}
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Grafik -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold text-slate-900">Penjualan bersih per {{ granularityWord }}</h3>
                    <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                        <button
                            v-for="t in chartTypes"
                            :key="t.key"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                            :class="chartType === t.key ? 'bg-white text-primary' : 'text-slate-500 hover:text-slate-800'"
                            @click="chartType = t.key"
                        >
                            <component :is="t.icon" class="h-3.5 w-3.5" /> {{ t.label }}
                        </button>
                    </div>
                </div>
                <div class="relative mt-3 h-[280px] lg:h-[380px]">
                    <p v-if="chartEmpty" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">
                        Belum ada penjualan pada rentang ini.
                    </p>
                    <component v-else :is="chartType === 'bar' ? Bar : Line" :data="chartConfig" :options="chartOptions as any" />
                </div>
            </div>

            <!-- Ringkasan SDM lintas outlet -->
            <OwnerHrSummary v-if="hr && hr.outlets.length" :hr="hr" />
        </div>
    </AdminLayout>
</template>
