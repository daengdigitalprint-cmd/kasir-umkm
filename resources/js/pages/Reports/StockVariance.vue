<script setup lang="ts">
import ReportExportButton from '@/components/ReportExportButton.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { TrendingDown } from 'lucide-vue-next';
import { ref } from 'vue';

interface ReasonRow {
    reason_code: string;
    reason_label: string;
    document_count: number;
    total_value: number;
}

interface ProductRow {
    product_id: number;
    product_name: string;
    variant_name: string | null;
    sku: string | null;
    unit: string | null;
    quantity: number;
    value: number;
    line_count: number;
}

const props = defineProps<{
    summary: {
        document_count: number;
        affected_items: number;
        total_value: number;
        loss_value: number;
        gain_value: number;
    };
    byReason: ReasonRow[];
    byProduct: ProductRow[];
    filters: {
        date_from: string;
        date_to: string;
        reason_code: string | null;
        type: string | null;
    };
    reasonLabels: Record<string, string>;
}>();

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const reasonCode = ref(props.filters.reason_code ?? '');
const type = ref(props.filters.type ?? '');

function applyFilter() {
    const params: Record<string, string> = {
        date_from: dateFrom.value,
        date_to: dateTo.value,
    };
    if (reasonCode.value) params.reason_code = reasonCode.value;
    if (type.value) params.type = type.value;
    router.get('/reports/stock-variance', params, { preserveState: true, replace: true });
}

const formatRupiah = (n: number) => {
    const sign = n < 0 ? '-' : n > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(n)).toLocaleString('id-ID')}`;
};

const formatQty = (n: number) => {
    const rounded = Math.round(n * 10000) / 10000;
    const sign = rounded > 0 ? '+' : '';
    return `${sign}${Number.isInteger(rounded) ? rounded : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 })}`;
};

const valueClass = (n: number) => {
    if (n < 0) return 'text-red-600';
    if (n > 0) return 'text-emerald-600';
    return 'text-slate-400';
};
</script>

<template>
    <Head title="Laporan selisih stok" />

    <AdminLayout title="Laporan selisih stok">
        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="selisih-stok" />
            <ReportExportButton report="selisih-stok" :params="{ date_from: dateFrom, date_to: dateTo, reason_code: reasonCode, type: type }" />
        </div>

        <Card class="mb-6 border-0 shadow-md">
            <CardContent class="grid gap-4 pt-6 md:grid-cols-5">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Dari tanggal</label>
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Sampai tanggal</label>
                    <input
                        v-model="dateTo"
                        type="date"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Alasan</label>
                    <select
                        v-model="reasonCode"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    >
                        <option value="">Semua alasan</option>
                        <option v-for="(label, code) in props.reasonLabels" :key="code" :value="code">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Jenis dokumen</label>
                    <select
                        v-model="type"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    >
                        <option value="">Semua</option>
                        <option value="adjustment">Penyesuaian</option>
                        <option value="opname">Stock opname</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <Button class="w-full rounded-xl" @click="applyFilter">Terapkan</Button>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 md:grid-cols-4">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Nilai bersih</p>
                    <p class="mt-1 text-xl font-black tabular-nums" :class="valueClass(props.summary.total_value)">
                        {{ formatRupiah(props.summary.total_value) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Kerugian</p>
                    <p class="mt-1 text-xl font-black text-red-600 tabular-nums">
                        {{ formatRupiah(props.summary.loss_value) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Penambahan</p>
                    <p class="mt-1 text-xl font-black text-emerald-600 tabular-nums">
                        {{ formatRupiah(props.summary.gain_value) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Dokumen / item</p>
                    <p class="mt-1 text-xl font-black text-slate-900">{{ props.summary.document_count }} / {{ props.summary.affected_items }}</p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader>
                <CardTitle class="text-base font-bold">Ringkasan menurut alasan</CardTitle>
                <CardDescription>Mengetahui alasan terbesar membantu menentukan perbaikan yang paling berdampak.</CardDescription>
            </CardHeader>
            <CardContent>
                <p v-if="props.byReason.length === 0" class="py-8 text-center text-sm text-slate-500">Tidak ada koreksi stok pada rentang ini.</p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                            <th class="py-3 pr-4 font-semibold">Alasan</th>
                            <th class="py-3 pr-4 text-right font-semibold">Dokumen</th>
                            <th class="py-3 text-right font-semibold">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.byReason" :key="row.reason_code" class="border-b border-slate-100">
                            <td class="py-3 pr-4 font-semibold text-slate-800">{{ row.reason_label }}</td>
                            <td class="py-3 pr-4 text-right text-slate-600 tabular-nums">{{ row.document_count }}</td>
                            <td class="py-3 text-right font-bold tabular-nums" :class="valueClass(row.total_value)">
                                {{ formatRupiah(row.total_value) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader>
                <CardTitle class="text-base font-bold">Produk dengan selisih terbesar</CardTitle>
                <CardDescription>Diurutkan dari kerugian terbesar.</CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="props.byProduct.length === 0" class="py-10 text-center">
                    <TrendingDown class="mx-auto h-9 w-9 text-slate-300" />
                    <p class="mt-2 text-sm font-semibold text-slate-600">Tidak ada selisih pada rentang ini</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                                <th class="py-3 pr-4 font-semibold">Produk</th>
                                <th class="py-3 pr-4 text-right font-semibold">Selisih qty</th>
                                <th class="py-3 pr-4 text-right font-semibold">Nilai</th>
                                <th class="py-3 text-right font-semibold">Kejadian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in props.byProduct" :key="`${row.product_id}-${row.variant_name ?? ''}`" class="border-b border-slate-100">
                                <td class="py-3 pr-4">
                                    <p class="font-semibold text-slate-900">
                                        {{ row.product_name }}<span v-if="row.variant_name" class="text-slate-500"> — {{ row.variant_name }}</span>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ row.sku ?? '—' }}</p>
                                </td>
                                <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="valueClass(row.quantity)">
                                    {{ formatQty(row.quantity) }}
                                    <span class="text-xs font-normal text-slate-400">{{ row.unit }}</span>
                                </td>
                                <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="valueClass(row.value)">
                                    {{ formatRupiah(row.value) }}
                                </td>
                                <td class="py-3 text-right text-slate-500 tabular-nums">{{ row.line_count }}x</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
