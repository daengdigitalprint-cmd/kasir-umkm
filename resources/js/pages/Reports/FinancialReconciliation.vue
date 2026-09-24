<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Scale } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';

type Row = {
    code: string;
    name: string;
    book_balance: number;
    physical_balance: number | null;
    difference: number | null;
    status: 'cocok' | 'selisih' | 'belum_tersedia';
    note: string;
};

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    asOf: string;
    rows: Row[];
    summary: { cocok: number; selisih: number; belum_tersedia: number };
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
        '/financial/reconciliation',
        { as_of: filterAsOf.value },
        { preserveState: true, preserveScroll: true },
    );
};

const formatCurrency = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const statusMeta: Record<Row['status'], { label: string; cls: string }> = {
    cocok: { label: 'Cocok', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    selisih: { label: 'Selisih', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
    belum_tersedia: { label: 'Belum tersedia', cls: 'bg-slate-100 text-slate-500 border-slate-200' },
};
</script>

<template>
    <AdminLayout title="Rekonsiliasi">
        <Head title="Rekonsiliasi" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="rekonsiliasi" />
        </div>

        <p class="text-sm text-slate-600 mb-4">
            Membandingkan saldo buku (jurnal double entry) dengan saldo operasional/fisik per tanggal terpilih. Selisih
            menandakan ada transaksi yang belum tercatat di jurnal.
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
                        <Label for="rec-asof" class="text-xs">Per tanggal</Label>
                        <Input id="rec-asof" v-model="filterAsOf" type="date" class="w-40" required />
                    </div>
                    <Button type="submit">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <div class="mb-4 flex flex-wrap gap-2 text-xs">
            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-700">
                Cocok: {{ summary.cocok }}
            </span>
            <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-700">
                Selisih: {{ summary.selisih }}
            </span>
            <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-slate-500">
                Belum tersedia: {{ summary.belum_tersedia }}
            </span>
        </div>

        <Card class="rounded-2xl border-slate-200 shadow-sm overflow-hidden">
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                            <th class="px-4 py-2">Akun</th>
                            <th class="px-4 py-2 text-right">Saldo buku</th>
                            <th class="px-4 py-2 text-right">Saldo fisik/operasional</th>
                            <th class="px-4 py-2 text-right">Selisih</th>
                            <th class="px-4 py-2 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.code" class="border-b border-slate-100 align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ row.name }}</div>
                                <div class="font-mono text-xs text-slate-500">{{ row.code }}</div>
                                <div class="mt-1 text-xs text-slate-400">{{ row.note }}</div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatCurrency(row.book_balance) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ row.physical_balance === null ? '—' : formatCurrency(row.physical_balance) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ row.difference === null ? '—' : formatCurrency(row.difference) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    :class="['inline-block rounded-full border px-2.5 py-1 text-xs font-medium', statusMeta[row.status].cls]"
                                >
                                    {{ statusMeta[row.status].label }}
                                </span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
