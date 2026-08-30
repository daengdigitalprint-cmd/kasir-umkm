<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ChevronLeft, ChevronRight, Eye, Flame, Package, AlertTriangle, SearchIcon } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

interface Product {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    stock_quantity: number | string;
    min_stock_level: number | string;
    average_cost: number | string | null;
    cost_price: number | string | null;
    allows_decimal: boolean;
}

interface Stats {
    total: number;
    in_stock: number;
    out_of_stock: number;
    low_stock: number;
    total_qty: number | string;
    total_value: number | string;
}

interface Category {
    id: number;
    name: string;
}

interface StoreOption {
    id: number;
    name: string;
    location_type: string;
    location_label: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    store: { id: number; name: string };
    products: Paginated<Product>;
    stats: Stats;
    categories: Category[];
    stores: StoreOption[];
    selectedStoreId: number;
    search: string;
    categoryId: number | null;
    showLowStock: boolean;
}>();

const searchQuery = ref(props.search);
const activeCategoryId = ref<number | null>(props.categoryId);
const activeShowLowStock = ref(props.showLowStock);
const activeStoreId = ref<number>(props.selectedStoreId);

const doSearch = () => {
    const params: Record<string, string | number> = {};
    if (activeStoreId.value) params.store_id = activeStoreId.value;
    if (searchQuery.value) params.search = searchQuery.value;
    if (activeCategoryId.value) params.category_id = activeCategoryId.value;
    if (activeShowLowStock.value) params.low_stock = '1';
    router.get('/stock-overview', params, { preserveState: true, replace: true });
};

const changeStore = (id: number) => {
    if (!id || id === activeStoreId.value) return;
    activeStoreId.value = id;
    activeCategoryId.value = null;
    activeShowLowStock.value = false;
    searchQuery.value = '';
    doSearch();
};

const filterByCategory = (id: number | null) => {
    activeCategoryId.value = id;
    doSearch();
};

const toggleLowStock = () => {
    activeShowLowStock.value = !activeShowLowStock.value;
    doSearch();
};

const categoryScrollRef = ref<HTMLDivElement | null>(null);
const categoryCanScrollLeft = ref(false);
const categoryCanScrollRight = ref(false);

const checkCategoryScroll = () => {
    const el = categoryScrollRef.value;
    if (!el) return;
    categoryCanScrollLeft.value = el.scrollLeft > 0;
    categoryCanScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
};

const scrollCategory = (dir: 'left' | 'right') => {
    const el = categoryScrollRef.value;
    if (!el) return;
    el.scrollBy({ left: dir === 'left' ? -200 : 200, behavior: 'smooth' });
};

onMounted(() => {
    setTimeout(checkCategoryScroll, 100);
});

const fmtNum = (n: number | string) => {
    const v = Number(n);
    return v % 1 === 0 ? v.toLocaleString('id-ID') : v.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
};

const fmtCurrency = (n: number | string) => {
    return 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
};

const stockStatus = (row: Product) => {
    const sq = Number(row.stock_quantity);
    const ml = Number(row.min_stock_level);
    if (sq <= 0) return { label: 'Habis', class: 'bg-red-100 text-red-700' };
    if (sq <= ml) return { label: 'Menipis', class: 'bg-amber-100 text-amber-700' };
    return { label: 'Aman', class: 'bg-emerald-100 text-emerald-700' };
};

const formatStock = (row: Product) => {
    const v = Number(row.stock_quantity);
    if (row.allows_decimal) return v.toFixed(4).replace(/\.?0+$/, '');
    return String(v);
};
</script>

<template>
    <Head title="Lihat Stok" />

    <AdminLayout title="Lihat Stok">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <!-- Pemilih outlet — admin & gudang bisa melihat stok tiap outlet -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-black text-slate-900">Lihat Stok</h2>
                    <p class="text-sm text-slate-500">
                        Stok produk di <span class="font-semibold text-slate-700">{{ store.name }}</span>
                    </p>
                </div>
                <label v-if="stores.length > 1" class="flex items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Outlet</span>
                    <select
                        :value="activeStoreId"
                        @change="changeStore(Number(($event.target as HTMLSelectElement).value))"
                        class="h-10 min-w-52 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                    >
                        <option v-for="s in stores" :key="s.id" :value="s.id">
                            {{ s.name }} — {{ s.location_label }}
                        </option>
                    </select>
                </label>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="flex items-center gap-4 p-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100">
                            <Package class="h-6 w-6 text-blue-600" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Total Produk</p>
                            <p class="text-2xl font-bold text-slate-900">{{ stats.total }}</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="flex items-center gap-4 p-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100">
                            <Package class="h-6 w-6 text-emerald-600" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Stok Aman</p>
                            <p class="text-2xl font-bold text-slate-900">{{ stats.in_stock }}</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="flex items-center gap-4 p-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-100">
                            <AlertTriangle class="h-6 w-6 text-amber-600" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Stok Menipis</p>
                            <p class="text-2xl font-bold text-slate-900">{{ stats.low_stock }}</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardContent class="flex items-center gap-4 p-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100">
                            <Package class="h-6 w-6 text-red-600" />
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-500">Stok Habis</p>
                            <p class="text-2xl font-bold text-slate-900">{{ stats.out_of_stock }}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Total Value -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardContent class="flex flex-wrap items-center gap-6 p-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500">Total Qty Stok</p>
                        <p class="text-lg font-bold text-slate-900">{{ fmtNum(stats.total_qty) }}</p>
                    </div>
                    <div class="h-8 w-px bg-slate-200" />
                    <div>
                        <p class="text-xs font-medium text-slate-500">Total Nilai Stok</p>
                        <p class="text-lg font-bold text-slate-900">{{ fmtCurrency(stats.total_value) }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Table -->
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                                <Eye class="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <CardTitle>Daftar Stok</CardTitle>
                                <CardDescription>Stok semua produk yang dilacak di {{ store.name }}.</CardDescription>
                            </div>
                        </div>
                        <div class="relative">
                            <SearchIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Cari nama / SKU..."
                                class="h-10 w-full rounded-lg border border-slate-200 bg-white pl-9 pr-3 text-sm"
                                @keyup.enter="doSearch"
                            />
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <!-- Category Filter -->
                    <div class="relative mb-4">
                        <button
                            v-if="categoryCanScrollLeft"
                            type="button"
                            class="absolute left-0 top-1/2 -translate-y-1/2 z-10 h-7 w-7 flex items-center justify-center rounded-full bg-white/90 border border-gray-200 shadow-sm text-gray-500 hover:text-gray-800 hover:bg-gray-50 transition-colors"
                            @click="scrollCategory('left')"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </button>

                        <div
                            ref="categoryScrollRef"
                            class="overflow-x-auto scrollbar-hide -mx-1 px-1"
                            @scroll="checkCategoryScroll"
                        >
                            <div class="flex gap-2 pb-1">
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium border transition-colors"
                                    :class="activeCategoryId === null && !activeShowLowStock
                                        ? 'bg-primary text-white border-primary'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-gray-300'"
                                    @click="activeCategoryId = null; activeShowLowStock = false; doSearch()"
                                >
                                    Semua
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium border transition-colors"
                                    :class="activeShowLowStock
                                        ? 'bg-primary text-white border-primary'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-gray-300'"
                                    @click="toggleLowStock"
                                >
                                    <span class="flex items-center gap-1">
                                        <AlertTriangle class="h-3.5 w-3.5 text-amber-500" />
                                        Stok Menipis
                                    </span>
                                </button>
                                <button
                                    v-for="cat in categories"
                                    :key="cat.id"
                                    type="button"
                                    class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium border transition-colors"
                                    :class="activeCategoryId === cat.id
                                        ? 'bg-primary text-white border-primary'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-gray-300'"
                                    @click="filterByCategory(cat.id)"
                                >
                                    {{ cat.name }}
                                </button>
                            </div>
                        </div>

                        <button
                            v-if="categoryCanScrollRight"
                            type="button"
                            class="absolute right-0 top-1/2 -translate-y-1/2 z-10 h-7 w-7 flex items-center justify-center rounded-full bg-white/90 border border-gray-200 shadow-sm text-gray-500 hover:text-gray-800 hover:bg-gray-50 transition-colors"
                            @click="scrollCategory('right')"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Produk</th>
                                    <th class="px-4 py-3 text-right">Stok</th>
                                    <th class="px-4 py-3 text-right">Min. Stok</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                    <th class="px-4 py-3 text-right">HPP / unit</th>
                                    <th class="px-4 py-3 text-right">Nilai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="p in products.data" :key="p.id" class="hover:bg-slate-50/80">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-slate-900">{{ p.name }}</div>
                                        <div class="text-xs text-slate-500">
                                            <span v-if="p.sku">SKU {{ p.sku }}</span>
                                            <span v-if="p.unit"> · {{ p.unit }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-slate-900">
                                        {{ formatStock(p) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-slate-600">
                                        {{ fmtNum(p.min_stock_level) }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <Badge :class="stockStatus(p).class + ' rounded-lg'" variant="outline">
                                            {{ stockStatus(p).label }}
                                        </Badge>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-600">
                                        {{ fmtCurrency(p.average_cost ?? p.cost_price ?? 0) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">
                                        {{ fmtCurrency(Number(p.stock_quantity) * Number(p.average_cost ?? p.cost_price ?? 0)) }}
                                    </td>
                                </tr>
                                <tr v-if="products.data.length === 0">
                                    <td colspan="6" class="px-4 py-12 text-center text-slate-500">
                                        Tidak ada produk atau pencarian tidak ditemukan.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="products.links.length > 3" class="flex flex-wrap justify-center gap-1 pt-4">
                        <Link
                            v-for="(l, i) in products.links"
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
