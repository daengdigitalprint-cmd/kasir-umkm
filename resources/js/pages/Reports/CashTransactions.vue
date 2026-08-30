<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { ArrowDownCircle, ArrowUpCircle, ArrowRightLeft } from 'lucide-vue-next';

interface TransactionRow {
    id: string | number;
    source?: 'pos' | 'manual';
    type: 'in' | 'out';
    type_label: string;
    category: string;
    category_label: string;
    amount: string;
    party_name: string | null;
    notes: string;
    recorded_by: string | null;
    shift_number: string | null;
    created_at: string;
}

interface Paginated {
    data: TransactionRow[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    transactions: Paginated;
    summary: {
        pos_cash_sales: number;
        manual_in: number;
        manual_out: number;
        total_in: number;
        total_out: number;
        net: number;
        count: number;
    };
    filters: {
        start_date: string;
        end_date: string;
        type: string;
        category: string;
        user_id: string | number;
    };
    cashiers: { id: number; name: string }[];
    categoryOptions: Record<string, string>;
}>();

const filterStart = ref(props.filters.start_date);
const filterEnd = ref(props.filters.end_date);
const filterType = ref(props.filters.type);
const filterCategory = ref(props.filters.category);
const filterUserId = ref(props.filters.user_id ? String(props.filters.user_id) : '');

watch(() => props.filters, (f) => {
    filterStart.value = f.start_date;
    filterEnd.value = f.end_date;
    filterType.value = f.type;
    filterCategory.value = f.category;
    filterUserId.value = f.user_id ? String(f.user_id) : '';
}, { deep: true });

function applyFilter() {
    router.get('/reports/cash-transactions', {
        start_date: filterStart.value,
        end_date: filterEnd.value,
        type: filterType.value || undefined,
        category: filterCategory.value || undefined,
        user_id: filterUserId.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function changePage(url: string | null) {
    if (!url) return;
    const urlObj = new URL(url, window.location.origin);
    const page = urlObj.searchParams.get('page');
    if (!page) return;
    
    router.get('/reports/cash-transactions', {
        start_date: filterStart.value,
        end_date: filterEnd.value,
        type: filterType.value || undefined,
        category: filterCategory.value || undefined,
        user_id: filterUserId.value || undefined,
        page: page,
    }, { preserveState: true, preserveScroll: true });
}

function formatRp(n: number | string) {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(n));
}

function formatDt(iso: string) {
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <AdminLayout title="Laporan Kas Masuk/Keluar">
        <Head title="Laporan Kas Masuk/Keluar" />

        <div class="mb-4">
            <ReportTabs active-tab="cash-transactions" />
            <p class="text-sm text-slate-600">
                Arus kas masuk dari penjualan tunai POS dan pencatatan kas manual, serta kas keluar dari shift.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <Card class="rounded-2xl border-blue-200 bg-blue-50/50">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <ArrowDownCircle class="h-8 w-8 text-blue-600" />
                        <div>
                            <p class="text-xs text-blue-700 uppercase">Penjualan Tunai</p>
                            <p class="text-xl font-bold text-blue-900">{{ formatRp(summary.pos_cash_sales) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-emerald-200 bg-emerald-50/50">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <ArrowDownCircle class="h-8 w-8 text-emerald-600" />
                        <div>
                            <p class="text-xs text-emerald-700 uppercase">Kas Masuk Manual</p>
                            <p class="text-xl font-bold text-emerald-900">{{ formatRp(summary.manual_in) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-red-200 bg-red-50/50">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <ArrowUpCircle class="h-8 w-8 text-red-600" />
                        <div>
                            <p class="text-xs text-red-700 uppercase">Total Keluar</p>
                            <p class="text-xl font-bold text-red-900">{{ formatRp(summary.total_out) }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200">
                <CardContent class="p-4">
                    <div class="flex items-center gap-3">
                        <ArrowRightLeft class="h-8 w-8 text-primary" />
                        <div>
                            <p class="text-xs text-slate-500 uppercase">Total Masuk</p>
                            <p class="text-xl font-bold text-slate-900">{{ formatRp(summary.total_in) }}</p>
                            <p class="text-xs text-slate-400">Tunai POS + kas masuk manual</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

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
                    <div class="space-y-1.5">
                        <Label class="text-xs">Jenis</Label>
                        <select v-model="filterType" class="h-10 rounded-md border border-input bg-background px-3 text-sm w-36">
                            <option value="">Semua</option>
                            <option value="in">Uang Masuk</option>
                            <option value="out">Uang Keluar</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Kategori</Label>
                        <select v-model="filterCategory" class="h-10 rounded-md border border-input bg-background px-3 text-sm w-44">
                            <option value="">Semua</option>
                            <option v-for="(label, key) in categoryOptions" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs">Dicatat oleh</Label>
                        <select v-model="filterUserId" class="h-10 rounded-md border border-input bg-background px-3 text-sm w-44">
                            <option value="">Semua kasir</option>
                            <option v-for="c in cashiers" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </div>
                    <Button type="submit" variant="outline">Terapkan</Button>
                </form>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader>
                <CardTitle>Daftar Transaksi</CardTitle>
                <CardDescription>{{ transactions.total }} transaksi ditemukan</CardDescription>
            </CardHeader>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-y border-slate-200">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Waktu</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Jenis</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Kategori</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Keterangan</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Pihak</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Shift</th>
                                <th class="text-left px-4 py-3 font-semibold text-slate-600">Dicatat oleh</th>
                                <th class="text-right px-4 py-3 font-semibold text-slate-600">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="t in transactions.data" :key="t.id" class="hover:bg-slate-50/80">
                                <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ formatDt(t.created_at) }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="t.type === 'in' ? 'secondary' : 'outline'" :class="t.type === 'in' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-50 text-red-700'">
                                        {{ t.type_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ t.category_label }}</td>
                                <td class="px-4 py-3 text-slate-700 max-w-[200px] truncate" :title="t.notes">{{ t.notes }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ t.party_name || '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ t.shift_number || '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ t.recorded_by || '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="t.type === 'in' ? 'text-emerald-700' : 'text-red-700'">
                                    {{ t.type === 'in' ? '+' : '-' }} {{ formatRp(t.amount) }}
                                </td>
                            </tr>
                            <tr v-if="!transactions.data.length">
                                <td colspan="8" class="px-4 py-10 text-center text-slate-500">Tidak ada transaksi pada periode ini.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="transactions.links.length > 3" class="flex flex-wrap gap-1 p-4 border-t border-slate-100">
                    <Button
                        v-for="link in transactions.links"
                        :key="link.label"
                        variant="outline"
                        size="sm"
                        class="min-w-9"
                        :class="link.active ? 'bg-slate-900 text-white border-slate-900' : ''"
                        :disabled="!link.url"
                        @click="changePage(link.url)"
                        v-html="link.label"
                    />
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
