<script setup lang="ts">
import { useForm, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { ArrowLeft, Package, Upload, X, Plus, Trash2, ScanLine } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import BarcodeGenerator from '@/components/BarcodeGenerator.vue';
import { toastWarning } from '@/lib/swal';
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';

interface CatalogPresets {
    categories: string[];
    units: string[];
}

interface BatchRow {
    quantity_remaining: number;
    expiry_date: string;
    batch_code: string;
}

interface ProductCategoryOption {
    id: number;
    name: string;
    sort_order: number;
}

interface Props {
    product: {
        id: number;
        name: string;
        sku: string;
        barcode: string;
        description: string;
        price: number;
        wholesale_price?: number | string | null;
        cost_price: number;
        average_cost?: number | string;
        stock_quantity: number;
        min_stock_level: number;
        product_category_id: number | null;
        category: string;
        brand: string;
        unit: string;
        inventory_type?: string;
        base_unit?: string;
        tax_rate: number;
        track_stock: boolean;
        unlimited_stock?: boolean;
        allows_decimal?: boolean;
        has_variants?: boolean;
        is_bundle?: boolean;
        is_active: boolean;
        images: string[];
        image_url?: string;
        modifier_options?: Array<{ key: string; label: string; price: number }>;
        pharmacy_sale_class?: string | null;
        pharmacy_registration_no?: string | null;
    };
    store: {
        id: number;
        name: string;
        currency: string;
    };
    catalogPresets?: CatalogPresets;
    batchTrackingEnabled?: boolean;
    stockLockedToAdjustment?: boolean;
    batches?: Array<{
        id: number;
        quantity_remaining: number;
        expiry_date: string;
        batch_code: string;
    }>;
    fnbModifierEnabled?: boolean;
    pharmacyFieldsEnabled?: boolean;
    bundleItems?: Array<{
        component_product_id: number;
        component_product_variant_id: number | null;
        quantity: number | string;
    }>;
    bundleCatalogProducts?: Array<{
        id: number;
        name: string;
        has_variants: boolean;
        allows_decimal: boolean;
        unit: string | null;
        variants: Array<{ id: number; name: string }>;
    }>;
    saleConsumptionCatalog?: Array<{
        id: number;
        name: string;
        sku: string | null;
        inventory_type: string;
        base_unit: string | null;
        average_cost: string | null;
        stock_quantity: string | number;
    }>;
    saleConsumptions?: Array<{
        product_variant_id: number | null;
        component_product_id: number;
        quantity: number | string;
        base_unit: string | null;
        sort_order: number;
    }>;
    productCategories?: ProductCategoryOption[];
    inventoryTypes?: Record<string, string>;
    baseUnits?: Record<string, string>;
    variants?: Array<{
        id: number;
        name: string;
        sku: string | null;
        barcode: string | null;
        price: number | string | null;
        wholesale_price: number | string | null;
        cost_price: number | string | null;
        stock_quantity: number | string;
        min_stock_level: number | string;
        is_active: boolean;
    }>;
}

const props = withDefaults(defineProps<Props>(), {
    catalogPresets: () => ({ categories: [], units: [] }),
    productCategories: () => [],
    batchTrackingEnabled: false,
    stockLockedToAdjustment: false,
    batches: () => [],
    fnbModifierEnabled: false,
    pharmacyFieldsEnabled: false,
    bundleItems: () => [],
    bundleCatalogProducts: () => [],
    saleConsumptionCatalog: () => [],
    saleConsumptions: () => [],
    variants: () => [],
    inventoryTypes: () => ({}),
    baseUnits: () => ({}),
});

const { product, store } = props;

const allowsAdvancedRetail = useAllowsAdvancedRetail();
const canEditAdvancedFields = computed(() => allowsAdvancedRetail.value);
const showDecimalBlock = computed(() => allowsAdvancedRetail.value || !!props.product.allows_decimal);
const showVariantBlock = computed(() => allowsAdvancedRetail.value || !!props.product.has_variants);
const showBundleBlock = computed(() => allowsAdvancedRetail.value || !!props.product.is_bundle);
const showConsumptionBlock = computed(() =>
    !form.is_bundle && (form.inventory_type === 'sellable' || !form.inventory_type)
);
/** Aktif otomatis bila produk ini sudah punya konfigurasi komponen tersimpan. */
const consumptionEnabled = ref((props.saleConsumptions?.length ?? 0) > 0);

const defaultUnits = ['pcs', 'kg', 'gram', 'liter', 'ml', 'bungkus', 'botol', 'kotak', 'karung', 'porsi', 'paket'];
const unitOptions = computed(() =>
    [...new Set([...defaultUnits, ...props.catalogPresets.units, product.unit || 'pcs'])],
);

// Format currency helper
const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

// Computed properties for formatted values
const formattedPrice = computed({
    get: () => form.price !== null && form.price !== undefined ? formatCurrency(form.price) : '',
    set: (value: string) => {
        // Remove formatting and convert to number
        const numericValue = value.replace(/[^\d]/g, '');
        form.price = numericValue ? parseFloat(numericValue) : 0;
    }
});

const formattedWholesalePrice = computed({
    get: () => form.wholesale_price !== null && form.wholesale_price !== undefined ? formatCurrency(form.wholesale_price) : '',
    set: (value: string) => {
        // Remove formatting and convert to number
        const numericValue = value.replace(/[^\d]/g, '');
        form.wholesale_price = numericValue ? parseFloat(numericValue) : 0;
    }
});

const formattedCostPrice = computed({
    get: () => form.cost_price !== null && form.cost_price !== undefined ? formatCurrency(form.cost_price) : '',
    set: (value: string) => {
        // Remove formatting and convert to number
        const numericValue = value.replace(/[^\d]/g, '');
        form.cost_price = numericValue ? parseFloat(numericValue) : 0;
    }
});

const formattedTaxRate = computed({
    get: () => form.tax_rate !== null && form.tax_rate !== undefined ? formatCurrency(form.tax_rate) : '',
    set: (value: string) => {
        // Remove formatting and convert to number
        const numericValue = value.replace(/[^\d]/g, '');
        form.tax_rate = numericValue ? parseFloat(numericValue) : 0;
    }
});

// Debug: Log product data received from server
console.log('Product data from server:', {
    name: product.name,
    sku: product.sku,
    barcode: product.barcode,
    description: product.description,
    price: product.price,
    cost_price: product.cost_price,
    stock_quantity: product.stock_quantity,
    min_stock_level: product.min_stock_level,
    product_category_id: product.product_category_id,
    category: product.category,
    brand: product.brand,
    unit: product.unit,
    tax_rate: product.tax_rate,
    track_stock: product.track_stock,
    unlimited_stock: product.unlimited_stock,
    unlimited_stock_type: typeof product.unlimited_stock,
    allows_decimal: product.allows_decimal ?? false,
    is_active: product.is_active,
});

const showBarcodeScannerEdit = ref(false);
const showBarcodeGeneratorEdit = ref(!product.barcode);

const form = useForm({
    name: product.name ?? '',
    sku: product.sku ?? '',
    barcode: product.barcode ?? '',
    description: product.description ?? '',
    price: typeof product.price === 'string' ? parseFloat(product.price) : (product.price ?? 0),
    wholesale_price: typeof product.wholesale_price === 'string' ? parseFloat(product.wholesale_price) : (product.wholesale_price ?? 0),
    cost_price: typeof product.cost_price === 'string' ? parseFloat(product.cost_price) : (product.cost_price ?? 0),
    stock_quantity:
        typeof product.stock_quantity === 'string'
            ? parseFloat(product.stock_quantity)
            : (product.stock_quantity ?? 0),
    min_stock_level:
        typeof product.min_stock_level === 'string'
            ? parseFloat(product.min_stock_level)
            : (product.min_stock_level ?? 0),
    product_category_id: product.product_category_id ?? '',
    brand: product.brand ?? '',
    unit: product.unit ?? 'pcs',
    inventory_type: product.inventory_type ?? 'sellable',
    base_unit: product.base_unit ?? 'pcs',
    tax_rate: typeof product.tax_rate === 'string' ? parseFloat(product.tax_rate) : (product.tax_rate ?? 0),
    track_stock: product.track_stock ?? false,
    unlimited_stock: product.unlimited_stock ?? false,
    allows_decimal: product.allows_decimal ?? false,
    has_variants: product.has_variants ?? false,
    is_bundle: product.is_bundle ?? false,
    is_active: product.is_active ?? false,
    images: [] as File[],
    batches_json: '',
    variants_json: '',
    bundles_json: '',
    modifier_options_json: '',
    sale_consumptions_json: '',
    pharmacy_sale_class: (product.pharmacy_sale_class as 'otc' | 'limited' | 'prescription') || 'otc',
    pharmacy_registration_no: product.pharmacy_registration_no ?? '',
});

interface ModifierOptionRow {
    key: string;
    label: string;
    price: number;
}

function initModifierOptionRows(): ModifierOptionRow[] {
    const raw = props.product.modifier_options;
    if (!raw?.length) {
        return [];
    }
    return raw.map((m) => ({
        key: m.key ?? '',
        label: m.label ?? '',
        price: typeof m.price === 'number' ? m.price : Number(m.price) || 0,
    }));
}

const modifierOptionRows = ref<ModifierOptionRow[]>(initModifierOptionRows());

function buildModifierOptionsPayload(): Array<{ key: string; label: string; price: number }> {
    return modifierOptionRows.value
        .filter((r) => r.label.trim() !== '')
        .map((r) => ({
            key: r.key.trim(),
            label: r.label.trim(),
            price: Math.max(0, Math.round((Number(r.price) || 0) * 100) / 100),
        }));
}

function addModifierOptionRow() {
    modifierOptionRows.value.push({ key: '', label: '', price: 0 });
}

function removeModifierOptionRow(index: number) {
    modifierOptionRows.value.splice(index, 1);
}

interface VariantFormRow {
    id: number | null;
    name: string;
    sku: string;
    barcode: string;
    price: number | '';
    wholesale_price: number | '';
    cost_price: number | '';
    stock_quantity: number;
    min_stock_level: number;
    is_active: boolean;
}

function initVariantRows(): VariantFormRow[] {
    const raw = props.variants;
    if (raw?.length) {
        return raw.map((v) => ({
            id: v.id,
            name: v.name ?? '',
            sku: v.sku ?? '',
            barcode: v.barcode ?? '',
            price:
                v.price != null && v.price !== ''
                    ? Math.max(0, typeof v.price === 'number' ? v.price : Number(v.price))
                    : '',
            wholesale_price:
                v.wholesale_price != null && v.wholesale_price !== ''
                    ? Math.max(0, typeof v.wholesale_price === 'number' ? v.wholesale_price : Number(v.wholesale_price))
                    : '',
            cost_price:
                v.cost_price != null && v.cost_price !== ''
                    ? Math.max(0, typeof v.cost_price === 'number' ? v.cost_price : Number(v.cost_price))
                    : '',
            stock_quantity:
                typeof v.stock_quantity === 'number'
                    ? v.stock_quantity
                    : parseFloat(String(v.stock_quantity)) || 0,
            min_stock_level:
                typeof v.min_stock_level === 'number'
                    ? v.min_stock_level
                    : parseFloat(String(v.min_stock_level)) || 0,
            is_active: v.is_active !== false,
        }));
    }
    return [
        {
            id: null,
            name: '',
            sku: '',
            barcode: '',
            price: '',
            wholesale_price: '',
            cost_price: '',
            stock_quantity: 0,
            min_stock_level: 0,
            is_active: true,
        },
    ];
}

const variantRows = ref<VariantFormRow[]>(initVariantRows());

function addVariantRow() {
    variantRows.value.push({
        id: null,
        name: '',
        sku: '',
        barcode: '',
        price: '',
        wholesale_price: '',
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
                id: r.id,
                name: r.name.trim(),
                sku: r.sku.trim() === '' ? null : r.sku.trim(),
                barcode: r.barcode.trim() === '' ? null : r.barcode.trim(),
                price,
                wholesale_price: wholesale,
                cost_price: cost,
                stock_quantity: sq,
                min_stock_level: mq,
                is_active: r.is_active,
            };
        });
}

interface BundleFormRow {
    component_product_id: number | '';
    component_product_variant_id: number | '';
    quantity: number;
}

function initBundleRows(): BundleFormRow[] {
    const raw = props.bundleItems;
    if (raw?.length) {
        return raw.map((r) => ({
            component_product_id: r.component_product_id,
            component_product_variant_id: r.component_product_variant_id ?? '',
            quantity: typeof r.quantity === 'number' ? r.quantity : parseFloat(String(r.quantity)) || 1,
        }));
    }
    return [{ component_product_id: '', component_product_variant_id: '', quantity: 1 }];
}

const bundleRows = ref<BundleFormRow[]>(initBundleRows());

function addBundleRow() {
    bundleRows.value.push({ component_product_id: '', component_product_variant_id: '', quantity: 1 });
}

function removeBundleRow(index: number) {
    if (bundleRows.value.length <= 1) return;
    bundleRows.value.splice(index, 1);
}

function catalogProductById(id: number | '') {
    if (id === '' || id === null) return undefined;
    return props.bundleCatalogProducts.find((p) => p.id === Number(id));
}

function onBundleProductRowChange(row: BundleFormRow) {
    row.component_product_variant_id = '';
}

function buildBundlesPayloadEdit(): Array<Record<string, unknown>> {
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

// ---- Sale consumption rows ----
interface ConsumptionFormRow {
    component_product_id: number | '';
    product_variant_id: number | null;
    quantity: number;
    base_unit: string | null;
}

function initConsumptionRows(): ConsumptionFormRow[] {
    const raw = props.saleConsumptions;
    const validVariantIds = new Set((props.variants || []).map((v) => v.id));
    if (raw?.length) {
        return raw.map((r) => ({
            component_product_id: r.component_product_id,
            product_variant_id: r.product_variant_id != null && validVariantIds.has(Number(r.product_variant_id))
                ? Number(r.product_variant_id)
                : null,
            quantity: typeof r.quantity === 'number' ? r.quantity : parseFloat(String(r.quantity)) || 1,
            base_unit: r.base_unit ?? null,
        }));
    }
    return [{ component_product_id: '', product_variant_id: null, quantity: 1, base_unit: null }];
}

const consumptionRows = ref<ConsumptionFormRow[]>(initConsumptionRows());

// Hanya varian yang sudah tersimpan dan masih aktif pada form yang boleh jadi target konsumsi.
const consumptionVariantOptions = computed(() =>
    variantRows.value
        .filter((v): v is VariantFormRow & { id: number } => v.id != null && v.is_active && v.name.trim() !== '')
        .map((v) => ({ id: v.id, name: v.name.trim() })),
);

const showConsumptionVariantColumn = computed(
    () => form.has_variants && consumptionVariantOptions.value.length > 0,
);

function consumptionCatalogById(id: number | '') {
    if (id === '' || id === null) return undefined;
    return props.saleConsumptionCatalog.find((c) => c.id === Number(id));
}

function consumptionCatalogOptions() {
    return props.saleConsumptionCatalog.filter(
        (c) => c.inventory_type === 'raw_material' || c.inventory_type === 'production_output' || c.inventory_type === 'packaging',
    );
}

function onConsumptionProductChange(row: ConsumptionFormRow) {
    const cat = consumptionCatalogById(row.component_product_id);
    row.base_unit = cat?.base_unit ?? null;
}

function addConsumptionRow() {
    consumptionRows.value.push({ component_product_id: '', product_variant_id: null, quantity: 1, base_unit: null });
}

function removeConsumptionRow(index: number) {
    if (consumptionRows.value.length <= 1) return;
    consumptionRows.value.splice(index, 1);
}

function buildSaleConsumptionsPayload(): string {
    // Blok konsumsi tersembunyi (paket / bukan produk jual) atau toggle nonaktif
    // berarti tidak ada konfigurasi yang dikirim.
    if (!showConsumptionBlock.value || !consumptionEnabled.value) return '[]';
    const filtered = consumptionRows.value
        .filter((r) => r.component_product_id !== '' && r.component_product_id != null);
    if (filtered.length === 0) return '[]';
    const allowedVariantIds = new Set(consumptionVariantOptions.value.map((v) => v.id));
    const payload = filtered.map((r, idx) => ({
        component_product_id: Number(r.component_product_id),
        product_variant_id:
            form.has_variants && r.product_variant_id != null && allowedVariantIds.has(Number(r.product_variant_id))
                ? Number(r.product_variant_id)
                : null,
        quantity: Math.max(0.0001, Number(r.quantity) || 1),
        base_unit: r.base_unit ?? null,
        sort_order: idx,
    }));
    return JSON.stringify(payload);
}

function initBatchRows(): BatchRow[] {
    if (product.has_variants || product.is_bundle) {
        return [];
    }
    if (props.batchTrackingEnabled && props.batches.length > 0) {
        return props.batches.map((b) => ({
            quantity_remaining: b.quantity_remaining,
            expiry_date: b.expiry_date || '',
            batch_code: b.batch_code || '',
        }));
    }
    if (props.batchTrackingEnabled) {
        return [
            {
                quantity_remaining: Math.max(
                    0,
                    typeof product.stock_quantity === 'string'
                        ? parseFloat(product.stock_quantity)
                        : (product.stock_quantity ?? 0),
                ),
                expiry_date: '',
                batch_code: '',
            },
        ];
    }
    return [];
}

const batchRows = ref<BatchRow[]>(initBatchRows());

watch(
    () => [props.batchTrackingEnabled, form.track_stock],
    ([enabled, track]) => {
        if (enabled && track && batchRows.value.length === 0) {
            batchRows.value = initBatchRows();
        }
    },
);

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

watch(
    [batchStockSum, () => props.batchTrackingEnabled, () => form.track_stock, () => form.has_variants, () => form.is_bundle],
    () => {
        if (props.batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle) {
            form.stock_quantity = batchStockSum.value;
        }
    },
    { immediate: true },
);

function buildBatchesPayload(): Array<{ quantity_remaining: number; expiry_date: string | null; batch_code: string | null }> {
    return batchRows.value.map((r) => ({
        quantity_remaining: form.allows_decimal
            ? Math.max(0, Math.round((Number(r.quantity_remaining) || 0) * 10000) / 10000)
            : Math.max(0, Math.round(Number(r.quantity_remaining) || 0)),
        expiry_date: r.expiry_date?.trim() || null,
        batch_code: r.batch_code?.trim() || null,
    }));
}

function addBatchRow() {
    batchRows.value.push({ quantity_remaining: 0, expiry_date: '', batch_code: '' });
}

function removeBatchRow(index: number) {
    if (batchRows.value.length <= 1) return;
    batchRows.value.splice(index, 1);
}

// Debug: Log form data after initialization
console.log('Form data after initialization:', {
    name: form.name,
    sku: form.sku,
    barcode: form.barcode,
    description: form.description,
    price: form.price,
    cost_price: form.cost_price,
    stock_quantity: form.stock_quantity,
    min_stock_level: form.min_stock_level,
    product_category_id: form.product_category_id,
    brand: form.brand,
    unit: form.unit,
    tax_rate: form.tax_rate,
    track_stock: form.track_stock,
    allows_decimal: form.allows_decimal,
    is_active: form.is_active,
});

// Initialize image previews with full URLs
const getImagePreviews = () => {
    console.log('Product images:', product.images);
    console.log('Product image_url:', product.image_url);
    
    if (product.images && product.images.length > 0) {
        return product.images.map(image => {
            console.log('Processing image:', image);
            // If it's already a full URL, return as is
            if (image.startsWith('http')) {
                return image;
            }
            // Otherwise, construct the full URL
            const fullUrl = `/storage/${image}`;
            console.log('Constructed URL:', fullUrl);
            return fullUrl;
        });
    }
    return [];
};

const imagePreviews = ref<string[]>(getImagePreviews());

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

const submit = () => {
    if (!form) {
        console.error('Form is not initialized');
        return;
    }

    const finiteNum = (v: unknown, fallback: number) => {
        const n = typeof v === 'number' ? v : Number(v);
        return Number.isFinite(n) ? n : fallback;
    };
    const sq = finiteNum(form.stock_quantity, 0);
    const mq = finiteNum(form.min_stock_level, 0);
    if (form.allows_decimal) {
        form.stock_quantity = Math.max(0, Math.round(sq * 10000) / 10000);
        form.min_stock_level = Math.max(0, Math.round(mq * 10000) / 10000);
    } else {
        form.stock_quantity = Math.max(0, Math.round(sq));
        form.min_stock_level = Math.max(0, Math.round(mq));
    }

    if (props.batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle) {
        form.stock_quantity = batchStockSum.value;
        form.batches_json = JSON.stringify(buildBatchesPayload());
    } else if (!form.has_variants && !form.is_bundle) {
        form.batches_json = '';
    }

    if (props.fnbModifierEnabled) {
        form.modifier_options_json = form.is_bundle ? '' : JSON.stringify(buildModifierOptionsPayload());
    } else {
        form.modifier_options_json = '';
    }

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
        form.stock_quantity = vPayload.reduce((s, r) => s + Number(r.stock_quantity), 0);
        form.min_stock_level = 0;
        form.batches_json = '';
        form.bundles_json = '';
        form.sale_consumptions_json = buildSaleConsumptionsPayload();
        form.variants_json = JSON.stringify(vPayload);
    } else if (form.is_bundle) {
        const bPayload = buildBundlesPayloadEdit();
        if (bPayload.length === 0) {
            toastWarning('Tambahkan minimal satu komponen paket.');
            return;
        }
        form.stock_quantity = 0;
        form.min_stock_level = 0;
        form.batches_json = '';
        form.variants_json = '';
        form.sale_consumptions_json = '';
        form.bundles_json = JSON.stringify(bPayload);
    } else {
        form.variants_json = '';
        form.bundles_json = '';
        form.sale_consumptions_json = buildSaleConsumptionsPayload();
    }

    if (
        props.pharmacyFieldsEnabled &&
        props.batchTrackingEnabled &&
        form.track_stock &&
        !form.has_variants &&
        !form.is_bundle
    ) {
        const rows = batchRows.value;
        for (let i = 0; i < rows.length; i++) {
            if (!rows[i]?.expiry_date?.trim()) {
                toastWarning(`Apotek: tanggal kedaluwarsa (ED) wajib untuk setiap batch (baris ${i + 1}).`);
                return;
            }
        }
    }
    
    // Debug: Log form data before submission (LENGKAP termasuk unlimited_stock)
    console.log('[EDIT_SUBMIT_START] Form data SEBELUM normalisasi:', {
        name: form.name,
        track_stock: form.track_stock,
        unlimited_stock: form.unlimited_stock,
        unlimited_stock_type: typeof form.unlimited_stock,
        unlimited_stock_strict_true: form.unlimited_stock === true,
        allows_decimal: form.allows_decimal,
        is_active: form.is_active,
        is_bundle: form.is_bundle,
        has_variants: form.has_variants,
    });
    
    // Debug: Check for empty or invalid values
    const requiredFields = ['name', 'price', 'stock_quantity', 'min_stock_level', 'unit'];
    const emptyFields = requiredFields.filter(field => {
        const value = (form as any)[field];
        return value === null || value === undefined || value === '' || (typeof value === 'number' && isNaN(value));
    });
    
    if (emptyFields.length > 0) {
        console.error('Empty required fields detected:', emptyFields);
        console.error('Form values:', form.data());
        return;
    }
    
    // Ensure all required fields have valid values before submission
    const normalizedData = {
        name: form.name || '',
        sku: form.sku || '',
        barcode: form.barcode || '',
        description: form.description || '',
        price: form.price || 0,
        wholesale_price: form.wholesale_price || 0,
        cost_price: form.cost_price || 0,
        stock_quantity: form.stock_quantity || 0,
        min_stock_level: form.min_stock_level || 0,
        product_category_id: form.product_category_id === '' || form.product_category_id === null ? '' : form.product_category_id,
        brand: form.brand || '',
        unit: form.unit || 'pcs',
        inventory_type: form.inventory_type || 'sellable',
        base_unit: form.base_unit || 'pcs',
        tax_rate: form.tax_rate || 0,
        track_stock: Boolean(form.track_stock),
        unlimited_stock: Boolean(form.unlimited_stock),
        allows_decimal: Boolean(form.allows_decimal),
        has_variants: Boolean(form.has_variants),
        is_bundle: Boolean(form.is_bundle),
        is_active: Boolean(form.is_active),
        images: form.images || [],
        pharmacy_sale_class: form.pharmacy_sale_class || 'otc',
        pharmacy_registration_no: form.pharmacy_registration_no ?? '',
    };

    console.log('[EDIT_SUBMIT_NORMALIZED] Data setelah normalisasi:', {
        track_stock: normalizedData.track_stock,
        unlimited_stock: normalizedData.unlimited_stock,
        unlimited_stock_type: typeof normalizedData.unlimited_stock,
        is_bundle: normalizedData.is_bundle,
        is_active: normalizedData.is_active,
    });
    
    // FORCE boolean for reka-ui indeterminate fallback (jangan overwrite json fields yang sudah di-set)
    form.track_stock = Boolean(normalizedData.track_stock);
    form.unlimited_stock = Boolean(normalizedData.unlimited_stock);
    form.allows_decimal = Boolean(normalizedData.allows_decimal);
    form.has_variants = Boolean(normalizedData.has_variants);
    form.is_bundle = Boolean(normalizedData.is_bundle);
    form.is_active = Boolean(normalizedData.is_active);

    console.log('[EDIT_SUBMIT_AFTER_ASSIGN] form.unlimited_stock setelah Object.assign:', {
        value: form.unlimited_stock,
        type: typeof form.unlimited_stock,
        formData: form.data(),
    });
    
    // Check if there are new images to upload
    const hasNewImages = form.images && form.images.length > 0;
    
    if (hasNewImages) {
        // Use router.visit with manual FormData for file uploads
        console.log('Submitting with router.visit + manual FormData (has new images)');
        console.log('Form data before submission:', {
            track_stock: normalizedData.track_stock,
            unlimited_stock: normalizedData.unlimited_stock,
            allData: normalizedData
        });
        
        // Create FormData manually
        const formData = new FormData();
        
        // Add all form fields to FormData
        formData.append('name', normalizedData.name || 'Untitled Product');
        formData.append('sku', normalizedData.sku || '');
        formData.append('barcode', normalizedData.barcode || '');
        formData.append('description', normalizedData.description || '');
        formData.append('price', String(normalizedData.price || 0));
        formData.append('wholesale_price', String(normalizedData.wholesale_price || 0));
        formData.append('cost_price', String(normalizedData.cost_price || 0));
        formData.append('stock_quantity', String(normalizedData.stock_quantity || 0));
        formData.append('min_stock_level', String(normalizedData.min_stock_level || 0));
        formData.append(
            'product_category_id',
            normalizedData.product_category_id === '' || normalizedData.product_category_id === null
                ? ''
                : String(normalizedData.product_category_id),
        );
        formData.append('brand', normalizedData.brand || '');
        formData.append('unit', normalizedData.unit || 'pcs');
        formData.append('inventory_type', normalizedData.inventory_type || 'sellable');
        formData.append('base_unit', normalizedData.base_unit || 'pcs');
        formData.append('tax_rate', String(normalizedData.tax_rate || 0));
        formData.append('track_stock', normalizedData.track_stock ? '1' : '0');
        formData.append('unlimited_stock', normalizedData.unlimited_stock ? '1' : '0');
        formData.append('allows_decimal', normalizedData.allows_decimal ? '1' : '0');
        formData.append('has_variants', normalizedData.has_variants ? '1' : '0');
        formData.append('is_bundle', normalizedData.is_bundle ? '1' : '0');
        formData.append('is_active', normalizedData.is_active ? '1' : '0');
        formData.append('pharmacy_sale_class', String(normalizedData.pharmacy_sale_class || 'otc'));
        formData.append('pharmacy_registration_no', normalizedData.pharmacy_registration_no || '');
        if (form.variants_json) {
            formData.append('variants_json', form.variants_json);
        }
        if (form.bundles_json) {
            formData.append('bundles_json', form.bundles_json);
        }
        if (form.batches_json) {
            formData.append('batches_json', form.batches_json);
        }
        if (form.modifier_options_json !== '') {
            formData.append('modifier_options_json', form.modifier_options_json);
        }
        if (form.sale_consumptions_json) {
            formData.append('sale_consumptions_json', form.sale_consumptions_json);
        }

        // Add images
        if (normalizedData.images && normalizedData.images.length > 0) {
            normalizedData.images.forEach((image, index) => {
                formData.append(`images[${index}]`, image);
            });
        }
        
        // Add _method for Laravel to recognize as PUT request
        formData.append('_method', 'PUT');
        
        // Debug: Log FormData contents
        console.log('FormData entries:');
        for (const [key, value] of formData.entries()) {
            console.log(`${key}:`, value);
        }
        
        // Use router.visit with FormData
        router.visit(`/products/${product.id}`, {
            method: 'post',
            data: formData,
            forceFormData: true,
            onSuccess: (page) => {
                console.log('Form submitted successfully:', page);
            },
            onError: (errors) => {
                console.log('Validation errors:', errors);
            }
        });
    } else {
        // Force strict boolean explicitly before put just in case
        form.track_stock = Boolean(form.track_stock);
        form.unlimited_stock = Boolean(form.unlimited_stock);
        form.allows_decimal = Boolean(form.allows_decimal);
        form.has_variants = Boolean(form.has_variants);
        form.is_bundle = Boolean(form.is_bundle);
        form.is_active = Boolean(form.is_active);

        // Use regular JSON for updates without new images
        console.log('[EDIT_SUBMIT_PUT] Submitting via form.put (JSON):', {
            unlimited_stock: form.unlimited_stock,
            track_stock: form.track_stock,
            fullData: form.data(),
        });
        form.put(`/products/${product.id}`, {
            onSuccess: (page) => {
                console.log('[EDIT_SUBMIT_PUT_SUCCESS] Berhasil:', {
                    props: (page as any)?.props,
                });
            },
            onError: (errors) => {
                console.error('[EDIT_SUBMIT_PUT_ERROR] Validation errors:', errors);
            },
            onFinish: () => {
                console.log('[EDIT_SUBMIT_PUT_FINISH] Request selesai, form.unlimited_stock:', form.unlimited_stock);
            },
        });
    }
};
</script>

<template>
    <AdminLayout title="Edit Produk">
        <template #header-actions>
            <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                <Button
                    as="a"
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
                as="a"
                href="/products"
                variant="ghost"
                size="sm"
                class="p-2 -mr-1 shrink-0"
                aria-label="Kembali ke daftar produk"
            >
                <ArrowLeft class="h-5 w-5" />
            </Button>
        </template>

        <p class="text-sm text-slate-600 -mt-1 mb-6">
            Mengubah: <span class="font-semibold text-slate-900">{{ product.name }}</span>
        </p>

        <form @submit.prevent="submit" class="space-y-8">
                <!-- Informasi Dasar -->
                <Card>
                    <CardHeader>
                        <CardTitle>Informasi Dasar</CardTitle>
                        <CardDescription>
                            Informasi utama produk
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
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
                                <Label for="sku">SKU</Label>
                                <Input
                                    id="sku"
                                    v-model="form.sku"
                                    type="text"
                                    placeholder="Masukkan SKU"
                                />
                                <div v-if="form.errors.sku" class="text-sm text-red-600">
                                    {{ form.errors.sku }}
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="barcode">Barcode</Label>
                                <div class="flex gap-2">
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
                                            showBarcodeScannerEdit
                                                ? 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100'
                                                : 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100',
                                        ]"
                                        :aria-label="showBarcodeScannerEdit ? 'Tutup kamera' : 'Scan via kamera'"
                                        @click="showBarcodeScannerEdit = !showBarcodeScannerEdit"
                                    >
                                        <ScanLine class="h-4 w-4" />
                                        <span>{{ showBarcodeScannerEdit ? 'Tutup' : 'Kamera' }}</span>
                                    </button>
                                </div>
                                <p class="text-xs text-slate-400">Klik field barcode lalu scan dengan USB scanner, atau gunakan tombol Kamera untuk scan via kamera.</p>

                                <!-- Scanner USB keyboard wedge: selalu aktif -->
                                <BarcodeScanner
                                    mode="keyboard"
                                    :active="true"
                                    @scanned="(code) => { form.barcode = code; }"
                                    @error="() => {}"
                                />

                                <!-- Scanner kamera -->
                                <BarcodeScanner
                                    v-if="showBarcodeScannerEdit"
                                    mode="camera"
                                    :active="showBarcodeScannerEdit"
                                    @scanned="(code) => { form.barcode = code; showBarcodeScannerEdit = false; }"
                                    @error="() => {}"
                                />

                                <!-- Generator barcode jika produk belum punya barcode -->
                                <div
                                    v-if="!form.barcode || showBarcodeGeneratorEdit"
                                    class="mt-2 rounded-xl border border-teal-100 bg-teal-50/30 p-3"
                                >
                                    <p class="mb-2 text-xs font-semibold text-teal-800">Generate barcode otomatis</p>
                                    <BarcodeGenerator
                                        :product-id="product.id"
                                        :product-name="form.name"
                                        :initial-code="form.barcode"
                                        @generated="(code) => { form.barcode = code; }"
                                        @saved="(code) => { form.barcode = code; showBarcodeGeneratorEdit = false; }"
                                    />
                                </div>
                                <button
                                    v-else
                                    type="button"
                                    class="text-xs text-teal-600 underline underline-offset-2 hover:text-teal-800"
                                    @click="showBarcodeGeneratorEdit = true"
                                >
                                    Generate barcode baru
                                </button>

                                <div v-if="form.errors.barcode" class="text-sm text-red-600">
                                    {{ form.errors.barcode }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="product_category_id">Kategori</Label>
                                <select
                                    id="product_category_id"
                                    v-model="form.product_category_id"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option value="">Tanpa kategori</option>
                                    <option v-for="c in productCategories" :key="c.id" :value="c.id">
                                        {{ c.name }}
                                    </option>
                                </select>
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
                    </CardContent>
                </Card>

                <!-- Harga dan Stok -->
                <Card>
                    <CardHeader>
                        <CardTitle>Harga dan Stok</CardTitle>
                        <CardDescription>
                            Informasi harga dan manajemen stok
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="price">Harga Jual *</Label>
                                <Input
                                    id="price"
                                    v-model="formattedPrice"
                                    type="text"
                                    :placeholder="formatCurrency(0)"
                                    required
                                />
                                <div v-if="form.errors.price" class="text-sm text-red-600">
                                    {{ form.errors.price }}
                                </div>
                            </div>

                            <div v-if="props.wholesaleEnabled" class="space-y-2">
                                <Label for="wholesale_price">Harga Grosir</Label>
                                <Input
                                    id="wholesale_price"
                                    v-model="formattedWholesalePrice"
                                    type="text"
                                    :placeholder="formatCurrency(0)"
                                />
                                <p class="text-xs text-muted-foreground">Kosongkan agar pelanggan grosir tetap memakai harga jual.</p>
                                <div v-if="form.errors.wholesale_price" class="text-sm text-red-600">
                                    {{ form.errors.wholesale_price }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="cost_price">Modal/HPP</Label>
                                <Input
                                    id="cost_price"
                                    v-model="formattedCostPrice"
                                    type="text"
                                    :placeholder="formatCurrency(0)"
                                />
                                <div v-if="form.errors.cost_price" class="text-sm text-red-600">
                                    {{ form.errors.cost_price }}
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="stock_quantity">Stok (total)</Label>
                                <Input
                                    id="stock_quantity"
                                    v-model.number="form.stock_quantity"
                                    type="number"
                                    min="0"
                                    :step="form.allows_decimal ? 0.0001 : 1"
                                    placeholder="0"
                                    :disabled="stockLockedToAdjustment || (batchTrackingEnabled && form.track_stock) || form.has_variants || form.is_bundle"
                                    :class="stockLockedToAdjustment || (batchTrackingEnabled && form.track_stock) || form.has_variants || form.is_bundle ? 'opacity-70' : ''"
                                />
                                <p v-if="form.is_bundle" class="text-xs text-muted-foreground">
                                    Paket: stok dari komponen; induk tidak dilacak.
                                </p>
                                <p v-else-if="form.has_variants" class="text-xs text-muted-foreground">
                                    Total stok = jumlah stok varian (dikelola di tabel varian).
                                </p>
                                <p v-else-if="batchTrackingEnabled && form.track_stock" class="text-xs text-muted-foreground">
                                    Dihitung otomatis dari batch di bawah.
                                </p>
                                <p v-else-if="stockLockedToAdjustment" class="text-xs text-muted-foreground">
                                    Stok hanya dapat diubah lewat
                                    <Link href="/stock-adjustments/create" class="font-semibold text-violet-700 hover:underline">
                                        Penyesuaian stok
                                    </Link>
                                    agar setiap perubahan tercatat beserta alasannya.
                                </p>
                                <div v-if="form.errors.stock_quantity" class="text-sm text-red-600">
                                    {{ form.errors.stock_quantity }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="min_stock_level">Stok Minimum</Label>
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
                                    Atur batas rendah pada masing-masing komponen.
                                </p>
                                <p v-else-if="form.has_variants" class="text-xs text-muted-foreground">
                                    Batas stok rendah diisi per varian.
                                </p>
                                <div v-if="form.errors.min_stock_level" class="text-sm text-red-600">
                                    {{ form.errors.min_stock_level }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="batchTrackingEnabled && form.track_stock && !form.has_variants && !form.is_bundle"
                            class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 space-y-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-violet-900">Batch & tanggal kedaluwarsa (FEFO)</p>
                                    <p class="text-xs text-violet-700 mt-0.5">
                                        Total stok = jumlah semua baris. Kasir mengurangi batch yang ED paling dekat dulu.
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
                                            <th class="p-2 font-semibold w-[110px]">Qty sisa</th>
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
                            <div v-if="form.errors.batches" class="text-sm text-red-600">{{ form.errors.batches }}</div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="inventory_type">Jenis inventory *</Label>
                                <select
                                    id="inventory_type"
                                    v-model="form.inventory_type"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    required
                                >
                                    <option v-for="(label, key) in inventoryTypes" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p class="text-xs text-muted-foreground">Menentukan fungsi produk pada pembelian, recipe, produksi, dan POS.</p>
                                <div v-if="form.errors.inventory_type" class="text-sm text-red-600">{{ form.errors.inventory_type }}</div>
                            </div>

                            <div class="space-y-2">
                                <Label for="base_unit">Satuan dasar ledger *</Label>
                                <select
                                    id="base_unit"
                                    v-model="form.base_unit"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    required
                                >
                                    <option v-for="(label, key) in baseUnits" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p class="text-xs text-muted-foreground">
                                    Tidak dapat diubah selama stok masih ada agar saldo tidak berganti makna.
                                </p>
                                <div v-if="form.errors.base_unit" class="text-sm text-red-600">{{ form.errors.base_unit }}</div>
                            </div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="unit">Satuan tampilan/penjualan</Label>
                                <select
                                    id="unit"
                                    v-model="form.unit"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
                                </select>
                                <div v-if="form.errors.unit" class="text-sm text-red-600">
                                    {{ form.errors.unit }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="tax_rate">Pajak (%)</Label>
                                <Input
                                    id="tax_rate"
                                    v-model="formattedTaxRate"
                                    type="text"
                                    placeholder="0"
                                />
                                <div v-if="form.errors.tax_rate" class="text-sm text-red-600">
                                    {{ form.errors.tax_rate }}
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center space-x-2">
                                <input
                                    type="checkbox"
                                    id="track_stock"
                                    v-model="form.track_stock"
                                    @change="(e) => {
                                        if (!e.target.checked) form.unlimited_stock = false;
                                    }"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                                <Label for="track_stock" class="text-sm">
                                    Lacak stok untuk produk ini
                                </Label>
                            </div>

                            <div class="flex items-center space-x-2">
                                <input
                                    type="checkbox"
                                    id="unlimited_stock"
                                    v-model="form.unlimited_stock"
                                    :disabled="!form.track_stock"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                                <Label for="unlimited_stock" class="text-sm">Stok Tidak Terbatas</Label>
                                <span
                                    v-if="form.unlimited_stock"
                                    class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800"
                                >
                                    unlimited
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground ml-7 -mt-2">
                                Produk akan selalu tersedia tanpa batasan stok
                            </p>

                            <template v-if="showDecimalBlock">
                                <div class="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        id="allows_decimal"
                                        v-model="form.allows_decimal"
                                        :disabled="!canEditAdvancedFields"
                                        @change="(e) => form.allows_decimal = e.target.checked"
                                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-50"
                                    />
                                    <Label for="allows_decimal" class="text-sm" :class="{'opacity-50': !canEditAdvancedFields}">Kuantitas desimal (kg, liter, …)</Label>
                                </div>
                                <p v-if="allowsAdvancedRetail" class="text-xs text-muted-foreground ml-7 -mt-2">
                                    Hingga 4 desimal. Matikan untuk PCS.
                                </p>
                            </template>

                            <template v-if="showVariantBlock">
                                <div class="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        id="has_variants"
                                        v-model="form.has_variants"
                                        :disabled="!canEditAdvancedFields || batchTrackingEnabled || form.is_bundle"
                                        @change="(e) => {
                                            form.has_variants = e.target.checked;
                                            if (e.target.checked) form.is_bundle = false;
                                        }"
                                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-50"
                                    />
                                    <Label for="has_variants" class="text-sm" :class="{'opacity-50': !canEditAdvancedFields || batchTrackingEnabled || form.is_bundle}">Produk punya varian</Label>
                                </div>
                                <p v-if="batchTrackingEnabled" class="text-xs text-muted-foreground ml-7 -mt-2">
                                    Varian tidak tersedia saat mode batch &amp; ED aktif.
                                </p>
                                <p v-else-if="allowsAdvancedRetail" class="text-xs text-muted-foreground ml-7 -mt-2">
                                    Harga jual induk bisa dipakai sebagai fallback jika harga varian dikosongkan.
                                </p>
                            </template>

                            <template v-if="showBundleBlock">
                                <div class="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        id="is_bundle_edit"
                                        v-model="form.is_bundle"
                                        :disabled="!canEditAdvancedFields || form.has_variants"
                                        @change="(e) => {
                                            if (e.target.checked) {
                                                form.has_variants = false;
                                                form.track_stock = false;
                                            }
                                        }"
                                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary disabled:opacity-50"
                                    />
                                    <Label for="is_bundle_edit" class="text-sm" :class="{'opacity-50': !canEditAdvancedFields || form.has_variants}">Produk paket / bundel</Label>
                                </div>
                                <p v-if="allowsAdvancedRetail" class="text-xs text-muted-foreground ml-7 -mt-2">
                                    Tidak bisa digabung dengan varian. Harga jual = harga yang tampil di kasir untuk satu paket.
                                </p>
                            </template>

                            <div
                                v-if="form.has_variants && !form.is_bundle && showVariantBlock"
                                class="rounded-xl border border-teal-200 bg-teal-50/50 p-4 space-y-3 ml-0"
                                :class="!canEditAdvancedFields ? 'pointer-events-none opacity-70' : ''"
                            >
                                <p
                                    v-if="!canEditAdvancedFields"
                                    class="text-xs font-medium text-amber-900 rounded-lg bg-amber-100/80 px-3 py-2 border border-amber-200"
                                >
                                    Pengelolaan varian memerlukan paket GrowUp. Data varian dipertahankan; Anda tetap bisa mengubah
                                    nama, harga induk, dan pengaturan lain di luar blok ini.
                                </p>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-teal-900">Varian</p>
                                        <p class="text-xs text-teal-800 mt-0.5">
                                            Minimal satu baris dengan nama. Mengirim form menyinkronkan stok induk.
                                        </p>
                                    </div>
                                    <Button type="button" variant="outline" size="sm" class="shrink-0" @click="addVariantRow">
                                        <Plus class="h-4 w-4 mr-1" />
                                        Varian
                                    </Button>
                                </div>
                                <div class="overflow-x-auto rounded-lg border border-teal-200/80 bg-white">
                                    <table class="w-full text-sm min-w-[900px]">
                                        <thead>
                                            <tr class="border-b bg-teal-50/80 text-left text-xs text-teal-900">
                                                <th class="p-2 font-semibold min-w-[140px]">Nama *</th>
                                                <th class="p-2 font-semibold w-[100px]">SKU</th>
                                                <th class="p-2 font-semibold w-[100px]">Barcode</th>
                                                <th class="p-2 font-semibold w-[120px]">Harga</th>
                                                <th class="p-2 font-semibold w-[120px]">Grosir</th>
                                                <th class="p-2 font-semibold w-[110px]">HPP</th>
                                                <th class="p-2 font-semibold w-[80px]">Stok</th>
                                                <th class="p-2 font-semibold w-[80px]">Min</th>
                                                <th class="p-2 font-semibold w-[70px]">Aktif</th>
                                                <th class="p-2 w-10"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(row, idx) in variantRows" :key="idx" class="border-b border-teal-100 last:border-0">
                                                <td class="p-2">
                                                    <Input v-model="row.name" type="text" class="h-9" />
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
                                                <td class="p-2">
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
                                                    <Checkbox
                                                        :checked="row.is_active"
                                                        @update:checked="(v: boolean) => row.is_active = Boolean(v)"
                                                    />
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
                                v-if="form.is_bundle && !form.has_variants && showBundleBlock"
                                class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 space-y-3 ml-0"
                                :class="!canEditAdvancedFields ? 'pointer-events-none opacity-70' : ''"
                            >
                                <p
                                    v-if="!canEditAdvancedFields"
                                    class="text-xs font-medium text-amber-900 rounded-lg bg-amber-100/80 px-3 py-2 border border-amber-200"
                                >
                                    Pengelolaan komponen paket memerlukan paket GrowUp. Komposisi dipertahankan di server.
                                </p>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-amber-900">Komponen paket</p>
                                        <p class="text-xs text-amber-900/90 mt-0.5">
                                            Stok komponen berkurang per kuantitas saat paket terjual.
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

                            <div
                                v-if="showConsumptionBlock"
                                class="rounded-xl border border-violet-200 bg-violet-50/50 p-4 space-y-3 ml-0"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-violet-900">Komponen konsumsi penjualan</p>
                                        <p class="text-xs text-violet-900/90 mt-0.5">
                                            Konfigurasi stok mana yang berkurang saat produk ini terjual (bahan baku / produksi output / packaging).
                                            <span v-if="showConsumptionVariantColumn"> Atur per varian untuk takaran berbeda.</span>
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
                                        id="consumption_enabled_edit"
                                        v-model="consumptionEnabled"
                                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                    />
                                    <Label for="consumption_enabled_edit" class="text-sm text-violet-900">
                                        Aktifkan komponen konsumsi untuk produk ini
                                    </Label>
                                </div>
                                <p v-if="!consumptionEnabled" class="text-xs text-violet-900/80 ml-6 -mt-1">
                                    Nonaktif: stok produk ini berkurang seperti biasa saat terjual.
                                    Menyimpan dalam keadaan nonaktif akan menghapus konfigurasi komponen yang tersimpan.
                                </p>
                                <div v-if="consumptionEnabled" class="overflow-x-auto rounded-lg border border-violet-200/80 bg-white">
                                    <table class="w-full text-sm min-w-[480px]">
                                        <thead>
                                            <tr class="border-b bg-violet-50/80 text-left text-xs text-violet-950">
                                                <th v-if="showConsumptionVariantColumn" class="p-2 font-semibold min-w-[140px]">Varian</th>
                                                <th class="p-2 font-semibold min-w-[200px]">Komponen *</th>
                                                <th class="p-2 font-semibold w-[120px]">Qty / pcs</th>
                                                <th class="p-2 font-semibold w-[100px]">Satuan</th>
                                                <th class="p-2 w-10"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(row, idx) in consumptionRows" :key="idx" class="border-b border-violet-100 last:border-0">
                                                <td v-if="showConsumptionVariantColumn" class="p-2">
                                                    <select
                                                        v-model="row.product_variant_id"
                                                        class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                    >
                                                        <option :value="null">Semua varian</option>
                                                        <option v-for="v in consumptionVariantOptions" :key="v.id" :value="v.id">
                                                            {{ v.name }}
                                                        </option>
                                                    </select>
                                                </td>
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
                            </div>

                            <div class="flex items-center space-x-2">
                                <Checkbox
                                    id="is_active"
                                    :checked="form.is_active"
                                    @update:checked="(v: boolean) => form.is_active = Boolean(v)"
                                />
                                <Label for="is_active" class="text-sm">
                                    Produk aktif
                                </Label>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="pharmacyFieldsEnabled">
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

                <Card v-if="fnbModifierEnabled && !form.is_bundle">
                    <CardHeader>
                        <CardTitle>Opsi modifier (F&amp;B)</CardTitle>
                        <CardDescription>
                            Tambahan harga per opsi untuk kasir (contoh: level pedas, extra topping).
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex justify-end">
                            <Button type="button" variant="outline" size="sm" @click="addModifierOptionRow">
                                <Plus class="h-4 w-4 mr-1" />
                                Opsi
                            </Button>
                        </div>
                        <div v-if="modifierOptionRows.length === 0" class="text-sm text-muted-foreground">
                            Belum ada opsi.
                        </div>
                        <div v-else class="overflow-x-auto rounded-lg border">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-muted/40 text-left">
                                        <th class="p-2 font-semibold w-[100px]">Kunci</th>
                                        <th class="p-2 font-semibold min-w-[140px]">Nama</th>
                                        <th class="p-2 font-semibold w-[120px]">Harga +</th>
                                        <th class="p-2 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in modifierOptionRows" :key="idx" class="border-b last:border-0">
                                        <td class="p-2">
                                            <Input v-model="row.key" type="text" placeholder="auto" class="h-9" />
                                        </td>
                                        <td class="p-2">
                                            <Input v-model="row.label" type="text" class="h-9" />
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

                <!-- Gambar Produk -->
                <Card>
                    <CardHeader>
                        <CardTitle>Gambar Produk</CardTitle>
                        <CardDescription>
                            Upload gambar produk (maksimal 5 gambar, 2MB per gambar)
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="space-y-2">
                            <Label>Upload Gambar Baru</Label>
                            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center relative group hover:border-primary/50 transition-colors">
                                <div class="pointer-events-none">
                                    <Upload class="h-12 w-12 mx-auto mb-4 text-gray-400 group-hover:text-primary transition-colors" />
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-600">
                                            Klik untuk upload atau drag & drop
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            PNG, JPG, GIF hingga 2MB
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
                        </div>
                        
                        <!-- Image Previews -->
                        <div v-if="imagePreviews.length > 0" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div v-for="(preview, index) in imagePreviews" :key="index" class="relative">
                                <img
                                    :src="preview"
                                    :alt="`Preview ${index + 1}`"
                                    class="w-full h-24 object-cover rounded-lg border border-gray-200"
                                />
                                <Button
                                    @click="removeImage(index)"
                                    variant="destructive"
                                    size="sm"
                                    class="absolute -top-2 -right-2 h-6 w-6 rounded-full p-0"
                                >
                                    <X class="h-3 w-3" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="flex-1 sm:flex-none"
                    >
                        {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                    </Button>
                    <Button 
                        type="button" 
                        variant="outline" 
                        as="a" 
                        href="/products"
                    >
                        Batal
                    </Button>
                </div>
            </form>
    </AdminLayout>
</template>
