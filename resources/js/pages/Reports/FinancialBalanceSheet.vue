<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Scale } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import FinancialReportStatusBadge from '@/components/FinancialReportStatusBadge.vue';
import ReportDocumentHeader from '@/components/ReportDocumentHeader.vue';

type Row = { code: string; name: string; balance: number };
type Group = { label: string; rows: Row[]; subtotal: number };

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    asOf: string;
    assets: Record<string, Group>;
    liabilities: Record<string, Group>;
    equity: { rows: Row[]; subtotal: number };
    totals: {
        assets: number;
        liabilities: number;
        equity: number;
        liabilities_equity: number;
        balanced: boolean;
        difference: number;
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

const filterAsOf = ref(props.asOf);

watch(
    () => props.asOf,
    (v) => {
        filterAsOf.value = v;
    },
);

const applyFilter = () => {
    router.get(
        '/reports/financial/balance-sheet',
        { as_of: filterAsOf.value },
        { preserveState: true, preserveScroll: true },
    );
};

const capitalForm = useForm({
    amount: '',
    entry_date: props.asOf,
    note: '',
});

const submitCapital = () => {
    capitalForm.post('/reports/financial/capital-injection', {
        preserveScroll: true,
        onSuccess: () => capitalForm.reset('amount', 'note'),
    });
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Neraca">
        <Head title="Neraca" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="neraca" />
            <ReportExportButton report="neraca" :params="{ as_of: filterAsOf }" />
        </div>

        <div class="flex justify-end mb-4">
            <FinancialReportStatusBadge
                report-type="neraca"
                :period-end="filterAsOf"
                :report-status="reportStatus"
            />
        </div>

        <ReportDocumentHeader report-type="neraca" :period-end="filterAsOf" :report-status="reportStatus" />

        <p class="text-sm text-slate-600 mb-4">
            Laporan posisi keuangan (saldo kumulatif s.d. tanggal terpilih) dari jurnal double entry. Laba (rugi)
            berjalan dihitung otomatis dari pendapatan, HPP, dan beban. Bukan pengganti audit SAK.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <Scale class="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <CardTitle>Per tanggal</CardTitle>
                        <CardDescription>{{ store.name }} · {{ store.currency }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label for="bs-asof" class="text-xs">Per tanggal</Label>
                        <Input id="bs-asof" v-model="filterAsOf" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <div
            :class="[
                'mb-6 rounded-xl px-4 py-3 text-sm font-medium',
                totals.balanced
                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                    : 'bg-amber-50 text-amber-700 border border-amber-200',
            ]"
        >
            <template v-if="totals.balanced">
                Neraca seimbang: total aset = total kewajiban + ekuitas ({{ formatCurrency(totals.assets) }}).
            </template>
            <template v-else>
                Neraca belum seimbang. Selisih {{ formatCurrency(totals.difference) }}. Kemungkinan modal awal belum
                dicatat atau ada transaksi lama tanpa jurnal. Catat setoran modal di bawah.
            </template>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Aset</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <table class="w-full text-sm">
                        <tbody>
                            <template v-for="(group, key) in assets" :key="key">
                                <tr class="bg-slate-50/60"><td colspan="3" class="px-4 py-2 text-xs font-semibold uppercase text-slate-500">{{ group.label }}</td></tr>
                                <tr v-for="row in group.rows" :key="row.code" class="border-b border-slate-100">
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ row.code }}</td>
                                    <td class="px-4 py-2">{{ row.name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.balance) }}</td>
                                </tr>
                                <tr class="border-b border-slate-100 font-medium"><td colspan="2" class="px-4 py-2">Total {{ group.label }}</td><td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(group.subtotal) }}</td></tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-semibold">
                                <td colspan="2" class="px-4 py-3">Total aset</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatCurrency(totals.assets) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Kewajiban &amp; Ekuitas</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <table class="w-full text-sm">
                        <tbody>
                            <template v-for="(group, key) in liabilities" :key="key">
                                <tr class="bg-slate-50/60"><td colspan="3" class="px-4 py-2 text-xs font-semibold uppercase text-slate-500">{{ group.label }}</td></tr>
                                <tr v-for="row in group.rows" :key="row.code" class="border-b border-slate-100">
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ row.code }}</td>
                                    <td class="px-4 py-2">{{ row.name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.balance) }}</td>
                                </tr>
                                <tr class="border-b border-slate-100 font-medium"><td colspan="2" class="px-4 py-2">Total {{ group.label }}</td><td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(group.subtotal) }}</td></tr>
                            </template>
                            <tr class="border-b border-slate-100 font-medium">
                                <td colspan="2" class="px-4 py-2">Total kewajiban</td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ formatCurrency(totals.liabilities) }}
                                </td>
                            </tr>
                            <tr class="bg-slate-50/60">
                                <td colspan="3" class="px-4 py-2 text-xs font-semibold uppercase text-slate-500">
                                    Ekuitas
                                </td>
                            </tr>
                            <tr v-for="row in equity.rows" :key="row.code" class="border-b border-slate-100">
                                <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ row.code }}</td>
                                <td class="px-4 py-2">{{ row.name }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.balance) }}</td>
                            </tr>
                            <tr class="border-b border-slate-100 font-medium">
                                <td colspan="2" class="px-4 py-2">Total ekuitas</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(totals.equity) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-semibold">
                                <td colspan="2" class="px-4 py-3">Total kewajiban + ekuitas</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatCurrency(totals.liabilities_equity) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-slate-200 shadow-sm mt-6">
            <CardHeader class="pb-2">
                <CardTitle class="text-base">Catat setoran modal</CardTitle>
                <CardDescription>
                    Debit Kas, Kredit Modal pemilik. Gunakan untuk mencatat modal awal agar neraca seimbang.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitCapital">
                    <div class="space-y-1.5">
                        <Label for="cap-amount" class="text-xs">Jumlah</Label>
                        <Input
                            id="cap-amount"
                            v-model="capitalForm.amount"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0"
                            class="w-48"
                            required
                        />
                        <div v-if="capitalForm.errors.amount" class="text-xs text-red-600">
                            {{ capitalForm.errors.amount }}
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="cap-date" class="text-xs">Tanggal</Label>
                        <Input id="cap-date" v-model="capitalForm.entry_date" type="date" class="w-40" required />
                        <div v-if="capitalForm.errors.entry_date" class="text-xs text-red-600">
                            {{ capitalForm.errors.entry_date }}
                        </div>
                    </div>
                    <div class="space-y-1.5 flex-1 min-w-[12rem]">
                        <Label for="cap-note" class="text-xs">Catatan (opsional)</Label>
                        <Input
                            id="cap-note"
                            v-model="capitalForm.note"
                            type="text"
                            placeholder="Setoran modal awal"
                            class="w-full"
                        />
                    </div>
                    <Button type="submit" :disabled="capitalForm.processing">Simpan</Button>
                </form>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
