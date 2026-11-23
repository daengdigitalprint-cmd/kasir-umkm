<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { alertInfo, alertSuccess, choiceDialog, confirmDelete } from '@/lib/swal';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownUp,
    Box,
    CheckSquare,
    ChevronLeft,
    ChevronRight,
    Download,
    EyeOff,
    FileSpreadsheet,
    FlaskConical,
    History,
    Layers,
    LayoutGrid,
    List,
    Loader2,
    MoreVertical,
    Package,
    PackageX,
    Pencil,
    Plus,
    Search,
    ShoppingCart,
    Sparkles,
    Square,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Product {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: number | string;
    cost_price: number | string | null;
    stock_quantity: number | string;
    min_stock_level: number | string;
    track_stock?: boolean;
    unlimited_stock?: boolean;
    category: string | null;
    unit: string | null;
    is_active: boolean;
    is_low_stock: boolean;
    has_sales?: boolean;
    image_url?: string | null;
    inventory_type?: string;
}

interface Filters {
    search?: string;
    inventory_type?: string;
    category?: string;
    status?: string;
    stock?: string;
    sort?: string;
    dir?: string;
}

const props = defineProps<{
    products: {
        data: Product[];
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
    filters?: Filters;
    categories?: string[];
    categoryOptions?: Array<{ id: number; name: string }>;
    counts?: { all: number; low: number; out: number; inactive: number };
}>();

const productsList = computed(() => props.products.data ?? []);
const meta = computed(() => ({
    total: props.products.total,
    current_page: props.products.current_page,
    last_page: props.products.last_page,
}));
const counts = computed(() => props.counts ?? { all: meta.value.total, low: 0, out: 0, inactive: 0 });

// ── Filter, urutan, tampilan ─────────────────────────────────────────────
const searchQuery = ref(props.filters?.search ?? '');
const activeType = ref(props.filters?.inventory_type ?? '');
const activeCategory = ref(props.filters?.category ?? '');
const activeStatus = ref(props.filters?.status ?? '');
const activeStock = ref(props.filters?.stock ?? '');
const sortKey = ref(`${props.filters?.sort ?? 'latest'}:${props.filters?.dir ?? 'desc'}`);

const SORT_OPTIONS = [
    { value: 'latest:desc', label: 'Terbaru' },
    { value: 'name:asc', label: 'Nama A–Z' },
    { value: 'name:desc', label: 'Nama Z–A' },
    { value: 'stock:asc', label: 'Stok paling sedikit' },
    { value: 'stock:desc', label: 'Stok paling banyak' },
    { value: 'price:desc', label: 'Harga tertinggi' },
    { value: 'price:asc', label: 'Harga terendah' },
];

const TYPE_TABS = [
    { key: '', label: 'Semua jenis', icon: Layers },
    { key: 'sellable', label: 'Produk jual', icon: ShoppingCart },
    { key: 'raw_material', label: 'Bahan baku', icon: Box },
    { key: 'packaging', label: 'Kemasan', icon: Package },
    { key: 'production_output', label: 'Hasil produksi', icon: FlaskConical },
];
const TYPE_LABEL: Record<string, string> = {
    sellable: 'Produk jual',
    raw_material: 'Bahan baku',
    packaging: 'Kemasan',
    production_output: 'Hasil produksi',
};

const VIEW_KEY = 'products.viewMode';
const readView = (): 'list' | 'grid' => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'grid' ? 'grid' : 'list';
    } catch {
        return 'list';
    }
};
const viewMode = ref<'list' | 'grid'>(readView());
watch(viewMode, (v) => {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        /* abaikan */
    }
});

function buildParams(page?: number): Record<string, string | number> {
    const [sort, dir] = sortKey.value.split(':');
    const params: Record<string, string | number> = {};
    if (searchQuery.value) params.search = searchQuery.value;
    if (activeType.value) params.inventory_type = activeType.value;
    if (activeCategory.value) params.category = activeCategory.value;
    if (activeStatus.value) params.status = activeStatus.value;
    if (activeStock.value) params.stock = activeStock.value;
    if (sort !== 'latest' || dir !== 'desc') {
        params.sort = sort;
        params.dir = dir;
    }
    if (page && page > 1) params.page = page;
    return params;
}

function applyFilters(page?: number) {
    router.get('/products', buildParams(page), { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 350);
});
watch([activeType, activeCategory, sortKey], () => applyFilters());

/** Tombol ringkasan: Semua / Stok rendah / Stok habis / Nonaktif. */
const quickFilter = computed(() =>
    activeStock.value === 'low' ? 'low' : activeStock.value === 'out' ? 'out' : activeStatus.value === 'inactive' ? 'inactive' : 'all',
);
function setQuickFilter(key: 'all' | 'low' | 'out' | 'inactive') {
    activeStock.value = key === 'low' || key === 'out' ? key : '';
    activeStatus.value = key === 'inactive' ? 'inactive' : '';
    applyFilters();
}

const hasFilters = computed(() => Boolean(searchQuery.value || activeType.value || activeCategory.value || activeStatus.value || activeStock.value));
function resetFilters() {
    searchQuery.value = '';
    activeType.value = '';
    activeCategory.value = '';
    activeStatus.value = '';
    activeStock.value = '';
    applyFilters();
}

function goToPage(page: number) {
    if (page < 1 || page > meta.value.last_page) return;
    applyFilters(page);
}

const paginationPages = computed(() => {
    const last = meta.value.last_page;
    const current = meta.value.current_page;
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const pages: (number | '...')[] = [1];
    if (current > 3) pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) pages.push(i);
    if (current < last - 2) pages.push('...');
    pages.push(last);
    return pages;
});

// ── Tampilan harga & stok ────────────────────────────────────────────────
const rupiah = (n: number | string | null | undefined) => 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(Number(n ?? 0));

function formatQty(qty: number | string): string {
    const num = Number(qty);
    return Number.isInteger(num) ? num.toLocaleString('id-ID') : num.toLocaleString('id-ID', { maximumFractionDigits: 3 });
}

const isMaterial = (p: Product) => p.inventory_type === 'raw_material' || p.inventory_type === 'packaging';

/** Bahan & kemasan tidak dijual: tampilkan harga beli. Produk jual: harga jual + margin. */
function priceInfo(p: Product): { main: string; sub: string | null; muted: boolean } {
    const cost = Number(p.cost_price ?? 0);
    if (isMaterial(p)) {
        // Staf tanpa akses biaya: harga beli disembunyikan server, jangan tampilkan "belum diisi".
        if (!canViewCost.value) return { main: '—', sub: null, muted: true };
        return cost > 0
            ? { main: rupiah(cost), sub: `harga beli / ${p.unit || 'satuan'}`, muted: false }
            : { main: 'Harga beli belum diisi', sub: null, muted: true };
    }
    const price = Number(p.price ?? 0);
    if (canViewCost.value && cost > 0 && price > 0) {
        const margin = Math.round(((price - cost) / price) * 100);
        return { main: rupiah(price), sub: `HPP ${rupiah(cost)} · margin ${margin}%`, muted: false };
    }
    return { main: rupiah(price), sub: null, muted: price <= 0 };
}

/** Menu yang stoknya tidak dihitung ditampilkan abu-abu, bukan merah "0". */
function stockInfo(p: Product): { text: string; tone: 'muted' | 'ok' | 'low' | 'empty'; hint?: string } {
    if (p.unlimited_stock) return { text: 'Tanpa batas', tone: 'muted' };
    if (p.track_stock === false) {
        return {
            text: 'Tidak dihitung',
            tone: 'muted',
            hint: 'Stok produk ini tidak dihitung. Bila memakai resep, stok bahan bakunya tetap berkurang.',
        };
    }
    const qty = Number(p.stock_quantity);
    const text = `${formatQty(qty)} ${p.unit ?? ''}`.trim();
    if (qty <= 0) return { text, tone: 'empty' };
    if (p.is_low_stock) return { text, tone: 'low', hint: `Di bawah batas minimum (${formatQty(p.min_stock_level)})` };
    return { text, tone: 'ok' };
}
const STOCK_TONE: Record<string, string> = {
    muted: 'text-slate-400',
    ok: 'text-slate-700',
    low: 'text-amber-600',
    empty: 'text-red-600',
};

// ── Aktif / nonaktif ─────────────────────────────────────────────────────
// Saklar langsung berubah di layar (optimistis); bila gagal, kembali ke nilai server.
const activeOverride = ref<Record<number, boolean>>({});
const isActive = (p: Product) => activeOverride.value[p.id] ?? p.is_active;
const isToggling = (p: Product) => p.id in activeOverride.value;
function setActive(p: Product, value: boolean) {
    if (isToggling(p)) return;
    activeOverride.value = { ...activeOverride.value, [p.id]: value };
    router.patch(
        `/products/${p.id}/active`,
        { is_active: value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['products', 'counts', 'flash'],
            onFinish: () => {
                const next = { ...activeOverride.value };
                delete next[p.id];
                activeOverride.value = next;
            },
        },
    );
}

// ── Hapus (sarankan nonaktifkan bila sudah pernah terjual) ───────────────
async function deleteProduct(p: Product) {
    if (p.has_sales) {
        const choice = await choiceDialog(
            'Produk ini sudah pernah terjual',
            `Sebaiknya «${p.name}» dinonaktifkan saja: tidak tampil di kasir, tetapi riwayat penjualan dan laporannya tetap rapi.`,
            { primaryText: 'Nonaktifkan', secondaryText: 'Tetap hapus' },
        );
        if (choice === 'primary') return setActive(p, false);
        if (choice !== 'secondary') return;
    } else if (!(await confirmDelete('Hapus produk?', `«${p.name}» akan dihapus permanen.`))) {
        return;
    }
    router.delete(`/products/${p.id}`, { preserveScroll: true });
}

// ── Pilih & aksi massal ──────────────────────────────────────────────────
const selectedIds = ref<Set<number>>(new Set());
const bulkBusy = ref(false);
const bulkCategoryId = ref<number | ''>('');

const allSelected = computed(() => productsList.value.length > 0 && productsList.value.every((p) => selectedIds.value.has(p.id)));
function toggleSelectAll() {
    const next = new Set(selectedIds.value);
    if (allSelected.value) productsList.value.forEach((p) => next.delete(p.id));
    else productsList.value.forEach((p) => next.add(p.id));
    selectedIds.value = next;
}
function toggleSelect(id: number) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedIds.value = next;
}
const clearSelection = () => (selectedIds.value = new Set());

function bulkUpdate(action: 'activate' | 'deactivate' | 'category') {
    if (!selectedIds.value.size || bulkBusy.value) return;
    if (action === 'category' && bulkCategoryId.value === '') return;
    bulkBusy.value = true;
    router.post(
        '/products/bulk-update',
        { ids: [...selectedIds.value], action, product_category_id: action === 'category' ? bulkCategoryId.value : null },
        {
            preserveScroll: true,
            onSuccess: () => {
                clearSelection();
                bulkCategoryId.value = '';
            },
            onFinish: () => (bulkBusy.value = false),
        },
    );
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function bulkDelete() {
    const count = selectedIds.value.size;
    if (!count) return;
    const sold = productsList.value.filter((p) => selectedIds.value.has(p.id) && p.has_sales).length;
    if (sold > 0) {
        const choice = await choiceDialog(
            `${sold} dari ${count} produk sudah pernah terjual`,
            'Sebaiknya produk yang dipilih dinonaktifkan saja agar riwayat penjualan dan laporan tetap rapi.',
            { primaryText: 'Nonaktifkan semua', secondaryText: 'Tetap hapus' },
        );
        if (choice === 'primary') return bulkUpdate('deactivate');
        if (choice !== 'secondary') return;
    } else if (!(await confirmDelete(`Hapus ${count} produk?`, 'Produk yang dipilih akan dihapus permanen.'))) {
        return;
    }

    bulkBusy.value = true;
    try {
        const res = await fetch('/products/bulk-delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''),
                'X-CSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ids: [...selectedIds.value] }),
        });
        const response = await res.json();
        clearSelection();
        router.reload({ only: ['products', 'counts'] });
        void alertSuccess('Berhasil', `${response?.deleted ?? 0} produk dihapus`);
    } finally {
        bulkBusy.value = false;
    }
}

// ── Ekspor & impor ───────────────────────────────────────────────────────
const showImportDialog = ref(false);
const importFile = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const importForm = useForm<{ file: File | null }>({ file: null });
const importErrors = computed<string[]>(() => (importForm.errors.file ?? '').split('\n').filter((l) => l.trim() !== ''));

function downloadExport() {
    void alertInfo('Menyiapkan file ekspor produk...');
    window.location.href = '/products/export';
}
function downloadExportWithComponents() {
    void alertInfo('Menyiapkan file ekspor produk + komponen...');
    window.location.href = '/products/export/with-components';
}
function downloadTemplate() {
    window.location.href = '/products/import/template';
}
function openImportDialog() {
    importFile.value = null;
    importForm.clearErrors();
    importForm.file = null;
    showImportDialog.value = true;
}
function closeImportDialog() {
    if (importForm.processing) return;
    showImportDialog.value = false;
    importFile.value = null;
    importForm.clearErrors();
    importForm.file = null;
    if (fileInput.value) fileInput.value.value = '';
}
function onImportFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importFile.value = file;
    importForm.file = file;
    importForm.clearErrors();
}
function submitImport() {
    if (!importFile.value) {
        importForm.setError('file', 'Pilih file impor terlebih dahulu.');
        return;
    }
    importForm.post('/products/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showImportDialog.value = false;
            importFile.value = null;
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const page = usePage();
const barbershopEnabled = computed(() => Boolean((page.props as { barbershop_enabled?: boolean }).barbershop_enabled));
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));
const sampleServicesForm = useForm({});
function addSampleServices() {
    sampleServicesForm.post('/products/sample-services', { preserveScroll: true });
}

const selectClass =
    'h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <Head title="Produk" />
    <AdminLayout title="Produk">
        <template #header-actions>
            <div class="flex items-center gap-2">
                <Button
                    v-if="barbershopEnabled"
                    variant="outline"
                    class="inline-flex items-center gap-2"
                    :disabled="sampleServicesForm.processing"
                    @click="addSampleServices"
                >
                    <Loader2 v-if="sampleServicesForm.processing" class="h-4 w-4 animate-spin" />
                    <Sparkles v-else class="h-4 w-4" />
                    Tambah Layanan Contoh
                </Button>
                <Button as="a" href="/products/create" class="inline-flex items-center gap-2 bg-primary text-white hover:bg-primary/90">
                    <Plus class="h-4 w-4" />
                    Tambah Produk
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 w-9 p-0" aria-label="Menu lain">
                            <MoreVertical class="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem as-child>
                            <a href="/product-categories" class="cursor-pointer">Kelola kategori</a>
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <a href="/product-option-groups" class="cursor-pointer">Pilihan tambahan (gula, topping)</a>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2" @click="downloadExport">
                            <Download class="h-4 w-4" /> Ekspor Excel
                        </DropdownMenuItem>
                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2" @click="downloadExportWithComponents">
                            <Layers class="h-4 w-4" /> Ekspor + komponen
                        </DropdownMenuItem>
                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2" @click="openImportDialog">
                            <Upload class="h-4 w-4" /> Impor produk
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </template>
        <template #mobile-header-actions>
            <div class="flex items-center gap-1">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-8 w-8 p-0" aria-label="Menu lain">
                            <MoreVertical class="h-3.5 w-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuItem as-child><a href="/product-categories" class="cursor-pointer">Kelola kategori</a></DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2" @click="downloadExport"
                            ><Download class="h-4 w-4" /> Ekspor Excel</DropdownMenuItem
                        >
                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2" @click="openImportDialog"
                            ><Upload class="h-4 w-4" /> Impor produk</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    as="a"
                    href="/products/create"
                    size="sm"
                    class="inline-flex items-center gap-1 bg-primary px-2 py-1 text-xs text-white hover:bg-primary/90"
                >
                    <Plus class="h-3 w-3" /> Tambah
                </Button>
            </div>
        </template>

        <!-- Ringkasan yang bisa diklik -->
        <div class="mb-4 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <button
                v-for="q in [
                    { key: 'all', label: 'Semua produk', count: counts.all, icon: Package },
                    { key: 'low', label: 'Stok rendah', count: counts.low, icon: AlertTriangle },
                    { key: 'out', label: 'Stok habis', count: counts.out, icon: PackageX },
                    { key: 'inactive', label: 'Nonaktif', count: counts.inactive, icon: EyeOff },
                ] as const"
                :key="q.key"
                type="button"
                :aria-pressed="quickFilter === q.key"
                class="flex shrink-0 items-center gap-2.5 rounded-2xl border px-4 py-2.5 text-left transition"
                :class="quickFilter === q.key ? 'border-primary bg-primary/5' : 'border-slate-200 bg-white hover:border-slate-300'"
                @click="setQuickFilter(q.key)"
            >
                <component
                    :is="q.icon"
                    class="h-4 w-4"
                    :class="
                        quickFilter === q.key
                            ? 'text-primary'
                            : q.key === 'low' && q.count > 0
                              ? 'text-amber-500'
                              : q.key === 'out' && q.count > 0
                                ? 'text-red-500'
                                : 'text-slate-400'
                    "
                />
                <span class="text-sm font-semibold text-slate-700">{{ q.label }}</span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-bold"
                    :class="
                        quickFilter === q.key
                            ? 'bg-primary text-white'
                            : q.key === 'low' && q.count > 0
                              ? 'bg-amber-100 text-amber-700'
                              : q.key === 'out' && q.count > 0
                                ? 'bg-red-100 text-red-700'
                                : 'bg-slate-100 text-slate-600'
                    "
                    >{{ q.count }}</span
                >
            </button>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <!-- Pencarian, kategori, urutan, tampilan -->
            <div class="grid grid-cols-[1fr_auto] gap-2 border-b border-slate-100 p-3 sm:flex sm:items-center">
                <div class="relative min-w-0 sm:flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari nama, SKU, atau barcode"
                        class="h-10 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-9 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    />
                </div>
                <div class="flex shrink-0 rounded-xl border border-slate-200 p-0.5 sm:order-last">
                    <button
                        type="button"
                        class="rounded-lg p-2"
                        :class="viewMode === 'list' ? 'bg-primary/10 text-primary' : 'text-slate-400 hover:text-slate-600'"
                        aria-label="Tampilan daftar"
                        :aria-pressed="viewMode === 'list'"
                        @click="viewMode = 'list'"
                    >
                        <List class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-lg p-2"
                        :class="viewMode === 'grid' ? 'bg-primary/10 text-primary' : 'text-slate-400 hover:text-slate-600'"
                        aria-label="Tampilan kotak"
                        :aria-pressed="viewMode === 'grid'"
                        @click="viewMode = 'grid'"
                    >
                        <LayoutGrid class="h-4 w-4" />
                    </button>
                </div>
                <div class="col-span-2 grid grid-cols-2 gap-2 sm:flex">
                    <select v-model="activeCategory" :class="[selectClass, 'w-full min-w-0 sm:w-44']" aria-label="Kategori">
                        <option value="">Semua kategori</option>
                        <option v-for="c in categories ?? []" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <div class="relative min-w-0 sm:w-48">
                        <ArrowDownUp class="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                        <select v-model="sortKey" :class="[selectClass, 'w-full pl-8']" aria-label="Urutkan">
                            <option v-for="o in SORT_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Jenis -->
            <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <button
                    v-for="t in TYPE_TABS"
                    :key="t.key"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition"
                    :class="activeType === t.key ? 'bg-primary text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'"
                    @click="activeType = t.key"
                >
                    <component :is="t.icon" class="h-3.5 w-3.5" />
                    {{ t.label }}
                </button>
            </div>

            <!-- Bilah pilih & aksi massal -->
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-3 py-2 text-sm">
                <button
                    v-if="productsList.length"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-semibold text-slate-600 hover:bg-slate-100"
                    @click="toggleSelectAll"
                >
                    <CheckSquare v-if="allSelected" class="h-4 w-4 text-primary" />
                    <Square v-else class="h-4 w-4 text-slate-400" />
                    {{ allSelected ? 'Batal pilih' : 'Pilih semua' }}
                </button>
                <template v-if="selectedIds.size">
                    <span class="font-semibold text-primary">{{ selectedIds.size }} dipilih</span>
                    <span class="mx-1 h-4 w-px bg-slate-200" />
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkUpdate('activate')"
                    >
                        Aktifkan
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkUpdate('deactivate')"
                    >
                        Nonaktifkan
                    </button>
                    <span v-if="categoryOptions?.length" class="flex items-center gap-1">
                        <select
                            v-model="bulkCategoryId"
                            class="h-8 rounded-lg border border-slate-200 bg-white px-2 text-xs"
                            aria-label="Pindah ke kategori"
                        >
                            <option value="">Pindah kategori…</option>
                            <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <button
                            v-if="bulkCategoryId !== ''"
                            type="button"
                            class="rounded-lg bg-primary px-2 py-1 text-xs font-semibold text-white disabled:opacity-50"
                            :disabled="bulkBusy"
                            @click="bulkUpdate('category')"
                        >
                            Pindahkan
                        </button>
                    </span>
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50"
                        :disabled="bulkBusy"
                        @click="bulkDelete"
                    >
                        Hapus
                    </button>
                    <Loader2 v-if="bulkBusy" class="h-4 w-4 animate-spin text-slate-400" />
                    <button
                        type="button"
                        class="ml-auto rounded-lg p-1 text-slate-400 hover:bg-slate-100"
                        aria-label="Batal pilih"
                        @click="clearSelection"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </template>
                <span v-else class="ml-auto text-xs text-slate-500">{{ productsList.length }} dari {{ meta.total }} produk</span>
            </div>

            <!-- Kosong -->
            <div v-if="productsList.length === 0" class="px-4 py-14 text-center">
                <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100">
                    <Package class="h-6 w-6 text-slate-400" />
                </div>
                <p class="font-semibold text-slate-700">{{ hasFilters ? 'Tidak ada produk yang cocok' : 'Belum ada produk' }}</p>
                <p class="mt-1 text-sm text-slate-500">
                    {{ hasFilters ? 'Coba kata kunci atau filter lain.' : 'Mulai dengan menambahkan produk pertama Anda.' }}
                </p>
                <button v-if="hasFilters" type="button" class="mt-4 text-sm font-semibold text-primary hover:underline" @click="resetFilters">
                    Hapus semua filter
                </button>
                <Button v-else as="a" href="/products/create" class="mt-4 bg-primary text-white hover:bg-primary/90"
                    ><Plus class="mr-1 h-4 w-4" /> Tambah Produk</Button
                >
            </div>

            <!-- Tampilan daftar -->
            <template v-else-if="viewMode === 'list'">
                <!-- Desktop: tabel -->
                <table class="hidden w-full text-sm lg:table">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs font-semibold text-slate-500">
                            <th class="w-10 py-2.5 pl-4"></th>
                            <th class="py-2.5 pr-3">Produk</th>
                            <th class="py-2.5 pr-3">Jenis & kategori</th>
                            <th class="py-2.5 pr-3 text-right">Harga</th>
                            <th class="py-2.5 pr-3 text-right">Stok</th>
                            <th class="py-2.5 pr-3 text-center">Aktif</th>
                            <th class="w-12 py-2.5 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="p in productsList"
                            :key="p.id"
                            class="align-middle hover:bg-slate-50/60"
                            :class="{ 'bg-primary/5': selectedIds.has(p.id) }"
                        >
                            <td class="py-2.5 pl-4">
                                <button type="button" :aria-label="`Pilih ${p.name}`" @click="toggleSelect(p.id)">
                                    <CheckSquare v-if="selectedIds.has(p.id)" class="h-4 w-4 text-primary" />
                                    <Square v-else class="h-4 w-4 text-slate-300 hover:text-slate-400" />
                                </button>
                            </td>
                            <td class="py-2.5 pr-3">
                                <Link :href="`/products/${p.id}/edit`" class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                                        <img v-if="p.image_url" :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" />
                                        <Package v-else class="h-4 w-4 text-slate-400" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold" :class="isActive(p) ? 'text-slate-900' : 'text-slate-400'">{{
                                            p.name
                                        }}</span>
                                        <span v-if="p.sku || p.barcode" class="block truncate text-xs text-slate-400">{{ p.sku || p.barcode }}</span>
                                    </span>
                                </Link>
                            </td>
                            <td class="py-2.5 pr-3">
                                <span class="block text-xs font-semibold text-slate-600">{{
                                    TYPE_LABEL[p.inventory_type ?? 'sellable'] ?? 'Produk jual'
                                }}</span>
                                <span class="block text-xs text-slate-400">{{ p.category || 'Tanpa kategori' }}</span>
                            </td>
                            <td class="py-2.5 pr-3 text-right">
                                <span
                                    class="block font-semibold"
                                    :class="priceInfo(p).muted ? 'text-xs font-medium text-slate-400' : 'text-slate-900'"
                                    >{{ priceInfo(p).main }}</span
                                >
                                <span v-if="priceInfo(p).sub" class="block text-xs text-slate-400">{{ priceInfo(p).sub }}</span>
                            </td>
                            <td class="py-2.5 pr-3 text-right">
                                <span class="font-semibold" :class="STOCK_TONE[stockInfo(p).tone]" :title="stockInfo(p).hint">{{
                                    stockInfo(p).text
                                }}</span>
                                <span v-if="stockInfo(p).tone === 'low'" class="block text-[11px] text-amber-600">stok rendah</span>
                            </td>
                            <td class="py-2.5 pr-3 text-center">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="isActive(p)"
                                    :aria-label="`${isActive(p) ? 'Nonaktifkan' : 'Aktifkan'} ${p.name}`"
                                    :disabled="isToggling(p)"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors disabled:opacity-60"
                                    :class="isActive(p) ? 'bg-primary' : 'bg-slate-300'"
                                    @click="setActive(p, !isActive(p))"
                                >
                                    <span
                                        class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                                        :class="isActive(p) ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                    />
                                </button>
                            </td>
                            <td class="py-2.5 pr-4 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <button
                                            type="button"
                                            class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                            :aria-label="`Aksi ${p.name}`"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-44">
                                        <DropdownMenuItem as-child>
                                            <Link :href="`/products/${p.id}/edit`" class="flex cursor-pointer items-center gap-2"
                                                ><Pencil class="h-4 w-4" /> Ubah</Link
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="p.track_stock !== false" as-child>
                                            <Link :href="`/stock-movements?product_id=${p.id}`" class="flex cursor-pointer items-center gap-2"
                                                ><History class="h-4 w-4" /> Riwayat stok</Link
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem class="flex cursor-pointer items-center gap-2 text-red-600" @click="deleteProduct(p)">
                                            <Trash2 class="h-4 w-4" /> Hapus
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- HP: baris ringkas -->
                <ul class="divide-y divide-slate-100 lg:hidden">
                    <li
                        v-for="p in productsList"
                        :key="p.id"
                        class="flex items-center gap-3 px-3 py-3"
                        :class="{ 'bg-primary/5': selectedIds.has(p.id) }"
                    >
                        <button type="button" :aria-label="`Pilih ${p.name}`" @click="toggleSelect(p.id)">
                            <CheckSquare v-if="selectedIds.has(p.id)" class="h-4 w-4 text-primary" />
                            <Square v-else class="h-4 w-4 text-slate-300" />
                        </button>
                        <Link :href="`/products/${p.id}/edit`" class="flex min-w-0 flex-1 items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" />
                                <Package v-else class="h-4 w-4 text-slate-400" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold" :class="isActive(p) ? 'text-slate-900' : 'text-slate-400'">{{
                                    p.name
                                }}</span>
                                <span class="block truncate text-xs text-slate-400">
                                    {{ TYPE_LABEL[p.inventory_type ?? 'sellable'] ?? 'Produk jual'
                                    }}<template v-if="p.category"> · {{ p.category }}</template>
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <span :class="priceInfo(p).muted ? 'text-slate-400' : 'font-semibold text-slate-800'">{{
                                        priceInfo(p).main
                                    }}</span>
                                    <span :class="STOCK_TONE[stockInfo(p).tone]">· {{ stockInfo(p).text }}</span>
                                </span>
                            </span>
                        </Link>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="isActive(p)"
                            :aria-label="`${isActive(p) ? 'Nonaktifkan' : 'Aktifkan'} ${p.name}`"
                            :disabled="isToggling(p)"
                            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
                            :class="isActive(p) ? 'bg-primary' : 'bg-slate-300'"
                            @click="setActive(p, !isActive(p))"
                        >
                            <span
                                class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                                :class="isActive(p) ? 'translate-x-[18px]' : 'translate-x-0.5'"
                            />
                        </button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button type="button" class="rounded-lg p-1 text-slate-400" :aria-label="`Aksi ${p.name}`">
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-44">
                                <DropdownMenuItem as-child>
                                    <Link :href="`/products/${p.id}/edit`" class="flex items-center gap-2"><Pencil class="h-4 w-4" /> Ubah</Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem v-if="p.track_stock !== false" as-child>
                                    <Link :href="`/stock-movements?product_id=${p.id}`" class="flex items-center gap-2"
                                        ><History class="h-4 w-4" /> Riwayat stok</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="flex items-center gap-2 text-red-600" @click="deleteProduct(p)"
                                    ><Trash2 class="h-4 w-4" /> Hapus</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>
                </ul>
            </template>

            <!-- Tampilan kotak -->
            <div v-else class="grid grid-cols-2 gap-3 p-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                <div
                    v-for="p in productsList"
                    :key="p.id"
                    class="flex flex-col rounded-xl border p-2.5"
                    :class="selectedIds.has(p.id) ? 'border-primary bg-primary/5' : 'border-slate-200'"
                >
                    <div class="relative">
                        <Link
                            :href="`/products/${p.id}/edit`"
                            class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-100"
                        >
                            <img
                                v-if="p.image_url"
                                :src="p.image_url"
                                :alt="p.name"
                                class="h-full w-full object-cover"
                                :class="{ 'opacity-50': !isActive(p) }"
                            />
                            <Package v-else class="h-8 w-8 text-slate-300" />
                        </Link>
                        <button
                            type="button"
                            class="absolute top-1.5 left-1.5 rounded bg-white/90 p-0.5"
                            :aria-label="`Pilih ${p.name}`"
                            @click="toggleSelect(p.id)"
                        >
                            <CheckSquare v-if="selectedIds.has(p.id)" class="h-4 w-4 text-primary" />
                            <Square v-else class="h-4 w-4 text-slate-400" />
                        </button>
                        <span
                            v-if="!isActive(p)"
                            class="absolute top-1.5 right-1.5 rounded-full bg-slate-700 px-2 py-0.5 text-[10px] font-semibold text-white"
                            >Nonaktif</span
                        >
                    </div>
                    <Link :href="`/products/${p.id}/edit`" class="mt-2 line-clamp-2 text-sm font-semibold text-slate-900">{{ p.name }}</Link>
                    <span class="mt-0.5 text-xs text-slate-400">{{ p.category || TYPE_LABEL[p.inventory_type ?? 'sellable'] }}</span>
                    <div class="mt-auto flex items-end justify-between gap-2 pt-2">
                        <span class="min-w-0">
                            <span
                                class="block truncate text-sm"
                                :class="priceInfo(p).muted ? 'text-xs text-slate-400' : 'font-bold text-slate-900'"
                                >{{ priceInfo(p).main }}</span
                            >
                            <span class="block truncate text-xs" :class="STOCK_TONE[stockInfo(p).tone]">{{ stockInfo(p).text }}</span>
                        </span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="isActive(p)"
                            :aria-label="`${isActive(p) ? 'Nonaktifkan' : 'Aktifkan'} ${p.name}`"
                            :disabled="isToggling(p)"
                            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
                            :class="isActive(p) ? 'bg-primary' : 'bg-slate-300'"
                            @click="setActive(p, !isActive(p))"
                        >
                            <span
                                class="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                                :class="isActive(p) ? 'translate-x-[18px]' : 'translate-x-0.5'"
                            />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Halaman -->
            <div v-if="meta.last_page > 1" class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row">
                <p class="text-xs text-slate-500">Halaman {{ meta.current_page }} dari {{ meta.last_page }} · {{ meta.total }} produk</p>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                        :disabled="meta.current_page <= 1"
                        aria-label="Sebelumnya"
                        @click="goToPage(meta.current_page - 1)"
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </button>
                    <template v-for="(pg, idx) in paginationPages" :key="idx">
                        <span v-if="pg === '...'" class="px-1 text-sm text-slate-400">…</span>
                        <button
                            v-else
                            type="button"
                            class="h-8 min-w-8 rounded-lg px-2 text-sm font-semibold"
                            :class="pg === meta.current_page ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-100'"
                            @click="goToPage(pg as number)"
                        >
                            {{ pg }}
                        </button>
                    </template>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page"
                        aria-label="Berikutnya"
                        @click="goToPage(meta.current_page + 1)"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Dialog Impor Produk -->
        <div
            v-if="showImportDialog"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-dialog-title"
            @click.self="closeImportDialog"
        >
            <div class="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl border border-slate-200 bg-white sm:max-w-lg sm:rounded-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 p-4 lg:p-6">
                    <div>
                        <h2 id="import-dialog-title" class="text-lg font-bold text-gray-900">Impor Produk</h2>
                        <p class="mt-1 text-xs text-gray-500 lg:text-sm">
                            Unggah file Excel/CSV untuk menambah produk baru dan memperbarui produk yang sudah ada. File hasil "Ekspor + Komponen"
                            juga memulihkan varian, komponen menu, komponen paket, dan resep.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50"
                        :disabled="importForm.processing"
                        aria-label="Tutup"
                        @click="closeImportDialog"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4 p-4 lg:p-6">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 lg:p-4">
                        <p class="text-xs text-emerald-800 lg:text-sm">
                            Belum punya file? Unduh template beserta panduan pengisiannya terlebih dahulu.
                        </p>
                        <Button variant="outline" size="sm" class="mt-3 inline-flex items-center gap-2 bg-white" @click="downloadTemplate">
                            <FileSpreadsheet class="h-4 w-4" />
                            Unduh Template Impor
                        </Button>
                    </div>

                    <label class="block cursor-pointer">
                        <span class="text-sm font-medium text-gray-700">File impor</span>
                        <div
                            class="mt-2 rounded-2xl border-2 border-dashed p-6 text-center transition-colors"
                            :class="importFile ? 'border-primary bg-primary/5' : 'border-gray-300 hover:border-primary/60'"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                class="hidden"
                                :disabled="importForm.processing"
                                @change="onImportFileChange"
                            />
                            <Upload class="mx-auto mb-2 h-6 w-6" :class="importFile ? 'text-primary' : 'text-gray-400'" />
                            <p v-if="importFile" class="text-sm font-semibold break-all text-primary">{{ importFile.name }}</p>
                            <p v-else class="text-sm text-gray-500">Klik untuk memilih file</p>
                            <p class="mt-1 text-xs text-gray-400">Format .xlsx, .xls, atau .csv &middot; maksimal 5 MB</p>
                        </div>
                    </label>

                    <div v-if="importErrors.length > 0" class="rounded-2xl border border-red-200 bg-red-50 p-3 lg:p-4">
                        <p class="flex items-center gap-2 text-sm font-semibold text-red-700">
                            <AlertTriangle class="h-4 w-4" />
                            Impor dibatalkan, data produk tidak berubah
                        </p>
                        <ul class="mt-2 max-h-52 list-inside list-disc space-y-1 overflow-y-auto">
                            <li v-for="(message, idx) in importErrors" :key="idx" class="text-xs text-red-700">
                                {{ message }}
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-gray-100 p-4 sm:flex-row sm:justify-end lg:p-6">
                    <Button variant="outline" class="w-full sm:w-auto" :disabled="importForm.processing" @click="closeImportDialog"> Batal </Button>
                    <Button
                        class="inline-flex w-full items-center justify-center gap-2 bg-primary text-white hover:bg-primary/90 sm:w-auto"
                        :disabled="importForm.processing || !importFile"
                        @click="submitImport"
                    >
                        <Loader2 v-if="importForm.processing" class="h-4 w-4 animate-spin" />
                        <Upload v-else class="h-4 w-4" />
                        {{ importForm.processing ? 'Memproses...' : 'Impor Sekarang' }}
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
