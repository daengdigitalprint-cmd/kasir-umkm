<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ClipboardCheck, Plus, ArrowRight } from 'lucide-vue-next';

interface OpnameRow {
    id: number;
    adjustment_number: string;
    status: string;
    notes: string | null;
    counted_at: string | null;
    posted_at: string | null;
    total_variance_value: string;
    lines_count: number;
    created_by: { id: number; name: string } | null;
    posted_by: { id: number; name: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    opnames: Paginated<OpnameRow>;
    filters: { status: string | null };
    statusLabels: Record<string, string>;
}>();

const statusClass: Record<string, string> = {
    draft: 'bg-amber-100 text-amber-800',
    posted: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-red-100 text-red-700',
};

const statusFilters = [
    { value: '', label: 'Semua' },
    { value: 'draft', label: 'Sedang dihitung' },
    { value: 'posted', label: 'Diposting' },
    { value: 'cancelled', label: 'Dibatalkan' },
];

const formatWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('id-ID', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

const formatRupiah = (value: string | number) => {
    const n = Number(value ?? 0);
    const sign = n < 0 ? '-' : n > 0 ? '+' : '';
    return `${sign}Rp${Math.abs(Math.round(n)).toLocaleString('id-ID')}`;
};

const valueClass = (value: string | number) => {
    const n = Number(value ?? 0);
    if (n < 0) return 'text-red-600';
    if (n > 0) return 'text-emerald-600';
    return 'text-slate-500';
};

function filterByStatus(status: string) {
    const params: Record<string, string> = {};
    if (status) params.status = status;
    router.get('/stock-opnames', params, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Stock opname" />

    <AdminLayout title="Stock opname">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Stock opname</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Hitung fisik barang secara berkala. Sistem membekukan stok saat sesi dibuka, lalu menghitung selisihnya.
                    </p>
                </div>
                <Button as-child class="rounded-xl">
                    <Link href="/stock-opnames/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Mulai opname
                        <ArrowRight class="h-4 w-4 opacity-80" />
                    </Link>
                </Button>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="f in statusFilters"
                    :key="f.value"
                    type="button"
                    class="rounded-full border px-4 py-1.5 text-sm font-semibold transition"
                    :class="
                        (props.filters.status ?? '') === f.value
                            ? 'border-slate-900 bg-slate-900 text-white'
                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                    "
                    @click="filterByStatus(f.value)"
                >
                    {{ f.label }}
                </button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Riwayat sesi opname</CardTitle>
                    <CardDescription>Sesi berstatus draft dapat dilanjutkan kapan saja.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="props.opnames.data.length === 0" class="py-12 text-center">
                        <ClipboardCheck class="mx-auto h-10 w-10 text-slate-300" />
                        <p class="mt-3 font-semibold text-slate-700">Belum ada sesi opname</p>
                        <p class="mt-1 text-sm text-slate-500">Mulai hitung fisik untuk mengetahui akurasi stok toko Anda.</p>
                        <Button as-child class="mt-4 rounded-xl">
                            <Link href="/stock-opnames/create">Mulai opname pertama</Link>
                        </Button>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th class="py-3 pr-4 font-semibold">Nomor</th>
                                    <th class="py-3 pr-4 font-semibold">Item dihitung</th>
                                    <th class="py-3 pr-4 text-right font-semibold">Nilai selisih</th>
                                    <th class="py-3 pr-4 font-semibold">Status</th>
                                    <th class="py-3 pr-4 font-semibold">Dibuka</th>
                                    <th class="py-3 font-semibold">Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in props.opnames.data" :key="row.id" class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="py-3 pr-4">
                                        <Link :href="`/stock-opnames/${row.id}`" class="font-bold text-slate-900 hover:underline">
                                            {{ row.adjustment_number }}
                                        </Link>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">{{ row.lines_count }} produk</td>
                                    <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="valueClass(row.total_variance_value)">
                                        {{ formatRupiah(row.total_variance_value) }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-bold"
                                            :class="statusClass[row.status] ?? 'bg-slate-100 text-slate-700'"
                                        >
                                            {{ props.statusLabels[row.status] ?? row.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-500">{{ formatWhen(row.counted_at) }}</td>
                                    <td class="py-3 text-slate-600">{{ row.created_by?.name ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="props.opnames.links.length > 3" class="mt-5 flex flex-wrap gap-1">
                        <Link
                            v-for="link in props.opnames.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            class="rounded-lg px-3 py-1.5 text-sm"
                            :class="[
                                link.active ? 'bg-slate-900 font-bold text-white' : 'text-slate-600 hover:bg-slate-100',
                                !link.url ? 'pointer-events-none opacity-40' : '',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
