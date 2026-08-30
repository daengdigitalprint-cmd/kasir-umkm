<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Package, Send, CheckSquare, Square, Building2, CircleCheck, CircleX, AlertTriangle } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { confirmDialog } from '@/lib/swal';
import AdminLayout from '@/layouts/AdminLayout.vue';

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

interface SourceInfo {
    id: number;
    name: string;
    type: 'central_warehouse' | 'admin_store';
    raw_material_count: number;
    sellable_count: number;
    total_count: number;
}

interface OutletInfo {
    id: number;
    name: string;
    location_type: string;
    current_product_count: number;
}

interface CopyResult {
    outlet_name: string;
    copied: number;
    skipped: number;
    errors: string[];
}

interface Props {
    sources: SourceInfo[];
    defaultSourceId: number | null;
    outlets: OutletInfo[];
}

const props = defineProps<Props>();
const selectedOutlets = ref<Set<number>>(new Set());
const loading = ref(false);
const selectedSourceId = ref<number | null>(props.defaultSourceId);
const copyType = ref<'raw_material' | 'sellable' | 'all'>('raw_material');
const copyResult = ref<{
    success: boolean;
    total_copied: number;
    total_skipped: number;
    outlet_count: number;
    results: CopyResult[];
    errors: string[];
} | null>(null);

const selectedSource = computed(() =>
    props.sources.find((s) => s.id === selectedSourceId.value),
);

const sourceProductCount = computed(() => {
    if (!selectedSource.value) return 0;
    if (copyType.value === 'raw_material') return selectedSource.value.raw_material_count;
    if (copyType.value === 'sellable') return selectedSource.value.sellable_count;
    return selectedSource.value.total_count;
});

const copyTypeOptions = [
    {
        value: 'raw_material',
        label: 'Salin Bahan Baku',
        description: 'Menyalin produk bahan mentah & kemasan (raw material, packaging). Cocok untuk outlet yang baru mulai produksi.',
        icon: Package,
    },
    {
        value: 'sellable',
        label: 'Salin Produk Jual',
        description: 'Menyalin produk yang dijual di POS & hasil produksi (sellable, production_output). Untuk outlet siap jual.',
        icon: Package,
    },
    {
        value: 'all',
        label: 'Salin Semua',
        description: 'Menyalin seluruh produk aktif dari sumber (bahan baku + produk jual). Paling lengkap.',
        icon: Package,
    },
];

const allSelected = computed(() =>
    props.outlets.length > 0 && selectedOutlets.value.size === props.outlets.length,
);

const toggleAll = () => {
    if (allSelected.value) {
        selectedOutlets.value.clear();
    } else {
        selectedOutlets.value = new Set(props.outlets.map((o) => o.id));
    }
};

const toggleOutlet = (id: number) => {
    if (selectedOutlets.value.has(id)) {
        selectedOutlets.value.delete(id);
    } else {
        selectedOutlets.value.add(id);
    }
};

const fmtSourceType = (t: string) => {
    if (t === 'central_warehouse') return 'Gudang Pusat';
    if (t === 'admin_store') return 'Toko Admin';
    return t;
};

const copyTypeLabel = computed(() => copyTypeOptions.find((o) => o.value === copyType.value)?.label ?? 'Salin');

const copyToSelected = async () => {
    if (selectedOutlets.value.size === 0 || !selectedSourceId.value) return;
    const count = selectedOutlets.value.size;
    const srcName = selectedSource.value?.name ?? '-';
    const typeName = copyTypeLabel.value;

    if (!(await confirmDialog(
        `${typeName} dari ${srcName} ke ${count} outlet?`,
        `Produk yang sudah ada (berdasarkan SKU) akan dilewat.`,
        { confirmText: 'Salin Sekarang', icon: 'question' },
    ))) return;

    loading.value = true;
    copyResult.value = null;

    try {
        const res = await fetch('/manage-products/copy', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                outlet_ids: [...selectedOutlets.value],
                source_store_id: selectedSourceId.value,
                copy_type: copyType.value,
            }),
        });
        const response = await res.json();

        if (!res.ok) {
            copyResult.value = {
                success: false,
                total_copied: 0,
                total_skipped: 0,
                outlet_count: 0,
                results: [],
                errors: [response?.error || 'Terjadi kesalahan saat menyalin produk.'],
            };
        } else {
            copyResult.value = response;
            router.reload({ only: ['outlets'], preserveScroll: true });
        }
    } catch (err: any) {
        copyResult.value = {
            success: false,
            total_copied: 0,
            total_skipped: 0,
            outlet_count: 0,
            results: [],
            errors: [err?.message || 'Terjadi kesalahan saat menyalin produk.'],
        };
    } finally {
        loading.value = false;
    }
};

watch(selectedSourceId, () => { copyResult.value = null; });
watch(copyType, () => { copyResult.value = null; });
</script>

<template>
    <Head title="Kelola Produk" />
    <AdminLayout title="Kelola Produk">
        <template #header-actions>
            <Button variant="outline" size="sm" as-child class="rounded-xl">
                <a href="/dashboard" class="inline-flex items-center gap-2">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </a>
            </Button>
        </template>
        <template #mobile-header-actions>
            <Button variant="ghost" size="sm" as-child class="rounded-xl text-slate-600">
                <a href="/dashboard" class="inline-flex items-center justify-center">
                    <ArrowLeft class="h-5 w-5" />
                </a>
            </Button>
        </template>

        <!-- Info banner -->
        <Card class="border-0 shadow-lg mb-6 border-emerald-200 bg-emerald-50">
            <CardContent class="p-4 sm:p-6">
                <div class="flex items-start gap-3">
                    <Building2 class="h-5 w-5 text-emerald-600 mt-0.5 shrink-0" />
                    <div>
                        <p class="text-sm font-bold text-emerald-900">Salin Produk ke Outlet</p>
                        <p class="text-xs text-emerald-700 mt-1">
                            Pilih sumber, jenis penyalinan, dan outlet tujuan untuk menyalin produk.
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Pilihan Sumber & Tipe Penyalinan -->
        <div class="grid gap-6 lg:grid-cols-2 mb-6">
            <!-- Sumber Produk -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-3 px-4 sm:px-6">
                    <CardTitle class="text-base font-bold text-gray-900">Sumber Produk</CardTitle>
                    <CardDescription class="text-xs">Pilih dari mana produk akan disalin</CardDescription>
                </CardHeader>
                <CardContent class="px-4 sm:px-6 space-y-2">
                    <div v-if="sources.length === 0" class="text-center py-6 text-sm text-gray-500">
                        Tidak ada sumber produk tersedia.
                    </div>
                    <button
                        v-for="src in sources"
                        :key="src.id"
                        class="w-full flex items-center gap-3 p-3 rounded-xl border transition-colors text-left"
                        :class="selectedSourceId === src.id ? 'border-primary bg-primary/5' : 'border-gray-200 hover:bg-gray-50'"
                        @click="selectedSourceId = src.id"
                    >
                        <div class="shrink-0">
                            <CheckSquare v-if="selectedSourceId === src.id" class="h-5 w-5 text-primary" />
                            <Square v-else class="h-5 w-5 text-gray-300" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ src.name }}</p>
                            <p class="text-[11px] text-gray-500">
                                {{ fmtSourceType(src.type) }} &middot;
                                <span v-if="src.raw_material_count > 0">{{ src.raw_material_count }} bahan baku</span>
                                <span v-if="src.raw_material_count > 0 && src.sellable_count > 0"> &middot; </span>
                                <span v-if="src.sellable_count > 0">{{ src.sellable_count }} produk jual</span>
                            </p>
                        </div>
                        <Badge variant="secondary" class="text-[10px] shrink-0">
                            {{ src.total_count }} produk
                        </Badge>
                    </button>
                </CardContent>
            </Card>

            <!-- Tipe Penyalinan -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-3 px-4 sm:px-6">
                    <CardTitle class="text-base font-bold text-gray-900">Jenis Penyalinan</CardTitle>
                    <CardDescription class="text-xs">Pilih jenis produk yang ingin disalin</CardDescription>
                </CardHeader>
                <CardContent class="px-4 sm:px-6 space-y-2">
                    <button
                        v-for="opt in copyTypeOptions"
                        :key="opt.value"
                        class="w-full flex items-start gap-3 p-3 rounded-xl border transition-colors text-left"
                        :class="copyType === opt.value ? 'border-primary bg-primary/5' : 'border-gray-200 hover:bg-gray-50'"
                        @click="copyType = opt.value as any"
                    >
                        <div class="shrink-0 mt-0.5">
                            <CheckSquare v-if="copyType === opt.value" class="h-5 w-5 text-primary" />
                            <Square v-else class="h-5 w-5 text-gray-300" />
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ opt.label }}</p>
                            <p class="text-[11px] text-gray-500 mt-0.5">{{ opt.description }}</p>
                        </div>
                    </button>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Daftar Outlet -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-3 px-4 sm:px-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-base font-bold text-gray-900">Outlet Tujuan</CardTitle>
                            <CardDescription class="text-xs">Pilih outlet yang ingin menerima salinan produk</CardDescription>
                        </div>
                        <button
                            class="text-xs font-medium text-primary hover:underline"
                            @click="toggleAll"
                        >
                            {{ allSelected ? 'Batal pilih' : 'Pilih semua' }}
                        </button>
                    </div>
                </CardHeader>
                <CardContent class="px-4 sm:px-6 space-y-2">
                    <div v-if="outlets.length === 0" class="text-center py-6 text-sm text-gray-500">
                        Tidak ada outlet lain dalam grup ini.
                    </div>
                    <button
                        v-for="outlet in outlets"
                        :key="outlet.id"
                        class="w-full flex items-center gap-3 p-3 rounded-xl border transition-colors text-left"
                        :class="selectedOutlets.has(outlet.id) ? 'border-primary bg-primary/5' : 'border-gray-200 hover:bg-gray-50'"
                        @click="toggleOutlet(outlet.id)"
                    >
                        <div class="shrink-0">
                            <CheckSquare v-if="selectedOutlets.has(outlet.id)" class="h-5 w-5 text-primary" />
                            <Square v-else class="h-5 w-5 text-gray-300" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ outlet.name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ outlet.current_product_count }} produk saat ini
                            </p>
                        </div>
                    </button>
                </CardContent>
            </Card>

            <!-- Ringkasan & Tombol Aksi -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="pb-3 px-4 sm:px-6">
                    <div>
                        <CardTitle class="text-base font-bold text-gray-900">Ringkasan</CardTitle>
                        <CardDescription class="text-xs">Detail penyalinan yang akan dilakukan</CardDescription>
                    </div>
                </CardHeader>
                <CardContent class="px-4 sm:px-6 space-y-4">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Sumber</span>
                            <span class="font-medium text-gray-900">{{ selectedSource?.name ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Jenis Penyalinan</span>
                            <span class="font-medium text-gray-900">{{ copyTypeLabel }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Produk Akan Disalin</span>
                            <span class="font-medium text-gray-900">{{ sourceProductCount }} produk</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Outlet Dipilih</span>
                            <span class="font-medium text-gray-900">{{ selectedOutlets.size }} outlet</span>
                        </div>
                    </div>

                    <div class="border-t pt-4">
                        <Button
                            :disabled="selectedOutlets.size === 0 || loading || !selectedSourceId"
                            @click="copyToSelected"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white"
                        >
                            <Send class="h-4 w-4 mr-2" />
                            {{ loading ? 'Menyalin...' : `${copyTypeLabel} ke ${selectedOutlets.size} Outlet` }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Hasil Verifikasi -->
        <Card v-if="copyResult" class="border-0 shadow-lg mt-6">
            <CardHeader class="pb-3 px-4 sm:px-6">
                <div class="flex items-center gap-2">
                    <div v-if="copyResult.success" class="rounded-full bg-emerald-100 p-1">
                        <CircleCheck class="h-4 w-4 text-emerald-600" />
                    </div>
                    <div v-else class="rounded-full bg-red-100 p-1">
                        <CircleX class="h-4 w-4 text-red-600" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-bold text-gray-900">
                            {{ copyResult.success ? 'Penyalinan Selesai' : 'Penyalinan Selesai dengan Error' }}
                        </CardTitle>
                        <CardDescription class="text-xs">
                            {{ copyResult.total_copied }} disalin
                            {{ copyResult.total_skipped > 0 ? `, ${copyResult.total_skipped} dilewat` : '' }}
                            ke {{ copyResult.outlet_count }} outlet
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="px-4 sm:px-6 space-y-3">
                <div v-for="r in copyResult.results" :key="r.outlet_name"
                    class="flex items-start gap-3 p-3 rounded-xl border"
                    :class="r.errors.length > 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-gray-50'"
                >
                    <div v-if="r.errors.length > 0" class="shrink-0 mt-0.5">
                        <AlertTriangle class="h-4 w-4 text-red-500" />
                    </div>
                    <div v-else class="shrink-0 mt-0.5">
                        <CircleCheck class="h-4 w-4 text-emerald-500" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ r.outlet_name }}</p>
                        <p class="text-[11px] text-gray-500">
                            {{ r.copied }} disalin
                            {{ r.skipped > 0 ? `, ${r.skipped} dilewat (SKU sudah ada)` : '' }}
                        </p>
                        <p v-for="(err, i) in r.errors" :key="i" class="text-[11px] text-red-600 mt-1">
                            {{ err }}
                        </p>
                    </div>
                </div>

                <div v-if="copyResult.errors.length > 0" class="bg-red-50 border border-red-200 rounded-xl p-3">
                    <p class="text-xs font-bold text-red-800 mb-1">Error yang terjadi:</p>
                    <p v-for="(err, i) in copyResult.errors" :key="i" class="text-[11px] text-red-700">
                        {{ err }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </AdminLayout>
</template>
