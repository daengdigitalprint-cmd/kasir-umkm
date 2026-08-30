<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Wallet } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import FinancialReportStatusBadge from '@/components/FinancialReportStatusBadge.vue';
import ReportDocumentHeader from '@/components/ReportDocumentHeader.vue';

type Bucket = { inflow: number; outflow: number; net: number };

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    startDate: string;
    endDate: string;
    operating: Bucket;
    investing: Bucket;
    financing: Bucket;
    netIncrease: number;
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
        '/reports/financial/cash-flow',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Arus Kas Akuntansi">
        <Head title="Arus Kas Akuntansi" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="arus-kas-akuntansi" />
        </div>

        <div class="flex justify-end mb-4">
            <FinancialReportStatusBadge
                report-type="arus_kas"
                :period-end="filterEnd"
                :report-status="reportStatus"
            />
        </div>

        <ReportDocumentHeader report-type="arus_kas" :period-end="filterEnd" :report-status="reportStatus" />

        <p class="text-sm text-slate-600 mb-4">
            Ringkasan arus kas berbasis jurnal (metode langsung): mutasi akun Kas dan Bank dikelompokkan ke aktivitas
            operasi, investasi, dan pendanaan.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <Wallet class="h-5 w-5 text-primary" />
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
                        <Label for="cf-start" class="text-xs">Dari</Label>
                        <Input id="cf-start" v-model="filterStart" type="date" class="w-40" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="cf-end" class="text-xs">Sampai</Label>
                        <Input id="cf-end" v-model="filterEnd" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardContent class="p-6 space-y-5">
                <div v-for="(section, key) in { operating, investing, financing }" :key="key" class="space-y-2">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-1">
                        <span class="font-semibold text-slate-800">
                            {{
                                key === 'operating'
                                    ? 'Aktivitas Operasi'
                                    : key === 'investing'
                                      ? 'Aktivitas Investasi'
                                      : 'Aktivitas Pendanaan'
                            }}
                        </span>
                        <span class="font-semibold tabular-nums" :class="section.net >= 0 ? 'text-emerald-700' : 'text-red-700'">
                            {{ formatCurrency(section.net) }}
                        </span>
                    </div>
                    <div class="flex justify-between text-sm text-slate-600">
                        <span class="pl-4">Arus kas masuk</span>
                        <span class="tabular-nums text-emerald-700">{{ formatCurrency(section.inflow) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-slate-600">
                        <span class="pl-4">Arus kas keluar</span>
                        <span class="tabular-nums text-red-700">({{ formatCurrency(section.outflow) }})</span>
                    </div>
                </div>

                <div class="flex justify-between border-t-2 border-slate-300 pt-3 text-base font-black">
                    <span>Kenaikan (penurunan) bersih kas</span>
                    <span class="tabular-nums" :class="netIncrease >= 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ formatCurrency(netIncrease) }}
                    </span>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
