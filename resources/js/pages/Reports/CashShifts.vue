<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import ReportExportButton from '@/components/ReportExportButton.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Clock, Banknote, AlertTriangle, Download } from 'lucide-vue-next';
import { ref, watch, computed } from 'vue';

interface CashierOpt {
    id: number;
    name: string;
}

interface ShiftRow {
    id: number;
    shift_number: string;
    cashier_name: string;
    opened_at: string | null;
    closed_at: string | null;
    opening_cash: number;
    expected_cash: number | null;
    closing_cash_counted: number | null;
    difference_amount: number | null;
    closing_notes: string | null;
    transactions_count: number;
    cash_sales_total: number;
}

interface Paginated {
    data: ShiftRow[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    store: { id: number; name: string; currency: string };
    dateRange: { start_date: string; end_date: string };
    filters: { user_id: string | number | null | undefined };
    cashiers: CashierOpt[];
    summary: {
        shifts_closed: number;
        shifts_with_difference: number;
        total_cash_sales_in_shifts: number;
        total_difference_sum: number;
        open_shifts_now: number;
    };
    shifts: Paginated;
}>();

const filterStart = ref(props.dateRange.start_date);
const filterEnd = ref(props.dateRange.end_date);
const filterUserId = ref(props.filters.user_id != null && props.filters.user_id !== '' ? String(props.filters.user_id) : '');

watch(
    () => props.dateRange,
    (dr) => {
        filterStart.value = dr.start_date;
        filterEnd.value = dr.end_date;
    },
);

watch(
    () => props.filters,
    (f) => {
        filterUserId.value = f.user_id != null && f.user_id !== '' ? String(f.user_id) : '';
    },
    { deep: true },
);

const applyFilter = () => {
    router.get(
        '/reports/cash-shifts',
        {
            start_date: filterStart.value,
            end_date: filterEnd.value,
            user_id: filterUserId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
};

const exportExcelUrl = computed(() => {
    const q = new URLSearchParams({
        start_date: filterStart.value,
        end_date: filterEnd.value,
    });
    if (filterUserId.value) {
        q.set('user_id', filterUserId.value);
    }
    return `/reports/export/cash-shifts-excel?${q.toString()}`;
});

const exportToExcel = () => {
    window.open(exportExcelUrl.value, '_blank');
};

const formatMoney = (n: number) =>
    new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(n);

const formatDt = (iso: string | null) => {
    if (!iso) return '—';
    const normalized = iso.trim();
    const hasTz = /[Zz]|[+-]\d{2}:?\d{2}$/.test(normalized);
    const isoUtc = hasTz ? normalized : normalized + 'Z';
    const d = new Date(isoUtc);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('id-ID', {
        timeZone: 'Asia/Makassar',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <AdminLayout title="Laporan shift kasir">
        <Head title="Laporan shift kasir" />

        <ReportTabs active-tab="shift" />

        <div
            v-if="summary.open_shifts_now > 0"
            class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
            <span class="font-semibold">{{ summary.open_shifts_now }} shift</span> masih terbuka di toko ini (belum tutup di
            <Link href="/cash-shifts" class="font-bold text-amber-900 underline">Shift kasir</Link>).
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
            <Card class="border-slate-200 shadow-sm">
                <CardContent class="pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Shift ditutup</p>
                    <p class="text-2xl font-black text-slate-900 mt-1">{{ summary.shifts_closed }}</p>
                    <p class="text-xs text-slate-500 mt-1">Periode filter</p>
                </CardContent>
            </Card>
            <Card class="border-slate-200 shadow-sm">
                <CardContent class="pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Penjualan tunai (dalam shift)</p>
                    <p class="text-2xl font-black text-emerald-700 mt-1">Rp {{ formatMoney(summary.total_cash_sales_in_shifts) }}</p>
                    <p class="text-xs text-slate-500 mt-1">Jumlah transaksi tunai tercatat</p>
                </CardContent>
            </Card>
            <Card class="border-slate-200 shadow-sm">
                <CardContent class="pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Shift ada selisih</p>
                    <p class="text-2xl font-black text-slate-900 mt-1">{{ summary.shifts_with_difference }}</p>
                    <p class="text-xs text-slate-500 mt-1">Kas dihitung ≠ perkiraan</p>
                </CardContent>
            </Card>
            <Card class="border-slate-200 shadow-sm">
                <CardContent class="pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Σ selisih (tutup)</p>
                    <p
                        class="text-2xl font-black mt-1"
                        :class="summary.total_difference_sum === 0 ? 'text-slate-900' : 'text-amber-800'"
                    >
                        {{ summary.total_difference_sum >= 0 ? '+' : '' }}{{ formatMoney(summary.total_difference_sum) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">Positif = lebih; negatif = kurang</p>
                </CardContent>
            </Card>
        </div>

        <Card class="border-0 shadow-md mb-6">
            <CardContent class="p-4 md:p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:flex-wrap">
                    <div class="space-y-2">
                        <Label>Dari (tutup shift)</Label>
                        <Input v-model="filterStart" type="date" class="max-w-xs" />
                    </div>
                    <div class="space-y-2">
                        <Label>Sampai</Label>
                        <Input v-model="filterEnd" type="date" class="max-w-xs" />
                    </div>
                    <div class="space-y-2 min-w-[200px]">
                        <Label>Kasir</Label>
                        <select
                            v-model="filterUserId"
                            class="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option value="">Semua kasir</option>
                            <option v-for="c in cashiers" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" class="rounded-xl" @click="applyFilter">Terapkan</Button>
                        <Button type="button" variant="outline" class="rounded-xl gap-2" @click="exportToExcel">
                            <Download class="h-4 w-4" />
                            Unduh Excel
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card class="border-0 shadow-lg">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Clock class="h-5 w-5 text-slate-600" />
                    Riwayat tutup shift
                </CardTitle>
                <CardDescription>
                    {{ store.name }} — berdasarkan waktu <strong>tutup shift</strong>. Hanya untuk peran admin/manajer.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="pb-3 pr-3 font-medium">Shift</th>
                            <th class="pb-3 pr-3 font-medium">Kasir</th>
                            <th class="pb-3 pr-3 font-medium">Buka</th>
                            <th class="pb-3 pr-3 font-medium">Tutup</th>
                            <th class="pb-3 pr-3 font-medium text-right">Tunai terjual</th>
                            <th class="pb-3 pr-3 font-medium text-right">Perkiraan</th>
                            <th class="pb-3 pr-3 font-medium text-right">Dihitung</th>
                            <th class="pb-3 pr-3 font-medium text-right">Selisih</th>
                            <th class="pb-3 font-medium text-right">Trx</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shifts.data" :key="s.id" class="border-b border-slate-100">
                            <td class="py-3 pr-3 font-mono text-xs font-semibold">{{ s.shift_number }}</td>
                            <td class="py-3 pr-3">{{ s.cashier_name }}</td>
                            <td class="py-3 pr-3 whitespace-nowrap text-slate-600">{{ formatDt(s.opened_at) }}</td>
                            <td class="py-3 pr-3 whitespace-nowrap text-slate-600">{{ formatDt(s.closed_at) }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums">Rp {{ formatMoney(s.cash_sales_total) }}</td>
                            <td class="py-3 pr-3 text-right tabular-nums text-slate-600">
                                {{ s.expected_cash != null ? `Rp ${formatMoney(s.expected_cash)}` : '—' }}
                            </td>
                            <td class="py-3 pr-3 text-right tabular-nums">
                                {{ s.closing_cash_counted != null ? `Rp ${formatMoney(s.closing_cash_counted)}` : '—' }}
                            </td>
                            <td class="py-3 pr-3 text-right">
                                <template v-if="s.difference_amount !== null">
                                    <Badge
                                        :variant="s.difference_amount === 0 ? 'secondary' : 'outline'"
                                        class="tabular-nums gap-1"
                                    >
                                        <AlertTriangle
                                            v-if="s.difference_amount !== 0"
                                            class="h-3 w-3"
                                        />
                                        {{ s.difference_amount >= 0 ? '+' : '' }}{{ formatMoney(s.difference_amount) }}
                                    </Badge>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="py-3 text-right">{{ s.transactions_count }}</td>
                        </tr>
                        <tr v-if="shifts.data.length === 0">
                            <td colspan="9" class="py-12 text-center text-slate-500">
                                Tidak ada shift yang ditutup pada periode ini.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="shifts.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                    <Link
                        v-for="(l, i) in shifts.links"
                        :key="i"
                        :href="l.url || '#'"
                        class="min-w-9 rounded-lg px-3 py-1.5 text-sm"
                        :class="
                            l.active
                                ? 'bg-primary text-white'
                                : l.url
                                  ? 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                  : 'cursor-not-allowed text-slate-300'
                        "
                        preserve-state
                    >
                        <span v-html="l.label" />
                    </Link>
                </div>

                <p class="mt-6 text-xs text-slate-500 flex items-center gap-1">
                    <Banknote class="h-3.5 w-3.5" />
                    Penjualan tunai dihitung dari transaksi selesai dengan metode tunai yang tertaut ke shift tersebut.
                </p>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
