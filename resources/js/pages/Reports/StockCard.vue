<script setup lang="ts">
import ReportExportButton from '@/components/ReportExportButton.vue';
import ReportTabs from '@/components/ReportTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { History, Search, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface MovementRow {
    id: number;
    created_at: string | null;
    type: string;
    type_label: string;
    variant_name: string | null;
    quantity_change: number;
    balance_after: number;
    notes: string | null;
    user_name: string | null;
}

interface ProductTiny {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    stock_quantity: string;
}

const props = defineProps<{
    products: ProductTiny[];
    product: ProductTiny | null;
    movements: MovementRow[];
    summary: {
        opening_balance: number;
        total_in: number;
        total_out: number;
        closing_balance: number;
        movement_count: number;
    };
    filters: {
        product_id: number | null;
        date_from: string;
        date_to: string;
    };
}>();

const productId = ref(props.filters.product_id ?? '');
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);

// --- Autocomplete pencarian produk ---
const searchQuery = ref('');
const dropdownOpen = ref(false);
const highlightIdx = ref(0);
const searchInputRef = ref<HTMLInputElement | null>(null);
const dropdownRef = ref<HTMLDivElement | null>(null);

const selectedProduct = computed(() => {
    const id = props.filters.product_id;
    if (!id) return null;
    return props.products.find((p) => p.id === id) ?? null;
});

const filteredProducts = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.products;
    return props.products.filter((p) => {
        const hay = `${p.name} ${p.sku ?? ''}`.toLowerCase();
        return hay.includes(q);
    });
});

function selectProduct(p: { id: number; name: string; sku: string | null }) {
    productId.value = p.id;
    searchQuery.value = `${p.name}${p.sku ? ` · ${p.sku}` : ''}`;
    dropdownOpen.value = false;
    highlightIdx.value = 0;
    applyFilter();
}

function clearProduct() {
    productId.value = '';
    searchQuery.value = '';
    applyFilter();
}

function handleKeydown(e: KeyboardEvent) {
    if (!dropdownOpen.value) return;
    const list = filteredProducts.value;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightIdx.value = Math.min(highlightIdx.value + 1, list.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightIdx.value = Math.max(highlightIdx.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (list[highlightIdx.value]) selectProduct(list[highlightIdx.value]);
    } else if (e.key === 'Escape') {
        dropdownOpen.value = false;
    }
}

function highlightMatch(text: string, q: string): string {
    if (!q) return text;
    const idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx === -1) return text;
    return (
        text.slice(0, idx) + '<mark class="bg-amber-200 rounded px-0.5">' + text.slice(idx, idx + q.length) + '</mark>' + text.slice(idx + q.length)
    );
}

// Inisialisasi searchQuery dari produk terpilih.
if (selectedProduct.value) {
    searchQuery.value = `${selectedProduct.value.name}${selectedProduct.value.sku ? ` · ${selectedProduct.value.sku}` : ''}`;
}

// Tutup dropdown jika klik di luar.
function handleOutsideClick(e: MouseEvent) {
    const target = e.target as Node;
    if (dropdownRef.value && !dropdownRef.value.contains(target) && searchInputRef.value && !searchInputRef.value.contains(target)) {
        dropdownOpen.value = false;
    }
}
onMounted(() => document.addEventListener('mousedown', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('mousedown', handleOutsideClick));

function applyFilter() {
    router.get(
        '/reports/stock-card',
        {
            product_id: String(productId.value),
            date_from: dateFrom.value,
            date_to: dateTo.value,
        },
        { preserveState: true, replace: true },
    );
}

const formatQty = (n: number, withSign = false) => {
    const rounded = Math.round(n * 10000) / 10000;
    const text = Number.isInteger(rounded) ? String(rounded) : rounded.toLocaleString('id-ID', { maximumFractionDigits: 4 });
    return withSign && rounded > 0 ? `+${text}` : text;
};

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

const qtyClass = (n: number) => (n < 0 ? 'text-red-600' : n > 0 ? 'text-emerald-600' : 'text-slate-400');

const balanceClass = (n: number) => {
    if (n < 0) return 'text-red-600 font-bold';
    if (n === 0) return 'text-amber-600';
    return 'text-slate-800 font-semibold';
};

const typeClass: Record<string, string> = {
    sale: 'bg-blue-100 text-blue-800',
    purchase: 'bg-emerald-100 text-emerald-800',
    adjustment: 'bg-amber-100 text-amber-800',
    opname: 'bg-violet-100 text-violet-800',
    adjustment_reversal: 'bg-slate-100 text-slate-700',
};
</script>

<template>
    <Head title="Kartu stok" />

    <AdminLayout title="Kartu stok">
        <div class="flex items-start justify-between gap-3">
            <ReportTabs active-tab="kartu-stok" />
            <ReportExportButton report="kartu-stok" :params="{ product_id: productId, date_from: dateFrom, date_to: dateTo }" />
        </div>

        <Card class="mb-6 border-0 shadow-md">
            <CardContent class="grid gap-4 pt-6 md:grid-cols-4">
                <div class="relative md:col-span-2" ref="dropdownRef">
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Produk</label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="text"
                            placeholder="Ketik nama atau SKU produk..."
                            class="w-full rounded-xl border border-slate-200 py-2 pr-9 pl-9 text-sm focus:border-slate-900 focus:outline-none"
                            autocomplete="off"
                            @focus="
                                dropdownOpen = true;
                                highlightIdx = 0;
                            "
                            @keydown="handleKeydown"
                        />
                        <button
                            v-if="searchQuery"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                            @click="clearProduct"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div
                        v-if="dropdownOpen && filteredProducts.length > 0"
                        class="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg"
                    >
                        <button
                            v-for="(p, idx) in filteredProducts"
                            :key="p.id"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-slate-50"
                            :class="{
                                'bg-slate-100': idx === highlightIdx,
                                'bg-primary/10 font-semibold': p.id === productId,
                            }"
                            @click="selectProduct(p)"
                            @mouseenter="highlightIdx = idx"
                        >
                            <span>
                                <span v-html="highlightMatch(p.name, searchQuery)" />
                                <span v-if="p.sku" class="ml-1 text-xs text-slate-400">· {{ p.sku }}</span>
                            </span>
                            <span class="ml-2 text-xs text-slate-500 tabular-nums">{{ p.stock_quantity }} {{ p.unit }}</span>
                        </button>
                    </div>
                    <div
                        v-else-if="dropdownOpen && searchQuery && filteredProducts.length === 0"
                        class="absolute z-50 mt-1 w-full rounded-xl border border-slate-200 bg-white p-3 text-center text-sm text-slate-500 shadow-lg"
                    >
                        Produk tidak ditemukan
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold text-slate-600">Dari</label>
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                    />
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="mb-1.5 block text-xs font-semibold text-slate-600">Sampai</label>
                        <input
                            v-model="dateTo"
                            type="date"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>
                    <div class="flex items-end">
                        <Button class="rounded-xl" @click="applyFilter">Terapkan</Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div v-if="props.product" class="grid gap-4 md:grid-cols-4">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Saldo awal</p>
                    <p class="mt-1 text-xl font-black text-slate-900 tabular-nums">
                        {{ formatQty(props.summary.opening_balance) }}
                    </p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Total masuk</p>
                    <p class="mt-1 text-xl font-black text-emerald-600 tabular-nums">+{{ formatQty(props.summary.total_in) }}</p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Total keluar</p>
                    <p class="mt-1 text-xl font-black text-red-600 tabular-nums">−{{ formatQty(props.summary.total_out) }}</p>
                </CardContent>
            </Card>
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="pt-6">
                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Saldo akhir</p>
                    <p
                        class="mt-1 text-xl font-black tabular-nums"
                        :class="props.summary.closing_balance < 0 ? 'text-red-600' : 'text-slate-900'"
                    >
                        {{ formatQty(props.summary.closing_balance) }}
                        <span class="text-sm font-semibold text-slate-400">{{ props.product.unit }}</span>
                        <Badge
                            v-if="props.summary.closing_balance < 0"
                            class="ml-2 text-[10px] font-bold bg-red-100 text-red-700"
                        >
                            Saldo negatif
                        </Badge>
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card class="rounded-2xl border-slate-200 shadow-sm">
            <CardHeader>
                <CardTitle class="text-base font-bold">
                    {{ props.product ? props.product.name : 'Pilih produk' }}
                </CardTitle>
                <CardDescription v-if="props.product"> {{ props.summary.movement_count }} mutasi pada rentang ini. </CardDescription>
            </CardHeader>
            <CardContent>
                <div v-if="!props.product || props.movements.length === 0" class="py-12 text-center">
                    <History class="mx-auto h-10 w-10 text-slate-300" />
                    <p class="mt-3 font-semibold text-slate-700">Tidak ada mutasi pada rentang ini</p>
                    <p class="mt-1 text-sm text-slate-500">Coba perlebar rentang tanggal atau pilih produk lain.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                                <th class="py-3 pr-4 font-semibold">Waktu</th>
                                <th class="py-3 pr-4 font-semibold">Jenis</th>
                                <th class="py-3 pr-4 text-right font-semibold">Perubahan</th>
                                <th class="py-3 pr-4 text-right font-semibold">Saldo</th>
                                <th class="py-3 pr-4 font-semibold">Keterangan</th>
                                <th class="py-3 font-semibold">Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-slate-100 bg-slate-50/60">
                                <td class="py-2.5 pr-4 text-xs font-semibold text-slate-500" colspan="3">Saldo awal</td>
                                <td
                                    class="py-2.5 pr-4 text-right font-bold tabular-nums"
                                    :class="balanceClass(props.summary.opening_balance)"
                                >
                                    {{ formatQty(props.summary.opening_balance) }}
                                </td>
                                <td class="py-2.5" colspan="2"></td>
                            </tr>
                            <tr v-for="m in props.movements" :key="m.id" class="border-b border-slate-100">
                                <td class="py-3 pr-4 whitespace-nowrap text-slate-500">{{ formatWhen(m.created_at) }}</td>
                                <td class="py-3 pr-4">
                                    <span
                                        class="rounded-full px-2.5 py-1 text-xs font-bold"
                                        :class="typeClass[m.type] ?? 'bg-slate-100 text-slate-700'"
                                    >
                                        {{ m.type_label }}
                                    </span>
                                    <span v-if="m.variant_name" class="ml-2 text-xs text-slate-500">{{ m.variant_name }}</span>
                                </td>
                                <td class="py-3 pr-4 text-right font-bold tabular-nums" :class="qtyClass(m.quantity_change)">
                                    {{ formatQty(m.quantity_change, true) }}
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums" :class="balanceClass(m.balance_after)">
                                    {{ formatQty(m.balance_after) }}
                                </td>
                                <td class="py-3 pr-4 text-slate-500">{{ m.notes ?? '—' }}</td>
                                <td class="py-3 text-slate-600">{{ m.user_name ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
