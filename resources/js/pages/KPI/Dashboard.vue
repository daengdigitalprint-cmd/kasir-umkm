<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { BarChart3, TrendingUp, TrendingDown, Minus, ShoppingCart, DollarSign, Activity, AlertTriangle, Package, Crown, Percent, Users, UserPlus, Settings2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface StoreItem {
    id: number;
    name: string;
}

interface DailyTrend {
    date: string;
    revenue: number;
}

interface KpiData {
    revenue: number;
    transactions: number;
    avg_transaction_value: number;
    gross_margin_percentage: number;
    active_customers: number;
    new_customers: number;
    growth_rate: number | null;
    daily_trend: DailyTrend[];
}interface Comparison {
    target_revenue: number;
    target_transactions: number;
    growth_percentage: number;
    revenue_progress: number;
    transactions_progress: number;
    status: 'on-track' | 'behind' | 'exceeded';
}

interface Props {
    stores: StoreItem[];
    currentStore: { id: number; name: string };
    period: string;
    kpi: KpiData;
    comparison: Comparison;
}

const props = defineProps<Props>();

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(value);
};

const formatNumber = (value: number) => {
    return new Intl.NumberFormat('id-ID').format(value);
};

const statusColor = computed(() => {
    return {
        'exceeded': 'text-emerald-600',
        'on-track': 'text-blue-600',
        'behind': 'text-red-600',
    }[props.comparison.status] ?? 'text-slate-600';
});

const statusLabel = computed(() => {
    return {
        'exceeded': 'Target Terlampaui',
        'on-track': 'On Track',
        'behind': 'Tertinggal',
    }[props.comparison.status] ?? '—';
});

const maxDailyRevenue = computed(() => {
    return Math.max(...props.kpi.daily_trend.map(d => d.revenue), 1);
});

const switchStore = (storeId: string) => {
    if (!storeId) return;
    router.get('/kpi', { store_id: storeId, period: props.period }, { preserveState: true, replace: true });
};

const switchPeriod = (newPeriod: string) => {
    router.get('/kpi', { store_id: props.currentStore.id, period: newPeriod }, { preserveState: true, replace: true });
};

const prevPeriod = computed(() => {
    const [y, m] = props.period.split('-').map(Number);
    const d = new Date(y, m - 2, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
});

const nextPeriod = computed(() => {
    const [y, m] = props.period.split('-').map(Number);
    const d = new Date(y, m, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
});

const periodLabel = computed(() => {
    const [y, m] = props.period.split('-').map(Number);
    const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return `${monthNames[m - 1]} ${y}`;
});

const isCurrentPeriod = computed(() => {
    return props.period === new Date().toISOString().slice(0, 7);
});

// Modal Setting Target
const showSettingsModal = ref(false);

const form = useForm({
    store_id: props.currentStore?.id || '',
    growth_percentage: props.comparison?.growth_percentage ?? 10,
});

const openSettings = () => {
    form.growth_percentage = props.comparison?.growth_percentage ?? 10;
    showSettingsModal.value = true;
};

const saveSettings = () => {
    form.post('/kpi/settings', {
        preserveScroll: true,
        onSuccess: () => {
            showSettingsModal.value = false;
        },
    });
};
</script>

<template>
    <Head title="KPI Dashboard" />

    <AdminLayout title="KPI Dashboard">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">KPI Dashboard</h1>
                    <p class="text-sm text-slate-600 mt-1">Pantau kinerja outlet Anda secara real-time</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Filter Outlet -->
                    <select
                        v-if="stores.length > 1"
                        :value="currentStore.id"
                        @change="switchStore(($event.target as HTMLSelectElement).value)"
                        class="rounded-xl border border-slate-200 px-3 py-2 text-sm bg-white"
                    >
                        <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <span v-else class="text-sm font-semibold text-slate-700 bg-slate-100 px-3 py-2 rounded-xl">
                        {{ currentStore.name }}
                    </span>
                </div>
            </div>

            <!-- Period Selector -->
            <div class="flex items-center justify-center gap-4">
                <Button size="sm" variant="outline" class="rounded-xl px-3" @click="switchPeriod(prevPeriod)">
                    &larr;
                </Button>
                <span class="text-lg font-bold text-slate-800 min-w-[180px] text-center">
                    {{ periodLabel }}
                </span>
                <Button
                    size="sm"
                    variant="outline"
                    class="rounded-xl px-3"
                    :disabled="isCurrentPeriod"
                    @click="switchPeriod(nextPeriod)"
                >
                    &rarr;
                </Button>
            </div>

            <!-- Status Card -->
            <Card class="rounded-2xl shadow-sm border-slate-100">
                <CardContent class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-slate-600">Status Target</p>
                            <p :class="['text-2xl font-black', statusColor]">{{ statusLabel }}</p>
                        </div>
                        <div v-if="comparison.status === 'exceeded'" class="p-3 bg-emerald-50 rounded-2xl">
                            <TrendingUp class="h-8 w-8 text-emerald-600" />
                        </div>
                        <div v-else-if="comparison.status === 'on-track'" class="p-3 bg-blue-50 rounded-2xl">
                            <Activity class="h-8 w-8 text-blue-600" />
                        </div>
                        <div v-else class="p-3 bg-red-50 rounded-2xl">
                            <TrendingDown class="h-8 w-8 text-red-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Metrics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Omset -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="p-2 bg-green-50 rounded-xl">
                                <DollarSign class="h-5 w-5 text-green-600" />
                            </div>
                            <span class="text-sm text-slate-600">Omset Bulan Ini</span>
                        </div>
                        <p class="text-xl font-black text-slate-900">{{ formatCurrency(kpi.revenue) }}</p>
                        <div class="mt-3">
                            <div class="w-full bg-slate-100 rounded-full h-2">
                                <div
                                    class="bg-green-500 h-2 rounded-full transition-all duration-500"
                                    :style="{ width: Math.min(comparison.revenue_progress, 100) + '%' }"
                                ></div>
                            </div>
                            <div class="flex justify-between mt-1">
                                <span class="text-xs text-slate-500">{{ comparison.revenue_progress }}% dari target</span>
                                <span class="text-xs text-slate-400">{{ formatCurrency(comparison.target_revenue) }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Transaksi -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="p-2 bg-blue-50 rounded-xl">
                                <ShoppingCart class="h-5 w-5 text-blue-600" />
                            </div>
                            <span class="text-sm text-slate-600">Jumlah Transaksi</span>
                        </div>
                        <p class="text-xl font-black text-slate-900">{{ formatNumber(kpi.transactions) }}</p>
                        <div class="mt-3">
                            <div class="w-full bg-slate-100 rounded-full h-2">
                                <div
                                    class="bg-blue-500 h-2 rounded-full transition-all duration-500"
                                    :style="{ width: Math.min(comparison.transactions_progress, 100) + '%' }"
                                ></div>
                            </div>
                            <div class="flex justify-between mt-1">
                                <span class="text-xs text-slate-500">{{ comparison.transactions_progress }}% dari target</span>
                                <span class="text-xs text-slate-400">{{ formatNumber(comparison.target_transactions) }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Rata-rata Transaksi -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="p-2 bg-purple-50 rounded-xl">
                                <BarChart3 class="h-5 w-5 text-purple-600" />
                            </div>
                            <span class="text-sm text-slate-600">Rata-rata/Transaksi</span>
                        </div>
                        <p class="text-xl font-black text-slate-900">{{ formatCurrency(kpi.avg_transaction_value) }}</p>
                        <p class="text-xs text-slate-400 mt-3">ATV (Average Transaction Value)</p>
                    </CardContent>
                </Card>

                <!-- Growth Rate -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div
                                :class="[
                                    'p-2 rounded-xl',
                                    kpi.growth_rate !== null && kpi.growth_rate >= 0 ? 'bg-emerald-50' : 'bg-red-50',
                                ]"
                            >
                                <TrendingUp
                                    :class="[
                                        'h-5 w-5',
                                        kpi.growth_rate !== null && kpi.growth_rate >= 0 ? 'text-emerald-600' : 'text-red-600',
                                    ]"
                                />
                            </div>
                            <span class="text-sm text-slate-600">Growth vs Bulan Lalu</span>
                        </div>
                        <p
                            :class="[
                                'text-xl font-black',
                                kpi.growth_rate !== null && kpi.growth_rate >= 0 ? 'text-emerald-600' : 'text-red-600',
                            ]"
                        >
                            {{ kpi.growth_rate !== null ? (kpi.growth_rate >= 0 ? '+' : '') + kpi.growth_rate + '%' : '—' }}
                        </p>
                        <p class="text-xs text-slate-400 mt-3">Target growth: +{{ comparison.growth_percentage }}%</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Metrics Cards Row 2 (Customer) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Pelanggan Aktif -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="p-2 bg-indigo-50 rounded-xl">
                                        <Users class="h-5 w-5 text-indigo-600" />
                                    </div>
                                    <span class="text-sm text-slate-600">Pelanggan Aktif</span>
                                </div>
                                <p class="text-xl font-black text-slate-900">{{ formatNumber(kpi.active_customers) }}</p>
                                <p class="text-xs text-slate-400 mt-1">Yang bertransaksi bulan ini</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Pelanggan Baru -->
                <Card class="rounded-2xl shadow-sm">
                    <CardContent class="p-5">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="p-2 bg-cyan-50 rounded-xl">
                                        <UserPlus class="h-5 w-5 text-cyan-600" />
                                    </div>
                                    <span class="text-sm text-slate-600">Pelanggan Baru</span>
                                </div>
                                <p class="text-xl font-black text-slate-900">{{ formatNumber(kpi.new_customers) }}</p>
                                <p class="text-xs text-slate-400 mt-1">Yang mendaftar bulan ini</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Chart Harian -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Trend Omset Harian (Lebar 2 kolom) -->
                <Card class="rounded-2xl shadow-sm lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-lg">Trend Omset Harian</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="relative" style="height: 280px;">
                            <!-- Bar Chart sederhana -->
                            <div class="flex items-end gap-0.5 h-[230px] border-b border-slate-100 pb-1">
                                <div
                                    v-for="day in kpi.daily_trend"
                                    :key="day.date"
                                    class="flex-1 group relative"
                                >
                                    <div
                                        class="w-full bg-blue-400 hover:bg-blue-500 rounded-t transition-all duration-200 min-h-[1px]"
                                        :style="{ height: day.revenue > 0 ? Math.max((day.revenue / maxDailyRevenue) * 100, 2) + '%' : '0%' }"
                                    ></div>
                                    <!-- Tooltip -->
                                    <div class="hidden group-hover:block absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-10">
                                        <div class="bg-slate-800 text-white text-xs px-2 py-1 rounded-lg whitespace-nowrap">
                                            <div>{{ new Date(day.date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) }}</div>
                                            <div class="font-bold">{{ formatCurrency(day.revenue) }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Label -->
                            <div class="flex justify-between mt-2 text-xs text-slate-400">
                                <span>{{ new Date(kpi.daily_trend[0]?.date || '').toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) }}</span>
                                <span>{{ new Date(kpi.daily_trend[kpi.daily_trend.length - 1]?.date || '').toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Operational Alerts (Kolom ke-3) -->
                <Card class="rounded-2xl shadow-sm">
                    <CardHeader>
                        <CardTitle class="text-lg">Perlu Perhatian</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <!-- Low Stock -->
                            <div v-if="alerts.low_stock_count > 0" class="flex items-start gap-3 p-3 bg-orange-50 rounded-xl">
                                <AlertTriangle class="h-5 w-5 text-orange-600 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-sm font-medium text-orange-800">
                                        {{ alerts.low_stock_count }} produk stok menipis
                                    </p>
                                    <p class="text-xs text-orange-600 mt-1">
                                        Segera lakukan re-stock agar penjualan tidak terganggu.
                                    </p>
                                </div>
                            </div>

                            <!-- Slow Moving -->
                            <div v-if="alerts.slow_moving_count > 0" class="flex items-start gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100">
                                <Package class="h-5 w-5 text-slate-500 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-sm font-medium text-slate-700">
                                        {{ alerts.slow_moving_count }} produk lambat terjual
                                    </p>
                                    <p class="text-xs text-slate-500 mt-1">
                                        Tidak ada atau minim penjualan dalam 30 hari terakhir.
                                    </p>
                                </div>
                            </div>

                            <div v-if="alerts.low_stock_count === 0 && alerts.slow_moving_count === 0" class="text-center py-8">
                                <div class="bg-green-50 rounded-full h-12 w-12 flex items-center justify-center mx-auto mb-3">
                                    <Activity class="h-6 w-6 text-green-500" />
                                </div>
                                <p class="text-sm font-medium text-slate-700">Semua operasional lancar</p>
                                <p class="text-xs text-slate-500">Tidak ada isu stok atau produk.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Top Products -->
            <Card class="rounded-2xl shadow-sm mt-6">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="p-2 bg-yellow-50 rounded-lg">
                            <Crown class="h-5 w-5 text-yellow-600" />
                        </div>
                        <CardTitle class="text-lg">Top 10 Produk Terlaris</CardTitle>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium rounded-l-lg">Peringkat</th>
                                    <th class="px-4 py-3 text-left font-medium">Nama Produk</th>
                                    <th class="px-4 py-3 text-right font-medium">Terjual (Qty)</th>
                                    <th class="px-4 py-3 text-right font-medium rounded-r-lg">Total Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(product, idx) in topProducts" :key="product.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                                            {{ idx + 1 }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-900">{{ product.name }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ formatNumber(product.quantity) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-800">{{ formatCurrency(product.revenue) }}</td>
                                </tr>
                                <tr v-if="topProducts.length === 0">
                                    <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                        Tidak ada data penjualan di periode ini.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Modal Setting Target -->
        <Dialog :open="showSettingsModal" @update:open="showSettingsModal = $event">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Atur Target Pertumbuhan</DialogTitle>
                    <DialogDescription>
                        Tentukan target pertumbuhan (growth) omset dan transaksi untuk bulan berjalan.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid grid-cols-4 items-center gap-4">
                        <Label for="growth" class="text-right">
                            Target Growth (%)
                        </Label>
                        <Input
                            id="growth"
                            type="number"
                            step="0.1"
                            min="0"
                            max="1000"
                            v-model="form.growth_percentage"
                            class="col-span-3"
                        />
                    </div>
                    <div v-if="form.errors.growth_percentage" class="text-xs text-red-500 text-right pr-4">
                        {{ form.errors.growth_percentage }}
                    </div>
                    <p class="text-xs text-slate-500 mt-2 px-2">
                        Target periode ini akan diperbarui berdasarkan performa bulan sebelumnya ditambah persentase pertumbuhan ini.
                    </p>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="showSettingsModal = false" type="button">
                        Batal
                    </Button>
                    <Button type="button" @click="saveSettings" :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan...' : 'Simpan Target' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
