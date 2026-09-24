<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Package,
    Plus,
    Search,
    Edit,
    Trash2,
    Eye,
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Box,
    Layers,
    ShoppingCart,
    FlaskConical,
    Download,
    Upload,
    FileSpreadsheet,
    X,
    Loader2,
    MoreVertical,
    CheckSquare,
    Square,
    Check,
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { confirmDelete, alertInfo, alertSuccess } from '@/lib/swal';
import AdminLayout from '@/layouts/AdminLayout.vue';

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function formatStock(qty: number | string): string {
    const num = Number(qty);
    if (Number.isInteger(num)) return num.toString();
    return num.toFixed(4).replace(/\.?0+$/, '');
}

interface Product {
    id: number;
    name: string;
    sku: string;
    barcode: string;
    price: number;
    stock_quantity: number;
    min_stock_level: number;
    unlimited_stock?: boolean;
    category: string;
    brand: string;
    unit: string;
    is_active: boolean;
    is_low_stock: boolean;
    image_url?: string;
    inventory_type?: string;
}

interface PaginatedProducts {
    data: Product[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
    path: string;
}

interface Props {
    products: PaginatedProducts;
    filters?: { search?: string; inventory_type?: string; category?: string };
    store: { id: number; name: string; currency: string };
    categories?: string[];
    inventoryTypes?: Record<string, string>;
    lowStockCount?: number;
}

const props = defineProps<Props>();

const productsList = computed(() => props.products.data ?? []);
const meta = computed(() => ({
    total: props.products.total,
    per_page: props.products.per_page,
    current_page: props.products.current_page,
    last_page: props.products.last_page,
}));

const searchQuery = ref(props.filters?.search ?? '');
const activeInventoryType = ref(props.filters?.inventory_type ?? '');
const activeCategory = ref(props.filters?.category ?? '');
const viewMode = ref<'grid' | 'list'>('list');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const typeTabs = computed(() => {
    const tabs: { key: string; label: string; icon: typeof Package }[] = [
        { key: '', label: 'Semua', icon: Layers },
        { key: 'sellable', label: 'Produk Jual', icon: ShoppingCart },
        { key: 'raw_material', label: 'Bahan Baku', icon: Box },
        { key: 'packaging', label: 'Kemasan', icon: Package },
        { key: 'production_output', label: 'Hasil Produksi', icon: FlaskConical },
    ];
    return tabs;
});

const categoriesList = computed(() => props.categories ?? []);

function applyFilters(overrides: { search?: string; inventory_type?: string; category?: string } = {}) {
    const params: Record<string, string> = {};
    const s = overrides.search !== undefined ? overrides.search : searchQuery.value;
    const it = overrides.inventory_type !== undefined ? overrides.inventory_type : activeInventoryType.value;
    const cat = overrides.category !== undefined ? overrides.category : activeCategory.value;
    if (s) params.search = s;
    if (it) params.inventory_type = it;
    if (cat) params.category = cat;
    router.get('/products', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(searchQuery, (val) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters({ search: val || '' });
    }, 350);
});

function setType(type: string) {
    activeInventoryType.value = type;
    applyFilters({ inventory_type: type });
}

function setCategory(cat: string) {
    activeCategory.value = activeCategory.value === cat ? '' : cat;
    applyFilters({ category: activeCategory.value });
}

function goToPage(page: number) {
    if (page < 1 || page > meta.value.last_page) return;
    const params: Record<string, string | number> = { page };
    if (searchQuery.value) params.search = searchQuery.value;
    if (activeInventoryType.value) params.inventory_type = activeInventoryType.value;
    if (activeCategory.value) params.category = activeCategory.value;
    router.get('/products', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

const paginationPages = computed(() => {
    const last = meta.value.last_page;
    const current = meta.value.current_page;
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);

    const pages: (number | '...')[] = [];
    pages.push(1);
    if (current > 3) pages.push('...');

    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);
    for (let i = start; i <= end; i++) pages.push(i);

    if (current < last - 2) pages.push('...');
    pages.push(last);
    return pages;
});

const deleteProduct = async (productId: number) => {
    if (!(await confirmDelete('Hapus produk?', 'Produk akan dihapus permanen dari daftar.'))) return;
    router.delete(`/products/${productId}`);
};

// ── Hapus Massal ───────────────────────────────────────────────────────
const selectedIds = ref<Set<number>>(new Set());
const bulkDeleting = ref(false);

const allCurrentPageSelected = computed(() =>
    productsList.value.length > 0 && productsList.value.every((p) => selectedIds.value.has(p.id)),
);

const toggleSelectAll = () => {
    if (allCurrentPageSelected.value) {
        // Unselect semua produk di halaman ini
        const pageIds = new Set(productsList.value.map((p) => p.id));
        const newSet = new Set(selectedIds.value);
        pageIds.forEach((id) => newSet.delete(id));
        selectedIds.value = newSet;
    } else {
        // Select semua produk di halaman ini
        const newSet = new Set(selectedIds.value);
        productsList.value.forEach((p) => newSet.add(p.id));
        selectedIds.value = newSet;
    }
};

const toggleSelect = (id: number) => {
    const newSet = new Set(selectedIds.value);
    if (newSet.has(id)) {
        newSet.delete(id);
    } else {
        newSet.add(id);
    }
    selectedIds.value = newSet;
};

const bulkDelete = async () => {
    if (selectedIds.value.size === 0) return;
    const count = selectedIds.value.size;
    if (!(await confirmDelete(
        `Hapus ${count} produk?`,
        'Produk akan dihapus permanen dari daftar.',
    ))) return;

    bulkDeleting.value = true;
    try {
        const res = await fetch('/products/bulk-delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''),
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ids: [...selectedIds.value],
            }),
        });
        const response = await res.json();
        const deleted = response?.deleted ?? 0;
        selectedIds.value = new Set();
        router.reload({ only: ['products'] });
        alertSuccess('Berhasil', `${deleted} produk berhasil dihapus`);
    } catch {
        // error ditangkap di akhir
    } finally {
        bulkDeleting.value = false;
    }
};

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', { style: 'decimal', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);

// ── Ekspor & impor produk ────────────────────────────────────────────────
const showImportDialog = ref(false);
const importFile = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const importForm = useForm<{ file: File | null }>({ file: null });

const importErrors = computed<string[]>(() => {
    const message = importForm.errors.file;
    if (!message) return [];
    return message.split('\n').filter((line) => line.trim() !== '');
});

function downloadExport() {
    alertInfo('Menyiapkan file ekspor produk...');
    window.location.href = '/products/export';
}

function downloadExportWithComponents() {
    alertInfo('Menyiapkan file ekspor produk + komponen...');
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
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
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
</script>

<template>
    <Head title="Manajemen Produk" />
    <AdminLayout title="Manajemen Produk">
        <template #header-actions>
            <div class="flex items-center gap-2">
                <Button as="a" href="/products/create" class="inline-flex items-center gap-2 bg-primary hover:bg-primary/90 text-white">
                    <Plus class="h-4 w-4" />
                    Tambah Produk
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 w-9 p-0">
                            <MoreVertical class="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuItem as-child>
                            <a href="/product-categories" class="flex items-center gap-2 cursor-pointer">
                                Kelola Kategori
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="flex items-center gap-2 cursor-pointer" @click="downloadExport">
                            <Download class="h-4 w-4" />
                            Ekspor Excel
                        </DropdownMenuItem>
                        <DropdownMenuItem class="flex items-center gap-2 cursor-pointer" @click="downloadExportWithComponents">
                            <Layers class="h-4 w-4" />
                            Ekspor + Komponen
                        </DropdownMenuItem>
                        <DropdownMenuItem class="flex items-center gap-2 cursor-pointer" @click="openImportDialog">
                            <Upload class="h-4 w-4" />
                            Impor Produk
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </template>
        <template #mobile-header-actions>
            <div class="flex items-center gap-1">
                <Button variant="outline" size="sm" class="h-8 w-8 p-0" title="Ekspor Excel" @click="downloadExport">
                    <Download class="h-3.5 w-3.5" />
                </Button>
                <Button variant="outline" size="sm" class="h-8 w-8 p-0" title="Impor produk" @click="openImportDialog">
                    <Upload class="h-3.5 w-3.5" />
                </Button>
                <Button as="a" href="/products/create" size="sm" class="inline-flex items-center gap-1 bg-primary hover:bg-primary/90 text-white text-xs px-2 py-1">
                    <Plus class="h-3 w-3" />
                    Tambah
                </Button>
            </div>
        </template>

        <!-- Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4 mb-4 lg:mb-6">
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 lg:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs lg:text-sm font-medium text-gray-600">Total Produk</p>
                            <p class="text-lg lg:text-2xl font-bold text-gray-900">{{ meta.total }}</p>
                        </div>
                        <div class="p-2 lg:p-3 bg-primary/10 rounded-full">
                            <Package class="h-4 w-4 lg:h-6 lg:w-6 text-primary" />
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 lg:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs lg:text-sm font-medium text-gray-600">Stok Rendah</p>
                            <p class="text-lg lg:text-2xl font-bold text-orange-600">{{ lowStockCount ?? 0 }}</p>
                        </div>
                        <div class="p-2 lg:p-3 bg-orange-100 rounded-full">
                            <AlertTriangle class="h-4 w-4 lg:h-6 lg:w-6 text-orange-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 lg:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs lg:text-sm font-medium text-gray-600">Produk Aktif</p>
                            <p class="text-lg lg:text-2xl font-bold text-green-600">{{ productsList.filter(p => p.is_active).length }}</p>
                        </div>
                        <div class="p-2 lg:p-3 bg-green-100 rounded-full">
                            <Eye class="h-4 w-4 lg:h-6 lg:w-6 text-green-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>
            <Card class="border-0 shadow-lg">
                <CardContent class="p-4 lg:p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs lg:text-sm font-medium text-gray-600">Halaman</p>
                            <p class="text-lg lg:text-2xl font-bold text-purple-600">{{ meta.current_page }} / {{ meta.last_page }}</p>
                        </div>
                        <div class="p-2 lg:p-3 bg-purple-100 rounded-full">
                            <ChevronRight class="h-4 w-4 lg:h-6 lg:w-6 text-purple-600" />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Search -->
        <Card class="border-0 shadow-lg mb-4 lg:mb-6">
            <CardContent class="p-4 lg:p-6">
                <div class="flex flex-col lg:flex-row gap-3 lg:gap-4">
                    <div class="flex-1 relative">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Cari produk, SKU, barcode, kategori..."
                            class="pl-10 h-10 lg:h-12"
                        />
                    </div>
                    <div class="hidden lg:flex items-center gap-2">
                        <Button :variant="viewMode === 'grid' ? 'default' : 'outline'" size="sm" @click="viewMode = 'grid'" class="h-8 px-3 text-xs">
                            Grid
                        </Button>
                        <Button :variant="viewMode === 'list' ? 'default' : 'outline'" size="sm" @click="viewMode = 'list'" class="h-8 px-3 text-xs">
                            List
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Tabs: Tipe Inventory -->
        <div class="border-b border-gray-200 mb-4 lg:mb-6">
            <div class="flex gap-5 lg:gap-6 overflow-x-auto scrollbar-none -mb-px">
                <button
                    v-for="tab in typeTabs"
                    :key="tab.key"
                    @click="setType(tab.key)"
                    class="inline-flex items-center gap-1.5 pb-3 text-xs lg:text-sm font-medium transition-colors whitespace-nowrap border-b-2 -mb-px"
                    :class="activeInventoryType === tab.key
                        ? 'border-primary text-primary'
                        : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                </button>
            </div>
        </div>

        <!-- Tabs: Kategori (muncul jika ada kategori) -->
        <div v-if="categoriesList.length > 0" class="mb-4 lg:mb-6">
            <div class="flex gap-1.5 items-center flex-wrap overflow-x-auto scrollbar-none lg:overflow-visible">
                <span class="inline-flex items-center px-2 py-1 text-[10px] font-semibold text-gray-500 uppercase tracking-wider shrink-0">
                    Kategori:
                </span>
                <button
                    v-for="cat in categoriesList"
                    :key="cat"
                    @click="setCategory(cat)"
                    class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium transition-all whitespace-nowrap shrink-0"
                    :class="activeCategory === cat
                        ? 'bg-emerald-600 text-white shadow-md'
                        : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200'"
                >
                    {{ cat }}
                </button>
            </div>
        </div>

        <!-- Active filters indicator -->
        <div v-if="activeCategory" class="mb-3 lg:mb-4 flex items-center gap-2 flex-wrap">
            <span class="text-xs text-gray-500">Filter aktif:</span>
            <Badge variant="secondary" class="text-xs cursor-pointer hover:bg-gray-300" @click="setCategory('')">
                {{ activeCategory }}
                <span class="ml-1">&times;</span>
            </Badge>
            <button
                @click="activeCategory = ''; applyFilters({ category: '' })"
                class="text-xs text-red-500 hover:text-red-700 underline"
            >
                Hapus semua
            </button>
        </div>

        <!-- Products -->
        <Card class="border-0 shadow-lg mb-6">
            <CardHeader class="pb-3 lg:pb-4 px-4 lg:px-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <CardTitle class="text-lg lg:text-xl font-bold text-gray-900">Daftar Produk</CardTitle>
                        <CardDescription class="text-sm lg:text-base text-gray-600">
                            Menampilkan {{ productsList.length }} dari {{ meta.total }} produk
                            <span v-if="selectedIds.size > 0" class="ml-1 text-primary font-semibold">
                                &middot; {{ selectedIds.size }} dipilih
                            </span>
                        </CardDescription>
                    </div>
                    <div v-if="productsList.length > 0" class="flex items-center gap-2">
                        <!-- Select all toggle -->
                        <button
                            @click="toggleSelectAll"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium border transition-colors"
                            :class="allCurrentPageSelected
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                        >
                            <CheckSquare v-if="allCurrentPageSelected" class="h-3.5 w-3.5" />
                            <Square v-else class="h-3.5 w-3.5" />
                            {{ allCurrentPageSelected ? 'Batal Pilih' : 'Pilih Semua' }}
                        </button>
                        <!-- Bulk delete -->
                        <Button
                            v-if="selectedIds.size > 0"
                            variant="destructive"
                            size="sm"
                            :disabled="bulkDeleting"
                            class="h-9 text-xs inline-flex items-center gap-1.5"
                            @click="bulkDelete"
                        >
                            <Trash2 v-if="!bulkDeleting" class="h-3.5 w-3.5" />
                            <Loader2 v-else class="h-3.5 w-3.5 animate-spin" />
                            Hapus {{ selectedIds.size }} Produk
                        </Button>
                    </div>
                </div>
            </CardHeader>

            <CardContent class="px-4 lg:px-6">
                <!-- Grid View -->
                <div v-if="viewMode === 'grid'" class="hidden lg:grid gap-3 lg:gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <Card v-for="product in productsList" :key="product.id" class="hover:shadow-lg transition-all duration-200 border-0 shadow-md">
                        <CardContent class="p-3 lg:p-4">
                            <!-- Checkbox -->
                            <div class="flex justify-end mb-1">
                                <button
                                    @click="toggleSelect(product.id)"
                                    class="p-0.5 rounded-lg transition-colors"
                                >
                                    <CheckSquare v-if="selectedIds.has(product.id)" class="h-5 w-5 text-primary" />
                                    <Square v-else class="h-5 w-5 text-gray-300 hover:text-gray-400" />
                                </button>
                            </div>
                            <div class="aspect-square bg-gray-100 rounded-2xl mb-2 lg:mb-3 flex items-center justify-center overflow-hidden">
                                <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="w-full h-full object-cover rounded-2xl" />
                                <Package v-else class="h-8 w-8 text-gray-400" />
                            </div>
                            <h3 class="font-semibold text-sm mb-2 line-clamp-2 text-gray-900">{{ product.name }}</h3>
                            <p class="text-base lg:text-lg font-bold text-primary mb-2 lg:mb-3">{{ formatCurrency(product.price) }}</p>
                            <div class="flex items-center justify-between mb-3 lg:mb-4">
                                <Badge v-if="product.unlimited_stock" class="text-xs bg-emerald-100 text-emerald-700 border-emerald-200">Stok: Unlimited</Badge>
                                <Badge v-else :variant="product.stock_quantity > 0 ? 'default' : 'destructive'" class="text-xs">
                                    Stok: {{ formatStock(product.stock_quantity) }} {{ product.unit }}
                                </Badge>
                                <Badge v-if="product.is_low_stock" variant="destructive" class="text-xs">
                                    <AlertTriangle class="h-3 w-3 mr-1" />Rendah
                                </Badge>
                            </div>
                            <div class="flex gap-2">
                                <Button as="a" :href="`/products/${product.id}/edit`" variant="outline" size="sm" class="flex-1 h-9 text-xs">
                                    <Edit class="h-3 w-3 mr-1" />Edit
                                </Button>
                                <Button variant="outline" size="sm" @click="deleteProduct(product.id)" class="h-9 w-9 p-0 text-red-600 hover:text-red-700 hover:bg-red-50">
                                    <Trash2 class="h-3 w-3" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- List View -->
                <div class="space-y-3">
                    <div
                        v-for="product in productsList"
                        :key="'list-' + product.id"
                        class="flex items-center gap-3 sm:gap-4 p-3 lg:p-4 bg-gray-50 rounded-2xl border transition-colors"
                        :class="selectedIds.has(product.id) ? 'border-primary bg-primary/5' : 'hover:bg-gray-100'"
                    >
                        <!-- Checkbox -->
                        <button
                            @click="toggleSelect(product.id)"
                            class="p-0.5 rounded-lg transition-colors shrink-0"
                        >
                            <CheckSquare v-if="selectedIds.has(product.id)" class="h-5 w-5 text-primary" />
                            <Square v-else class="h-5 w-5 text-gray-300 hover:text-gray-400" />
                        </button>
                        <div class="w-16 h-16 bg-gray-200 rounded-2xl flex items-center justify-center overflow-hidden flex-shrink-0">
                            <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="w-full h-full object-cover rounded-2xl" />
                            <Package v-else class="h-6 w-6 text-gray-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-gray-900 truncate text-sm sm:text-base">{{ product.name }}</h3>
                                <Badge v-if="product.inventory_type === 'raw_material'" class="text-[10px] bg-amber-100 text-amber-700 border-amber-200 hidden sm:inline-flex">Bahan</Badge>
                                <Badge v-else-if="product.inventory_type === 'packaging'" class="text-[10px] bg-blue-100 text-blue-700 border-blue-200 hidden sm:inline-flex">Kemasan</Badge>
                                <Badge v-else-if="product.inventory_type === 'production_output'" class="text-[10px] bg-purple-100 text-purple-700 border-purple-200 hidden sm:inline-flex">Hasil Produksi</Badge>
                            </div>
                            <p class="text-sm sm:text-lg font-bold text-primary mt-1">{{ formatCurrency(product.price) }}</p>
                            <div class="flex items-center gap-2 mt-2">
                                <Badge v-if="product.unlimited_stock" class="text-xs bg-emerald-100 text-emerald-700 border-emerald-200">Unlimited {{ product.unit }}</Badge>
                                <Badge v-else :variant="product.stock_quantity > 0 ? 'default' : 'destructive'" class="text-xs">
                                    {{ formatStock(product.stock_quantity) }} {{ product.unit }}
                                </Badge>
                                <Badge v-if="product.is_low_stock" variant="destructive" class="text-xs">
                                    <AlertTriangle class="h-3 w-3 mr-1" />Rendah
                                </Badge>
                                <Badge v-if="product.category" variant="outline" class="text-xs text-gray-500 hidden sm:inline-flex">
                                    {{ product.category }}
                                </Badge>
                            </div>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            <Button as="a" :href="`/products/${product.id}/edit`" variant="outline" size="sm" class="h-8 w-8 p-0">
                                <Edit class="h-3 w-3" />
                            </Button>
                            <Button variant="outline" size="sm" @click="deleteProduct(product.id)" class="h-8 w-8 p-0 text-red-600 hover:text-red-700 hover:bg-red-50">
                                <Trash2 class="h-3 w-3" />
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="productsList.length === 0" class="text-center py-8 lg:py-12">
                    <div class="p-4 bg-gray-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                        <Package class="h-8 w-8 text-gray-400" />
                    </div>
                    <p class="text-gray-500 text-base lg:text-lg">Tidak ada produk ditemukan</p>
                    <p class="text-gray-400 text-xs lg:text-sm mt-2">
                        {{ (searchQuery || activeInventoryType || activeCategory) ? 'Coba kata kunci atau filter yang berbeda' : 'Mulai dengan menambahkan produk pertama Anda' }}
                    </p>
                    <Button v-if="!searchQuery && !activeInventoryType && !activeCategory" as="a" href="/products/create" class="mt-4 bg-primary hover:bg-primary/90 text-white text-sm lg:text-base">
                        <Plus class="h-4 w-4 mr-2" />
                        Tambah Produk Pertama
                    </Button>
                </div>

                <!-- Pagination -->
                <div v-if="meta.last_page > 1" class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6 pt-4 border-t border-gray-100">
                    <p class="text-xs sm:text-sm text-gray-500">
                        Halaman {{ meta.current_page }} dari {{ meta.last_page }} ({{ meta.total }} produk)
                    </p>
                    <div class="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="meta.current_page <= 1"
                            @click="goToPage(meta.current_page - 1)"
                            class="h-9 px-3 text-xs"
                        >
                            <ChevronLeft class="h-4 w-4 mr-1" />
                            Prev
                        </Button>

                        <template v-for="(page, idx) in paginationPages" :key="idx">
                            <span v-if="page === '...'" class="px-2 text-gray-400 text-sm">...</span>
                            <Button
                                v-else
                                :variant="page === meta.current_page ? 'default' : 'outline'"
                                size="sm"
                                @click="goToPage(page as number)"
                                class="h-9 w-9 p-0 text-xs"
                                :class="page === meta.current_page ? 'bg-primary text-white shadow-md' : ''"
                            >
                                {{ page }}
                            </Button>
                        </template>

                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="meta.current_page >= meta.last_page"
                            @click="goToPage(meta.current_page + 1)"
                            class="h-9 px-3 text-xs"
                        >
                            Next
                            <ChevronRight class="h-4 w-4 ml-1" />
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Dialog Impor Produk -->
        <div
            v-if="showImportDialog"
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-dialog-title"
            @click.self="closeImportDialog"
        >
            <div class="w-full sm:max-w-lg bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-3 p-4 lg:p-6 border-b border-gray-100">
                    <div>
                        <h2 id="import-dialog-title" class="text-lg font-bold text-gray-900">Impor Produk</h2>
                        <p class="text-xs lg:text-sm text-gray-500 mt-1">
                            Unggah file Excel/CSV untuk menambah produk baru dan memperbarui produk yang sudah ada.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="p-1 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 disabled:opacity-50"
                        :disabled="importForm.processing"
                        aria-label="Tutup"
                        @click="closeImportDialog"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-4 lg:p-6 space-y-4">
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-3 lg:p-4">
                        <p class="text-xs lg:text-sm text-emerald-800">
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
                            <p v-if="importFile" class="text-sm font-semibold text-primary break-all">{{ importFile.name }}</p>
                            <p v-else class="text-sm text-gray-500">Klik untuk memilih file</p>
                            <p class="mt-1 text-xs text-gray-400">Format .xlsx, .xls, atau .csv &middot; maksimal 5 MB</p>
                        </div>
                    </label>

                    <div v-if="importErrors.length > 0" class="rounded-2xl bg-red-50 border border-red-200 p-3 lg:p-4">
                        <p class="text-sm font-semibold text-red-700 flex items-center gap-2">
                            <AlertTriangle class="h-4 w-4" />
                            Impor dibatalkan, data produk tidak berubah
                        </p>
                        <ul class="mt-2 space-y-1 max-h-52 overflow-y-auto list-disc list-inside">
                            <li v-for="(message, idx) in importErrors" :key="idx" class="text-xs text-red-700">
                                {{ message }}
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 p-4 lg:p-6 border-t border-gray-100">
                    <Button variant="outline" class="w-full sm:w-auto" :disabled="importForm.processing" @click="closeImportDialog">
                        Batal
                    </Button>
                    <Button
                        class="w-full sm:w-auto bg-primary hover:bg-primary/90 text-white inline-flex items-center justify-center gap-2"
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
