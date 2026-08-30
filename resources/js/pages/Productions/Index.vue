<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Factory, Eye } from 'lucide-vue-next';

interface RecipeTiny {
    id: number;
    name: string;
    code: string | null;
}

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
}

interface BatchRow {
    id: number;
    batch_number: string;
    status: string;
    multiplier: string;
    output_quantity_initial: string;
    output_quantity_remaining: string;
    total_cost: string;
    unit_cost: string;
    recipe: RecipeTiny | null;
    product: ProductTiny | null;
    lines_count: number;
    produced_at: string | null;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

defineProps<{
    batches: Paginated<BatchRow>;
    currentStore: { id: number; name: string };
    statusFilter?: string | null;
}>();

const formatWhen = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const formatRp = (val: string) =>
    `Rp${Number(val).toLocaleString('id-ID', { minimumFractionDigits: 0 })}`;

const stripZeros = (val: string | number) =>
    parseFloat(String(val)).toString();

const statusConfig: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    completed: { label: 'Selesai', class: 'bg-emerald-100 text-emerald-800' },
    cancelled: { label: 'Dibatalkan', class: 'bg-red-100 text-red-700' },
};

const statusFilters = [
    { value: '', label: 'Semua' },
    { value: 'draft', label: 'Draft' },
    { value: 'completed', label: 'Selesai' },
    { value: 'cancelled', label: 'Dibatalkan' },
];

function filterByStatus(status: string) {
    const params: Record<string, string> = {};
    if (status) params.status = status;
    router.get('/productions', params, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Produksi" />

    <AdminLayout title="Produksi">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Batch Produksi</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Produksi mengurangi stok bahan dan menambah output di <strong>{{ currentStore.name }}</strong>.
                    </p>
                </div>
                <Button as-child class="rounded-xl">
                    <Link href="/productions/create" class="inline-flex items-center gap-2">
                        <Plus class="h-4 w-4" />
                        Produksi Baru
                    </Link>
                </Button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10">
                            <Factory class="h-5 w-5 text-orange-700" />
                        </div>
                        <div>
                            <CardTitle>Riwayat Produksi</CardTitle>
                            <CardDescription>{{ batches.data.length }} batch</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <button
                            v-for="f in statusFilters"
                            :key="f.value"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="
                                statusFilter === f.value || (!statusFilter && f.value === '')
                                    ? 'bg-orange-100 text-orange-800'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            "
                            @click="filterByStatus(f.value)"
                        >
                            {{ f.label }}
                        </button>
                    </div>

                    <div v-if="batches.data.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada batch produksi.</p>
                        <Button as-child variant="outline" class="mt-4 rounded-xl">
                            <Link href="/productions/create">Buat produksi pertama</Link>
                        </Button>
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="b in batches.data"
                            :key="b.id"
                            class="rounded-xl border border-slate-100 p-4 hover:bg-slate-50/80 transition-colors"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Link :href="`/productions/${b.id}`" class="text-sm font-bold font-mono text-slate-900 hover:underline">
                                            {{ b.batch_number }}
                                        </Link>
                                        <Badge
                                            :class="statusConfig[b.status]?.class ?? 'bg-slate-100 text-slate-600'"
                                            class="rounded-lg text-xs"
                                        >
                                            {{ statusConfig[b.status]?.label ?? b.status }}
                                        </Badge>
                                        <span class="text-xs text-slate-400">×{{ stripZeros(b.multiplier) }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-slate-600">
                                        {{ b.recipe?.name ?? '—' }}
                                        →
                                        <strong>{{ b.product?.name ?? '—' }}</strong>
                                        {{ stripZeros(b.output_quantity_initial) }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-400">
                                        {{ formatWhen(b.created_at) }}
                                        <template v-if="b.produced_at"> · Selesai {{ formatWhen(b.produced_at) }}</template>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-1 shrink-0">
                                    <span class="text-xs font-mono text-slate-700">{{ formatRp(b.total_cost) }}</span>
                                    <Button as-child variant="ghost" size="sm" class="h-8 w-8 p-0">
                                        <Link :href="`/productions/${b.id}`"><Eye class="h-4 w-4" /></Link>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="batches.links?.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                        <Link
                            v-for="(l, i) in batches.links"
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
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
