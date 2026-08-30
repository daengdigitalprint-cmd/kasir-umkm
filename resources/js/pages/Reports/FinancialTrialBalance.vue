<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BookMarked } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    lines: Array<{
        code: string;
        name: string;
        type: string;
        opening_balance: number;
        opening_balance_side: string;
        debit: number;
        credit: number;
        closing_balance: number;
        closing_balance_side: string;
    }>;
    totals: { debit: number; credit: number };
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
        '/reports/financial/trial-balance',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Neraca saldo">
        <Head title="Neraca saldo" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="neraca-saldo" />
            <ReportExportButton report="neraca-saldo" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <p class="text-sm text-slate-600 mb-4">
            Mutasi jurnal per akun (double entry dari penjualan & pembelian). Transaksi lama sebelum fitur ini tidak
            memiliki jurnal otomatis.
        </p>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                        <BookMarked class="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <CardTitle>Periode</CardTitle>
                        <CardDescription>{{ store.name }} · {{ store.currency }}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label for="tb-start" class="text-xs">Dari</Label>
                        <Input id="tb-start" v-model="filterStart" type="date" class="w-40" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="tb-end" class="text-xs">Sampai</Label>
                        <Input id="tb-end" v-model="filterEnd" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-slate-600">
                                <th class="px-4 py-3 font-semibold">Kode</th>
                                <th class="px-4 py-3 font-semibold">Nama akun</th>
                                <th class="px-4 py-3 font-semibold">Tipe</th>
                                <th class="px-4 py-3 font-semibold text-right">Saldo awal</th>
                                <th class="px-4 py-3 font-semibold">Sisi</th>
                                <th class="px-4 py-3 font-semibold text-right">Debit</th>
                                <th class="px-4 py-3 font-semibold text-right">Kredit</th>
                                <th class="px-4 py-3 font-semibold text-right">Saldo akhir</th>
                                <th class="px-4 py-3 font-semibold">Sisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in lines" :key="row.code" class="border-b border-slate-100">
                                <td class="px-4 py-2 font-mono text-xs">{{ row.code }}</td>
                                <td class="px-4 py-2">{{ row.name }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ row.type }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.opening_balance) }}</td>
                                <td class="px-4 py-2 capitalize text-slate-600">{{ row.opening_balance_side }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.debit) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ formatCurrency(row.credit) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium">
                                    {{ formatCurrency(row.closing_balance) }}
                                </td>
                                <td class="px-4 py-2 capitalize text-slate-600">{{ row.closing_balance_side }}</td>
                            </tr>
                            <tr v-if="lines.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-slate-500">
                                    Belum ada jurnal di periode ini. Lakukan penjualan (POS) atau catat pembelian dengan
                                    harga per baris.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="lines.length > 0">
                            <tr class="bg-slate-50 font-semibold">
                                <td colspan="5" class="px-4 py-3">Total mutasi</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatCurrency(totals.debit) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ formatCurrency(totals.credit) }}</td>
                                <td colspan="2" class="px-4 py-3 text-slate-500 text-xs font-normal">
                                    Total debit harus = total kredit per jurnal (per transaksi).
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
