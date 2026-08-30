<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Percent } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    amounts: {
        ppn_keluaran: number;
        ppn_masukan: number;
        net_position: number;
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
        '/reports/tax/ppn-summary',
        { start_date: filterStart.value, end_date: filterEnd.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Rekap PPN">
        <Head title="Rekap PPN" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="ppn" />
            <ReportExportButton report="ppn" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 mb-4">
            <p class="font-semibold mb-1">Bantuan pelaporan pajak</p>
            <p>
                Angka diambil dari jurnal: kredit ke akun «Utang PPN Keluaran» (penjualan dengan PPN di POS) dan debit ke
                «PPN Masukan» (pembelian jika Anda mengisi PPN). Ini <strong>bukan</strong> e-Faktur atau SPT otomatis —
                validasi dengan akuntan / KPP.
            </p>
        </div>

        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardHeader class="pb-2">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15">
                        <Percent class="h-5 w-5 text-amber-800" />
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
                        <Label for="ppn-start" class="text-xs">Dari</Label>
                        <Input id="ppn-start" v-model="filterStart" type="date" class="w-40" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="ppn-end" class="text-xs">Sampai</Label>
                        <Input id="ppn-end" v-model="filterEnd" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardContent class="p-6 space-y-4">
                <div class="flex justify-between border-b border-slate-100 pb-3">
                    <span class="text-slate-700">PPN Keluaran (dari penjualan)</span>
                    <span class="font-semibold tabular-nums">{{ formatCurrency(amounts.ppn_keluaran) }}</span>
                </div>
                <div class="flex justify-between border-b border-slate-100 pb-3">
                    <span class="text-slate-700">PPN Masukan (dari pembelian)</span>
                    <span class="font-semibold tabular-nums">{{ formatCurrency(amounts.ppn_masukan) }}</span>
                </div>
                <div class="flex justify-between pt-2 text-lg font-black">
                    <span>Posisi bersih (Keluaran − Masukan)</span>
                    <span class="tabular-nums">{{ formatCurrency(amounts.net_position) }}</span>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
