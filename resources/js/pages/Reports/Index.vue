<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import ReportOutletFilter from '@/components/ReportOutletFilter.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Line, Bar, Doughnut } from 'vue-chartjs';
import {
    BarChart3,
    DollarSign,
    TrendingUp,
    TrendingDown,
    ShoppingCart,
    Package,
    Users,
    Activity,
    CalendarRange,
    Percent,
    Receipt,
    Tags,
    Gift,
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

interface TopProduct {
    id: number;
    name: string;
    total_sold: number;
    price: number;
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
        prev_total_transactions: number;
        sales_change_pct: number | null;
        transactions_change_pct: number | null;
    };
    summary: {
        total_sales: number;
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
    router.get(
        '/reports',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const presetDays = (days: number) => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));
    filterEnd.value = end.toISOString().slice(0, 10);
    filterStart.value = start.toISOString().slice(0, 10);
    applyFilter();
};

const presetThisMonth = () => {
    const now = new Date();
    filterStart.value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
    filterEnd.value = now.toISOString().slice(0, 10);
    applyFilter();
};

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
    const colors = [
        'rgb(124, 58, 237)',
        'rgb(99, 102, 241)',
        'rgb(168, 85, 247)',
        'rgb(192, 132, 252)',
        'rgb(124, 58, 237)',
    ];
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
    labels: props.topProductsChart.map((p) =>
        p.name.length > 22 ? p.name.slice(0, 20) + '…' : p.name,
    ),
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

        <!-- Filter periode -->
        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <!-- Baris 1: Pilih outlet (owner saja) -->
                <div
                    v-if="props.isOwner && props.accessibleStores && props.accessibleStores.length"
                    class="flex items-center gap-3 mb-4 pb-4 border-b border-slate-100"
                >
                    <div class="p-2 bg-primary/10 rounded-xl shrink-0">
                        <Package class="h-4 w-4 text-primary" />
                    </div>
                    <span class="text-sm font-bold text-slate-700 shrink-0">Lihat data outlet:</span>
                    <ReportOutletFilter
                        :stores="props.accessibleStores"
                        :selected-id="props.selectedStoreId ?? null"
                    />
                </div>

                <!-- Baris 2: Filter tanggal -->
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex items-center gap-2 text-primary">
                        <CalendarRange class="h-5 w-5 shrink-0" />
                        <div>
                            <p class="text-sm font-black text-slate-900">Filter periode</p>
                            <p class="text-xs text-slate-500">Data membaca transaksi selesai dalam rentang tanggal</p>
                        </div>
                    </div>
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                        <div class="space-y-1.5">
                            <Label for="rep-start" class="text-xs">Dari</Label>
                            <Input id="rep-start" v-model="filterStart" type="date" class="w-40" required />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="rep-end" class="text-xs">Sampai</Label>
                            <Input id="rep-end" v-model="filterEnd" type="date" class="w-40" required />
                        </div>
                        <Button type="submit" class="shrink-0">Terapkan</Button>
                    </form>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" @click="presetDays(7)">7 hari</Button>
                        <Button type="button" variant="outline" size="sm" @click="presetDays(30)">30 hari</Button>
                        <Button type="button" variant="outline" size="sm" @click="presetDays(90)">90 hari</Button>
                        <Button type="button" variant="outline" size="sm" @click="presetThisMonth">Bulan ini</Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Summary KPI -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="p-2.5 bg-emerald-50 rounded-2xl">
                            <DollarSign class="h-5 w-5 text-emerald-600" />
                        </div>
                        <div class="flex items-center gap-0.5 text-xs font-bold" :class="deltaClass(comparison.sales_change_pct)">
                            <TrendingUp v-if="comparison.sales_change_pct != null && comparison.sales_change_pct >= 0" class="h-3.5 w-3.5" />
                            <TrendingDown v-else-if="comparison.sales_change_pct != null" class="h-3.5 w-3.5" />
                            <span v-if="comparison.sales_change_pct != null">{{ comparison.sales_change_pct }}%</span>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Total omzet</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(summary.total_sales) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1 leading-tight">{{ formatDelta(comparison.sales_change_pct) }}</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="p-2.5 bg-primary/10 rounded-2xl">
                            <ShoppingCart class="h-5 w-5 text-primary" />
                        </div>
                        <div
                            class="flex items-center gap-0.5 text-xs font-bold"
                            :class="deltaClass(comparison.transactions_change_pct)"
                        >
                            <TrendingUp
                                v-if="comparison.transactions_change_pct != null && comparison.transactions_change_pct >= 0"
                                class="h-3.5 w-3.5"
                            />
                            <TrendingDown v-else-if="comparison.transactions_change_pct != null" class="h-3.5 w-3.5" />
                            <span v-if="comparison.transactions_change_pct != null">{{ comparison.transactions_change_pct }}%</span>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Transaksi selesai</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">{{ summary.total_transactions }}</p>
                    <p class="text-[11px] text-slate-500 mt-1 leading-tight">
                        {{ formatDelta(comparison.transactions_change_pct) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Rata-rata: {{ formatCurrency(summary.average_order_value) }}</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="p-2.5 bg-violet-100 rounded-2xl">
                            <Users class="h-5 w-5 text-violet-600" />
                        </div>
                        <div
                            v-if="new_customers_change_pct != null"
                            class="flex items-center gap-0.5 text-xs font-bold"
                            :class="deltaClass(new_customers_change_pct)"
                        >
                            <Percent class="h-3.5 w-3.5" />
                            <span>{{ new_customers_change_pct }}%</span>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-slate-500">Pelanggan baru</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">{{ summary.new_customers }}</p>
                    <p class="text-[11px] text-slate-500 mt-1 leading-tight">{{ formatDelta(new_customers_change_pct) }}</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Total terdaftar: {{ summary.total_customers }}</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="p-2.5 bg-amber-50 rounded-2xl">
                            <Package class="h-5 w-5 text-amber-600" />
                        </div>
                        <Activity class="h-5 w-5 text-slate-300" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Unit terjual</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">{{ summary.items_sold }}</p>
                    <p class="text-[11px] text-slate-500 mt-1">Jumlah qty baris item</p>
                </CardContent>
            </Card>
        </div>

        <!-- Ringkasan diskon & pajak -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <Card class="border-0 shadow-md border-slate-100">
                <CardContent class="p-4">
                    <div class="flex items-center gap-4">
                        <div class="p-3 rounded-2xl bg-orange-50">
                            <Tags class="h-6 w-6 text-orange-600" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Total diskon (transaksi)</p>
                            <p class="text-xl font-black text-slate-900">{{ formatCurrency(summary.total_discount) }}</p>
                        </div>
                    </div>
                    <ul v-if="summary.discount_by_type?.length" class="mt-3 space-y-1 border-t border-slate-100 pt-2">
                        <li v-for="d in summary.discount_by_type" :key="d.type" class="flex items-center justify-between text-xs">
                            <span class="text-slate-500">{{ discountTypeLabel(d.type) }}</span>
                            <span class="font-semibold text-slate-700 tabular-nums">{{ formatCurrency(d.total) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md border-slate-100">
                <CardContent class="p-4 flex items-center gap-4">
                    <div class="p-3 rounded-2xl bg-sky-50">
                        <Receipt class="h-6 w-6 text-sky-600" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Total pajak (transaksi)</p>
                        <p class="text-xl font-black text-slate-900">{{ formatCurrency(summary.total_tax) }}</p>
                    </div>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md border-slate-100">
                <CardContent class="p-4 flex items-center gap-4">
                    <div class="p-3 rounded-2xl bg-emerald-50">
                        <Gift class="h-6 w-6 text-emerald-600" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Unit gratis (promo)</p>
                        <p class="text-xl font-black text-slate-900 tabular-nums">{{ fmtQty(summary.total_free_quantity) }}</p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Grafik: tren omzet -->
        <Card class="border-0 shadow-lg mb-6">
            <CardHeader>
                <CardTitle class="text-lg font-black">Tren omzet</CardTitle>
                <CardDescription>
                    {{ salesTrend.length > 62 ? 'Agregasi bulanan (periode panjang).' : 'Per hari dalam rentang filter.' }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="hasSalesInTrend" class="h-[300px] w-full min-w-0">
                    <Line :data="lineChartData" :options="lineChartOptions" />
                </div>
                <div v-else class="py-16 text-center text-slate-500">
                    <BarChart3 class="h-12 w-12 mx-auto mb-3 text-slate-300" />
                    <p class="font-medium">Belum ada omzet di periode ini</p>
                    <p class="text-sm mt-1">Coba perbesar rentang tanggal atau lakukan transaksi POS.</p>
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <Card class="border-0 shadow-lg min-w-0">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Metode pembayaran</CardTitle>
                    <CardDescription>Proporsi omzet menurut metode bayar</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasPaymentData" class="h-[280px] flex justify-center">
                        <Doughnut :data="paymentChartData" :options="paymentChartOptions" />
                    </div>
                    <p v-else class="py-12 text-center text-slate-500 text-sm">Tidak ada data pembayaran.</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg min-w-0">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Omzet per kategori</CardTitle>
                    <CardDescription>Berdasarkan kategori produk di keranjang</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasCategoryData" class="h-[280px] w-full min-w-0">
                        <Bar :data="categoryBarData" :options="categoryBarOptions" />
                    </div>
                    <p v-else class="py-12 text-center text-slate-500 text-sm">Belum ada penjualan per kategori.</p>
                </CardContent>
            </Card>
        </div>

        <!-- Produk terlaris (grafik batang) -->
        <Card v-if="hasTopBarData" class="border-0 shadow-lg mb-6">
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <CardTitle class="text-lg font-black">Produk terlaris</CardTitle>
                            <CardDescription>10 SKU dengan unit terjual tertinggi</CardDescription>
                        </div>
                        <div class="p-2 bg-primary/10 rounded-2xl">
                            <Package class="h-5 w-5 text-primary" />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                        <div
                            v-for="(product, index) in topProducts"
                            :key="product.id"
                            class="flex items-center justify-between p-3 bg-slate-50 rounded-2xl"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="w-8 h-8 bg-primary rounded-full flex items-center justify-center text-white text-sm font-black shrink-0"
                                >
                                    {{ index + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 truncate">{{ product.name }}</h4>
                                    <p class="text-xs text-slate-500">{{ fmtQty(product.total_sold) }} terjual</p>
                                </div>
                            </div>
                            <div class="text-right shrink-0 pl-2">
                                <p class="font-bold text-slate-900 tabular-nums">{{ formatCurrency(product.price * product.total_sold) }}</p>
                                <p class="text-xs text-slate-500">{{ formatCurrency(product.price) }}/unit</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="topProducts.length === 0" class="text-center py-10 text-slate-500">
                        <Package class="h-10 w-10 mx-auto mb-3 text-slate-300" />
                        <p class="font-medium">Belum ada data penjualan</p>
                    </div>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <CardTitle class="text-lg font-black">Transaksi terbaru</CardTitle>
                            <CardDescription>10 transaksi terakhir di periode</CardDescription>
                        </div>
                        <div class="p-2 bg-emerald-50 rounded-2xl">
                            <ShoppingCart class="h-5 w-5 text-emerald-600" />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                        <div
                            v-for="transaction in recentTransactions"
                            :key="transaction.id"
                            class="flex items-center justify-between p-3 bg-slate-50 rounded-2xl"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center shrink-0">
                                    <ShoppingCart class="h-4 w-4 text-emerald-600" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 truncate">
                                        {{ transaction.customer?.name || 'Pelanggan umum' }}
                                    </h4>
                                    <p class="text-xs text-slate-500">{{ formatDate(transaction.created_at.slice(0, 10)) }}</p>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-bold tabular-nums">{{ formatCurrency(transaction.total_amount) }}</p>
                                <Badge variant="secondary" class="text-[10px]">{{ transaction.status }}</Badge>
                            </div>
                        </div>
                    </div>

                    <div v-if="recentTransactions.length === 0" class="text-center py-10 text-slate-500">
                        <ShoppingCart class="h-10 w-10 mx-auto mb-3 text-slate-300" />
                        <p class="font-medium">Belum ada transaksi di periode ini</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
