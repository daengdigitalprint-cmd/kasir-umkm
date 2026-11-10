<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportOutletFilter from '@/components/ReportOutletFilter.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CalendarRange, Scissors, Users, Wallet, Receipt } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface BarberRow {
    barber_id: number;
    barber_name: string;
    transaction_count: number;
    gross_revenue: number;
    commission_amount: number;
}

interface CommissionTransaction {
    id: number;
    total_amount: number;
    barber_commission_percent: number | string | null;
    barber_commission_amount: number | string | null;
    created_at: string;
    barber?: { name: string } | null;
}

interface Props {
    store: {
        id: number;
        name: string;
        currency: string;
    };
    accessibleStores?: { id: number; name: string }[];
    selectedStoreId?: number | null;
    isOwner?: boolean;
    dateRange: {
        start_date: string;
        end_date: string;
    };
    summary: {
        total_commission: number;
        transaction_count: number;
        gross_revenue: number;
        barber_count: number;
    };
    rows: BarberRow[];
    transactions: {
        data: CommissionTransaction[];
        links?: { url: string | null; label: string; active: boolean }[];
    };
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

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatTime = (dateString: string) => {
    return new Date(dateString).toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    });
};

const applyFilter = () => {
    router.get(
        '/reports/commissions',
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

const goToPage = (url: string | null) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};
</script>

<template>
    <AdminLayout title="Laporan Komisi Barber">
        <ReportTabs active-tab="komisi" />

        <!-- Filter periode -->
        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div
                    v-if="props.isOwner && props.accessibleStores && props.accessibleStores.length"
                    class="flex items-center gap-3 mb-4 pb-4 border-b border-slate-100"
                >
                    <div class="p-2 bg-primary/10 rounded-xl shrink-0">
                        <Scissors class="h-4 w-4 text-primary" />
                    </div>
                    <span class="text-sm font-bold text-slate-700 shrink-0">Lihat data outlet:</span>
                    <ReportOutletFilter
                        :stores="props.accessibleStores"
                        :selected-id="props.selectedStoreId ?? null"
                    />
                </div>

                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex items-center gap-2 text-primary">
                        <CalendarRange class="h-5 w-5 shrink-0" />
                        <div>
                            <p class="text-sm font-black text-slate-900">Filter periode</p>
                            <p class="text-xs text-slate-500">Hanya transaksi selesai (void tidak dihitung)</p>
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
                        <Button type="button" variant="outline" size="sm" @click="presetThisMonth">Bulan ini</Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Summary KPI -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-violet-100 rounded-2xl mb-3">
                        <Wallet class="h-5 w-5 text-violet-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Total komisi periode ini</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(props.summary.total_commission) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-primary/10 rounded-2xl mb-3">
                        <Scissors class="h-5 w-5 text-primary" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Transaksi dengan barber</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ props.summary.transaction_count }}
                    </p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-emerald-50 rounded-2xl mb-3">
                        <Receipt class="h-5 w-5 text-emerald-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Omzet dari transaksi barber</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ formatCurrency(props.summary.gross_revenue) }}
                    </p>
                </CardContent>
            </Card>

            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 md:p-5">
                    <div class="p-2.5 bg-sky-50 rounded-2xl mb-3">
                        <Users class="h-5 w-5 text-sky-600" />
                    </div>
                    <p class="text-xs font-medium text-slate-500">Barber terlibat</p>
                    <p class="text-lg md:text-2xl font-black text-slate-900 tabular-nums">
                        {{ props.summary.barber_count }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Rekap per barber -->
        <Card class="border-0 shadow-lg mb-6">
            <CardHeader>
                <CardTitle class="text-lg font-black">Rekap komisi per barber</CardTitle>
                <CardDescription>
                    Tarif dihitung dari pengaturan global toko, dapat di-override per layanan.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="props.rows.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                                <th class="py-2 pr-4 font-semibold">Barber</th>
                                <th class="py-2 pr-4 font-semibold text-right">Transaksi</th>
                                <th class="py-2 pr-4 font-semibold text-right">Omzet</th>
                                <th class="py-2 font-semibold text-right">Komisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in props.rows"
                                :key="row.barber_id"
                                class="border-b border-slate-100 last:border-0"
                            >
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 bg-violet-100 rounded-full flex items-center justify-center shrink-0">
                                            <Scissors class="h-4 w-4 text-violet-600" />
                                        </div>
                                        <span class="font-semibold text-slate-900">{{ row.barber_name }}</span>
                                    </div>
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ row.transaction_count }}</td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ formatCurrency(row.gross_revenue) }}</td>
                                <td class="py-3 text-right tabular-nums font-black text-violet-700">
                                    {{ formatCurrency(row.commission_amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="py-12 text-center text-slate-500">
                    <Scissors class="h-10 w-10 mx-auto mb-3 text-slate-300" />
                    <p class="font-medium">Belum ada transaksi dengan barber di periode ini</p>
                    <p class="text-sm mt-1">Tugaskan barber saat pembayaran di POS untuk mulai menghitung komisi.</p>
                </div>
            </CardContent>
        </Card>

        <!-- Detail transaksi -->
        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle class="text-lg font-black">Transaksi dengan barber</CardTitle>
                <CardDescription>15 transaksi terakhir dalam periode</CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="props.transactions.data.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                                <th class="py-2 pr-4 font-semibold">#</th>
                                <th class="py-2 pr-4 font-semibold">Tanggal</th>
                                <th class="py-2 pr-4 font-semibold">Barber</th>
                                <th class="py-2 pr-4 font-semibold text-right">Total</th>
                                <th class="py-2 pr-4 font-semibold text-right">Komisi %</th>
                                <th class="py-2 font-semibold text-right">Komisi (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tx in props.transactions.data"
                                :key="tx.id"
                                class="border-b border-slate-100 last:border-0"
                            >
                                <td class="py-3 pr-4 text-slate-500">#{{ tx.id }}</td>
                                <td class="py-3 pr-4">
                                    {{ formatDate(tx.created_at) }}
                                    <span class="text-xs text-slate-400">{{ formatTime(tx.created_at) }}</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <Badge variant="secondary" class="text-[11px]">
                                        {{ tx.barber?.name || 'Barber (nonaktif)' }}
                                    </Badge>
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ formatCurrency(tx.total_amount) }}</td>
                                <td class="py-3 pr-4 text-right tabular-nums">
                                    {{ tx.barber_commission_percent !== null ? Number(tx.barber_commission_percent) + '%' : '—' }}
                                </td>
                                <td class="py-3 text-right tabular-nums font-semibold text-violet-700">
                                    {{ tx.barber_commission_amount !== null ? formatCurrency(Number(tx.barber_commission_amount)) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="py-12 text-center text-slate-500">
                    <Receipt class="h-10 w-10 mx-auto mb-3 text-slate-300" />
                    <p class="font-medium">Belum ada transaksi di periode ini</p>
                </div>

                <div
                    v-if="props.transactions.links && props.transactions.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 mt-4"
                >
                    <Button
                        v-for="(link, index) in props.transactions.links"
                        :key="index"
                        variant="outline"
                        size="sm"
                        :disabled="!link.url"
                        :class="link.active ? 'bg-slate-900 text-white border-slate-900' : ''"
                        @click="goToPage(link.url)"
                        v-html="link.label"
                    />
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
