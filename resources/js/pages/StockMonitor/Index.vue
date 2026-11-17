<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Download, Search, Warehouse } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Cell {
    stock: number;
    min: number;
    status: 'ok' | 'low' | 'out';
}

interface Row {
    key: string;
    name: string;
    sku: string | null;
    unit: string;
    total: number;
    problem: boolean;
    locations: Record<string, Cell | null>;
}

const props = defineProps<{
    stores: { id: number; name: string; is_central_warehouse: boolean }[];
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    summary: { products: number; out: number; low: number };
    filters: { search: string; problems: boolean };
}>();

const search = ref(props.filters.search);
const problems = ref(props.filters.problems);

function applyFilters() {
    router.get(
        '/owner/stock-monitor',
        { ...(search.value ? { search: search.value } : {}), ...(problems.value ? { problems: 1 } : {}) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(applyFilters, 350);
});
watch(problems, applyFilters);

function exportUrl() {
    const params = new URLSearchParams();
    if (search.value) params.set('search', search.value);
    if (problems.value) params.set('problems', '1');
    const qs = params.toString();
    return `/owner/stock-monitor/export${qs ? `?${qs}` : ''}`;
}

const fmt = (n: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(n);
const cellClass = (cell: Cell | null) =>
    !cell ? 'text-slate-300' : cell.status === 'out' ? 'bg-red-50 font-bold text-red-700' : cell.status === 'low' ? 'bg-amber-50 font-semibold text-amber-800' : 'text-slate-700';
</script>

<template>
    <Head title="Pantau Stok Semua Lokasi" />

    <AdminLayout title="Pantau Stok Semua Lokasi">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 100rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Pantau Stok Semua Lokasi</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Stok setiap barang di Gudang Pusat dan semua outlet. Barang dicocokkan lewat SKU, atau nama bila tanpa SKU.
                    </p>
                </div>
                <Button as-child variant="outline" class="rounded-xl">
                    <a :href="exportUrl()" class="inline-flex items-center gap-2">
                        <Download class="h-4 w-4" />
                        Ekspor Excel
                    </a>
                </Button>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase text-slate-500">Barang dipantau</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ fmt(summary.products) }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-2xl border border-red-200 bg-red-50 p-4 text-left"
                    @click="problems = true"
                >
                    <p class="text-xs font-semibold uppercase text-red-700">Habis di suatu lokasi</p>
                    <p class="mt-1 text-2xl font-black text-red-700">{{ fmt(summary.out) }}</p>
                </button>
                <button
                    type="button"
                    class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-left"
                    @click="problems = true"
                >
                    <p class="text-xs font-semibold uppercase text-amber-800">Menipis (≤ Stok Minimum)</p>
                    <p class="mt-1 text-2xl font-black text-amber-800">{{ fmt(summary.low) }}</p>
                </button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="space-y-4 pt-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="relative min-w-64 flex-1">
                            <Search class="absolute left-3 top-3 h-4 w-4 text-slate-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari nama atau SKU…"
                                class="h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm"
                                aria-label="Cari barang"
                            />
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input v-model="problems" type="checkbox" class="h-4 w-4 rounded" />
                            Hanya yang menipis / habis
                        </label>
                    </div>

                    <div v-if="stores.length === 0" class="py-12 text-center text-slate-500">Belum ada lokasi yang bisa dipantau.</div>
                    <div v-else-if="rows.data.length === 0" class="py-12 text-center text-slate-500">Tidak ada barang yang cocok.</div>
                    <div v-else class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="sticky left-0 z-10 bg-slate-50 p-3 text-left">Barang</th>
                                    <th v-for="s in stores" :key="s.id" class="whitespace-nowrap p-3 text-right">
                                        <span class="inline-flex items-center gap-1">
                                            <Warehouse v-if="s.is_central_warehouse" class="h-3.5 w-3.5" />
                                            {{ s.name }}
                                        </span>
                                    </th>
                                    <th class="p-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows.data" :key="row.key" class="border-b border-slate-100 last:border-0">
                                    <td class="sticky left-0 z-10 bg-white p-3">
                                        <p class="font-medium text-slate-900">{{ row.name }}</p>
                                        <p class="text-xs text-slate-400">
                                            <span v-if="row.sku" class="font-mono">{{ row.sku }} · </span>{{ row.unit }}
                                        </p>
                                    </td>
                                    <td
                                        v-for="s in stores"
                                        :key="s.id"
                                        class="p-3 text-right tabular-nums"
                                        :class="cellClass(row.locations[s.id])"
                                        :title="row.locations[s.id] && row.locations[s.id]!.min > 0 ? `Stok Minimum ${fmt(row.locations[s.id]!.min)}` : ''"
                                    >
                                        {{ row.locations[s.id] ? fmt(row.locations[s.id]!.stock) : '—' }}
                                    </td>
                                    <td class="p-3 text-right font-semibold tabular-nums text-slate-900">{{ fmt(row.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="text-xs text-slate-500">
                        <span class="mr-3 inline-block rounded bg-red-50 px-2 text-red-700">Merah = habis</span>
                        <span class="mr-3 inline-block rounded bg-amber-50 px-2 text-amber-800">Kuning = ≤ Stok Minimum</span>
                        "—" = barang tidak ada di lokasi itu.
                    </p>

                    <div v-if="rows.links.length > 3" class="flex flex-wrap gap-1">
                        <Link
                            v-for="(link, i) in rows.links"
                            :key="i"
                            :href="link.url ?? ''"
                            class="rounded-lg px-3 py-1.5 text-xs"
                            :class="[link.active ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700', !link.url ? 'pointer-events-none opacity-40' : '']"
                            preserve-scroll
                            preserve-state
                        >
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
