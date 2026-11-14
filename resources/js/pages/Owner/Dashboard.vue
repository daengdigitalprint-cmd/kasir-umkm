<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/AdminLayout.vue';
import OwnerHrSummary, { type HrSummary } from '@/components/owner/OwnerHrSummary.vue';
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
import zoomPlugin from 'chartjs-plugin-zoom';
import { Line, Bar } from 'vue-chartjs';
import {
    Wallet,
    TrendingUp,
    TrendingDown,
    Download,
    BarChart3,
    LineChart as LineChartIcon,
    Layers,
    Store,
    DollarSign,
    Percent,
    Filter,
    RefreshCw,
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import 'hammerjs';

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
    zoomPlugin,
);

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
    /** Persen penjualan yang harga modalnya diketahui (0-100). */
    cogs_coverage: number;
}

interface RevenueTotals {
    /** Penjualan bersih: subtotal - diskon - retur, tanpa pajak/servis/tip. */
    net_revenue: number;
    cogs: number;
    gross_profit: number;
    margin: number | null;
    /** Pajak + service charge + tip: tertagih di kasir, bukan pendapatan usaha. */
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
}

interface Props {
    stores: StoreItem[];
    chartData: ChartDataResponse;
    /** Ringkasan SDM lintas outlet (kosong bila tidak ada outlet dengan modul absensi aktif). */
    hr?: HrSummary;
    filters: {
        period: string;
        start_date: string;
        end_date: string;
        store_id: number | null;
    };
}

const props = defineProps<Props>();

const selectedPeriod = ref(props.filters.period);
const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const selectedStoreId = ref<number | null>(props.filters.store_id);
const chartType = ref<'line' | 'bar' | 'area'>('line');
const loading = ref(false);

const periodOptions = [
    { value: 'daily', label: 'Harian' },
    { value: 'weekly', label: 'Mingguan' },
    { value: 'monthly', label: 'Bulanan' },
    { value: 'yearly', label: 'Tahunan' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);

const formatPercent = (value: number | null) => {
    if (value === null) return '-';
    const prefix = value >= 0 ? '+' : '';
    return `${prefix}${value.toFixed(1)}%`;
};

const summaryList = computed(() => {
    const s = props.chartData.summary;
    return Object.values(s);
});

const totalAllOutlets = computed(() => summaryList.value.reduce((sum, item) => sum + item.total, 0));

/** Ringkasan laba dari server; aman bila backend lama belum mengirim totals. */
const totals = computed<RevenueTotals | null>(() => props.chartData?.totals ?? null);

/** Cakupan HPP rendah = angka margin belum bisa dipercaya penuh. */
const marginTerpercaya = computed(() => (totals.value?.cogs_coverage ?? 0) >= 80);
const avgGrowth = computed(() => {
    const growths = summaryList.value.filter((s) => s.growth !== null).map((s) => s.growth as number);
    return growths.length > 0 ? growths.reduce((a, b) => a + b, 0) / growths.length : null;
});

const chartData = computed(() => ({
    labels: props.chartData.labels,
    datasets: props.chartData.datasets.map((ds) => ({
        ...ds,
        borderWidth: chartType.value === 'area' ? 1 : 2,
        pointRadius: chartType.value === 'bar' ? 0 : 3,
        pointHoverRadius: chartType.value === 'bar' ? 0 : 6,
        tension: 0.3,
        fill: chartType.value === 'area' ? { target: 'origin', above: ds.backgroundColor } : false,
        backgroundColor: chartType.value === 'bar' ? ds.backgroundColor.replace('33', 'CC') : ds.backgroundColor,
    })),
}));

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index' as const,
        intersect: false,
    },
    plugins: {
        legend: {
            display: true,
            position: 'top' as const,
            labels: {
                usePointStyle: true,
                pointStyle: 'circle',
                padding: 16,
                font: { size: 12 },
            },
        },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            titleFont: { size: 13 },
            bodyFont: { size: 12 },
            padding: 12,
            cornerRadius: 8,
            callbacks: {
                label: (ctx: any) => {
                    const value = ctx.parsed?.y ?? 0;
                    return ` ${ctx.dataset.label}: Rp ${formatCurrency(value)}`;
                },
            },
        },
        zoom: {
            pan: { enabled: true, mode: 'x' as const },
            zoom: {
                wheel: { enabled: true },
                pinch: { enabled: true },
                mode: 'x' as const,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, maxRotation: 45 },
        },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: {
                font: { size: 11 },
                callback: (value: any) => `Rp ${formatCurrency(Number(value))}`,
            },
        },
    },
}));

function fetchData() {
    loading.value = true;
    const params: Record<string, string | number> = {
        period: selectedPeriod.value,
        start_date: startDate.value,
        end_date: endDate.value,
    };
    if (selectedStoreId.value) {
        params.store_id = selectedStoreId.value;
    }
    router.get('/owner/dashboard', params, {
        preserveState: true,
        only: ['chartData', 'filters'],
        onFinish: () => {
            loading.value = false;
        },
    });
}

function applyFilter() {
    fetchData();
}

function exportExcel() {
    const params = new URLSearchParams({
        period: selectedPeriod.value,
        start_date: startDate.value,
        end_date: endDate.value,
    });
    window.location.href = `/owner/dashboard/export?${params.toString()}`;
}

function setQuickRange(days: number) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - days);
    startDate.value = start.toISOString().split('T')[0];
    endDate.value = end.toISOString().split('T')[0];
    selectedPeriod.value = days <= 7 ? 'daily' : days <= 90 ? 'weekly' : 'monthly';
    fetchData();
}
</script>

<template>
    <Head title="Dashboard Owner" />
    <AdminLayout title="Dashboard Owner">
        <template #header-actions>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="inline-flex items-center gap-2" @click="fetchData" :disabled="loading">
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                </Button>
                <Button variant="outline" size="sm" class="inline-flex items-center gap-2" @click="exportExcel">
                    <Download class="h-4 w-4" />
                    Export Excel
                </Button>
            </div>
        </template>

        <!-- Ringkasan SDM lintas outlet -->
        <OwnerHrSummary v-if="hr && hr.outlets.length" :hr="hr" />

        <!-- Filter Bar -->
        <Card class="border-0 shadow-lg mb-6">
            <CardContent class="p-4 lg:p-6">
                <div class="flex flex-wrap items-end gap-3 lg:gap-4">
                    <!-- Period -->
                    <div class="flex-1 min-w-[140px]">
                        <label class="text-xs font-semibold text-gray-500 mb-1 block">Periode</label>
                        <select
                            v-model="selectedPeriod"
                            class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                        >
                            <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="min-w-[140px]">
                        <label class="text-xs font-semibold text-gray-500 mb-1 block">Dari</label>
                        <input
                            v-model="startDate"
                            type="date"
                            class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </div>
                    <div class="min-w-[140px]">
                        <label class="text-xs font-semibold text-gray-500 mb-1 block">Sampai</label>
                        <input
                            v-model="endDate"
                            type="date"
                            class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </div>

                    <!-- Outlet Filter -->
                    <div v-if="stores.length > 1" class="min-w-[180px]">
                        <label class="text-xs font-semibold text-gray-500 mb-1 block">Outlet</label>
                        <select
                            v-model="selectedStoreId"
                            class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                        >
                            <option :value="null">Semua Outlet</option>
                            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <!-- Apply -->
                    <Button class="bg-primary hover:bg-primary/90 text-white" @click="applyFilter" :disabled="loading">
                        <Filter class="h-4 w-4 mr-1" />
                        Terapkan
                    </Button>
                </div>

                <!-- Quick Range Buttons -->
                <div class="flex flex-wrap gap-2 mt-3">
                    <Button variant="outline" size="sm" class="text-xs" @click="setQuickRange(7)">7 Hari</Button>
                    <Button variant="outline" size="sm" class="text-xs" @click="setQuickRange(30)">30 Hari</Button>
                    <Button variant="outline" size="sm" class="text-xs" @click="setQuickRange(90)">3 Bulan</Button>
                    <Button variant="outline" size="sm" class="text-xs" @click="setQuickRange(365)">1 Tahun</Button>
                </div>
            </CardContent>
        </Card>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Penjualan bersih: tanpa pajak, service charge, tip; setelah retur -->
            <Card class="border-0 shadow-lg">
                <CardContent class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Penjualan Bersih</p>
                            <p class="text-2xl font-bold text-gray-900 mt-1">Rp {{ formatCurrency(totalAllOutlets) }}</p>
                            <p v-if="totals && totals.collected_not_revenue > 0" class="text-xs text-gray-400 mt-1">
                                Di luar pajak Rp {{ formatCurrency(totals.collected_not_revenue) }}
                            </p>
                            <p v-else class="text-xs text-gray-400 mt-1">Semua outlet dipilih</p>
                        </div>
                        <div class="p-3 bg-primary/10 rounded-full">
                            <DollarSign class="h-6 w-6 text-primary" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Rata-rata per Outlet -->
            <Card class="border-0 shadow-lg">
                <CardContent class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Rata-rata per Outlet</p>
                            <p class="text-2xl font-bold text-gray-900 mt-1">
                                Rp {{ formatCurrency(summaryList.length > 0 ? totalAllOutlets / summaryList.length : 0) }}
                            </p>
                            <p class="text-xs text-gray-400 mt-1">{{ summaryList.length }} outlet aktif</p>
                        </div>
                        <div class="p-3 bg-emerald-100 rounded-full">
                            <Store class="h-6 w-6 text-emerald-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Laba kotor = penjualan bersih - HPP -->
            <Card class="border-0 shadow-lg">
                <CardContent class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Laba Kotor</p>
                            <p class="text-2xl font-bold mt-1" :class="(totals?.gross_profit ?? 0) >= 0 ? 'text-gray-900' : 'text-red-600'">
                                Rp {{ formatCurrency(totals?.gross_profit ?? 0) }}
                            </p>
                            <p class="text-xs text-gray-400 mt-1">
                                Margin {{ totals?.margin !== null && totals?.margin !== undefined ? formatPercent(totals.margin) : '—' }}
                                <span v-if="totals && !marginTerpercaya" class="text-amber-600 font-medium">
                                    · data HPP {{ totals.cogs_coverage }}%
                                </span>
                            </p>
                        </div>
                        <div class="p-3 rounded-full" :class="(totals?.gross_profit ?? 0) >= 0 ? 'bg-indigo-100' : 'bg-red-100'">
                            <Wallet class="h-6 w-6" :class="(totals?.gross_profit ?? 0) >= 0 ? 'text-indigo-600' : 'text-red-600'" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Pertumbuhan -->
            <Card class="border-0 shadow-lg">
                <CardContent class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium text-gray-500">Pertumbuhan vs Periode Lalu</p>
                            <p
                                class="text-2xl font-bold mt-1"
                                :class="(avgGrowth ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600'"
                            >
                                {{ formatPercent(avgGrowth) }}
                            </p>
                            <p class="text-xs text-gray-400 mt-1">Rata-rata semua outlet</p>
                        </div>
                        <div class="p-3 rounded-full" :class="(avgGrowth ?? 0) >= 0 ? 'bg-emerald-100' : 'bg-red-100'">
                            <TrendingUp v-if="(avgGrowth ?? 0) >= 0" class="h-6 w-6 text-emerald-600" />
                            <TrendingDown v-else class="h-6 w-6 text-red-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Per-Outlet Summary -->
        <div v-if="summaryList.length > 1" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <Card v-for="item in summaryList" :key="item.name" class="border-0 shadow-md">
                <CardContent class="p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }" />
                        <span class="text-sm font-semibold text-gray-800 truncate">{{ item.name }}</span>
                    </div>
                    <p class="text-lg font-bold text-gray-900">Rp {{ formatCurrency(item.total) }}</p>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-xs text-gray-400">Avg: Rp {{ formatCurrency(item.average) }}</span>
                        <Badge
                            v-if="item.growth !== null"
                            variant="outline"
                            class="text-[10px]"
                            :class="(item.growth ?? 0) >= 0 ? 'text-emerald-600 border-emerald-300' : 'text-red-600 border-red-300'"
                        >
                            {{ formatPercent(item.growth) }}
                        </Badge>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Chart Card -->
        <Card class="border-0 shadow-lg mb-6">
            <CardHeader class="pb-3">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <CardTitle class="text-lg font-bold text-gray-900">Grafik Pendapatan</CardTitle>
                    <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-8 px-3 text-xs"
                            :class="chartType === 'line' ? 'bg-white shadow-sm text-primary' : 'text-gray-500'"
                            @click="chartType = 'line'"
                        >
                            <LineChartIcon class="h-3.5 w-3.5 mr-1" />
                            Line
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-8 px-3 text-xs"
                            :class="chartType === 'bar' ? 'bg-white shadow-sm text-primary' : 'text-gray-500'"
                            @click="chartType = 'bar'"
                        >
                            <BarChart3 class="h-3.5 w-3.5 mr-1" />
                            Bar
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-8 px-3 text-xs"
                            :class="chartType === 'area' ? 'bg-white shadow-sm text-primary' : 'text-gray-500'"
                            @click="chartType = 'area'"
                        >
                            <Layers class="h-3.5 w-3.5 mr-1" />
                            Area
                        </Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div class="relative" style="height: 420px">
                    <component
                        :is="chartType === 'bar' ? Bar : Line"
                        :data="chartData"
                        :options="chartOptions as any"
                    />
                </div>
                <p class="text-xs text-gray-400 mt-3 text-center">
                    Gunakan scroll untuk zoom, drag untuk geser. Klik legend untuk sembunyikan/munculkan outlet.
                </p>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
