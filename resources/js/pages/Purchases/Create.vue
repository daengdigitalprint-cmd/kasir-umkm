<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Plus, Trash2, Truck, ScanLine, AlertCircle, PackagePlus, Package, Search, Receipt } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { modalError, toastSuccess } from '@/lib/swal';
import BarcodeScanner from '@/components/BarcodeScanner.vue';

interface SupplierOpt {
    id: number;
    name: string;
    phone: string | null;
}

interface ProductVariantOpt {
    id: number;
    name: string;
    stock_quantity: number;
}

interface ProductOpt {
    id: number;
    name: string;
    sku: string | null;
    barcode?: string | null;
    unit: string | null;
    base_unit: string;
    input_units: string[];
    stock_quantity: number;
    cost_price?: number | null;
    average_cost?: number | null;
    category?: string | null;
    product_category_id?: number | null;
    allows_decimal?: boolean;
    has_variants?: boolean;
    variants?: ProductVariantOpt[];
    inventory_type?: string;
}

// Satuan yang tersedia per satuan dasar (barang manual)
const BaseUnitOptions: Record<string, string[]> = {
    pcs: ['pcs'],
    gram: ['gram', 'kg'],
    kg: ['kg', 'gram'],
    ml: ['ml', 'liter'],
    liter: ['liter', 'ml'],
};

// Format stok: tampilkan desimal hanya jika diperlukan
function formatStock(qty: number | string): string {
    const num = Number(qty);
    if (Number.isInteger(num)) {
        return num.toString();
    }
    return num.toFixed(4).replace(/\.?0+$/, '');
}

// --- State barcode scanner ---
const barcodeActive = ref(true);
const barcodeNotFound = ref<string | null>(null);
const barcodeLookupLoading = ref(false);

// Form pendaftaran produk baru dari barcode
const newProductDialog = ref(false);
const scannedBarcode = ref('');
const newProductForm = ref({
    name: '',
    unit: 'pcs',
    inventory_type: 'raw_material' as string,
    cost_price: '' as number | '',
    price: '' as number | '',
    stock_quantity: 1,
    category: '',
});
const newProductSaving = ref(false);

function csrfTokenVal(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/** Fetch wrapper: ambil CSRF token fresh + auto-reload pada 419 (session expired di tablet). */
async function apiFetch(url: string, init?: RequestInit): Promise<Response> {
    const headers = new Headers(init?.headers);
    headers.set('X-CSRF-TOKEN', csrfTokenVal());
    headers.set('Accept', headers.get('Accept') || 'application/json');
    if (!headers.has('Content-Type') && init?.method && init.method !== 'GET') {
        headers.set('Content-Type', 'application/json');
    }
    const res = await fetch(url, { ...init, headers, credentials: 'same-origin' });
    if (res.status === 419) {
        window.location.reload();
        throw new Error('Session expired, memuat ulang...');
    }
    return res;
}

async function handleBarcodeScan(code: string) {
    if (barcodeLookupLoading.value) return;
    barcodeNotFound.value = null;
    barcodeLookupLoading.value = true;

    try {
        const res = await apiFetch(`/barcode/lookup?code=${encodeURIComponent(code)}&context=purchase`);
        const data = (await res.json()) as {
            found: boolean;
            barcode: string;
            product?: ProductOpt;
            matched_variant_id?: number;
        };

        if (!data.found) {
            // Produk tidak ditemukan — tampilkan form pendaftaran
            scannedBarcode.value = code;
            newProductForm.value = { name: '', unit: 'pcs', inventory_type: 'raw_material', cost_price: '', price: '', stock_quantity: 1, category: '' };
            newProductDialog.value = true;
            barcodeNotFound.value = `Barcode "${code}" belum terdaftar. Isi form di bawah untuk mendaftarkan produk baru.`;
            return;
        }

        const product = data.product!;
        // Cari apakah produk sudah ada di list (bisa jadi tidak ada karena track_stock=false)
        const existingInList = productList.value.find((p) => p.id === product.id);

        if (existingInList) {
            if (data.matched_variant_id) {
                lineDrafts.value.push(catalogLine(existingInList, data.matched_variant_id));
                syncCostDisplay(lineDrafts.value.length - 1, lineDrafts.value[lineDrafts.value.length - 1].unit_cost);
                void toastSuccess(`${product.name} ditambahkan ke daftar barang masuk`);
            } else {
                addProductLine(existingInList);
            }
        } else {
            // Produk ada di DB tapi tidak tampil (mungkin track_stock=false)
            barcodeNotFound.value = `Produk "${product.name}" ditemukan tapi tidak melacak stok. Aktifkan pelacakan stok di halaman produk.`;
        }
    } catch {
        barcodeNotFound.value = 'Gagal menghubungi server. Periksa koneksi internet.';
    } finally {
        barcodeLookupLoading.value = false;
    }
}

async function saveNewProduct() {
    if (!newProductForm.value.name.trim()) {
        void modalError('Nama wajib', 'Isi nama produk terlebih dahulu.');
        return;
    }
    newProductSaving.value = true;
    try {
        const payload = {
            name: newProductForm.value.name.trim(),
            barcode: scannedBarcode.value,
            sku: null,
            unit: newProductForm.value.unit || 'pcs',
            base_unit: 'pcs',
            inventory_type: newProductForm.value.inventory_type,
            cost_price: Number(newProductForm.value.cost_price) || 0,
            price: Number(newProductForm.value.price) || 0,
            stock_quantity: 0,
            min_stock_level: 0,
            track_stock: true,
            allows_decimal: false,
            has_variants: false,
            is_bundle: false,
            category: newProductForm.value.category,
        };
        const res = await apiFetch('/products', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) {
            const errMsg = (data as { message?: string; errors?: Record<string, string[]> });
            const first = errMsg.errors ? Object.values(errMsg.errors).flat()[0] : errMsg.message;
            throw new Error(first || 'Gagal menyimpan produk');
        }
        newProductDialog.value = false;
        barcodeNotFound.value = null;
        // Tambahkan ke lineDrafts dengan id produk baru
        const newId = (data as { id?: number }).id ?? (data as { product?: { id: number } }).product?.id;
        if (newId) {
            const newProduct: ProductOpt = {
                id: newId,
                name: newProductForm.value.name.trim(),
                sku: null,
                barcode: scannedBarcode.value || null,
                unit: newProductForm.value.unit || 'pcs',
                base_unit: 'pcs',
                input_units: ['pcs'],
                stock_quantity: 0,
                cost_price: Number(newProductForm.value.cost_price) || 0,
                average_cost: Number(newProductForm.value.cost_price) || 0,
                allows_decimal: false,
                has_variants: false,
                inventory_type: newProductForm.value.inventory_type,
            };
            productList.value.push(newProduct);
            lineDrafts.value.push({
                ...catalogLine(newProduct),
                quantity: newProductForm.value.stock_quantity,
            });
            syncCostDisplay(lineDrafts.value.length - 1, lineDrafts.value[lineDrafts.value.length - 1].unit_cost);
        }
        void toastSuccess('Produk baru berhasil didaftarkan dan ditambahkan ke barang masuk');
    } catch (e) {
        void modalError('Gagal', e instanceof Error ? e.message : 'Tidak dapat menyimpan produk');
    } finally {
        newProductSaving.value = false;
    }
}

function handleBarcodeError(msg: string) {
    barcodeNotFound.value = msg;
}

const props = defineProps<{
    suppliers: SupplierOpt[];
    products: ProductOpt[];
    chargeSuggestions: string[];
    batchTrackingEnabled: boolean;
    store: { id: number; name: string; currency: string };
}>();

const supplierList = ref<SupplierOpt[]>([...props.suppliers]);
const productList = ref<ProductOpt[]>([...props.products]);

const quickName = ref('');
const quickPhone = ref('');

interface NewProductDraft {
    name: string;
    sku: string;
    barcode: string;
    inventory_type: string;
    base_unit: string;
    allows_decimal: boolean;
}

interface LineDraft {
    // 'catalog' = produk yang sudah ada di database, 'manual' = barang baru yang akan didaftarkan.
    kind: 'catalog' | 'manual';
    product_id: number | '';
    product_variant_id: number | '';
    label: string;
    quantity: number;
    input_unit: string;
    unit_cost: number | '';
    batch_expiry_date: string;
    batch_code: string;
    new_product: NewProductDraft;
}

interface ChargeDraft {
    name: string;
    amount: number | '';
    notes: string;
}

function emptyNewProduct(): NewProductDraft {
    return { name: '', sku: '', barcode: '', inventory_type: 'raw_material', base_unit: 'pcs', allows_decimal: false };
}

function catalogLine(product: ProductOpt, variantId: number | '' = ''): LineDraft {
    return {
        kind: 'catalog',
        product_id: product.id,
        product_variant_id: variantId,
        label: product.name,
        quantity: 1,
        input_unit: product.base_unit ?? 'pcs',
        unit_cost: cleanNumber(product.average_cost ?? product.cost_price ?? ''),
        batch_expiry_date: '',
        batch_code: '',
        new_product: emptyNewProduct(),
    };
}

function manualLine(name: string): LineDraft {
    return {
        kind: 'manual',
        product_id: '',
        product_variant_id: '',
        label: name,
        quantity: 1,
        input_unit: 'pcs',
        unit_cost: '',
        batch_expiry_date: '',
        batch_code: '',
        new_product: { ...emptyNewProduct(), name },
    };
}

const lineDrafts = ref<LineDraft[]>([]);
const chargeDrafts = ref<ChargeDraft[]>([]);

const form = useForm({
    supplier_id: null as number | null,
    purchased_at: new Date().toISOString().slice(0, 10),
    notes: '',
    ppn_amount: 0,
    supplier_tax_invoice_no: '',
});

function onManualBaseUnitChange(row: LineDraft) {
    const bu = row.new_product.base_unit;
    if (bu === 'gram') {
        row.input_unit = 'kg';
    } else if (bu === 'ml') {
        row.input_unit = 'liter';
    } else {
        row.input_unit = 'pcs';
    }
}

function productById(id: number | ''): ProductOpt | undefined {
    if (id === '' || id === null) {
        return undefined;
    }
    return productList.value.find((p) => p.id === id);
}

function variantsForProduct(productId: number | ''): ProductVariantOpt[] {
    return productById(productId)?.variants ?? [];
}

function unitOptions(row: LineDraft): string[] {
    if (row.kind === 'manual') {
        return BaseUnitOptions[row.new_product.base_unit] ?? [row.new_product.base_unit];
    }
    return productById(row.product_id)?.input_units ?? [row.input_unit];
}

// --- Omnibox: satu kotak ketik untuk cari produk maupun biaya ---
type Suggestion =
    | { type: 'product'; key: string; product: ProductOpt }
    | { type: 'charge'; key: string; name: string };

const search = ref('');
const searchFocused = ref(false);
const highlightIndex = ref(0);
const searchBox = ref<HTMLInputElement | null>(null);

const suggestions = computed<Suggestion[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return [];

    const productHits: Suggestion[] = productList.value
        .filter((p) => {
            if (props.batchTrackingEnabled && p.has_variants) return false;
            return (
                p.name.toLowerCase().includes(q) ||
                (p.sku ?? '').toLowerCase().includes(q) ||
                (p.barcode ?? '').toLowerCase().includes(q)
            );
        })
        .slice(0, 8)
        .map((p) => ({ type: 'product' as const, key: `p-${p.id}`, product: p }));

    const chargeHits: Suggestion[] = props.chargeSuggestions
        .filter((name) => name.toLowerCase().includes(q))
        .slice(0, 5)
        .map((name) => ({ type: 'charge' as const, key: `c-${name}`, name }));

    return [...productHits, ...chargeHits];
});

// Apakah kata yang diketik persis sama dengan salah satu saran
const exactMatch = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return false;
    return (
        productList.value.some((p) => p.name.toLowerCase() === q) ||
        props.chargeSuggestions.some((n) => n.toLowerCase() === q)
    );
});

function resetSearch() {
    search.value = '';
    highlightIndex.value = 0;
    searchBox.value?.focus();
}

function addProductLine(product: ProductOpt) {
    const existing = lineDrafts.value.find(
        (r) => r.kind === 'catalog' && r.product_id === product.id && !product.has_variants,
    );
    if (existing) {
        existing.quantity = Number(existing.quantity) + 1;
        void toastSuccess(`Qty «${product.name}» ditambah`);
    } else {
        lineDrafts.value.push(catalogLine(product));
        syncCostDisplay(lineDrafts.value.length - 1, lineDrafts.value[lineDrafts.value.length - 1].unit_cost);
    }
    resetSearch();
}

function addManualLine(name: string) {
    lineDrafts.value.push(manualLine(name));
    syncCostDisplay(lineDrafts.value.length - 1, '');
    resetSearch();
}

function addChargeLine(name: string) {
    chargeDrafts.value.push({ name, amount: '', notes: '' });
    resetSearch();
}

function applySuggestion(s: Suggestion) {
    if (s.type === 'product') {
        addProductLine(s.product);
    } else {
        addChargeLine(s.name);
    }
}

function onSearchEnter() {
    const list = suggestions.value;
    if (list.length > 0) {
        applySuggestion(list[Math.min(highlightIndex.value, list.length - 1)]);
        return;
    }
    // Tidak ada saran: default ke barang baru agar alur cepat tidak terputus.
    const name = search.value.trim();
    if (name) {
        addManualLine(name);
    }
}

function moveHighlight(delta: number) {
    const len = suggestions.value.length;
    if (len === 0) return;
    highlightIndex.value = (highlightIndex.value + delta + len) % len;
}

function removeCharge(idx: number) {
    chargeDrafts.value.splice(idx, 1);
}

// Format angka ke gaya Indonesia: 2500 → "2.500", 1500.5 → "1.500,5"
function formatIDR(n: number | ''): string {
    if (n === '' || n === null || n === undefined) return '';
    const num = Number(n);
    if (Number.isNaN(num)) return '';
    const parts = num.toFixed(2).split('.');
    const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const decPart = parts[1].replace(/0+$/, '');
    return decPart ? `${intPart},${decPart}` : intPart;
}

// Parse "2.500" atau "2.500,5" → number
function parseIDR(s: string): number | '' {
    const cleaned = s.replace(/\./g, '').replace(',', '.');
    if (cleaned === '' || cleaned === '.') return '';
    const n = Number(cleaned);
    return Number.isNaN(n) ? '' : n;
}

// State tampilan formatted untuk kolom harga per baris
const formattedCosts = ref<Record<number, string>>({});

// Formatted PPN
const formattedPPN = ref(formatIDR(form.ppn_amount));

function onPPNInput() {
    const raw = formattedPPN.value.replace(/[^\d.,]/g, '');
    formattedPPN.value = raw;
    form.ppn_amount = Number(parseIDR(raw)) || 0;
}

function onPPNBlur() {
    formattedPPN.value = formatIDR(form.ppn_amount);
}

// --- Display & parse nominal biaya tambahan ---
const formattedCharges = ref<Record<number, string>>({});

function getChargeDisplay(idx: number): string {
    return formattedCharges.value[idx] ?? '';
}

function setChargeDisplay(idx: number, val: string) {
    formattedCharges.value[idx] = val;
}

function onChargeAmountInput(idx: number, c: ChargeDraft) {
    const raw = getChargeDisplay(idx).replace(/[^\d.,]/g, '');
    setChargeDisplay(idx, raw);
    c.amount = parseIDR(raw);
}

function syncChargeDisplay(idx: number, val: number | '') {
    formattedCharges.value[idx] = val === '' ? '' : formatIDR(val);
}

// --- Ringkasan (subtotal barang, biaya, total) ---
const goodsSubtotal = computed(() =>
    lineDrafts.value.reduce((sum, row, idx) => {
        const qty = Number(row.quantity) || 0;
        const cost = Number(row.unit_cost) || 0;
        return sum + qty * cost;
    }, 0),
);

const chargesSubtotal = computed(() =>
    chargeDrafts.value.reduce((sum, c) => sum + (Number(c.amount) || 0), 0),
);

const grandTotal = computed(() => goodsSubtotal.value + chargesSubtotal.value + (Number(form.ppn_amount) || 0));

function getCostDisplay(idx: number): string {
    return formattedCosts.value[idx] ?? '';
}

function setCostDisplay(idx: number, val: string) {
    formattedCosts.value[idx] = val;
}

function onCostInput(idx: number, row: LineDraft) {
    // Simpan raw input, parse ke number
    const raw = getCostDisplay(idx).replace(/[^\d.,]/g, '');
    setCostDisplay(idx, raw);
    row.unit_cost = parseIDR(raw);
}

function initCostDisplay(idx: number, val: number | '') {
    if (!(idx in formattedCosts.value)) {
        formattedCosts.value[idx] = val === '' ? '' : formatIDR(val);
    }
}

function syncCostDisplay(idx: number, val: number | '') {
    formattedCosts.value[idx] = val === '' ? '' : formatIDR(val);
}

// Bersihkan angka desimal: hilangkan trailing zeros, bulatkan ke integer jika habis dibagi
function cleanNumber(val: number | string | null | undefined): number | '' {
    if (val === null || val === undefined || val === '') return '';
    const n = Number(val);
    if (Number.isNaN(n)) return '';
    return n % 1 === 0 ? Math.round(n) : Math.round(n * 10000) / 10000;
}

function removeLine(idx: number) {
    lineDrafts.value.splice(idx, 1);
}

async function quickAddSupplier() {
    const name = quickName.value.trim();
    if (!name) {
        void modalError('Nama wajib', 'Isi nama supplier.');
        return;
    }
    try {
        const res = await apiFetch('/purchases/suppliers', {
            method: 'POST',
            body: JSON.stringify({ name, phone: quickPhone.value.trim() || null }),
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error((data as { message?: string }).message || 'Gagal menyimpan');
        }
        const s = data.supplier as SupplierOpt;
        supplierList.value = [...supplierList.value, s].sort((a, b) => a.name.localeCompare(b.name));
        form.supplier_id = s.id;
        quickName.value = '';
        quickPhone.value = '';
        void toastSuccess('Supplier ditambahkan');
    } catch (e) {
        void modalError('Gagal', e instanceof Error ? e.message : 'Tidak dapat menambah supplier');
    }
}

function buildServerLines():
    | Array<{
          product_id: number;
          product_variant_id?: number | null;
          new_product?: { name: string; sku?: string; barcode?: string; inventory_type?: string; base_unit?: string; unit?: string; allows_decimal?: boolean };
          quantity: number;
          input_unit: string;
          unit_cost?: number | null;
          batch_expiry_date?: string | null;
          batch_code?: string | null;
      }>
    | null {
    const out: Array<{
        product_id: number;
        product_variant_id?: number | null;
        new_product?: { name: string; sku?: string; barcode?: string; inventory_type?: string; base_unit?: string; unit?: string; allows_decimal?: boolean };
        quantity: number;
        input_unit: string;
        unit_cost?: number | null;
        batch_expiry_date?: string | null;
        batch_code?: string | null;
    }> = [];
    for (const row of lineDrafts.value) {
        // Barang manual: kirim new_product, product_id di-resolve server-side
        if (row.kind === 'manual') {
            if (!row.new_product.name.trim()) {
                void modalError('Nama wajib', 'Isi nama produk manual terlebih dahulu.');
                return null;
            }
            const rawQ = Number(row.quantity) || 0;
            const qty = Math.round(rawQ * 10000) / 10000;
            if (qty < 0.0001) {
                void modalError('Jumlah tidak valid', `Periksa jumlah untuk «${row.new_product.name.trim()}».`);
                return null;
            }
            if (!row.input_unit) {
                void modalError('Satuan tidak valid', `Pilih satuan untuk «${row.new_product.name.trim()}».`);
                return null;
            }
            const uc = row.unit_cost === '' || row.unit_cost === null ? null : Math.max(0, Number(row.unit_cost));
            out.push({
                product_id: 0,
                new_product: {
                    name: row.new_product.name.trim(),
                    sku: row.new_product.sku.trim() || undefined,
                    barcode: row.new_product.barcode.trim() || undefined,
                    inventory_type: row.new_product.inventory_type,
                    base_unit: row.new_product.base_unit,
                    unit: row.new_product.base_unit,
                    allows_decimal: row.new_product.allows_decimal,
                },
                quantity: qty,
                input_unit: row.input_unit,
                unit_cost: uc,
                batch_expiry_date: row.batch_expiry_date?.trim() || null,
                batch_code: row.batch_code?.trim().slice(0, 120) || null,
            });
            continue;
        }

        // Mode catalog
        if (row.product_id === '' || row.product_id === null) {
            continue;
        }
        const pr = productById(row.product_id);
        if (!pr) {
            continue;
        }
        if (props.batchTrackingEnabled && pr.has_variants) {
            void modalError(
                'Tidak didukung',
                'Mode batch toko aktif: pembelian stok untuk produk ber-varian belum didukung. Nonaktifkan batch di pengaturan atau pilih produk tanpa varian.',
            );
            return null;
        }
        if (pr.has_variants && (row.product_variant_id === '' || row.product_variant_id === null)) {
            void modalError('Varian wajib', `Pilih varian untuk «${pr.name}».`);
            return null;
        }
        const rawQ = Number(row.quantity) || 0;
        const qty = Math.round(rawQ * 10000) / 10000;
        if (qty < 0.0001 || !row.input_unit) {
            void modalError('Jumlah atau satuan tidak valid', `Periksa jumlah dan satuan beli untuk «${pr.name}».`);
            return null;
        }
        const uc =
            row.unit_cost === '' || row.unit_cost === null ? null : Math.max(0, Number(row.unit_cost));
        const bed =
            row.batch_expiry_date?.trim() === '' ? null : row.batch_expiry_date.trim();
        const bc =
            row.batch_code?.trim() === '' ? null : row.batch_code.trim().slice(0, 120);
        const pvid =
            pr.has_variants && row.product_variant_id !== '' && row.product_variant_id != null
                ? Number(row.product_variant_id)
                : null;
        out.push({
            product_id: Number(row.product_id),
            product_variant_id: pvid,
            quantity: qty,
            input_unit: row.input_unit,
            unit_cost: uc,
            batch_expiry_date: bed,
            batch_code: bc,
        });
    }
    return out;
}

function lineQtyStep(row: LineDraft): string {
    if (row.kind === 'manual') return row.new_product.allows_decimal ? '0.0001' : '1';
    const product = productById(row.product_id);
    return product?.allows_decimal ? '0.0001' : '1';
}

function lineQtyMin(row: LineDraft): number {
    if (row.kind === 'manual') return row.new_product.allows_decimal ? 0.0001 : 1;
    const product = productById(row.product_id);
    return product?.allows_decimal ? 0.0001 : 1;
}

function buildServerCharges(): Array<{ name: string; amount: number; notes: string | null }> | null {
    const out: Array<{ name: string; amount: number; notes: string | null }> = [];
    for (const c of chargeDrafts.value) {
        const name = c.name.trim();
        if (!name) {
            void modalError('Nama biaya wajib', 'Isi nama untuk setiap biaya tambahan.');
            return null;
        }
        const amount = Math.round((Number(c.amount) || 0) * 100) / 100;
        if (amount < 0.01) {
            void modalError('Nominal tidak valid', `Isi nominal untuk «${name}».`);
            return null;
        }
        out.push({ name, amount, notes: c.notes.trim() || null });
    }
    return out;
}

function submit() {
    const lines = buildServerLines();
    if (lines === null) {
        return;
    }
    const charges = buildServerCharges();
    if (charges === null) {
        return;
    }
    if (lines.length === 0 && charges.length === 0) {
        void modalError('Belum ada isi', 'Cari dan tambahkan minimal satu barang atau biaya.');
        return;
    }
    form.transform(() => ({
        supplier_id: form.supplier_id,
        purchased_at: form.purchased_at,
        notes: form.notes,
        ppn_amount: Number(form.ppn_amount) || 0,
        supplier_tax_invoice_no: form.supplier_tax_invoice_no?.trim() || null,
        lines,
        charges,
    }));
    form.post('/purchases', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Pembelian baru" />

    <AdminLayout title="Pembelian baru">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="outline" size="sm" as-child class="rounded-xl">
                    <Link href="/purchases" class="inline-flex items-center gap-2">
                        <ArrowLeft class="h-4 w-4" />
                        Kembali
                    </Link>
                </Button>
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-500/10">
                        <Truck class="h-4 w-4 text-teal-700" />
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-900">Catat pembelian</h1>
                        <p class="text-xs text-slate-500">{{ store.name }}</p>
                    </div>
                </div>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle>Informasi pembelian</CardTitle>
                        <CardDescription>Supplier opsional — bisa ditambah cepat di bawah.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="supplier_id">Supplier</Label>
                                <select
                                    id="supplier_id"
                                    v-model="form.supplier_id"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option :value="null">— Tanpa supplier —</option>
                                    <option v-for="s in supplierList" :key="s.id" :value="s.id">
                                        {{ s.name }}{{ s.phone ? ` · ${s.phone}` : '' }}
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <Label for="purchased_at">Tanggal pembelian</Label>
                                <Input id="purchased_at" v-model="form.purchased_at" type="date" class="h-10" />
                            </div>
                        </div>
                        <div class="rounded-xl border border-dashed border-teal-200 bg-teal-50/40 p-4 space-y-2">
                            <p class="text-xs font-bold text-teal-900">Tambah supplier cepat</p>
                            <div class="flex flex-wrap gap-2 items-end">
                                <div class="flex-1 min-w-[140px] space-y-1">
                                    <Label class="text-xs">Nama</Label>
                                    <Input v-model="quickName" placeholder="CV Sumber Rejeki" class="h-9" />
                                </div>
                                <div class="flex-1 min-w-[120px] space-y-1">
                                    <Label class="text-xs">Telepon (opsional)</Label>
                                    <Input v-model="quickPhone" placeholder="08…" class="h-9" />
                                </div>
                                <Button type="button" variant="secondary" class="h-9 rounded-lg" @click="quickAddSupplier">
                                    Simpan supplier
                                </Button>
                            </div>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="ppn_amount">PPN Masukan (opsional)</Label>
                                <input
                                    id="ppn_amount"
                                    type="text"
                                    inputmode="numeric"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-right font-mono"
                                    :value="formattedPPN"
                                    placeholder="0"
                                    @input="onPPNInput"
                                    @blur="onPPNBlur"
                                />
                                <p class="text-xs text-slate-500">
                                    DPP dihitung dari Σ (harga beli × qty) per baris. Wajib isi harga beli jika ada PPN.
                                </p>
                                <p v-if="form.errors.ppn_amount" class="text-sm text-red-600">{{ form.errors.ppn_amount }}</p>
                            </div>
                            <div class="space-y-2">
                                <Label for="supplier_tax_invoice_no">Nomor faktur pajak / referensi</Label>
                                <Input
                                    id="supplier_tax_invoice_no"
                                    v-model="form.supplier_tax_invoice_no"
                                    maxlength="120"
                                    class="h-10"
                                    placeholder="Opsional"
                                />
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="notes">Catatan</Label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="2"
                                class="flex min-h-[72px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="No. faktur, keterangan…"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-2xl border-slate-200 shadow-sm">
                    <CardHeader>
                        <CardTitle>Barang masuk &amp; biaya</CardTitle>
                        <CardDescription>
                            Ketik nama barang atau biaya di kotak pencarian, lalu pilih saran yang muncul. Barang
                            menambah stok; biaya (mis. ongkos kirim) tercatat sebagai pengeluaran tanpa menambah stok.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <!-- Scanner barcode -->
                        <div class="rounded-xl border border-teal-100 bg-teal-50/30 p-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <ScanLine class="h-4 w-4 text-teal-700 shrink-0" />
                                <span class="text-sm font-semibold text-teal-800">Scan barcode barang masuk</span>
                                <span v-if="barcodeLookupLoading" class="text-xs text-slate-400 animate-pulse ml-1">Mencari produk…</span>
                            </div>
                            <BarcodeScanner
                                mode="both"
                                :active="barcodeActive && !newProductDialog"
                                @scanned="handleBarcodeScan"
                                @error="handleBarcodeError"
                            />
                            <div
                                v-if="barcodeNotFound"
                                class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
                                role="alert"
                            >
                                <AlertCircle class="h-4 w-4 shrink-0 mt-0.5" />
                                <span>{{ barcodeNotFound }}</span>
                            </div>
                        </div>

                        <!-- Dialog form produk baru dari barcode -->
                        <div
                            v-if="newProductDialog"
                            class="rounded-xl border border-teal-200 bg-white p-4 shadow-sm space-y-4"
                            role="region"
                            aria-label="Form pendaftaran produk baru"
                        >
                            <div class="flex items-center gap-2">
                                <PackagePlus class="h-5 w-5 text-teal-700" />
                                <h3 class="font-bold text-slate-900">Daftarkan produk baru</h3>
                                <code class="ml-auto text-xs bg-slate-100 px-2 py-0.5 rounded font-mono">{{ scannedBarcode }}</code>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1 sm:col-span-2">
                                    <Label class="text-xs">Nama produk <span class="text-red-500">*</span></Label>
                                    <Input v-model="newProductForm.name" placeholder="Contoh: Minyak Goreng 1L" class="h-9" />
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Tipe inventory</Label>
                                    <select v-model="newProductForm.inventory_type" class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm">
                                        <option value="raw_material">Bahan mentah</option>
                                        <option value="packaging">Kemasan</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Satuan</Label>
                                    <Input v-model="newProductForm.unit" placeholder="pcs / kg / ltr" class="h-9" />
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Kategori (opsional)</Label>
                                    <Input v-model="newProductForm.category" placeholder="Makanan, Minuman…" class="h-9" />
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Harga modal / unit</Label>
                                    <Input v-model.number="newProductForm.cost_price" type="number" min="0" step="1" placeholder="0" class="h-9" />
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Harga jual</Label>
                                    <Input v-model.number="newProductForm.price" type="number" min="0" step="1" placeholder="0" class="h-9" />
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Jumlah stok masuk</Label>
                                    <Input v-model.number="newProductForm.stock_quantity" type="number" min="1" step="1" class="h-9" />
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button type="button" class="flex-1 rounded-xl" :disabled="newProductSaving" @click="saveNewProduct">
                                    {{ newProductSaving ? 'Menyimpan…' : 'Simpan &amp; tambahkan' }}
                                </Button>
                                <Button type="button" variant="outline" class="rounded-xl" @click="newProductDialog = false; barcodeNotFound = null">
                                    Batal
                                </Button>
                            </div>
                        </div>

                        <div
                            v-if="batchTrackingEnabled"
                            class="text-xs text-violet-800 bg-violet-50 border border-violet-100 rounded-lg p-3"
                        >
                            Mode batch aktif: setiap baris menjadi <strong>satu batch baru</strong>. Isi ED/kode batch
                            bila perlu. Produk ber-varian tidak bisa dipilih saat mode ini.
                        </div>

                        <!-- Omnibox pencarian barang / biaya -->
                        <div class="relative">
                            <div class="relative">
                                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    ref="searchBox"
                                    v-model="search"
                                    type="text"
                                    role="combobox"
                                    aria-controls="purchase-omnibox-list"
                                    aria-autocomplete="list"
                                    :aria-expanded="searchFocused && search.trim().length > 0"
                                    autocomplete="off"
                                    placeholder="Ketik nama barang atau biaya… (mis. Gula, Ongkos kirim)"
                                    class="flex h-11 w-full rounded-xl border border-input bg-background pl-9 pr-3 py-2 text-sm"
                                    @focus="searchFocused = true"
                                    @blur="searchFocused = false"
                                    @keydown.down.prevent="moveHighlight(1)"
                                    @keydown.up.prevent="moveHighlight(-1)"
                                    @keydown.enter.prevent="onSearchEnter"
                                    @keydown.esc="search = ''"
                                />
                            </div>

                            <ul
                                v-if="searchFocused && search.trim().length > 0"
                                id="purchase-omnibox-list"
                                role="listbox"
                                class="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg"
                            >
                                <li
                                    v-for="(s, i) in suggestions"
                                    :key="s.key"
                                    role="option"
                                    :aria-selected="i === highlightIndex"
                                    class="cursor-pointer px-3 py-2 text-sm"
                                    :class="i === highlightIndex ? 'bg-teal-50' : 'hover:bg-slate-50'"
                                    @mouseenter="highlightIndex = i"
                                    @mousedown.prevent="applySuggestion(s)"
                                >
                                    <template v-if="s.type === 'product'">
                                        <div class="flex items-center gap-2">
                                            <Package class="h-4 w-4 shrink-0 text-teal-600" />
                                            <span class="font-medium text-slate-900">{{ s.product.name }}</span>
                                            <span class="ml-auto shrink-0 text-xs text-slate-500">
                                                stok {{ formatStock(s.product.stock_quantity) }} {{ s.product.unit || 'pcs' }}
                                            </span>
                                        </div>
                                        <div class="pl-6 text-xs text-slate-500">
                                            Barang ·
                                            {{ s.product.inventory_type === 'packaging' ? 'Kemasan' : 'Bahan mentah' }}
                                            <span v-if="s.product.sku"> · {{ s.product.sku }}</span>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="flex items-center gap-2">
                                            <Receipt class="h-4 w-4 shrink-0 text-amber-600" />
                                            <span class="font-medium text-slate-900">{{ s.name }}</span>
                                        </div>
                                        <div class="pl-6 text-xs text-slate-500">Biaya · masuk laporan pengeluaran</div>
                                    </template>
                                </li>

                                <li v-if="!exactMatch" class="border-t border-slate-100 mt-1 pt-1">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                                        @mousedown.prevent="addManualLine(search.trim())"
                                    >
                                        <PackagePlus class="h-4 w-4 shrink-0 text-teal-600" />
                                        <span>
                                            Barang baru «<strong>{{ search.trim() }}</strong>» — daftarkan &amp; tambah stok
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                                        @mousedown.prevent="addChargeLine(search.trim())"
                                    >
                                        <Receipt class="h-4 w-4 shrink-0 text-amber-600" />
                                        <span>
                                            Biaya «<strong>{{ search.trim() }}</strong>» — catat sebagai pengeluaran
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Daftar barang -->
                        <div v-if="lineDrafts.length > 0" class="overflow-x-auto rounded-xl border">
                            <table class="w-full text-sm min-w-[720px]">
                                <thead>
                                    <tr class="border-b bg-slate-50 text-left text-xs font-semibold text-slate-600">
                                        <th class="p-2 min-w-[220px]">Barang</th>
                                        <th class="p-2 min-w-[120px]">Varian</th>
                                        <th class="p-2 w-28 text-right">Qty</th>
                                        <th class="p-2 w-24">Satuan</th>
                                        <th class="p-2 w-44 text-right">Harga beli /u</th>
                                        <th v-if="batchTrackingEnabled" class="p-2 w-36">ED</th>
                                        <th v-if="batchTrackingEnabled" class="p-2 w-28">Kode batch</th>
                                        <th class="p-2 w-10" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in lineDrafts" :key="idx" class="border-b last:border-0 align-top">
                                        <td class="p-2">
                                            <div class="space-y-1.5">
                                                <div class="flex items-start gap-2">
                                                    <Package class="mt-0.5 h-4 w-4 shrink-0 text-teal-600" />
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-slate-900 break-words">{{ row.label }}</p>
                                                        <p v-if="row.kind === 'catalog'" class="text-xs text-slate-500">
                                                            Stok saat ini
                                                            {{ formatStock(productById(row.product_id)?.stock_quantity ?? 0) }}
                                                            {{ productById(row.product_id)?.unit || 'pcs' }}
                                                        </p>
                                                        <p v-else class="text-xs font-medium text-amber-700">
                                                            Barang baru — akan didaftarkan ke produk
                                                        </p>
                                                    </div>
                                                </div>
                                                <!-- Detail hanya untuk barang baru -->
                                                <div v-if="row.kind === 'manual'" class="space-y-1.5">
                                                    <div class="grid grid-cols-2 gap-1.5">
                                                        <select
                                                            v-model="row.new_product.inventory_type"
                                                            class="flex h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                                                            aria-label="Tipe inventory barang baru"
                                                        >
                                                            <option value="raw_material">Bahan mentah</option>
                                                            <option value="packaging">Kemasan</option>
                                                        </select>
                                                        <select
                                                            v-model="row.new_product.base_unit"
                                                            class="flex h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                                                            aria-label="Satuan dasar barang baru"
                                                            @change="onManualBaseUnitChange(row)"
                                                        >
                                                            <option value="pcs">Pcs</option>
                                                            <option value="gram">Gram</option>
                                                            <option value="ml">Mililiter</option>
                                                        </select>
                                                    </div>
                                                    <div class="grid grid-cols-2 gap-1.5">
                                                        <Input v-model="row.new_product.sku" placeholder="SKU (opsional)" class="h-8 text-xs" />
                                                        <Input v-model="row.new_product.barcode" placeholder="Barcode (opsional)" class="h-8 text-xs" />
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <select
                                                v-if="row.kind === 'catalog' && productById(row.product_id)?.has_variants"
                                                v-model="row.product_variant_id"
                                                class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                aria-label="Varian produk"
                                            >
                                                <option disabled value="">Pilih varian</option>
                                                <option v-for="v in variantsForProduct(row.product_id)" :key="v.id" :value="v.id">
                                                    {{ v.name }} (stok {{ formatStock(v.stock_quantity) }})
                                                </option>
                                            </select>
                                            <span v-else class="text-xs text-slate-400 leading-9 block">—</span>
                                        </td>
                                        <td class="p-2">
                                            <Input
                                                v-model.number="row.quantity"
                                                type="number"
                                                :min="lineQtyMin(row)"
                                                :step="lineQtyStep(row)"
                                                class="h-9"
                                                aria-label="Jumlah"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <select
                                                v-model="row.input_unit"
                                                class="flex h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                aria-label="Satuan beli"
                                            >
                                                <option v-for="u in unitOptions(row)" :key="u" :value="u">{{ u }}</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            <input
                                                type="text"
                                                inputmode="numeric"
                                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm text-right font-mono"
                                                :value="getCostDisplay(idx)"
                                                placeholder="0"
                                                aria-label="Harga beli per satuan"
                                                @input="onCostInput(idx, row)"
                                                @blur="() => syncCostDisplay(idx, row.unit_cost)"
                                            />
                                        </td>
                                        <td v-if="batchTrackingEnabled" class="p-2">
                                            <Input v-model="row.batch_expiry_date" type="date" class="h-9" />
                                        </td>
                                        <td v-if="batchTrackingEnabled" class="p-2">
                                            <Input v-model="row.batch_code" type="text" class="h-9" placeholder="—" />
                                        </td>
                                        <td class="p-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                class="h-9 w-9 p-0 text-destructive"
                                                aria-label="Hapus barang"
                                                @click="removeLine(idx)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Daftar biaya -->
                        <div v-if="chargeDrafts.length > 0" class="overflow-x-auto rounded-xl border border-amber-200">
                            <table class="w-full text-sm min-w-[560px]">
                                <thead>
                                    <tr class="border-b bg-amber-50 text-left text-xs font-semibold text-amber-900">
                                        <th class="p-2 min-w-[200px]">Biaya (masuk laporan pengeluaran)</th>
                                        <th class="p-2 w-44 text-right">Nominal</th>
                                        <th class="p-2 min-w-[160px]">Keterangan</th>
                                        <th class="p-2 w-10" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(c, ci) in chargeDrafts" :key="`charge-${ci}`" class="border-b last:border-0">
                                        <td class="p-2">
                                            <div class="flex items-center gap-2">
                                                <Receipt class="h-4 w-4 shrink-0 text-amber-600" />
                                                <Input v-model="c.name" class="h-9" placeholder="Nama biaya" aria-label="Nama biaya" />
                                            </div>
                                        </td>
                                        <td class="p-2">
                                            <input
                                                type="text"
                                                inputmode="numeric"
                                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm text-right font-mono"
                                                :value="getChargeDisplay(ci)"
                                                placeholder="0"
                                                aria-label="Nominal biaya"
                                                @input="onChargeAmountInput(ci, c)"
                                                @blur="() => syncChargeDisplay(ci, c.amount)"
                                            />
                                        </td>
                                        <td class="p-2">
                                            <Input v-model="c.notes" class="h-9" placeholder="Opsional" aria-label="Keterangan biaya" />
                                        </td>
                                        <td class="p-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                class="h-9 w-9 p-0 text-destructive"
                                                aria-label="Hapus biaya"
                                                @click="removeCharge(ci)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p
                            v-if="lineDrafts.length === 0 && chargeDrafts.length === 0"
                            class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 py-8 text-center text-sm text-slate-500"
                        >
                            Belum ada isi. Ketik di kotak pencarian di atas untuk menambah barang atau biaya.
                        </p>

                        <!-- Ringkasan -->
                        <div v-if="lineDrafts.length > 0 || chargeDrafts.length > 0" class="rounded-xl border bg-slate-50/60 p-3 text-sm space-y-1">
                            <div class="flex justify-between">
                                <span class="text-slate-600">Subtotal barang (DPP)</span>
                                <span class="font-mono tabular-nums">{{ formatIDR(goodsSubtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-600">Total biaya (pengeluaran)</span>
                                <span class="font-mono tabular-nums text-amber-700">{{ formatIDR(chargesSubtotal) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-600">PPN Masukan</span>
                                <span class="font-mono tabular-nums">{{ formatIDR(Number(form.ppn_amount) || 0) }}</span>
                            </div>
                            <div class="flex justify-between border-t pt-1 font-bold text-slate-900">
                                <span>Total dibayar</span>
                                <span class="font-mono tabular-nums">{{ formatIDR(grandTotal) }}</span>
                            </div>
                        </div>

                        <p v-if="(form.errors as Record<string, string>)['lines']" class="text-sm text-red-600">{{ (form.errors as Record<string, string>)['lines'] }}</p>
                        <template v-for="(err, key) in form.errors" :key="`charge-err-${String(key)}`">
                            <p v-if="String(key).startsWith('charges')" class="text-sm text-red-600">{{ err }}</p>
                        </template>
                        <template v-for="(err, key) in form.errors" :key="String(key)">
                            <p v-if="String(key).startsWith('lines.')" class="text-sm text-red-600">{{ err }}</p>
                        </template>

                        <div class="flex gap-3">
                            <Button type="submit" class="rounded-xl flex-1" :disabled="form.processing">
                                {{ form.processing ? 'Menyimpan…' : 'Simpan pembelian' }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </form>
        </div>
    </AdminLayout>
</template>
