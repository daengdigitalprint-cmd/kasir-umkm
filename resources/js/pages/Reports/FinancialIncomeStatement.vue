<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TrendingUp } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import FinancialReportStatusBadge from '@/components/FinancialReportStatusBadge.vue';
import ReportDocumentHeader from '@/components/ReportDocumentHeader.vue';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    amounts: {
        revenue: number;
        cogs: number;
        gross_profit: number;
        expense: number;
        operating_income: number;
        income_before_tax: number;
        tax_expense: number;
        net_income: number;
    };
    accounts: Record<string, Array<{ code: string; name: string; type: string; amount: number }>>;
    comparison: {
        prev_start: string;
        prev_end: string;
        prev_revenue: number;
        prev_gross_profit: number;
        prev_net_income: number;
        revenue_change_pct: number | null;
        net_income_change_pct: number | null;
    };
    margins: {
        gross_margin: number | null;
        net_margin: number | null;
    };
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
        '/reports/financial/income-statement',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const formatPct = (n: number | null) => (n === null ? '—' : `${n.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`);
const signPct = (n: number | null) => (n === null ? '' : n > 0 ? '+' : '');
const trendClass = (n: number | null) => (n === null ? 'text-slate-500' : n > 0 ? 'text-emerald-700' : n < 0 ? 'text-red-700' : 'text-slate-500');
</script>

<template>
    <AdminLayout title="Laba rugi">
        <Head title="Laba rugi" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="laba-rugi" />
            <ReportExportButton report="laba-rugi" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <div class="flex justify-end mb-4">
            <FinancialReportStatusBadge
                report-type="laba_rugi"
                :period-end="filterEnd"
                :report-status="reportStatus"
            />
        </div>

        <ReportDocumentHeader report-type="laba_rugi" :period-end="filterEnd" :report-status="reportStatus" />

        <p class="text-sm text-slate-600 mb-4">
            Ringkasan dari jurnal: pendapatan (kredit neto akun tipe revenue), HPP (debit neto akun COGS), beban operasional.
            Bukan pengganti audit SAK; sesuaikan dengan kebijakan akuntansi Anda.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                        <TrendingUp class="h-5 w-5 text-emerald-700" />
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
                        <Label for="is-start" class="text-xs">Dari</Label>
                        <Input id="is-start" v-model="filterStart" type="date" class="w-40" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="is-end" class="text-xs">Sampai</Label>
                        <Input id="is-end" v-model="filterEnd" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardContent class="p-6 space-y-4">
                <div class="space-y-2 border-b border-slate-100 pb-3">
                    <div v-for="account in accounts.revenue ?? []" :key="account.code" class="flex justify-between text-sm text-slate-600">
                        <span>{{ account.code }} · {{ account.name }}</span>
                        <span class="tabular-nums">{{ formatCurrency(account.amount) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-700">Pendapatan (penjualan & tip, DPP)</span>
                        <span class="font-semibold tabular-nums text-emerald-700">{{ formatCurrency(amounts.revenue) }}</span>
                    </div>
                </div>
                <div class="space-y-2 border-b border-slate-100 pb-3">
                    <div v-for="account in accounts.cogs ?? []" :key="account.code" class="flex justify-between text-sm text-slate-600">
                        <span>{{ account.code }} · {{ account.name }}</span>
                        <span class="tabular-nums">{{ formatCurrency(account.amount) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-700">Harga pokok penjualan (HPP)</span>
                        <span class="font-semibold tabular-nums text-red-700">({{ formatCurrency(amounts.cogs) }})</span>
                    </div>
                </div>
                <div class="flex justify-between border-b-2 border-slate-200 pb-3 font-bold">
                    <span>Laba kotor</span>
                    <span class="tabular-nums">{{ formatCurrency(amounts.gross_profit) }}</span>
                </div>
                <div class="space-y-2 border-b border-slate-100 pb-3">
                    <div v-for="account in accounts.expense ?? []" :key="account.code" class="flex justify-between text-sm text-slate-600">
                        <span>{{ account.code }} · {{ account.name }}</span>
                        <span class="tabular-nums">{{ formatCurrency(account.amount) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-700">Beban operasional (tanpa pajak)</span>
                        <span class="font-semibold tabular-nums text-red-700">({{ formatCurrency(amounts.expense) }})</span>
                    </div>
                </div>
                <div class="flex justify-between border-b border-slate-100 pb-3 font-bold">
                    <span>Laba operasional</span>
                    <span class="tabular-nums" :class="amounts.operating_income >= 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ formatCurrency(amounts.operating_income) }}
                    </span>
                </div>
                <div class="flex justify-between border-b border-slate-100 pb-3 font-bold">
                    <span>Laba sebelum pajak</span>
                    <span class="tabular-nums" :class="amounts.income_before_tax >= 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ formatCurrency(amounts.income_before_tax) }}
                    </span>
                </div>
                <div class="flex justify-between border-b border-slate-100 pb-3">
                    <span class="text-slate-700">Beban pajak</span>
                    <span class="font-semibold tabular-nums text-red-700">({{ formatCurrency(amounts.tax_expense) }})</span>
                </div>
                <div class="flex justify-between pt-1 text-lg font-black">
                    <span>Laba bersih</span>
                    <span class="tabular-nums" :class="amounts.net_income >= 0 ? 'text-emerald-700' : 'text-red-700'">
                        {{ formatCurrency(amounts.net_income) }}
                    </span>
                </div>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader class="pb-2">
                    <CardTitle>Margin</CardTitle>
                    <CardDescription>Rasio terhadap pendapatan bersih</CardDescription>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Margin laba kotor</span>
                        <span class="font-semibold tabular-nums">{{ formatPct(margins.gross_margin) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Margin laba bersih</span>
                        <span class="font-semibold tabular-nums">{{ formatPct(margins.net_margin) }}</span>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader class="pb-2">
                    <CardTitle>Perbandingan periode sebelumnya</CardTitle>
                    <CardDescription>{{ comparison.prev_start }} s.d. {{ comparison.prev_end }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Pendapatan</span>
                        <span class="tabular-nums">
                            <span class="font-semibold">{{ formatCurrency(comparison.prev_revenue) }}</span>
                            <span class="ml-2" :class="trendClass(comparison.revenue_change_pct)">{{ signPct(comparison.revenue_change_pct) }}{{ formatPct(comparison.revenue_change_pct) }}</span>
                        </span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Laba kotor</span>
                        <span class="font-semibold tabular-nums">{{ formatCurrency(comparison.prev_gross_profit) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">Laba bersih</span>
                        <span class="tabular-nums">
                            <span class="font-semibold">{{ formatCurrency(comparison.prev_net_income) }}</span>
                            <span class="ml-2" :class="trendClass(comparison.net_income_change_pct)">{{ signPct(comparison.net_income_change_pct) }}{{ formatPct(comparison.net_income_change_pct) }}</span>
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
