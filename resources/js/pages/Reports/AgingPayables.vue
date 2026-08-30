<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AlertTriangle, CheckCircle, Clock } from 'lucide-vue-next';
import { ref } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';

interface AgingBucket {
    label: string;
    total: number;
    count: number;
}

interface PurchaseRow {
    id: number;
    purchase_number: string;
    supplier_name: string;
    purchased_at: string;
    due_date: string;
    total_amount: number;
    amount_paid: number;
    remaining: number;
    status: string;
    aging_days: number | null;
}

const props = defineProps<{
    purchases: PurchaseRow[];
    buckets: Record<string, AgingBucket>;
    summary: {
        total_outstanding: number;
        overdue: number;
        current: number;
        count: number;
    };
    filters: { as_of: string };
}>();

const filterAsOf = ref(props.filters.as_of);

const applyFilter = () => {
    router.get('/reports/aging-payables', { as_of: filterAsOf.value }, { preserveState: true, preserveScroll: true });
};

const formatRp = (n: number) => 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const bucketColor = (key: string) => {
    const colors: Record<string, string> = {
        'not_due': 'bg-emerald-500',
        '0-30': 'bg-amber-500',
        '31-60': 'bg-orange-500',
        '61-90': 'bg-red-500',
        'over_90': 'bg-red-700',
    };
    return colors[key] ?? 'bg-slate-400';
};

const statusColor = (days: number | null, remaining: number) => {
    if (remaining <= 0) return 'bg-emerald-100 text-emerald-800';
    if (days === null) return 'bg-slate-100 text-slate-700';
    if (days > 60) return 'bg-red-100 text-red-800';
    if (days > 30) return 'bg-orange-100 text-orange-800';
    if (days > 0) return 'bg-amber-100 text-amber-800';
    return 'bg-emerald-100 text-emerald-800';
};
</script>

<template>
    <AdminLayout title="Utang Usaha (Aging Report)">
        <Head title="Utang Usaha (Aging Report)" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="aging-payables" />
            <ReportExportButton report="utang-usaha" :params="{ as_of: filterAsOf }" />
        </div>

        <p class="text-sm text-slate-600 mb-4">Laporan umur utang usaha — melihat pembelian mana yang sudah jatuh tempo dan perlu segera dilunasi.</p>

        <!-- Filter -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardContent class="p-4">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label class="text-xs">Per tanggal</Label>
                        <Input v-model="filterAsOf" type="date" class="w-40" />
                    </div>
                    <Button type="submit" variant="outline">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <!-- Aging Buckets -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
            <div v-for="(bucket, key) in props.buckets" :key="key" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div :class="['h-3 w-3 rounded-full', bucketColor(key)]" />
                    <span class="text-xs font-medium text-slate-600">{{ bucket.label }}</span>
                </div>
                <p class="text-lg font-bold text-slate-900">{{ formatRp(bucket.total) }}</p>
                <p class="text-xs text-slate-500">{{ bucket.count }} pembelian</p>
            </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Clock class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total Utang Outstanding</p>
                            <p class="text-lg font-bold text-slate-900">{{ formatRp(summary.total_outstanding) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500/10">
                            <AlertTriangle class="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Sudah Jatuh Tempo</p>
                            <p class="text-lg font-bold text-red-600">{{ formatRp(summary.overdue) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                            <CheckCircle class="h-5 w-5 text-emerald-600" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Belum Jatuh Tempo</p>
                            <p class="text-lg font-bold text-emerald-600">{{ formatRp(summary.current) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Table -->
        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">Rincian Utang Usaha</CardTitle>
                <CardDescription>{{ summary.count }} pembelian belum lunas</CardDescription>
            </CardHeader>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/80">
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Pembelian</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Supplier</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Tanggal</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Jatuh Tempo</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Total</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Dibayar</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Sisa Utang</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in purchases" :key="p.id" class="border-b border-slate-50 hover:bg-slate-50/50">
                                <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ p.purchase_number }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ p.supplier_name }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ p.purchased_at }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ p.due_date }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatRp(p.total_amount) }}</td>
                                <td class="px-4 py-3 text-right text-emerald-600">{{ formatRp(p.amount_paid) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-600">{{ formatRp(p.remaining) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusColor(p.aging_days, p.remaining)]">
                                        {{ p.status }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="purchases.length === 0">
                                <td colspan="8" class="px-4 py-12 text-center text-slate-400">Tidak ada utang outstanding.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
