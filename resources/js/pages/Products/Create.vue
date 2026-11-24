<script setup lang="ts">
import { useForm, usePage, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, ChevronDown, Package, Upload, X, Plus, Trash2, ScanLine, FolderPlus, Loader2 } from 'lucide-vue-next';
import { ref, computed, watch, onMounted } from 'vue';
import type { ComputedRef } from 'vue';
import { toastWarning, toastSuccess } from '@/lib/swal';
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import BarcodeGenerator from '@/components/BarcodeGenerator.vue';
import OptionGroupsPicker from '@/components/products/OptionGroupsPicker.vue';
import ProductTypePicker from '@/components/products/ProductTypePicker.vue';
import OnlinePriceFields from '@/components/products/OnlinePriceFields.vue';
import { ONLINE_PRICE_CHANNELS, onlinePricesToForm, onlinePricesToPayload, type OnlinePriceForm } from '@/lib/onlinePrices';
import StockModePicker, { type StockMode } from '@/components/products/StockModePicker.vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { useProductFormMode } from '@/composables/useProductFormMode';
import type { OptionGroup } from '@/types/optionGroups';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface CatalogPresets {
    categories: string[];
    units: string[];
}

interface BatchRow {
    quantity_remaining: number;
    expiry_date: string;
    batch_code: string;
}

interface BundleCatalogProduct {
    id: number;
    name: string;
    has_variants: boolean;
    allows_decimal: boolean;
    unit: string | null;
    variants: Array<{ id: number; name: string }>;
}

interface ProductCategoryOption {
    id: number;
    name: string;
    sort_order: number;
}

interface Props {
    wholesaleEnabled?: boolean;
    onlinePricesEnabled?: boolean;
    store: {
        id: number;
        name: string;
        currency: string;
    };
    catalogPresets?: CatalogPresets;
    batchTrackingEnabled?: boolean;
    fnbModifierEnabled?: boolean;
    modifierGroups?: OptionGroup[];
    pharmacyFieldsEnabled?: boolean;
    barbershopEnabled?: boolean;
    bundleCatalogProducts?: BundleCatalogProduct[];
    saleConsumptionCatalog?: Array<{
        id: number;
        name: string;
        sku: string | null;
        inventory_type: string;
        base_unit: string | null;
        average_cost: string | null;
        stock_quantity: string | number;
    }>;
    productCategories?: ProductCategoryOption[];
    inventoryTypes?: Record<string, string>;
    baseUnits?: Record<string, string>;
}

const props = withDefaults(defineProps<Props>(), {
    wholesaleEnabled: false,
    onlinePricesEnabled: false,
    catalogPresets: () => ({ categories: [], units: [] }),
    batchTrackingEnabled: false,
    fnbModifierEnabled: false,
    modifierGroups: () => [],
    pharmacyFieldsEnabled: false,
    barbershopEnabled: false,
    bundleCatalogProducts: () => [],
    saleConsumptionCatalog: () => [],
    productCategories: () => [],
    inventoryTypes: () => ({}),
    baseUnits: () => ({}),
});

const page = usePage();
/** Staf tanpa akses biaya (gudang/kasir/dapur): field HPP/harga beli & margin disembunyikan. */
const canViewCost = computed(() => Boolean((page.props as { abilities?: { view_cost?: boolean } }).abilities?.view_cost));
const allowsAdvancedRetail = useAllowsAdvancedRetail();

// Daftar kategori lokal reaktif — bisa ditambah langsung lewat popup tanpa reload.
const categories = ref<ProductCategoryOption[]>([...props.productCategories]);

// Popup "Tambah Kategori Baru"
const categoryDialogOpen = ref(false);
const categorySaving = ref(false);
const categoryName = ref('');
const categoryError = ref('');

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function saveCategory() {
    const name = categoryName.value.trim();
    if (!name) {
        categoryError.value = 'Nama kategori wajib diisi.';
        return;
    }
    const existing = categories.value.find((c) => c.name.toLowerCase() === name.toLowerCase());
    if (existing) {
        form.product_category_id = existing.id;
        categoryError.value = '';
        categoryName.value = '';
        categoryDialogOpen.value = false;
        void toastSuccess('Kategori sudah ada — dipilih otomatis.');
        return;
    }
    categoryError.value = '';
    categorySaving.value = true;
    try {
        const res = await fetch('/product-categories/quick', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ name }),
        });
        const data = await res.json();
        if (!res.ok) {
            categoryError.value = data.message ?? data.errors?.name?.[0] ?? 'Gagal menyimpan kategori.';
            return;
        }
        categories.value.push({ id: data.id, name: data.name });
        form.product_category_id = data.id;
        categoryName.value = '';
        categoryDialogOpen.value = false;
        void toastSuccess('Kategori berhasil ditambahkan.');
    } catch {
        categoryError.value = 'Terjadi kesalahan jaringan. Coba lagi.';
    } finally {
        categorySaving.value = false;
    }
}

const productLimitHint = computed(() => {
    const pl = page.props.plan_limits as {
        max_products?: number | null;
        products_count?: number;
        plan_name?: string | null;
    } | null;
    if (!pl?.max_products) {
        return null;
    }
    const max = pl.max_products;
    const count = pl.products_count ?? 0;
    return {
        max_products: max,
        products_count: count,
        plan_name: pl.plan_name,
        atCap: count >= max,
    };
});

const defaultUnits = ['pcs', 'kg', 'gram', 'liter', 'ml', 'bungkus', 'botol', 'kotak', 'karung', 'porsi', 'paket'];
const unitOptions = computed(() => [...new Set([...defaultUnits, ...props.catalogPresets.units])]);

const showBarcodeScannerCreate = ref(false);
const showBarcodeGenerator = ref(false);

const form = useForm({
    name: '',
    sku: '',
    barcode: '',
    description: '',
    price: 0,
    wholesale_price: 0,
    // Harga toko online per aplikasi; null = pakai harga toko.
    online_prices: onlinePricesToPayload(onlinePricesToForm(null)),
    cost_price: 0,
    stock_quantity: 0,
    min_stock_level: 0,
    product_category_id: '' as string | number,
    brand: '',
    unit: 'pcs',
    inventory_type: 'sellable',
    base_unit: 'pcs',
    // Pajak dihitung dari pengaturan PPN toko, bukan per produk.
    tax_rate: 0,
    // Menu F&B umumnya dibuat saat dipesan: stok tidak dihitung.
    track_stock: !(page.props as { fnb_enabled?: boolean }).fnb_enabled,
    unlimited_stock: false,
    allow_negative_stock: false,
    allows_decimal: false,
    has_variants: false,
    is_bundle: false,
    images: [] as File[],
    batches_json: '',
    variants_json: '',
    bundles_json: '',
    sale_consumptions_json: '',
    modifier_options_json: '',
    modifier_group_ids_json: '[]',
    pharmacy_sale_class: 'otc' as 'otc' | 'limited' | 'prescription',
    pharmacy_registration_no: '',
    service_duration_minutes: null as number | null,
    barber_commission_mode: '' as string,
    barber_commission_value: null as number | null,
    save_and_new: false,
});

/** Format angka jadi rupiah (pemisah ribuan id-ID) dan parse balik ke number. */
function moneyDisplay(key: 'price' | 'wholesale_price' | 'cost_price') {
    return computed<string>({
        get() {
            const n = Number(form[key]) || 0;
            return n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
        },
        set(val: string) {
            const t = val.trim();
            if (!t) {
                form[key] = 0;
                return;
            }
            // "1.500,5" → koma = desimal, titik = ribuan
            const normalized = t.includes(',')
                ? t.replace(/\./g, '').replace(',', '.')
                : t;

            const parts = normalized.split('.');
            if (parts.length > 1) {
                const last = parts[parts.length - 1];
                // Titik desimal hanya bila diikuti 1-2 digit ("1500.5"); selain itu pemisah ribuan
                if (last.length <= 2) {
                    form[key] = Number(parts.slice(0, -1).join('') + '.' + last) || 0;
                } else {
                    form[key] = Number(normalized.replace(/\./g, '')) || 0;
                }
            } else {
                form[key] = Number(normalized) || 0;
            }
        },
    });
}

const priceDisplay = moneyDisplay('price');
const wholesalePriceDisplay = moneyDisplay('wholesale_price');
const costPriceDisplay = moneyDisplay('cost_price');

/** Isian harga toko online (GoFood/GrabFood/ShopeeFood); kosong = pakai harga toko. */
const onlinePrices = ref<OnlinePriceForm>(onlinePricesToForm(null));

/** Persen keuntungan (markup) = (harga jual - modal) / modal × 100. Null bila modal atau harga jual belum valid. */
const profitPercent = computed<number | null>(() => {
    const price = Number(form.price) || 0;
    const cost = Number(form.cost_price) || 0;
    if (cost <= 0) return null;
    return Math.round(((price - cost) / cost) * 10000) / 100;
});

/** Grup pilihan tambahan (gula, topping, …) yang ditempel ke produk ini. */
const selectedModifierGroupIds = ref<number[]>([]);

interface ModifierOptionRow {
    key: string;
    label: string;
    price: number;
}

interface VariantFormRow {
    name: string;
    sku: string;
    barcode: string;
    price: number | '';
    wholesale_price: number | '';
    online_prices: OnlinePriceForm;
    cost_price: number | '';
    stock_quantity: number;
    min_stock_level: number;
    is_active: boolean;
}

const variantRows = ref<VariantFormRow[]>([
    {
        name: '',
        sku: '',
        barcode: '',
        price: '',
        wholesale_price: '',
        online_prices: onlinePricesToForm(null),
        cost_price: '',
        stock_quantity: 0,
        min_stock_level: 0,
        is_active: true,
    },
]);

function addVariantRow() {
    variantRows.value.push({
        name: '',
        sku: '',
        barcode: '',
        price: '',
        wholesale_price: '',
        online_prices: onlinePricesToForm(null),
        cost_price: '',
        stock_quantity: 0,
        min_stock_level: 0,
        is_active: true,
    });
}

function removeVariantRow(index: number) {
    if (variantRows.value.length <= 1) return;
    variantRows.value.splice(index, 1);
}

function buildVariantsPayload(): Array<Record<string, unknown>> {
    return variantRows.value
        .filter((r) => r.name.trim() !== '')
        .map((r) => {
            const price =
                r.price === '' || r.price === null || r.price === undefined ? null : Math.max(0, Number(r.price));
            const wholesale =
                r.wholesale_price === '' || r.wholesale_price === null || r.wholesale_price === undefined
                    ? null
                    : Math.max(0, Number(r.wholesale_price));
            const cost =
                r.cost_price === '' || r.cost_price === null || r.cost_price === undefined
                    ? null
                    : Math.max(0, Number(r.cost_price));
            let sq = Math.max(0, Number(r.stock_quantity) || 0);
            let mq = Math.max(0, Number(r.min_stock_level) || 0);
            if (form.allows_decimal) {
                sq = Math.round(sq * 10000) / 10000;
                mq = Math.round(mq * 10000) / 10000;
            } else {
                sq = Math.round(sq);
                mq = Math.round(mq);
            }
            return {
                name: r.name.trim(),
                sku: r.sku.trim() === '' ? null : r.sku.trim(),
                barcode: r.barcode.trim() === '' ? null : r.barcode.trim(),
                price,
                wholesale_price: wholesale,
                ...(props.onlinePricesEnabled ? { online_prices: onlinePricesToPayload(r.online_prices) } : {}),
                cost_price: cost,
                stock_quantity: sq,
                min_stock_level: mq,
                is_active: r.is_active,
            };
        });
}

const modifierOptionRows = ref<ModifierOptionRow[]>([]);

function addModifierOptionRow() {
    modifierOptionRows.value.push({ key: '', label: '', price: 0 });
}

function removeModifierOptionRow(index: number) {
    modifierOptionRows.value.splice(index, 1);
}

function buildModifierOptionsPayload(): Array<{ key: string; label: string; price: number }> {
    return modifierOptionRows.value
        .filter((r) => r.label.trim() !== '')
        .map((r) => ({
            key: r.key.trim(),
            label: r.label.trim(),
            price: Math.max(0, Math.round((Number(r.price) || 0) * 100) / 100),
        }));
}

const batchRows = ref<BatchRow[]>([{ quantity_remaining: 0, expiry_date: '', batch_code: '' }]);

const batchStockSum = computed(() =>
    batchRows.value.reduce((s, r) => {
        let n = Math.max(0, Number(r.quantity_remaining) || 0);
        if (!form.allows_decimal) {
            n = Math.round(n);
        } else {
            n = Math.round(n * 10000) / 10000;
        }
        return s + n;
    }, 0),
);

function syncStockFromBatches() {
    if (props.batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle) {
        form.stock_quantity = batchStockSum.value;
    }
}

interface BundleFormRow {
    component_product_id: number | '';
    component_product_variant_id: number | '';
    quantity: number;
}

const bundleRows = ref<BundleFormRow[]>([
    { component_product_id: '', component_product_variant_id: '', quantity: 1 },
]);

function addBundleRow() {
    bundleRows.value.push({ component_product_id: '', component_product_variant_id: '', quantity: 1 });
}

function removeBundleRow(index: number) {
    if (bundleRows.value.length <= 1) return;
    bundleRows.value.splice(index, 1);
}

function catalogProductById(id: number | ''): BundleCatalogProduct | undefined {
    if (id === '' || id === null) return undefined;
    return props.bundleCatalogProducts.find((p) => p.id === id);
}

function onBundleProductRowChange(row: BundleFormRow) {
    row.component_product_variant_id = '';
}

function buildBundlesPayload(): Array<Record<string, unknown>> {
    return bundleRows.value
        .filter((r) => r.component_product_id !== '' && r.component_product_id != null)
        .map((r) => {
            const pid = Number(r.component_product_id);
            const cat = catalogProductById(pid);
            const vidRaw = r.component_product_variant_id;
            const vid = vidRaw === '' || vidRaw === null ? null : Number(vidRaw);
            let q = Math.max(0, Number(r.quantity) || 0);
            if (cat?.allows_decimal) {
                q = Math.round(q * 10000) / 10000;
            } else {
                q = Math.round(q);
            }
            return {
                component_product_id: pid,
                component_product_variant_id: vid,
                quantity: q,
            };
        });
}

function buildBatchesPayload(): Array<{ quantity_remaining: number; expiry_date: string | null; batch_code: string | null }> {
    return batchRows.value.map((r) => ({
        quantity_remaining: form.allows_decimal
            ? Math.max(0, Math.round((Number(r.quantity_remaining) || 0) * 10000) / 10000)
            : Math.max(0, Math.round(Number(r.quantity_remaining) || 0)),
        expiry_date: r.expiry_date?.trim() || null,
        batch_code: r.batch_code?.trim() || null,
    }));
}

// ---- Komponen konsumsi penjualan ----
interface ConsumptionFormRow {
    component_product_id: number | '';
    quantity: number;
    base_unit: string | null;
}

/**
 * Blok hanya relevan untuk produk jual non-paket.
 * Produk bervarian dikecualikan karena backend meminta konfigurasi varian
 * dilakukan setelah produk dibuat, lewat halaman Edit.
 */
const { showAdvanced } = useProductFormMode(false, () => form.errors);

const showConsumptionBlock = computed(
    () => !form.is_bundle && !form.has_variants && (form.inventory_type === 'sellable' || !form.inventory_type),
);
const consumptionEnabled = ref(false);
const consumptionRows = ref<ConsumptionFormRow[]>([
    { component_product_id: '', quantity: 1, base_unit: null },
]);

function consumptionCatalogOptions() {
    return props.saleConsumptionCatalog.filter(
        (c) =>
            c.inventory_type === 'raw_material' ||
            c.inventory_type === 'production_output' ||
            c.inventory_type === 'packaging',
    );
}

function onConsumptionProductChange(row: ConsumptionFormRow) {
    const cat = props.saleConsumptionCatalog.find((c) => c.id === Number(row.component_product_id));
    row.base_unit = cat?.base_unit ?? null;
}

function addConsumptionRow() {
    consumptionRows.value.push({ component_product_id: '', quantity: 1, base_unit: null });
}

function removeConsumptionRow(index: number) {
    if (consumptionRows.value.length <= 1) return;
    consumptionRows.value.splice(index, 1);
}

function buildSaleConsumptionsPayload(): string {
    if (!showConsumptionBlock.value || !consumptionEnabled.value) return '';
    const filtered = consumptionRows.value.filter(
        (r) => r.component_product_id !== '' && r.component_product_id != null,
    );
    if (filtered.length === 0) return '';
    const payload = filtered.map((r, idx) => ({
        component_product_id: Number(r.component_product_id),
        product_variant_id: null,
        quantity: Math.max(0.0001, Number(r.quantity) || 1),
        base_unit: r.base_unit ?? null,
        sort_order: idx,
    }));
    return JSON.stringify(payload);
}

/** Estimasi HPP dari total biaya komponen konsumsi penjualan (qty × average_cost). */
const estimatedHpp = computed<number>(() => {
    if (!consumptionEnabled.value) return 0;
    return consumptionRows.value.reduce((total, row) => {
        if (!row.component_product_id) return total;
        const cat = props.saleConsumptionCatalog.find((c) => c.id === Number(row.component_product_id));
        const cost = parseFloat(cat?.average_cost ?? '0') || 0;
        return total + row.quantity * cost;
    }, 0);
});

watch(estimatedHpp, (val) => {
    if (consumptionEnabled.value && val > 0) {
        form.cost_price = Math.round(val);
    }
});

watch(consumptionEnabled, (enabled) => {
    if (!enabled) return;
    const val = estimatedHpp.value;
    if (val > 0) {
        form.cost_price = Math.round(val);
    }
});

function addBatchRow() {
    batchRows.value.push({ quantity_remaining: 0, expiry_date: '', batch_code: '' });
    syncStockFromBatches();
}

function removeBatchRow(index: number) {
    if (batchRows.value.length <= 1) return;
    batchRows.value.splice(index, 1);
    syncStockFromBatches();
}

onMounted(() => syncStockFromBatches());

const imagePreviews = ref<string[]>([]);

const imageFieldErrors: ComputedRef<string[]> = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('images'))
        .map(([, message]) => String(message)),
);

const handleImageUpload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        const files = Array.from(target.files);
        
        files.forEach(file => {
            if (file.size > 2 * 1024 * 1024) {
                toastWarning('Ukuran file tidak boleh lebih dari 2MB');
                return;
            }
            
            form.images.push(file);
            
            const reader = new FileReader();
            reader.onload = (e) => {
                imagePreviews.value.push(e.target?.result as string);
            };
            reader.readAsDataURL(file);
        });
    }
};

const removeImage = (index: number) => {
    form.images.splice(index, 1);
    imagePreviews.value.splice(index, 1);
};

// ── Jenis produk, cara stok, dan satuan ─────────────────────────────────
const isMaterial = computed(() => form.inventory_type === 'raw_material' || form.inventory_type === 'packaging');

/** Satu pilihan cara stok yang memetakan ke track_stock + unlimited_stock. */
const stockMode = computed<StockMode>({
    get: () => (!form.track_stock ? 'none' : form.unlimited_stock ? 'unlimited' : 'track'),
    set: (mode) => {
        form.track_stock = mode !== 'none';
        form.unlimited_stock = mode === 'unlimited';
        if (mode !== 'track') form.allow_negative_stock = false;
        syncStockFromBatches();
    },
});

// Bahan & kemasan selalu dihitung stoknya dan tidak punya harga jual.
watch(
    () => form.inventory_type,
    (type) => {
        if (type === 'raw_material' || type === 'packaging') {
            stockMode.value = 'track';
            form.price = 0;
        }
    },
);

/** Satuan stok tunggal: satuan dasar (resep) mengikuti satuan bila setara, selain itu dihitung pcs. */
const baseUnitFor = (unit: string) => (Object.keys(props.baseUnits).includes(unit) ? unit : 'pcs');
watch(
    () => form.unit,
    (unit) => {
        form.base_unit = baseUnitFor(unit);
    },
);
/** Satuan non-dasar (mis. botol) boleh diatur padanannya untuk resep di "Lebih lanjut". */
const baseUnitMismatch = computed(() => !Object.keys(props.baseUnits).includes(form.unit));

useUnsavedGuard(
    () => form.isDirty,
    () => form.processing,
);

const submit = () => {
    const finiteNum = (v: unknown, fallback: number) => {
        const n = typeof v === 'number' ? v : Number(v);
        return Number.isFinite(n) ? n : fallback;
    };
    form.price = finiteNum(form.price, 0);
    form.wholesale_price = finiteNum(form.wholesale_price, 0);
    form.cost_price = finiteNum(form.cost_price, 0);
    const sq = finiteNum(form.stock_quantity, 0);
    const mq = finiteNum(form.min_stock_level, 0);
    if (form.allows_decimal) {
        form.stock_quantity = Math.max(0, Math.round(sq * 10000) / 10000);
        form.min_stock_level = Math.max(0, Math.round(mq * 10000) / 10000);
    } else {
        form.stock_quantity = Math.max(0, Math.round(sq));
        form.min_stock_level = Math.max(0, Math.round(mq));
    }
    form.tax_rate = finiteNum(form.tax_rate, 0);

    if (form.has_variants && form.is_bundle) {
        toastWarning('Pilih salah satu: varian atau paket, tidak keduanya.');
        return;
    }

    if (form.has_variants) {
        const vPayload = buildVariantsPayload();
        if (vPayload.length === 0) {
            toastWarning('Isi minimal satu varian (nama wajib).');
            return;
        }
        form.stock_quantity = 0;
        form.min_stock_level = 0;
        form.batches_json = '';
        form.bundles_json = '';
        form.variants_json = JSON.stringify(vPayload);
    } else if (form.is_bundle) {
        const bPayload = buildBundlesPayload();
        if (bPayload.length === 0) {
            toastWarning('Tambahkan minimal satu komponen paket.');
            return;
        }
        form.stock_quantity = 0;
        form.min_stock_level = 0;
        form.batches_json = '';
        form.variants_json = '';
        form.bundles_json = JSON.stringify(bPayload);
    } else {
        form.variants_json = '';
        form.bundles_json = '';
    }

    if (props.batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle) {
        form.stock_quantity = batchStockSum.value;
        form.batches_json = JSON.stringify(buildBatchesPayload());
        if (props.pharmacyFieldsEnabled) {
            const rows = batchRows.value;
            for (let i = 0; i < rows.length; i++) {
                if (!rows[i]?.expiry_date?.trim()) {
                    toastWarning(`Apotek: tanggal kedaluwarsa (ED) wajib untuk setiap batch (baris ${i + 1}).`);
                    return;
                }
            }
        }
    } else if (!form.has_variants && !form.is_bundle) {
        form.batches_json = '';
    }

    form.modifier_group_ids_json = JSON.stringify(form.is_bundle ? [] : selectedModifierGroupIds.value);

    if (props.fnbModifierEnabled) {
        form.modifier_options_json = form.is_bundle
            ? ''
            : JSON.stringify(buildModifierOptionsPayload());
    } else {
        form.modifier_options_json = '';
    }

    form.sale_consumptions_json = buildSaleConsumptionsPayload();

    form.online_prices = onlinePricesToPayload(isMaterial.value ? onlinePricesToForm(null) : onlinePrices.value);

    form.post('/products', {
        forceFormData: true,
    });
};

const submitAndAddAnother = () => {
    form.save_and_new = true;
    submit();
};
</script>

<template>
    <AdminLayout title="Tambah Produk Baru" hide-bottom-nav>
        <template #header-actions>
            <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                <Button
                    :as="Link"
                    href="/products"
                    variant="outline"
                    size="sm"
                    class="inline-flex items-center gap-2 shrink-0"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Kembali
                </Button>
                <p class="text-sm font-bold text-slate-800 max-w-[200px] truncate hidden md:block" :title="store.name">
                    {{ store.name }}
                </p>
            </div>
        </template>
        <template #mobile-header-actions>
            <Button
                :as="Link"
                href="/products"
                variant="ghost"
                size="sm"
                class="p-2 -mr-1 shrink-0"
                aria-label="Kembali ke daftar produk"
            >
                <ArrowLeft class="h-5 w-5" />
            </Button>
        </template>

        <div
            v-if="productLimitHint"
            class="mb-6 rounded-xl border px-4 py-3 text-sm"
            :class="
                productLimitHint.atCap
                    ? 'border-red-200 bg-red-50 text-red-900'
                    : 'border-slate-200 bg-slate-50 text-slate-700'
            "
        >
            Paket <strong>{{ productLimitHint.plan_name || 'langganan' }}</strong>:
            {{ productLimitHint.products_count }} / {{ productLimitHint.max_products }} produk.
            <template v-if="productLimitHint.atCap">
                Kuota penuh —
                <a href="/subscription" class="font-bold text-red-950 underline">upgrade paket</a>
                untuk menambah produk.
            </template>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
                <ProductTypePicker v-model="form.inventory_type" :types="inventoryTypes" />

                <!-- Info dasar -->
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-bold text-slate-900">Info dasar</h2>
                    <div class="mt-4 space-y-5">
                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Nama Produk *</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Masukkan nama produk"
                                    required
                                />
                                <div v-if="form.errors.name" class="text-sm text-red-600">
                                    {{ form.errors.name }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="product_category_id">Kategori</Label>
                                <div class="flex items-center gap-2">
                                    <select
                                        id="product_category_id"
                                        v-model="form.product_category_id"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    >
                                        <option value="">Tanpa kategori</option>
                                        <option v-for="c in categories" :key="c.id" :value="c.id">
                                            {{ c.name }}
                                        </option>
                                    </select>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="h-10 w-10 shrink-0"
                                        :title="'Tambah kategori baru'"
                                        @click="categoryDialogOpen = true"
                                    >
                                        <Plus class="h-4 w-4" />
                                    </Button>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    <Link href="/product-categories" class="text-primary underline-offset-2 hover:underline">
                                        Kelola kategori
                                    </Link>
                                </p>
                                <div v-if="form.errors.product_category_id" class="text-sm text-red-600">
                                    {{ form.errors.product_category_id }}
                                </div>
                            </div>
                        </div>

                        <div v-if="!isMaterial || canViewCost" class="grid gap-6 md:grid-cols-2">
<template v-if="!isMaterial">
                            <div class="space-y-2">
                                <Label for="price">Harga Jual {{ form.has_variants ? '' : '*' }}</Label>
                                <Input
                                    id="price"
                                    v-model="priceDisplay"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0"
                                    :required="!form.has_variants"
                                />
                                <div v-if="form.errors.price" class="text-sm text-red-600">
                                    {{ form.errors.price }}
                                </div>
                                <p
                                    v-if="canViewCost && profitPercent !== null"
                                    class="text-xs font-medium"
                                    :class="profitPercent >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                >
                                    Keuntungan {{ profitPercent }}% dari modal
                                </p>
                            </div>
</template>
                            <div class="space-y-2" v-if="wholesaleEnabled && !isMaterial">
                                <Label for="wholesale_price">Harga Grosir</Label>
                                <Input
                                    id="wholesale_price"
                                    v-model="wholesalePriceDisplay"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0"
                                />
                                <p class="text-xs text-muted-foreground">Kosongkan agar pelanggan grosir tetap memakai harga jual.</p>
                                <div v-if="form.errors.wholesale_price" class="text-sm text-red-600">
                                    {{ form.errors.wholesale_price }}
                                </div>
                            </div>
                            <div v-if="canViewCost" class="space-y-2">
                                <Label for="cost_price">{{ isMaterial ? `Harga beli per ${form.unit}` : 'Harga modal (HPP)' }}</Label>
                                <Input
                                    id="cost_price"
                                    v-model="costPriceDisplay"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0"
                                />
                                <p v-if="consumptionEnabled && estimatedHpp > 0" class="text-xs text-violet-700 font-medium">
                                    Estimasi dari komponen: Rp {{ Math.round(estimatedHpp).toLocaleString('id-ID') }}
                                    <span class="text-muted-foreground font-normal">(diisi otomatis, bisa diubah manual)</span>
                                </p>
                                <div v-if="form.errors.cost_price" class="text-sm text-red-600">
                                    {{ form.errors.cost_price }}
                                </div>
                            </div>
                        </div>
                        <OnlinePriceFields
                            v-if="onlinePricesEnabled && !isMaterial"
                            v-model="onlinePrices"
                            :store-price="Number(form.price) || 0"
                            :has-variants="form.has_variants"
                            :errors="form.errors"
                        />
                        <div v-if="barbershopEnabled" class="space-y-2">
                            <Label for="service_duration_minutes">Durasi Layanan (menit)</Label>
                            <Input
                                id="service_duration_minutes"
                                v-model.number="form.service_duration_minutes"
                                type="number"
                                min="1"
                                max="1440"
                                step="1"
                                placeholder="45"
                            />
                            <p class="text-xs text-muted-foreground">
                                Kosongkan jika layanan ini tidak berdurasi tetap.
                            </p>
                            <div v-if="form.errors.service_duration_minutes" class="text-sm text-red-600">
                                {{ form.errors.service_duration_minutes }}
                            </div>
                        </div>

                        <div v-if="barbershopEnabled" class="space-y-2">
                            <Label for="barber_commission_mode">Komisi Teknisi (override)</Label>
                            <select
                                id="barber_commission_mode"
                                v-model="form.barber_commission_mode"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            >
                                <option value="">Ikuti tarif global toko</option>
                                <option value="percent">Persen dari harga jual (%)</option>
                                <option value="fixed">Tarif tetap per item (Rp)</option>
                            </select>
                            <Input
                                v-if="form.barber_commission_mode"
                                id="barber_commission_value"
                                v-model.number="form.barber_commission_value"
                                type="number"
                                min="0"
                                :max="form.barber_commission_mode === 'fixed' ? 100000000 : 100"
                                :step="form.barber_commission_mode === 'fixed' ? 1000 : 0.5"
                                :placeholder="form.barber_commission_mode === 'fixed' ? '10000' : '40'"
                            />
                            <p class="text-xs text-muted-foreground">
                                Tarif khusus produk/layanan ini. Pilih "Ikuti tarif global toko" untuk memakai pengaturan di Pengaturan > Toko.
                            </p>
                            <div v-if="form.errors.barber_commission_mode" class="text-sm text-red-600">
                                {{ form.errors.barber_commission_mode }}
                            </div>
                            <div v-if="form.errors.barber_commission_value" class="text-sm text-red-600">
                                {{ form.errors.barber_commission_value }}
                            </div>
                        </div>
                    </div>
                </section>

                <Card class="rounded-2xl shadow-none">
                    <CardHeader>
                        <CardTitle>{{ isMaterial ? 'Foto bahan' : 'Foto produk' }}</CardTitle>
                        <CardDescription>
                            {{ isMaterial ? 'Memudahkan mengenali bahan di daftar produk dan stok.' : 'Tampil di kasir dan menu digital.' }}
                            Maksimal 5 foto, 2MB per foto.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="border-2 border-dashed border-gray-300 rounded-xl p-4 text-center relative group hover:border-primary/50 transition-colors">
                            <div class="pointer-events-none">
                                <Upload class="h-8 w-8 mx-auto mb-2 text-gray-400 group-hover:text-primary transition-colors" />
                                <div class="space-y-2">
                                    <p class="text-sm text-gray-600">
                                        Klik untuk upload atau drag & drop
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        PNG, JPG, GIF, WebP hingga 2MB
                                    </p>
                                </div>
                            </div>
                            <input
                                type="file"
                                multiple
                                accept="image/*"
                                @change="handleImageUpload"
                                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                            />
                        </div>

                        <div v-if="imagePreviews.length > 0" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <div 
                                v-for="(preview, index) in imagePreviews" 
                                :key="index"
                                class="relative group"
                            >
                                <img 
                                    :src="preview" 
                                    :alt="`Preview ${index + 1}`"
                                    class="w-full h-32 object-cover rounded-lg"
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity"
                                    @click="removeImage(index)"
                                >
                                    <X class="h-3 w-3" />
                                </Button>
                            </div>
                        </div>

                        <div v-if="form.errors.images" class="text-sm text-red-600">
                            {{ form.errors.images }}
                        </div>
                        <ul
                            v-if="imageFieldErrors.length > 0"
                            class="text-sm text-red-600 list-disc pl-5 space-y-1"
                        >
                            <li v-for="(msg, idx) in imageFieldErrors" :key="idx">{{ msg }}</li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- Stok -->
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-bold text-slate-900">Stok</h2>
                    <div class="mt-4 space-y-4">
                        <p v-if="form.is_bundle" class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            Stok paket dihitung dari komponennya, jadi tidak perlu diisi di sini.
                        </p>
                        <StockModePicker
                            v-else
                            v-model="stockMode"
                            :locked-reason="form.has_variants ? 'Produk bervarian selalu menghitung stok per varian.' : null"
                        />
                        <div class="grid gap-6" :class="stockMode === 'track' && !form.is_bundle ? 'md:grid-cols-3' : 'md:grid-cols-3'">
<template v-if="stockMode === 'track' && !form.is_bundle">
                            <div class="space-y-2">
                                <Label for="stock_quantity">Stok Awal *</Label>
                                <Input
                                    id="stock_quantity"
                                    v-model.number="form.stock_quantity"
                                    type="number"
                                    min="0"
                                    :step="form.allows_decimal ? 0.0001 : 1"
                                    placeholder="0"
                                    required
                                    :disabled="(batchTrackingEnabled && form.track_stock) || form.has_variants || form.is_bundle"
                                    :class="(batchTrackingEnabled && form.track_stock) || form.has_variants || form.is_bundle ? 'opacity-70' : ''"
                                />
                                <p v-if="form.is_bundle" class="text-xs text-muted-foreground">
                                    Stok dihitung dari komponen paket (bukan stok induk).
                                </p>
                                <p v-else-if="form.has_variants" class="text-xs text-muted-foreground">
                                    Stok diisi per varian di bawah.
                                </p>
                                <p v-else-if="batchTrackingEnabled && form.track_stock" class="text-xs text-muted-foreground">
                                    Isi lewat batch di bawah (total otomatis).
                                </p>
                                <div v-if="form.errors.stock_quantity" class="text-sm text-red-600">
                                    {{ form.errors.stock_quantity }}
                                </div>
                            </div>
</template>
                            <div class="space-y-2">
                                <Label for="unit">Satuan</Label>
                                <select
                                    id="unit"
                                    v-model="form.unit"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    required
                                >
                                    <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
                                </select>
                                <div v-if="form.errors.unit" class="text-sm text-red-600">
                                    {{ form.errors.unit }}
                                </div>
                            </div>
<template v-if="stockMode === 'track' && !form.is_bundle">
                            <div class="space-y-2">
                                <Label for="min_stock_level">Stok minimum <span class="font-normal text-slate-400">(opsional)</span></Label>
                                <Input
                                    id="min_stock_level"
                                    v-model.number="form.min_stock_level"
                                    type="number"
                                    min="0"
                                    :step="form.allows_decimal ? 0.0001 : 1"
                                    placeholder="0"
                                    :disabled="form.has_variants || form.is_bundle"
                                    :class="form.has_variants || form.is_bundle ? 'opacity-70' : ''"
                                />
                                <p v-if="form.is_bundle" class="text-xs text-muted-foreground">
                                    Batas stok rendah di komponen masing-masing.
                                </p>
                                <p v-else-if="form.has_variants" class="text-xs text-muted-foreground">
                                    Batas stok rendah per varian (tabel varian).
                                </p>
                                <div v-if="form.errors.min_stock_level" class="text-sm text-red-600">
                                    {{ form.errors.min_stock_level }}
                                </div>
                            </div>
</template>
                        </div>
                        <label v-if="stockMode === 'track' && !form.is_bundle" class="flex items-start gap-2 text-sm text-slate-700">
                            <input v-model="form.allow_negative_stock" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                            <span>
                                Tetap boleh dijual saat stok habis
                                <span class="block text-xs text-slate-500">Stok bisa menjadi minus; berguna bila barang datang belum dicatat.</span>
                            </span>
                        </label>
                        <div
                            v-if="batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle"
                            class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 space-y-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-violet-900">Batch & tanggal kedaluwarsa</p>
                                    <p class="text-xs text-violet-700 mt-0.5">
                                        Minimal satu baris. Total stok = jumlah qty batch.
                                        <span v-if="pharmacyFieldsEnabled"> Untuk apotek, ED wajib di setiap baris.</span>
                                    </p>
                                </div>
                                <Button type="button" variant="outline" size="sm" class="shrink-0" @click="addBatchRow">
                                    <Plus class="h-4 w-4 mr-1" />
                                    Baris batch
                                </Button>
                            </div>
                            <div class="overflow-x-auto rounded-lg border border-violet-200/80 bg-white">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b bg-violet-50/80 text-left text-xs text-violet-800">
                                            <th class="p-2 font-semibold w-[110px]">Qty</th>
                                            <th class="p-2 font-semibold min-w-[140px]">
                                                {{ pharmacyFieldsEnabled ? 'ED (wajib)' : 'ED (opsional)' }}
                                            </th>
                                            <th class="p-2 font-semibold min-w-[120px]">Kode batch</th>
                                            <th class="p-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, idx) in batchRows" :key="idx" class="border-b border-violet-100 last:border-0">
                                            <td class="p-2">
                                                <Input
                                                    v-model.number="row.quantity_remaining"
                                                    type="number"
                                                    min="0"
                                                    :step="form.allows_decimal ? 0.0001 : 1"
                                                    class="h-9"
                                                    @update:model-value="syncStockFromBatches"
                                                />
                                            </td>
                                            <td class="p-2">
                                                <Input v-model="row.expiry_date" type="date" class="h-9" />
                                            </td>
                                            <td class="p-2">
                                                <Input v-model="row.batch_code" type="text" placeholder="—" class="h-9" />
                                            </td>
                                            <td class="p-2 align-middle">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-9 w-9 p-0 text-destructive"
                                                    :disabled="batchRows.length <= 1"
                                                    @click="removeBatchRow(idx)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-violet-800 font-medium">Total stok: {{ batchStockSum }}</p>
                            <div v-if="form.errors.batches_json" class="text-sm text-red-600">{{ form.errors.batches_json }}</div>
                        </div>
                    </div>
                </section>

                        <div
                            v-if="showConsumptionBlock"
                            class="rounded-2xl border border-slate-200 bg-white p-5 space-y-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 class="text-base font-bold text-slate-900">Bahan yang terpakai saat terjual</h2>
                                    <p class="text-sm text-slate-500 mt-0.5">
                                        Mis. 1 es teh memakai 10 gram teh dan 1 gelas plastik. Stok bahan itu berkurang otomatis tiap produk ini terjual.
                                    </p>
                                </div>
                                <Button
                                    v-if="consumptionEnabled"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="shrink-0"
                                    @click="addConsumptionRow"
                                >
                                    <Plus class="h-4 w-4 mr-1" />
                                    Tambah
                                </Button>
                            </div>
                            <div class="flex items-center space-x-2">
                                <input
                                    type="checkbox"
                                    id="consumption_enabled_create"
                                    v-model="consumptionEnabled"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                                <Label for="consumption_enabled_create" class="text-sm text-violet-900">
                                    Produk ini memakai bahan baku saat terjual
                                </Label>
                            </div>
                            <p v-if="!consumptionEnabled" class="text-xs text-violet-900/80 ml-6 -mt-1">
                                Nonaktif: stok produk ini berkurang seperti biasa saat terjual.
                            </p>
                            <div v-if="consumptionEnabled" class="overflow-x-auto rounded-lg border border-violet-200/80 bg-white">
                                <table class="w-full text-sm min-w-[420px]">
                                    <thead>
                                        <tr class="border-b bg-violet-50/80 text-left text-xs text-violet-950">
                                            <th class="p-2 font-semibold min-w-[200px]">Komponen *</th>
                                            <th class="p-2 font-semibold w-[120px]">Qty / pcs</th>
                                            <th class="p-2 font-semibold w-[100px]">Satuan</th>
                                            <th class="p-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, idx) in consumptionRows" :key="idx" class="border-b border-violet-100 last:border-0">
                                            <td class="p-2">
                                                <select
                                                    v-model="row.component_product_id"
                                                    class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    @change="onConsumptionProductChange(row)"
                                                >
                                                    <option disabled value="">Pilih komponen</option>
                                                    <option v-for="c in consumptionCatalogOptions()" :key="c.id" :value="c.id">
                                                        {{ c.name }}{{ c.sku ? ` (${c.sku})` : '' }}
                                                    </option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <Input
                                                    v-model.number="row.quantity"
                                                    type="number"
                                                    min="0.0001"
                                                    step="0.0001"
                                                    class="h-9"
                                                />
                                            </td>
                                            <td class="p-2 text-xs text-muted-foreground leading-9">
                                                {{ row.base_unit || '—' }}
                                            </td>
                                            <td class="p-2 align-middle">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-9 w-9 p-0 text-destructive"
                                                    :disabled="consumptionRows.length <= 1"
                                                    @click="removeConsumptionRow(idx)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="form.errors.sale_consumptions_json" class="text-sm text-red-600">
                                {{ form.errors.sale_consumptions_json }}
                            </div>
                        </div>

                <!-- Pilihan tambahan (grup + khusus produk) -->
                <section v-if="!form.is_bundle && !isMaterial" class="rounded-2xl border border-slate-200 bg-white px-5 py-1">
                    <div class="divide-y divide-slate-100">
                <OptionGroupsPicker
                    class="border-0 py-0 shadow-none [&>[data-slot=card-header]]:px-0 [&>[data-slot=card-content]]:px-0"
                    v-model="selectedModifierGroupIds"
                    :groups="modifierGroups"
                />
                <Card v-if="fnbModifierEnabled" class="border-0 py-0 shadow-none">
                    <CardHeader class="px-0">
                        <CardTitle class="text-sm">Pilihan khusus produk ini</CardTitle>
                        <CardDescription>
                            Tambahan yang hanya ada di produk ini dan boleh dipilih lebih dari satu. Untuk pilihan yang dipakai banyak
                            produk (gula, es, topping), gunakan grup di atas.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3 px-0">
                        <div class="flex justify-end">
                            <Button type="button" variant="outline" size="sm" @click="addModifierOptionRow">
                                <Plus class="h-4 w-4 mr-1" />
                                Opsi
                            </Button>
                        </div>
                        <div v-if="modifierOptionRows.length === 0" class="text-sm text-muted-foreground">
                            Belum ada opsi. Klik &quot;Opsi&quot; untuk menambah baris.
                        </div>
                        <div v-else class="overflow-x-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40 text-left">
                                        <th class="p-2 font-semibold min-w-[140px]">Nama</th>
                                        <th class="p-2 font-semibold w-[120px]">Harga +</th>
                                        <th class="p-2 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in modifierOptionRows" :key="idx" class="border-b last:border-0">
                                        <td class="p-2">
                                            <Input v-model="row.label" type="text" placeholder="Contoh: Pedas level 2" class="h-9" />
                                        </td>
                                        <td class="p-2">
                                            <Input v-model.number="row.price" type="number" min="0" step="500" class="h-9" />
                                        </td>
                                        <td class="p-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                class="h-9 w-9 p-0 text-destructive"
                                                @click="removeModifierOptionRow(idx)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="form.errors.modifier_options_json" class="text-sm text-red-600">
                            {{ form.errors.modifier_options_json }}
                        </div>
                    </CardContent>
                </Card>
                    </div>
                </section>

                <!-- Lebih lanjut -->
                <section class="rounded-2xl border border-slate-200 bg-white">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 p-5 text-left"
                        :aria-expanded="showAdvanced"
                        @click="showAdvanced = !showAdvanced"
                    >
                        <span>
                            <span class="block text-base font-bold text-slate-900">Lebih lanjut</span>
                            <span class="block text-sm text-slate-500">SKU & barcode, deskripsi, merek, varian, paket, dan satuan resep</span>
                        </span>
                        <ChevronDown class="h-5 w-5 shrink-0 text-slate-400 transition-transform" :class="{ 'rotate-180': showAdvanced }" />
                    </button>
                    <div v-show="showAdvanced" class="space-y-6 border-t border-slate-100 p-5">
                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="sku">SKU / kode produk <span class="text-gray-500 font-normal">(Opsional)</span></Label>
                                <Input
                                    id="sku"
                                    v-model="form.sku"
                                    type="text"
                                    placeholder="Kode SKU produk"
                                />
                                <div v-if="form.errors.sku" class="text-sm text-red-600">
                                    {{ form.errors.sku }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="barcode">Barcode <span class="text-gray-500 font-normal">(Opsional)</span></Label>
                                <div class="flex gap-2">
                                    <!-- data-barcode-input memberi tahu BarcodeScanner bahwa Enter di sini adalah terminasi barcode scanner USB -->
                                    <input
                                        id="barcode"
                                        v-model="form.barcode"
                                        type="text"
                                        placeholder="Ketik atau scan barcode di sini"
                                        data-barcode-input="true"
                                        autocomplete="off"
                                        class="flex h-10 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    />
                                    <button
                                        type="button"
                                        :class="[
                                            'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors shrink-0',
                                            showBarcodeScannerCreate
                                                ? 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100'
                                                : 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100',
                                        ]"
                                        :aria-label="showBarcodeScannerCreate ? 'Tutup kamera' : 'Scan via kamera'"
                                        @click="showBarcodeScannerCreate = !showBarcodeScannerCreate"
                                    >
                                        <ScanLine class="h-4 w-4" />
                                        <span>{{ showBarcodeScannerCreate ? 'Tutup' : 'Kamera' }}</span>
                                    </button>
                                </div>
                                <p class="text-xs text-slate-400">Klik field barcode lalu scan dengan USB scanner, atau gunakan tombol Kamera untuk scan via kamera.</p>

                                <!-- Scanner USB/keyboard wedge: selalu aktif (tidak perlu klik tombol) -->
                                <BarcodeScanner
                                    mode="keyboard"
                                    :active="true"
                                    @scanned="(code) => { form.barcode = code; }"
                                    @error="() => {}"
                                />

                                <!-- Scanner kamera: hanya tampil saat tombol diklik -->
                                <BarcodeScanner
                                    v-if="showBarcodeScannerCreate"
                                    mode="camera"
                                    :active="showBarcodeScannerCreate"
                                    @scanned="(code) => { form.barcode = code; showBarcodeScannerCreate = false; }"
                                    @error="() => {}"
                                />

                                <!-- Generator barcode otomatis (produk baru belum punya barcode) -->
                                <button
                                    v-if="!showBarcodeGenerator"
                                    type="button"
                                    class="text-xs font-semibold text-primary hover:underline"
                                    @click="showBarcodeGenerator = true"
                                >
                                    Belum punya barcode? Buat otomatis
                                </button>
                                <div v-else class="mt-2 rounded-xl border border-slate-200 p-3">
                                    <p class="mb-2 text-xs font-semibold text-slate-700">Buat barcode otomatis</p>
                                    <BarcodeGenerator
                                        :product-name="form.name"
                                        :initial-code="form.barcode"
                                        @generated="(code) => { form.barcode = code; }"
                                        @saved="(code) => { form.barcode = code; }"
                                    />
                                </div>

                                <div v-if="form.errors.barcode" class="text-sm text-red-600">
                                    {{ form.errors.barcode }}
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="description">Deskripsi</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Deskripsikan produk..."
                                rows="3"
                                class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <div v-if="form.errors.description" class="text-sm text-red-600">
                                {{ form.errors.description }}
                            </div>
                        </div>
                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="brand">Merek</Label>
                                <Input
                                    id="brand"
                                    v-model="form.brand"
                                    type="text"
                                    placeholder="Nama merek produk"
                                />
                                <div v-if="form.errors.brand" class="text-sm text-red-600">
                                    {{ form.errors.brand }}
                                </div>
                            </div>
                            <div v-if="baseUnitMismatch" class="space-y-2">
                                <Label for="base_unit">Satuan hitung untuk resep</Label>
                                <select
                                    id="base_unit"
                                    v-model="form.base_unit"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option v-for="(label, key) in baseUnits" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p class="text-xs text-muted-foreground">Satuan "{{ form.unit }}" dihitung sebagai satuan ini di resep dan produksi.</p>
                                <div v-if="form.errors.base_unit" class="text-sm text-red-600">{{ form.errors.base_unit }}</div>
                            </div>
                        </div>
                        <template v-if="allowsAdvancedRetail">
                            <div class="flex items-center space-x-2">
                                <input
                                    id="allows_decimal"
                                    v-model="form.allows_decimal"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                    @change="syncStockFromBatches()"
                                />
                                <Label for="allows_decimal">Kuantitas desimal (curah: kg, liter, …)</Label>
                            </div>
                            <p class="text-xs text-muted-foreground ml-7">
                                Hingga 4 angka di belakang koma. Nonaktif untuk barang per PCS.
                            </p>

                            <div class="flex items-center space-x-2">
                                <input
                                    type="checkbox"
                                    id="has_variants"
                                    v-model="form.has_variants"
                                    :disabled="batchTrackingEnabled || form.is_bundle"
                                    @change="(e) => {
                                        if (e.target.checked) {
                                            form.is_bundle = false;
                                            form.track_stock = true;
                                        }
                                        syncStockFromBatches();
                                    }"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-50"
                                />
                                <Label for="has_variants" :class="{'opacity-50': batchTrackingEnabled || form.is_bundle}">Produk punya varian (ukuran, rasa, …)</Label>
                            </div>
                            <p v-if="batchTrackingEnabled" class="text-xs text-muted-foreground ml-7">
                                Varian tidak tersedia saat mode batch &amp; ED aktif di toko.
                            </p>
                            <p v-else class="text-xs text-muted-foreground ml-7">
                                Harga jual induk bisa dipakai sebagai fallback jika harga varian dikosongkan.
                            </p>

                            <div class="flex items-center space-x-2">
                                <input
                                    type="checkbox"
                                    id="is_bundle"
                                    v-model="form.is_bundle"
                                    :disabled="form.has_variants"
                                    @change="(e) => {
                                        if (e.target.checked) {
                                            form.has_variants = false;
                                            form.track_stock = false;
                                        } else {
                                            form.track_stock = true;
                                        }
                                        syncStockFromBatches();
                                    }"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-50"
                                />
                                <Label for="is_bundle" :class="{'opacity-50': form.has_variants}">Produk paket / bundel (kurangi stok komponen saat dijual)</Label>
                            </div>
                            <p class="text-xs text-muted-foreground ml-7">
                                Tidak bisa digabung dengan varian. Harga jual = harga yang tampil di kasir untuk satu paket.
                            </p>
                        </template>
                        <div
                            v-if="form.has_variants && !form.is_bundle"
                            class="rounded-xl border border-teal-200 bg-teal-50/50 p-4 space-y-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-teal-900">Varian</p>
                                    <p class="text-xs text-teal-800 mt-0.5">
                                        Minimal satu baris dengan nama. Total stok induk = jumlah stok varian.
                                    </p>
                                </div>
                                <Button type="button" variant="outline" size="sm" class="shrink-0" @click="addVariantRow">
                                    <Plus class="h-4 w-4 mr-1" />
                                    Varian
                                </Button>
                            </div>
                            <div class="overflow-x-auto rounded-lg border border-teal-200/80 bg-white">
                                <table class="w-full text-sm" :class="props.onlinePricesEnabled ? 'min-w-[1230px]' : 'min-w-[900px]'">
                                    <thead>
                                        <tr class="border-b bg-teal-50/80 text-left text-xs text-teal-900">
                                            <th class="p-2 font-semibold min-w-[140px]">Nama *</th>
                                            <th class="p-2 font-semibold w-[100px]">SKU</th>
                                            <th class="p-2 font-semibold w-[100px]">Barcode</th>
                                            <th class="p-2 font-semibold w-[120px]">Harga</th>
                                            <th v-if="props.wholesaleEnabled" class="p-2 font-semibold w-[110px]">Grosir</th>
                                            <template v-if="props.onlinePricesEnabled">
                                                <th v-for="channel in ONLINE_PRICE_CHANNELS" :key="channel.value" class="p-2 font-semibold w-[110px]">
                                                    {{ channel.label }}
                                                </th>
                                            </template>
                                            <th v-if="canViewCost" class="p-2 font-semibold w-[110px]">HPP</th>
                                            <th class="p-2 font-semibold w-[80px]">Stok</th>
                                            <th class="p-2 font-semibold w-[80px]">Min</th>
                                            <th class="p-2 font-semibold w-[70px]">Aktif</th>
                                            <th class="p-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, idx) in variantRows" :key="idx" class="border-b border-teal-100 last:border-0">
                                            <td class="p-2">
                                                <Input v-model="row.name" type="text" placeholder="Mis. Merah" class="h-9" />
                                            </td>
                                            <td class="p-2">
                                                <Input v-model="row.sku" type="text" class="h-9" />
                                            </td>
                                            <td class="p-2">
                                                <Input v-model="row.barcode" type="text" class="h-9" />
                                            </td>
                                            <td class="p-2">
                                                <Input v-model.number="row.price" type="number" min="0" step="100" class="h-9" />
                                            </td>
                                            <td v-if="props.wholesaleEnabled" class="p-2">
                                                <Input v-model.number="row.wholesale_price" type="number" min="0" step="100" class="h-9" />
                                            </td>
                                            <template v-if="props.onlinePricesEnabled">
                                                <td v-for="channel in ONLINE_PRICE_CHANNELS" :key="channel.value" class="p-2">
                                                    <Input
                                                        v-model.number="row.online_prices[channel.value]"
                                                        type="number"
                                                        min="0"
                                                        step="100"
                                                        class="h-9"
                                                        placeholder="Harga toko"
                                                        :aria-label="`Harga ${channel.label} varian`"
                                                    />
                                                </td>
                                            </template>
                                            <td v-if="canViewCost" class="p-2">
                                                <Input v-model.number="row.cost_price" type="number" min="0" step="100" class="h-9" />
                                            </td>
                                            <td class="p-2">
                                                <Input
                                                    v-model.number="row.stock_quantity"
                                                    type="number"
                                                    min="0"
                                                    :step="form.allows_decimal ? 0.0001 : 1"
                                                    class="h-9"
                                                />
                                            </td>
                                            <td class="p-2">
                                                <Input
                                                    v-model.number="row.min_stock_level"
                                                    type="number"
                                                    min="0"
                                                    :step="form.allows_decimal ? 0.0001 : 1"
                                                    class="h-9"
                                                />
                                            </td>
                                            <td class="p-2 align-middle">
                                                <input v-model="row.is_active" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" aria-label="Varian aktif" />
                                            </td>
                                            <td class="p-2 align-middle">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-9 w-9 p-0 text-destructive"
                                                    :disabled="variantRows.length <= 1"
                                                    @click="removeVariantRow(idx)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="form.errors.variants_json" class="text-sm text-red-600">{{ form.errors.variants_json }}</div>
                            <div v-if="form.errors.has_variants" class="text-sm text-red-600">{{ form.errors.has_variants }}</div>
                        </div>

                        <div
                            v-if="form.is_bundle && !form.has_variants"
                            class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 space-y-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-amber-900">Komponen paket</p>
                                    <p class="text-xs text-amber-900/90 mt-0.5">
                                        Per <strong>satu</strong> paket terjual, stok tiap komponen berkurang sesuai kuantitas di bawah.
                                    </p>
                                </div>
                                <Button type="button" variant="outline" size="sm" class="shrink-0" @click="addBundleRow">
                                    <Plus class="h-4 w-4 mr-1" />
                                    Komponen
                                </Button>
                            </div>
                            <div class="overflow-x-auto rounded-lg border border-amber-200/80 bg-white">
                                <table class="w-full text-sm min-w-[560px]">
                                    <thead>
                                        <tr class="border-b bg-amber-50/80 text-left text-xs text-amber-950">
                                            <th class="p-2 font-semibold min-w-[200px]">Produk *</th>
                                            <th class="p-2 font-semibold min-w-[140px]">Varian</th>
                                            <th class="p-2 font-semibold w-[100px]">Qty / paket</th>
                                            <th class="p-2 w-10"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, idx) in bundleRows" :key="idx" class="border-b border-amber-100 last:border-0">
                                            <td class="p-2">
                                                <select
                                                    v-model="row.component_product_id"
                                                    class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    @change="onBundleProductRowChange(row)"
                                                >
                                                    <option disabled value="">Pilih produk</option>
                                                    <option v-for="p in bundleCatalogProducts" :key="p.id" :value="p.id">
                                                        {{ p.name }}
                                                    </option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <select
                                                    v-if="catalogProductById(row.component_product_id)?.has_variants"
                                                    v-model="row.component_product_variant_id"
                                                    class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                >
                                                    <option disabled value="">Pilih varian</option>
                                                    <option
                                                        v-for="v in catalogProductById(row.component_product_id)?.variants ?? []"
                                                        :key="v.id"
                                                        :value="v.id"
                                                    >
                                                        {{ v.name }}
                                                    </option>
                                                </select>
                                                <span v-else class="text-xs text-muted-foreground leading-9 block">—</span>
                                            </td>
                                            <td class="p-2">
                                                <Input
                                                    v-model.number="row.quantity"
                                                    type="number"
                                                    min="0.0001"
                                                    :step="catalogProductById(row.component_product_id)?.allows_decimal ? 0.0001 : 1"
                                                    class="h-9"
                                                />
                                            </td>
                                            <td class="p-2 align-middle">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-9 w-9 p-0 text-destructive"
                                                    :disabled="bundleRows.length <= 1"
                                                    @click="removeBundleRow(idx)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="form.errors.bundles_json" class="text-sm text-red-600">{{ form.errors.bundles_json }}</div>
                            <div v-if="form.errors.is_bundle" class="text-sm text-red-600">{{ form.errors.is_bundle }}</div>
                        </div>
                    </div>
                </section>

                <Card v-if="pharmacyFieldsEnabled" class="rounded-2xl shadow-none">
                    <CardHeader>
                        <CardTitle>Apotek — klasifikasi &amp; izin edar</CardTitle>
                        <CardDescription>
                            Wajib untuk toko bertipe apotek. Isi nomor izin edar (mis. pada kemasan) bila relevan. Pengguna tetap mematuhi
                            peraturan setempat; fitur ini hanya pendukung pencatatan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2 max-w-md">
                            <Label for="pharmacy_sale_class">Golongan penjualan</Label>
                            <select
                                id="pharmacy_sale_class"
                                v-model="form.pharmacy_sale_class"
                                class="flex h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
                            >
                                <option value="otc">Obat bebas (OTC)</option>
                                <option value="limited">Obat bebas terbatas</option>
                                <option value="prescription">Obat resep / keras</option>
                            </select>
                            <div v-if="form.errors.pharmacy_sale_class" class="text-sm text-red-600">
                                {{ form.errors.pharmacy_sale_class }}
                            </div>
                        </div>
                        <div class="space-y-2 max-w-lg">
                            <Label for="pharmacy_registration_no">Nomor izin edar (opsional)</Label>
                            <Input
                                id="pharmacy_registration_no"
                                v-model="form.pharmacy_registration_no"
                                type="text"
                                maxlength="128"
                                placeholder="Contoh: sesuai etiket / registrasi BPOM"
                                class="h-11"
                            />
                            <div v-if="form.errors.pharmacy_registration_no" class="text-sm text-red-600">
                                {{ form.errors.pharmacy_registration_no }}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Bilah simpan melayang -->
                <div class="h-20" aria-hidden="true" />
                <div
                    class="fixed right-4 bottom-4 left-4 z-40 flex items-center justify-end gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2.5 lg:bottom-4 lg:left-auto lg:w-auto"
                >
                    <Button
                        type="button"
                        variant="outline"
                        :as="Link"
                        href="/products"
                        class="border-red-200 bg-red-50 px-3 text-red-600 hover:bg-red-100 hover:text-red-700"
                    >
                        <X class="h-4 w-4 sm:mr-1" />
                        <span class="hidden sm:inline">Batal</span>
                        <span class="sr-only sm:hidden">Batal</span>
                    </Button>
                    <Button
                        type="button"
                        :disabled="form.processing"
                        variant="outline"
                        title="Simpan produk ini lalu langsung buka formulir kosong untuk produk berikutnya"
                        @click="submitAndAddAnother"
                    >
                        <Plus class="mr-1 h-4 w-4" />
                        <span class="hidden sm:inline">Simpan &amp; tambah produk lain</span>
                        <span class="sm:hidden">Simpan &amp; baru</span>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="mr-1 h-4 w-4 animate-spin" />
                        <Package v-else class="mr-1 h-4 w-4" />
                        <span v-if="form.processing">Menyimpan…</span>
                        <template v-else>
                            <span class="hidden sm:inline">Simpan produk</span>
                            <span class="sm:hidden">Simpan</span>
                        </template>
                    </Button>
                </div>
            </form>

        <!-- Popup Tambah Kategori Baru -->
        <Dialog v-model:open="categoryDialogOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Tambah Kategori Baru</DialogTitle>
                    <DialogDescription>
                        Kategori akan langsung dipilih untuk produk ini.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-2">
                    <Label for="new-category-name">Nama Kategori</Label>
                    <Input
                        id="new-category-name"
                        v-model="categoryName"
                        type="text"
                        placeholder="cth: Minuman, Makanan Ringan"
                        @keyup.enter="saveCategory"
                    />
                    <div v-if="categoryError" class="text-sm text-red-600">{{ categoryError }}</div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="categorySaving"
                        @click="categoryDialogOpen = false"
                    >
                        Batal
                    </Button>
                    <Button
                        type="button"
                        :disabled="categorySaving"
                        @click="saveCategory"
                    >
                        <Loader2 v-if="categorySaving" class="h-4 w-4 mr-2 animate-spin" />
                        <FolderPlus v-else class="h-4 w-4 mr-2" />
                        Simpan Kategori
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
