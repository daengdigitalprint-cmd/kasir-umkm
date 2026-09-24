<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BarChart3 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import FinancialReportStatusBadge from '@/components/FinancialReportStatusBadge.vue';
import ReportDocumentHeader from '@/components/ReportDocumentHeader.vue';

type Rows = {
    opening_modal: number;
    opening_saldo_laba: number;
    opening_total: number;
    setoran_modal: number;
    penarikan_modal: number;
    laba_bersih: number;
    closing_modal: number;
    closing_saldo_laba: number;
    closing_total: number;
};

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    startDate: string;
    endDate: string;
    rows: Rows;
    summary: { modal: number; saldo_laba: number; total: number };
    reportStatus: {
        status: 'draft' | 'final' | 'closed';
        label: string;
        finalized_at: string | null;
        finalized_by: string | null;
        report_number: string | null;
        issued_at: string | null;
        prepared_by: string | null;
        reviewed_by: string | null;
    };
}>();

const filterStart = ref(props.startDate);
const filterEnd = ref(props.endDate);

watch(
    () => props.startDate,
    (v) => {
        filterStart.value = v;
    },
);
watch(
    () => props.endDate,
    (v) => {
        filterEnd.value = v;
    },
);

const applyFilter = () => {
    router.get(
        '/financial/statement-of-equity-changes',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Perubahan Ekuitas">
        <Head title="Perubahan Ekuitas" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="perubahan-ekuitas" />
        </div>

        <div class="flex justify-end mb-4">
            <FinancialReportStatusBadge
                report-type="perubahan_ekuitas"
                :period-end="filterEnd"
                :report-status="reportStatus"
            />
        </div>

        <ReportDocumentHeader report-type="perubahan_ekuitas" :period-end="filterEnd" :report-status="reportStatus" />

        <p class="text-sm text-slate-600 mb-4">
            Ringkasan perubahan ekuitas pemilik dari jurnal: modal awal, setoran/penarikan modal, laba bersih, hingga
            saldo akhir.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <BarChart3 class="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <CardTitle>Periode</CardTitle>
                        <CardDescription>{{ store.name }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label for="soe-start" class="text-xs">Dari</Label>
                        <Input id="soe-start" v-model="filterStart" type="date" class="w-40" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="soe-end" class="text-xs">Sampai</Label>
                        <Input id="soe-end" v-model="filterEnd" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
            <CardContent class="p-0">
                <div class="overflow-x-auto p-6">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="border-b border-slate-200 text-left text-xs font-semibold uppercase text-slate-500">
                            <th class="py-2 pr-4">Keterangan</th>
                            <th class="py-2 px-4 text-right">Modal</th>
                            <th class="py-2 px-4 text-right">Saldo Laba</th>
                            <th class="py-2 pl-4 text-right">Total Ekuitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 text-slate-700">Saldo awal</td>
                            <td class="py-2.5 px-4 text-right tabular-nums">{{ formatCurrency(rows.opening_modal) }}</td>
                            <td class="py-2.5 px-4 text-right tabular-nums">{{ formatCurrency(rows.opening_saldo_laba) }}</td>
                            <td class="py-2.5 pl-4 text-right tabular-nums font-semibold">{{ formatCurrency(rows.opening_total) }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 text-slate-700">Setoran modal</td>
                            <td class="py-2.5 px-4 text-right tabular-nums text-emerald-700">+{{ formatCurrency(rows.setoran_modal) }}</td>
                            <td class="py-2.5 px-4 text-right tabular-nums text-slate-300">—</td>
                            <td class="py-2.5 pl-4 text-right tabular-nums">+{{ formatCurrency(rows.setoran_modal) }}</td>
                        </tr>
                        <tr class="border-b border-slate-100">
                            <td class="py-2.5 pr-4 text-slate-700">Penarikan modal</td>
                            <td class="py-2.5 px-4 text-right tabular-nums text-red-700">-{{ formatCurrency(rows.penarikan_modal) }}</td>
                            <td class="py-2.5 px-4 text-right tabular-nums text-slate-300">—</td>
                            <td class="py-2.5 pl-4 text-right tabular-nums">-{{ formatCurrency(rows.penarikan_modal) }}</td>
                        </tr>
                        <tr class="border-b-2 border-slate-200">
                            <td class="py-2.5 pr-4 text-slate-700">Laba bersih periode berjalan</td>
                            <td class="py-2.5 px-4 text-right tabular-nums text-slate-300">—</td>
                            <td class="py-2.5 px-4 text-right tabular-nums" :class="rows.laba_bersih >= 0 ? 'text-emerald-700' : 'text-red-700'">
                                {{ rows.laba_bersih >= 0 ? '+' : '-' }}{{ formatCurrency(Math.abs(rows.laba_bersih)) }}
                            </td>
                            <td class="py-2.5 pl-4 text-right tabular-nums">{{ formatCurrency(rows.laba_bersih) }}</td>
                        </tr>
                        <tr class="text-base font-bold">
                            <td class="py-3 pr-4">Saldo akhir</td>
                            <td class="py-3 px-4 text-right tabular-nums">{{ formatCurrency(rows.closing_modal) }}</td>
                            <td class="py-3 px-4 text-right tabular-nums">{{ formatCurrency(rows.closing_saldo_laba) }}</td>
                            <td class="py-3 pl-4 text-right tabular-nums">{{ formatCurrency(rows.closing_total) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
