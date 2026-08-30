<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { History, ArrowLeft } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface MovementUser {
    id: number;
    name: string;
}

interface MovementProduct {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
}

interface Movement {
    id: number;
    quantity_change: number;
    balance_after: number | null;
    type: string;
    notes: string | null;
    reference_type: string | null;
    reference_id: number | null;
    created_at: string;
    product: MovementProduct;
    user: MovementUser | null;
}

interface ProductOption {
    id: number;
    name: string;
    sku: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: Record<string, unknown>;
}

const props = defineProps<{
    movements: Paginated<Movement>;
    products: ProductOption[];
    filters: {
        product_id: string | number | null | undefined;
        type: string | null | undefined;
        date_from: string | null | undefined;
        date_to: string | null | undefined;
    };
    typeLabels: Record<string, string>;
}>();

const productId = ref(String(props.filters.product_id ?? ''));
const typeFilter = ref(String(props.filters.type ?? ''));
const dateFrom = ref(String(props.filters.date_from ?? ''));
const dateTo = ref(String(props.filters.date_to ?? ''));

watch(
    () => props.filters,
    (f) => {
        productId.value = String(f.product_id ?? '');
        typeFilter.value = String(f.type ?? '');
        dateFrom.value = String(f.date_from ?? '');
        dateTo.value = String(f.date_to ?? '');
    },
    { deep: true },
);

const applyFilters = () => {
    router.get(
        '/stock-movements',
        {
            product_id: productId.value || undefined,
            type: typeFilter.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

const formatWhen = (iso: string) => {
    const d = new Date(iso);
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatQty = (n: number | string) => {
    const t = Number(n);
    const abs = Math.abs(t);
    // Hapus trailing zeros setelah desimal, pertahankan max 4 desimal
    const formatted = abs % 1 === 0 ? String(abs) : abs.toFixed(4).replace(/\.?0+$/, '');
    return t > 0 ? `+${formatted}` : (t < 0 ? `-${formatted}` : '0');
};

const formatBalance = (n: number | string | null) => {
    if (n === null || n === undefined) return '—';
    const t = Number(n);
    return t % 1 === 0 ? String(t) : t.toFixed(4).replace(/\.?0+$/, '');
};
</script>

<template>
    <Head title="Riwayat stok" />

    <AdminLayout title="Riwayat stok">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center gap-3">
                <Button as="a" href="/products" variant="outline" size="sm" class="gap-2 rounded-xl">
                    <ArrowLeft class="h-4 w-4" />
                    Produk
                </Button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <History class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle>Mutasi stok</CardTitle>
                            <CardDescription>
                                Filter per produk, jenis mutasi, atau rentang tanggal. Penjualan kasir tercatat otomatis.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="space-y-1">
                            <Label class="text-xs">Produk</Label>
                            <select
                                v-model="productId"
                                class="h-10 min-w-[200px] rounded-lg border border-slate-200 bg-white px-3 text-sm"
                                @change="applyFilters"
                            >
                                <option value="">Semua</option>
                                <option v-for="p in products" :key="p.id" :value="String(p.id)">
                                    {{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label class="text-xs">Jenis</Label>
                            <select
                                v-model="typeFilter"
                                class="h-10 min-w-[180px] rounded-lg border border-slate-200 bg-white px-3 text-sm"
                                @change="applyFilters"
                            >
                                <option value="">Semua</option>
                                <option v-for="(label, key) in typeLabels" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <Label class="text-xs">Dari tanggal</Label>
                            <input
                                v-model="dateFrom"
                                type="date"
                                class="h-10 min-w-[160px] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900"
                                @change="applyFilters"
                            />
                        </div>
                        <div class="space-y-1">
                            <Label class="text-xs">Sampai tanggal</Label>
                            <input
                                v-model="dateTo"
                                type="date"
                                class="h-10 min-w-[160px] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900"
                                @change="applyFilters"
                            />
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Waktu</th>
                                    <th class="px-4 py-3">Produk</th>
                                    <th class="px-4 py-3 text-right">Perubahan</th>
                                    <th class="px-4 py-3 text-right">Saldo</th>
                                    <th class="px-4 py-3">Jenis</th>
                                    <th class="px-4 py-3">Oleh</th>
                                    <th class="px-4 py-3">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="m in movements.data" :key="m.id" class="hover:bg-slate-50/80">
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                        {{ formatWhen(m.created_at) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-slate-900">{{ m.product.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="m.product.sku">SKU {{ m.product.sku }}</span>
                                            <span v-if="m.product.unit"> · {{ m.product.unit }}</span>
                                        </div>
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right font-mono font-semibold"
                                        :class="Number(m.quantity_change) >= 0 ? 'text-emerald-700' : 'text-red-600'"
                                    >
                                        {{ formatQty(m.quantity_change) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-slate-700">
                                        {{ formatBalance(m.balance_after) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge variant="secondary" class="rounded-lg">
                                            {{ typeLabels[m.type] ?? m.type }}
                                        </Badge>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ m.user?.name ?? '—' }}
                                    </td>
                                    <td class="max-w-[200px] truncate px-4 py-3 text-slate-500" :title="m.notes ?? ''">
                                        {{ m.notes ?? '—' }}
                                    </td>
                                </tr>
                                <tr v-if="movements.data.length === 0">
                                    <td colspan="7" class="px-4 py-12 text-center text-slate-500">
                                        Belum ada mutasi atau filter terlalu ketat.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="movements.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-2">
                        <Link
                            v-for="(l, i) in movements.links"
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
