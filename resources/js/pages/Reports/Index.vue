<script setup lang="ts">
import ReportExportButton from '@/components/ReportExportButton.vue';
import ReportOutletFilter from '@/components/ReportOutletFilter.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { router } from '@inertiajs/vue3';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';
import { BarChart3, CalendarDays, ChevronDown, Package, ShoppingCart, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Title, Tooltip, Legend, Filler);

interface TopProduct {
    id: number;
    name: string;
    total_sold: number;
    total_revenue: number;
    total_profit: number;
    has_hpp: boolean;
    avg_price: number;
}

interface RecentTransaction {
    id: number;
    total_amount: number;
    status: string;
    created_at: string;
    customer?: {
        name: string;
    };
}

interface SalesTrendPoint {
    period: string;
    label: string;
    total: number;
}

interface PaymentSlice {
    method: string;
    transaction_count: number;
    total_amount: number;
}

interface CategorySlice {
    category: string;
    revenue: number;
}

interface TopProductChartRow {
    name: string;
    total_sold: number;
    revenue: number;
}

interface Props {
    store: {
        id: number;
        name: string;
        currency: string;
    };
    dateRange: {
        start_date: string;
        end_date: string;
    };
    comparison: {
        prev_start: string;
        prev_end: string;
        prev_total_sales: number;
        prev_net_sales?: number;
        net_sales_change_pct?: number | null;
        prev_total_transactions: number;
        sales_change_pct: number | null;
        transactions_change_pct: number | null;
    };
    summary: {
        total_sales: number;
        net_sales?: number;
        collected_not_revenue?: number;
        total_transactions: number;
        average_order_value: number;
        total_customers: number;
        new_customers: number;
        items_sold: number;
        total_discount: number;
        total_tax: number;
        total_free_quantity: number;
        discount_by_type: Array<{ type: string; total: number }>;
    };
    new_customers_change_pct: number | null;
    hppSummary: {
        total_revenue: number;
        total_cost: number;
        total_gross_profit: number;
        gross_margin_pct: number | null;
        items_with_hpp: number;
        items_total: number;
    };
    topProducts: TopProduct[];
    topProductsChart: TopProductChartRow[];
    recentTransactions: RecentTransaction[];
    salesTrend: SalesTrendPoint[];
    paymentBreakdown: PaymentSlice[];
    categoryRevenue: CategorySlice[];
    accessibleStores?: { id: number; name: string }[];
    selectedStoreId?: number | null;
    isOwner?: boolean;
}

const props = defineProps<Props>();

const filterStart = ref(props.dateRange.start_date);
const filterEnd = ref(props.dateRange.end_date);

watch(
    () => props.dateRange,
    (dr) => {
        filterStart.value = dr.start_date;
        filterEnd.value = dr.end_date;
    },
);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

// Hapus trailing zeros: 5.0000 → 5, 4.5000 → 4.5
function fmtQty(n: number | string): string {
    const num = Number(n);
    if (Number.isNaN(num)) return String(n);
    return num % 1 === 0 ? String(Math.round(num)) : num.toFixed(4).replace(/\.?0+$/, '');
}

const discountTypeLabel = (type: string) => {
    const map: Record<string, string> = {
        percentage: 'Diskon persen',
        fixed_amount: 'Potongan tetap',
        buy_x_get_y: 'Beli X Gratis Y',
        manual: 'Manual',
    };
    return map[type] || type;
};

const formatDate = (dateString: string) => {
    return new Date(dateString + 'T12:00:00').toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const paymentMethodLabel = (method: string) => {
    const map: Record<string, string> = {
        cash: 'Tunai',
        card: 'Kartu',
        ewallet: 'E-Wallet',
        transfer: 'Transfer',
        other: 'Lainnya',
    };
    return map[method] || method;
};

const deltaClass = (pct: number | null) => {
    if (pct === null) return 'text-slate-400';
    if (pct > 0) return 'text-emerald-600';
    if (pct < 0) return 'text-red-600';
    return 'text-slate-600';
};

const formatDelta = (pct: number | null) => {
    if (pct === null) return 'vs periode sebelumnya: —';
    const sign = pct > 0 ? '+' : '';
    return `vs periode sebelumnya: ${sign}${pct}%`;
};

const applyFilter = () => {
    if (!filterStart.value || !filterEnd.value || filterStart.value > filterEnd.value) return;
    const params: Record<string, string | number> = { start_date: filterStart.value, end_date: filterEnd.value };
    if (props.selectedStoreId) params.store_id = props.selectedStoreId;
    router.get('/reports', params, { preserveState: true, preserveScroll: true, replace: true });
};

// Tanggal lokal (toISOString memakai UTC dan bisa mundur sehari di pagi hari).
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const presets = [
    { key: 'today', label: 'Hari ini', range: () => [new Date(), new Date()] },
    { key: '7d', label: '7 hari', range: () => [new Date(Date.now() - 6 * 864e5), new Date()] },
    { key: 'month', label: 'Bulan ini', range: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()] },
    {
        key: 'lastmonth',
        label: 'Bulan lalu',
        range: () => [new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), new Date(new Date().getFullYear(), new Date().getMonth(), 0)],
    },
    { key: '3m', label: '3 bulan', range: () => [new Date(new Date().getFullYear(), new Date().getMonth() - 2, 1), new Date()] },
] as const;
const activePreset = computed(
    () => presets.find((p) => ymd(p.range()[0]) === filterStart.value && ymd(p.range()[1]) === filterEnd.value)?.key ?? null,
);
const showCustom = ref(false);
const applyPreset = (p: (typeof presets)[number]) => {
    const [a, b] = p.range();
    filterStart.value = ymd(a);
    filterEnd.value = ymd(b);
    showCustom.value = false;
    applyFilter();
};
const onDateChange = () => applyFilter();

const rangeText = computed(() => {
    const f = (x: string) => new Date(x + 'T12:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    const a = props.dateRange.start_date;
    const b = props.dateRange.end_date;
    return a === b ? f(a) : `${f(a)} – ${f(b)}`;
});
const comparisonText = computed(() => {
    const f = (x: string) => new Date(x + 'T12:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
    return `${f(props.comparison.prev_start)} – ${f(props.comparison.prev_end)}`;
});

// Angka utama memakai penjualan bersih (sama dengan Dashboard Owner); fallback ke data lama.
const netSales = computed(() => props.summary.net_sales ?? props.summary.total_sales);
const netChange = computed(() =>
    props.summary.net_sales !== undefined ? (props.comparison.net_sales_change_pct ?? null) : props.comparison.sales_change_pct,
);
const showDetails = ref(false);

const violetFill = 'rgba(124, 58, 237, 0.12)';
const violetBorder = 'rgb(124, 58, 237)';

const hasSalesInTrend = computed(() => props.salesTrend.some((p) => p.total > 0));

const lineChartData = computed(() => ({
    labels: props.salesTrend.map((p) => p.label),
    datasets: [
        {
            label: 'Omzet',
            data: props.salesTrend.map((p) => p.total),
            borderColor: violetBorder,
            backgroundColor: violetFill,
            fill: true,
            tension: 0.35,
            pointRadius: props.salesTrend.length > 45 ? 0 : 3,
            pointHoverRadius: 5,
        },
    ],
}));

const lineChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => ' Rp ' + formatCurrency(ctx.parsed.y),
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { maxRotation: 45, minRotation: 0, autoSkip: true, maxTicksLimit: 14 },
        },
        y: {
            beginAtZero: true,
            ticks: {
                callback: (v: string | number) => formatCurrency(Number(v)),
            },
        },
    },
}));

const paymentChartData = computed(() => {
    const colors = ['rgb(124, 58, 237)', 'rgb(99, 102, 241)', 'rgb(168, 85, 247)', 'rgb(192, 132, 252)', 'rgb(124, 58, 237)'];
    return {
        labels: props.paymentBreakdown.map((p) => paymentMethodLabel(p.method)),
        datasets: [
            {
                data: props.paymentBreakdown.map((p) => p.total_amount),
                backgroundColor: props.paymentBreakdown.map((_, i) => colors[i % colors.length]),
                borderWidth: 0,
            },
        ],
    };
});

const paymentChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom' as const,
            labels: { boxWidth: 12, padding: 16 },
        },
        tooltip: {
            callbacks: {
                label: (ctx) => {
                    const i = ctx.dataIndex;
                    const row = props.paymentBreakdown[i];
                    if (!row) return '';
                    const amt = formatCurrency(row.total_amount);
                    return ` ${paymentMethodLabel(row.method)}: Rp ${amt} (${row.transaction_count} trx)`;
                },
            },
        },
    },
}));

const categoryBarData = computed(() => ({
    labels: props.categoryRevenue.map((c) => c.category),
    datasets: [
        {
            label: 'Omzet kategori',
            data: props.categoryRevenue.map((c) => c.revenue),
            backgroundColor: 'rgba(124, 58, 237, 0.75)',
            borderRadius: 8,
        },
    ],
}));

const categoryBarOptions = computed(() => ({
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => ' Rp ' + formatCurrency(ctx.parsed.x),
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            ticks: { callback: (v: string | number) => formatCurrency(Number(v)) },
        },
        y: { grid: { display: false } },
    },
}));

const topProductsBarData = computed(() => ({
    labels: props.topProductsChart.map((p) => (p.name.length > 22 ? p.name.slice(0, 20) + '…' : p.name)),
    datasets: [
        {
            label: 'Qty terjual',
            data: props.topProductsChart.map((p) => p.total_sold),
            backgroundColor: 'rgba(99, 102, 241, 0.85)',
            borderRadius: 8,
        },
    ],
}));

const topProductsBarOptions = computed(() => ({
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                afterLabel: (ctx) => {
                    const p = props.topProductsChart[ctx.dataIndex];
                    return p ? ' Omzet: Rp ' + formatCurrency(p.revenue) : '';
                },
            },
        },
    },
    scales: {
        x: { beginAtZero: true, ticks: { stepSize: 1 } },
        y: { grid: { display: false } },
    },
}));

const hasPaymentData = computed(() => props.paymentBreakdown.some((p) => p.total_amount > 0));
const hasCategoryData = computed(() => props.categoryRevenue.length > 0);
const hasTopBarData = computed(() => props.topProductsChart.length > 0);

// Baris item tanpa snapshot HPP membuat laba kotor tampak lebih besar dari semestinya.
const hppCoverageGap = computed(() => Math.max(0, props.hppSummary.items_total - props.hppSummary.items_with_hpp));
</script>

<template>
    <AdminLayout title="Laporan & Analitik">
        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="ringkasan" />
            <ReportExportButton
                report="ringkasan"
                :params="{ start_date: dateRange.start_date, end_date: dateRange.end_date, store_id: selectedStoreId }"
            />
        </div>

        <!-- Filter periode (langsung berlaku) -->
        <div class="mb-5 space-y-3 rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-500">
                    <b class="text-slate-800">{{ rangeText }}</b> · dibanding {{ comparisonText }}
                </p>
                <div v-if="props.isOwner && props.accessibleStores && props.accessibleStores.length" class="flex items-center gap-2">
                    <span class="text-xs font-medium text-slate-500">Outlet</span>
                    <ReportOutletFilter :stores="props.accessibleStores" :selected-id="props.selectedStoreId ?? null" />
                </div>
            </div>
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
            <div v-if="showCustom" class="flex flex-wrap items-center gap-2">
                <Label for="rep-start" class="sr-only">Dari</Label>
                <Input id="rep-start" v-model="filterStart" type="date" :max="filterEnd" class="h-9 w-40" @change="onDateChange" />
                <span class="text-sm text-slate-400">s/d</span>
                <Label for="rep-end" class="sr-only">Sampai</Label>
                <Input id="rep-end" v-model="filterEnd" type="date" :min="filterStart" class="h-9 w-40" @change="onDateChange" />
            </div>
        </div>

        <!-- Angka utama -->
        <div class="mb-4 grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <p class="text-xs font-medium text-slate-500">Penjualan bersih</p>
                    <span class="flex items-center gap-0.5 text-xs font-bold" :class="deltaClass(netChange)">
                        <TrendingUp v-if="netChange != null && netChange >= 0" class="h-3.5 w-3.5" />
                        <TrendingDown v-else-if="netChange != null" class="h-3.5 w-3.5" />
                        <span v-if="netChange != null">{{ netChange }}%</span>
                    </span>
                </div>
                <p class="text-lg font-black text-slate-900 tabular-nums md:text-2xl">Rp {{ formatCurrency(netSales) }}</p>
                <p class="mt-1 text-[11px] leading-tight text-slate-500">Setelah diskon &amp; retur, tanpa pajak/tip</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <p class="text-xs font-medium text-slate-500">Laba kotor</p>
                    <span class="text-xs font-bold text-slate-500">{{
                        hppSummary.gross_margin_pct === null ? '' : 'Margin ' + hppSummary.gross_margin_pct + '%'
                    }}</span>
                </div>
                <p class="text-lg font-black text-slate-900 tabular-nums md:text-2xl">Rp {{ formatCurrency(hppSummary.total_gross_profit) }}</p>
                <p v-if="hppCoverageGap > 0" class="mt-1 text-[11px] leading-tight text-amber-600">
                    {{ hppCoverageGap }} baris item belum punya harga modal
                </p>
                <p v-else class="mt-1 text-[11px] leading-tight text-slate-500">Penjualan dikurangi harga modal</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <p class="text-xs font-medium text-slate-500">Transaksi</p>
                    <span class="flex items-center gap-0.5 text-xs font-bold" :class="deltaClass(comparison.transactions_change_pct)">
                        <TrendingUp
                            v-if="comparison.transactions_change_pct != null && comparison.transactions_change_pct >= 0"
                            class="h-3.5 w-3.5"
                        />
                        <TrendingDown v-else-if="comparison.transactions_change_pct != null" class="h-3.5 w-3.5" />
                        <span v-if="comparison.transactions_change_pct != null">{{ comparison.transactions_change_pct }}%</span>
                    </span>
                </div>
                <p class="text-lg font-black text-slate-900 tabular-nums md:text-2xl">{{ summary.total_transactions.toLocaleString('id-ID') }}</p>
                <p class="mt-1 text-[11px] leading-tight text-slate-500">Transaksi selesai</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="mb-2 text-xs font-medium text-slate-500">Rata-rata per transaksi</p>
                <p class="text-lg font-black text-slate-900 tabular-nums md:text-2xl">Rp {{ formatCurrency(summary.average_order_value) }}</p>
                <p class="mt-1 text-[11px] leading-tight text-slate-500">Nilai belanja tiap pelanggan</p>
            </div>
        </div>

        <!-- Rincian (terlipat) -->
        <div class="mb-6 rounded-2xl border border-slate-200 bg-white">
            <button
                type="button"
                class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left"
                :aria-expanded="showDetails"
                @click="showDetails = !showDetails"
            >
                <span class="text-sm font-bold text-slate-800">Rincian lain</span>
                <span class="flex items-center gap-1 text-xs text-slate-500">
                    Uang diterima, HPP, diskon, pajak, pelanggan, unit terjual
                    <ChevronDown class="h-4 w-4 transition-transform" :class="{ 'rotate-180': showDetails }" />
                </span>
            </button>
            <div v-if="showDetails" class="grid grid-cols-2 gap-x-6 gap-y-3 border-t border-slate-100 p-4 text-sm sm:grid-cols-3 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-slate-500">Uang diterima kasir</p>
                    <p class="font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(summary.total_sales) }}</p>
                    <p class="text-[11px] text-slate-400">Termasuk pajak, layanan, tip, ongkir</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Total HPP</p>
                    <p class="font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(hppSummary.total_cost) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Total diskon</p>
                    <p class="font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(summary.total_discount) }}</p>
                    <p v-for="d in summary.discount_by_type ?? []" :key="d.type" class="text-[11px] text-slate-400">
                        {{ discountTypeLabel(d.type) }}: Rp {{ formatCurrency(d.total) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Total pajak</p>
                    <p class="font-bold text-slate-900 tabular-nums">Rp {{ formatCurrency(summary.total_tax) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Pelanggan baru</p>
                    <p class="font-bold text-slate-900 tabular-nums">{{ summary.new_customers }}</p>
                    <p class="text-[11px] text-slate-400">{{ formatDelta(new_customers_change_pct) }} · total {{ summary.total_customers }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Unit terjual</p>
                    <p class="font-bold text-slate-900 tabular-nums">{{ fmtQty(summary.items_sold) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Unit gratis (promo)</p>
                    <p class="font-bold text-slate-900 tabular-nums">{{ fmtQty(summary.total_free_quantity) }}</p>
                </div>
            </div>
        </div>

        <!-- Grafik: tren omzet -->
        <Card class="mb-6 border border-slate-200 shadow-none">
            <CardHeader>
                <CardTitle class="text-lg font-black">Tren penjualan bersih</CardTitle>
                <CardDescription>
                    {{ salesTrend.length > 62 ? 'Agregasi bulanan (periode panjang).' : 'Per hari dalam rentang filter.' }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="hasSalesInTrend" class="h-[300px] w-full min-w-0">
                    <Line :data="lineChartData" :options="lineChartOptions" />
                </div>
                <div v-else class="py-16 text-center text-slate-500">
                    <BarChart3 class="mx-auto mb-3 h-12 w-12 text-slate-300" />
                    <p class="font-medium">Belum ada omzet di periode ini</p>
                    <p class="mt-1 text-sm">Coba perbesar rentang tanggal atau lakukan transaksi POS.</p>
                </div>
            </CardContent>
        </Card>

        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card class="min-w-0 border border-slate-200 shadow-none">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Metode pembayaran</CardTitle>
                    <CardDescription>Proporsi omzet menurut metode bayar</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasPaymentData" class="flex h-[280px] justify-center">
                        <Doughnut :data="paymentChartData" :options="paymentChartOptions" />
                    </div>
                    <p v-else class="py-12 text-center text-sm text-slate-500">Tidak ada data pembayaran.</p>
                </CardContent>
            </Card>

            <Card class="min-w-0 border border-slate-200 shadow-none">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Omzet per kategori</CardTitle>
                    <CardDescription>Berdasarkan kategori produk di keranjang</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasCategoryData" class="h-[280px] w-full min-w-0">
                        <Bar :data="categoryBarData" :options="categoryBarOptions" />
                    </div>
                    <p v-else class="py-12 text-center text-sm text-slate-500">Belum ada penjualan per kategori.</p>
                </CardContent>
            </Card>
        </div>

        <!-- Produk terlaris (grafik batang) -->
        <Card v-if="hasTopBarData" class="mb-6 border border-slate-200 shadow-none">
            <CardHeader>
                <CardTitle class="text-lg font-black">Volume penjualan produk teratas</CardTitle>
                <CardDescription>Jumlah unit terjual (hingga 8 SKU teratas)</CardDescription>
            </CardHeader>
            <CardContent>
                <div class="h-[min(360px,50vh)] w-full min-w-0">
                    <Bar :data="topProductsBarData" :options="topProductsBarOptions" />
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card class="border border-slate-200 shadow-none">
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <CardTitle class="text-lg font-black">Produk terlaris</CardTitle>
                            <CardDescription>
                                {{ topProducts.length }} SKU terjual di periode ini — omzet dari nilai nota, bukan harga master
                            </CardDescription>
                        </div>
                        <div class="rounded-2xl bg-primary/10 p-2">
                            <Package class="h-5 w-5 text-primary" />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="max-h-[560px] space-y-2 overflow-y-auto pr-1">
                        <div
                            v-for="(product, index) in topProducts"
                            :key="product.id"
                            class="flex items-center justify-between rounded-2xl bg-slate-50 p-3"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-black text-white">
                                    {{ index + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <h4 class="truncate font-semibold text-slate-900">{{ product.name }}</h4>
                                    <p class="text-xs text-slate-500">{{ fmtQty(product.total_sold) }} terjual</p>
                                </div>
                            </div>
                            <div class="shrink-0 pl-2 text-right">
                                <p class="font-bold text-slate-900 tabular-nums">{{ formatCurrency(product.total_revenue) }}</p>
                                <p class="text-xs text-slate-500">
                                    <span v-if="product.has_hpp" class="font-semibold text-emerald-600">
                                        laba {{ formatCurrency(product.total_profit) }}
                                    </span>
                                    <span v-else>{{ formatCurrency(product.avg_price) }}/unit</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="topProducts.length === 0" class="py-10 text-center text-slate-500">
                        <Package class="mx-auto mb-3 h-10 w-10 text-slate-300" />
                        <p class="font-medium">Belum ada data penjualan</p>
                    </div>
                </CardContent>
            </Card>

            <Card class="border border-slate-200 shadow-none">
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <CardTitle class="text-lg font-black">Transaksi terbaru</CardTitle>
                            <CardDescription>10 transaksi terakhir di periode</CardDescription>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-2">
                            <ShoppingCart class="h-5 w-5 text-emerald-600" />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="max-h-[420px] space-y-2 overflow-y-auto pr-1">
                        <div
                            v-for="transaction in recentTransactions"
                            :key="transaction.id"
                            class="flex items-center justify-between rounded-2xl bg-slate-50 p-3"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100">
                                    <ShoppingCart class="h-4 w-4 text-emerald-600" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="truncate font-semibold text-slate-900">
                                        {{ transaction.customer?.name || 'Pelanggan umum' }}
                                    </h4>
                                    <p class="text-xs text-slate-500">{{ formatDate(transaction.created_at.slice(0, 10)) }}</p>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="font-bold tabular-nums">{{ formatCurrency(transaction.total_amount) }}</p>
                                <Badge variant="secondary" class="text-[10px]">{{ transaction.status }}</Badge>
                            </div>
                        </div>
                    </div>

                    <div v-if="recentTransactions.length === 0" class="py-10 text-center text-slate-500">
                        <ShoppingCart class="mx-auto mb-3 h-10 w-10 text-slate-300" />
                        <p class="font-medium">Belum ada transaksi di periode ini</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
