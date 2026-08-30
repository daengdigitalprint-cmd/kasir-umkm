<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Wallet, TrendingUp, TrendingDown, Minus } from 'lucide-vue-next';
import { ref } from 'vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';

interface PaymentMethod {
    method: string;
    count: number;
    total: number;
}

interface ShiftRow {
    id: number;
    shift_number: string;
    cashier: string;
    opened_at: string;
    closed_at: string;
    opening_cash: number;
    closing_cash_counted: number;
    expected_cash: number;
    theoretical_cash: number;
    difference: number;
    total_sales: number;
    sales_cash: number;
    sales_non_cash: number;
    tx_count: number;
    cash_in: number;
    cash_out: number;
    payment_methods: PaymentMethod[];
}

const props = defineProps<{
    shifts: ShiftRow[];
    summary: {
        total_shifts: number;
        total_sales: number;
        total_sales_cash: number;
        total_sales_non_cash: number;
        total_cash_in: number;
        total_cash_out: number;
        total_difference: number;
        total_tx_count: number;
        avg_sales_per_shift: number;
    };
    filters: { start_date: string; end_date: string };
}>();

const filterStart = ref(props.filters.start_date);
const filterEnd = ref(props.filters.end_date);

const applyFilter = () => {
    router.get('/reports/cash-flow', { start_date: filterStart.value, end_date: filterEnd.value }, { preserveState: true, preserveScroll: true });
};

const formatRp = (n: number) => 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);
</script>

<template>
    <AdminLayout title="Arus Kas per Shift">
        <Head title="Arus Kas per Shift" />

        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="cash-flow" />
            <ReportExportButton report="cash-flow" :params="{ start_date: filterStart, end_date: filterEnd }" />
        </div>

        <p class="text-sm text-slate-600 mb-4">Laporan arus kas harian berdasarkan data shift kasir yang sudah ditutup.</p>

        <!-- Filter -->
        <Card class="rounded-2xl border-slate-200 shadow-sm mb-6">
            <CardContent class="p-4">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                    <div class="space-y-1.5">
                        <Label class="text-xs">Dari</Label>
                        <Input v-model="filterStart" type="date" class="w-40" />
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Sampai</Label>
                        <Input v-model="filterEnd" type="date" class="w-40" />
                    </div>
                    <Button type="submit" variant="outline">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <!-- Summary -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Wallet class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total Shift</p>
                            <p class="text-lg font-bold text-slate-900">{{ summary.total_shifts }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10">
                            <TrendingUp class="h-5 w-5 text-emerald-600" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total Penjualan</p>
                            <p class="text-lg font-bold text-slate-900">{{ formatRp(summary.total_sales) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10">
                            <span class="text-sm font-bold text-blue-700">Rp</span>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Tunai</p>
                            <p class="text-lg font-bold text-slate-900">{{ formatRp(summary.total_sales_cash) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl" :class="summary.total_difference >= 0 ? 'bg-emerald-500/10' : 'bg-red-500/10'">
                            <Minus class="h-5 w-5" :class="summary.total_difference >= 0 ? 'text-emerald-600' : 'text-red-600'" />
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Total Selisih</p>
                            <p class="text-lg font-bold" :class="summary.total_difference >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ formatRp(summary.total_difference) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Detail Table -->
        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader class="pb-2">
                <CardTitle class="text-sm">Rincian per Shift</CardTitle>
                <CardDescription>Menampilkan {{ shifts.length }} shift</CardDescription>
            </CardHeader>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/80">
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Shift</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Kasir</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Buka</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Tutup</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Kas Awal</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Penj. Tunai</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Penj. Non-Tunai</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Total Penj.</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Kas Masuk</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Kas Keluar</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-700">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in shifts" :key="s.id" class="border-b border-slate-50 hover:bg-slate-50/50">
                                <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ s.shift_number }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ s.cashier }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ s.opened_at }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ s.closed_at }}</td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ formatRp(s.opening_cash) }}</td>
                                <td class="px-4 py-3 text-right text-blue-700">{{ formatRp(s.sales_cash) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600">{{ formatRp(s.sales_non_cash) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ formatRp(s.total_sales) }}</td>
                                <td class="px-4 py-3 text-right text-emerald-600">{{ formatRp(s.cash_in) }}</td>
                                <td class="px-4 py-3 text-right text-red-600">{{ formatRp(s.cash_out) }}</td>
                                <td class="px-4 py-3 text-right font-semibold" :class="s.difference >= 0 ? 'text-emerald-600' : 'text-red-600'">{{ formatRp(s.difference) }}</td>
                            </tr>
                            <tr v-if="shifts.length === 0">
                                <td colspan="11" class="px-4 py-12 text-center text-slate-400">Tidak ada data shift.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
