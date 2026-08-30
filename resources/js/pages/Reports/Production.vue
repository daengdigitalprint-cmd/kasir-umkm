<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Factory } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Product {
    id: number;
    name: string;
}

interface Recipe {
    id: number;
    name: string;
}

interface ProductionBatch {
    id: number;
    batch_number: string;
    status: string;
    multiplier: number;
    output_quantity_initial: number;
    total_cost: number;
    unit_cost: number;
    produced_at: string;
    product?: Product | null;
    recipe?: Recipe | null;
}

interface Paginated {
    data: ProductionBatch[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Summary {
    total_batches: number;
    total_output: number;
    total_cost: number;
    avg_cost_per_unit: number;
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    batches: Paginated;
    summary: Summary;
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
        '/reports/production',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDecimal = (value: number, decimals: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const statusBadge = (status: string) => {
    const map: Record<string, { label: string; class: string }> = {
        draft: { label: 'Draft', class: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
        completed: { label: 'Selesai', class: 'bg-green-100 text-green-800 border-green-200' },
        cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-800 border-red-200' },
    };
    return map[status] || { label: status, class: 'bg-slate-100 text-slate-800 border-slate-200' };
};
</script>

<template>
    <AdminLayout title="Laporan produksi">
        <Head title="Laporan produksi" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="produksi" />
            <ReportExportButton report="produksi" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-end md:flex-wrap">
                    <div class="space-y-2">
                        <Label>Dari</Label>
                        <Input v-model="filterStart" type="date" class="max-w-xs" />
                    </div>
                    <div class="space-y-2">
                        <Label>Sampai</Label>
                        <Input v-model="filterEnd" type="date" class="max-w-xs" />
                    </div>
                    <Button type="button" class="rounded-xl" @click="applyFilter">Terapkan</Button>
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
            <Card class="border-0 shadow-md">
                <CardContent class="p-4">
                    <p class="text-xs font-medium text-slate-500">Total Batch</p>
                    <p class="mt-1 text-2xl font-bold text-slate-800">{{ summary.total_batches }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md">
                <CardContent class="p-4">
                    <p class="text-xs font-medium text-slate-500">Total Output</p>
                    <p class="mt-1 text-2xl font-bold text-slate-800">{{ formatDecimal(summary.total_output, 2) }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md">
                <CardContent class="p-4">
                    <p class="text-xs font-medium text-slate-500">Total Biaya</p>
                    <p class="mt-1 text-2xl font-bold text-slate-800">Rp {{ formatCurrency(summary.total_cost) }}</p>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-md">
                <CardContent class="p-4">
                    <p class="text-xs font-medium text-slate-500">Rata-rata Biaya/Unit</p>
                    <p class="mt-1 text-2xl font-bold text-slate-800">Rp {{ formatDecimal(summary.avg_cost_per_unit, 0) }}</p>
                </CardContent>
            </Card>
        </div>

        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Factory class="h-5 w-5 text-slate-600" />
                    Batch produksi
                </CardTitle>
                <CardDescription>
                    {{ store.name }} — {{ batches.data?.length ?? 0 }} entri di halaman ini
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-4 font-medium">No.</th>
                            <th class="pb-3 pr-4 font-medium">No. Batch</th>
                            <th class="pb-3 pr-4 font-medium">Produk</th>
                            <th class="pb-3 pr-4 font-medium">Resep</th>
                            <th class="pb-3 pr-4 font-medium text-right">Pengali</th>
                            <th class="pb-3 pr-4 font-medium text-right">Qty Output</th>
                            <th class="pb-3 pr-4 font-medium text-right">Total Biaya</th>
                            <th class="pb-3 pr-4 font-medium text-right">Biaya/Unit</th>
                            <th class="pb-3 pr-4 font-medium">Status</th>
                            <th class="pb-3 font-medium">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(b, idx) in batches.data" :key="b.id" class="border-b border-slate-100">
                            <td class="py-3 pr-4 font-mono text-xs text-slate-400">
                                {{ idx + 1 }}
                            </td>
                            <td class="py-3 pr-4 font-mono text-xs">
                                <Link :href="`/productions/${b.id}`" class="hover:underline text-teal-700">
                                    {{ b.batch_number }}
                                </Link>
                            </td>
                            <td class="py-3 pr-4">{{ b.product?.name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ b.recipe?.name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-right">{{ formatDecimal(b.multiplier, 4) }}</td>
                            <td class="py-3 pr-4 text-right">{{ formatDecimal(b.output_quantity_initial, 2) }}</td>
                            <td class="py-3 pr-4 text-right font-semibold">Rp {{ formatCurrency(b.total_cost) }}</td>
                            <td class="py-3 pr-4 text-right">Rp {{ formatDecimal(b.unit_cost, 0) }}</td>
                            <td class="py-3 pr-4">
                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold',
                                        statusBadge(b.status).class,
                                    ]"
                                >
                                    {{ statusBadge(b.status).label }}
                                </span>
                            </td>
                            <td class="py-3 whitespace-nowrap">{{ formatDate(b.produced_at) }}</td>
                        </tr>
                        <tr v-if="!batches.data?.length">
                            <td colspan="10" class="py-10 text-center text-slate-500">Tidak ada batch produksi di periode ini.</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="batches.links && batches.links.length > 3" class="mt-6 flex justify-center">
                    <nav class="flex flex-wrap items-center justify-center gap-1">
                        <Link
                            v-for="link in batches.links"
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
