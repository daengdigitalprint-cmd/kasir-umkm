<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportOutletFilter from '@/components/ReportOutletFilter.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler,
    type TooltipItem,
} from 'chart.js';
import { Line, Bar } from 'vue-chartjs';
import { ShoppingBag, Package, Receipt, Truck, BarChart3 } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend, Filler);

interface PurchaseRow {
    id: number;
    purchase_number: string;
    purchased_at: string | null;
    supplier_name: string | null;
    user_name: string | null;
    store_name: string | null;
    lines_count: number;
    dpp_amount: number;
    charges_amount: number;
    ppn_amount: number;
    total_amount: number;
    supplier_tax_invoice_no: string | null;
}

interface Paginated {
    data: PurchaseRow[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface TrendPoint {
    period: string;
    label: string;
    total: number;
}

interface SupplierRow {
    supplier: string;
    purchase_count: number;
    total_amount: number;
}

interface TopItemRow {
    name: string;
    total_qty: number;
    total_value: number;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    purchases: Paginated;
    summary: {
        total_purchases: number;
        total_dpp: number;
        total_charges: number;
        total_ppn: number;
        total_amount: number;
        average_purchase: number;
    };
    bySupplier: SupplierRow[];
    topItems: TopItemRow[];
    purchaseTrend: TrendPoint[];
    accessibleStores?: { id: number; name: string }[];
    selectedStoreId?: number | null;
    isOwner?: boolean;
}>();

const filterStart = ref(props.dateRange.start_date);
const filterEnd = ref(props.dateRange.end_date);

watch(
    () => props.dateRange,
    (dr) => {
        filterStart.value = dr.start_date;
        filterEnd.value = dr.end_date;
    },
);

const applyFilter = () => {
    router.get(
        '/reports/purchases',
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

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);

const fmtQty = (n: number | string): string => {
    const num = Number(n);
    if (Number.isNaN(num)) return String(n);
    return num % 1 === 0 ? String(Math.round(num)) : num.toFixed(4).replace(/\.?0+$/, '');
};

const formatDate = (iso: string | null) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const hasTrendData = computed(() => props.purchaseTrend.some((p) => p.total > 0));
const hasSupplierData = computed(() => props.bySupplier.length > 0);
const hasTopItems = computed(() => props.topItems.length > 0);

const trendChartData = computed(() => ({
    labels: props.purchaseTrend.map((p) => p.label),
    datasets: [
        {
            label: 'Pembelian',
            data: props.purchaseTrend.map((p) => p.total),
            borderColor: 'rgb(217, 119, 6)',
            backgroundColor: 'rgba(217, 119, 6, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: props.purchaseTrend.length > 45 ? 0 : 3,
            pointHoverRadius: 5,
        },
    ],
}));

const trendChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'line'>) => ' Rp ' + formatCurrency(ctx.parsed.y ?? 0),
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
            ticks: { callback: (v: string | number) => formatCurrency(Number(v)) },
        },
    },
}));

const supplierBarData = computed(() => ({
    labels: props.bySupplier.map((s) => (s.supplier.length > 22 ? s.supplier.slice(0, 20) + '…' : s.supplier)),
    datasets: [
        {
            label: 'Nilai pembelian',
            data: props.bySupplier.map((s) => s.total_amount),
            backgroundColor: 'rgba(217, 119, 6, 0.75)',
            borderRadius: 8,
        },
    ],
}));

const supplierBarOptions = computed(() => ({
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'bar'>) => ' Rp ' + formatCurrency(ctx.parsed.x ?? 0),
            },
        },
    },
    scales: {
        x: { beginAtZero: true, ticks: { callback: (v: string | number) => formatCurrency(Number(v)) } },
        y: { grid: { display: false } },
    },
}));
</script>

<template>
    <AdminLayout title="Laporan pembelian">
        <Head title="Laporan pembelian" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="pembelian" />
            <ReportExportButton report="pembelian" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <!-- Filter periode -->
        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div
                    v-if="props.isOwner && props.accessibleStores && props.accessibleStores.length"
                    class="flex items-center gap-3 mb-4 pb-4 border-b border-slate-100"
                >
                    <div class="p-2 bg-primary/10 rounded-xl shrink-0">
                        <Package class="h-4 w-4 text-primary" />
                    </div>
                    <span class="text-sm font-bold text-slate-700 shrink-0">Lihat data outlet:</span>
                    <ReportOutletFilter :stores="props.accessibleStores" :selected-id="props.selectedStoreId ?? null" />
                </div>

                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                        <div class="space-y-1.5">
                            <Label for="pur-start" class="text-xs">Dari</Label>
                            <Input id="pur-start" v-model="filterStart" type="date" class="w-40" required />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="pur-end" class="text-xs">Sampai</Label>
                            <Input id="pur-end" v-model="filterEnd" type="date" class="w-40" required />
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

        <!-- KPI -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-amber-50 rounded-2xl w-fit mb-3">
                        <ShoppingBag class="h-5 w-5 text-amber-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Total pembelian</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(summary.total_amount) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">{{ summary.total_purchases }} nota pembelian</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-primary/10 rounded-2xl w-fit mb-3">
                        <Receipt class="h-5 w-5 text-primary" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Rata-rata per nota</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(summary.average_purchase) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">Nilai rata-rata pembelian</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-sky-50 rounded-2xl w-fit mb-3">
                        <Receipt class="h-5 w-5 text-sky-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Total PPN masukan</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(summary.total_ppn) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">DPP: {{ formatCurrency(summary.total_dpp) }}</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-violet-100 rounded-2xl w-fit mb-3">
                        <Truck class="h-5 w-5 text-violet-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Biaya tambahan</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(summary.total_charges) }}
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">Ongkir, bongkar muat, dll</p>
                </CardContent>
            </Card>
        </div>

        <!-- Tren pembelian -->
        <Card class="border-0 shadow-lg mb-6">
            <CardHeader>
                <CardTitle class="text-lg font-black">Tren pembelian</CardTitle>
                <CardDescription>
                    {{ purchaseTrend.length > 62 ? 'Agregasi bulanan (periode panjang).' : 'Per hari dalam rentang filter.' }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="hasTrendData" class="h-[300px] w-full min-w-0">
                    <Line :data="trendChartData" :options="trendChartOptions" />
                </div>
                <div v-else class="py-16 text-center text-slate-500">
                    <BarChart3 class="h-12 w-12 mx-auto mb-3 text-slate-300" />
                    <p class="font-medium">Belum ada pembelian di periode ini</p>
                    <p class="text-sm mt-1">Coba perbesar rentang tanggal atau catat pembelian baru.</p>
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <Card class="border-0 shadow-lg min-w-0">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Pembelian per supplier</CardTitle>
                    <CardDescription>10 supplier dengan nilai pembelian tertinggi</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasSupplierData" class="h-[300px] w-full min-w-0">
                        <Bar :data="supplierBarData" :options="supplierBarOptions" />
                    </div>
                    <p v-else class="py-12 text-center text-slate-500 text-sm">Belum ada data supplier.</p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg min-w-0">
                <CardHeader>
                    <CardTitle class="text-lg font-black">Barang paling banyak dibeli</CardTitle>
                    <CardDescription>Berdasarkan nilai pembelian</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="hasTopItems" class="space-y-2 max-h-[300px] overflow-y-auto pr-1">
                        <div
                            v-for="(item, index) in topItems"
                            :key="item.name"
                            class="flex items-center justify-between p-3 bg-slate-50 rounded-2xl"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="w-8 h-8 bg-amber-500 rounded-full flex items-center justify-center text-white text-sm font-black shrink-0"
                                >
                                    {{ index + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 truncate">{{ item.name }}</h4>
                                    <p class="text-xs text-slate-500">{{ fmtQty(item.total_qty) }} unit dibeli</p>
                                </div>
                            </div>
                            <p class="font-bold text-slate-900 tabular-nums shrink-0 pl-2">
                                {{ formatCurrency(item.total_value) }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="py-12 text-center text-slate-500 text-sm">Belum ada barang yang dibeli.</p>
                </CardContent>
            </Card>
        </div>

        <!-- Daftar pembelian -->
        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <ShoppingBag class="h-5 w-5 text-slate-600" />
                    Daftar pembelian
                </CardTitle>
                <CardDescription>{{ store.name }} — {{ purchases.data?.length ?? 0 }} entri di halaman ini</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">No. Nota</th>
                            <th class="pb-3 pr-4 font-medium">Tanggal</th>
                            <th class="pb-3 pr-4 font-medium">Supplier</th>
                            <th class="pb-3 pr-4 font-medium">Dicatat oleh</th>
                            <th class="pb-3 pr-4 font-medium text-center">Item</th>
                            <th class="pb-3 pr-4 font-medium text-right">DPP</th>
                            <th class="pb-3 pr-4 font-medium text-right">PPN</th>
                            <th class="pb-3 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in purchases.data" :key="p.id" class="border-b border-slate-100">
                            <td class="py-3 pr-4 font-mono text-xs">
                                <Link :href="`/purchases/${p.id}`" class="hover:underline text-teal-700">
                                    {{ p.purchase_number }}
                                </Link>
                            </td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ formatDate(p.purchased_at) }}</td>
                            <td class="py-3 pr-4">{{ p.supplier_name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ p.user_name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-center">{{ p.lines_count }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums">{{ formatCurrency(p.dpp_amount) }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums">{{ formatCurrency(p.ppn_amount) }}</td>
                            <td class="py-3 text-right font-semibold tabular-nums">Rp {{ formatCurrency(p.total_amount) }}</td>
                        </tr>
                        <tr v-if="!purchases.data?.length">
                            <td colspan="8" class="py-10 text-center text-slate-500">Tidak ada pembelian di periode ini.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="purchases.links && purchases.links.length > 3" class="mt-6 flex justify-center">
                    <nav class="flex flex-wrap items-center justify-center gap-1">
                        <Link
                            v-for="link in purchases.links"
                            :key="`${link.label}-${link.active}`"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-2 text-sm rounded-xl font-semibold transition-colors',
                                link.active ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100',
                            ]"
                            v-html="link.label"
                        />
                    </nav>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
