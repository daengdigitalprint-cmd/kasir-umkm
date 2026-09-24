<script setup lang="ts">
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import PinLoginModal from '@/components/CashierPin/PinLoginModal.vue';
import ThermalReceiptStruk from '@/components/pos/ThermalReceiptStruk.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { queueOfflineSale, setOfflineCashierId, useOfflineSync } from '@/composables/useOfflineSync';
import { useAllowsAdvancedRetail } from '@/composables/usePlanLimits';
import { usePrinter } from '@/composables/usePrinter';
import { useProductCache } from '@/composables/useProductCache';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { imageUrlToEscPosRaster, printableDotsFor, receiptLinesToEscPos } from '@/lib/escPosEncode';
import { printKitchenTicket } from '@/lib/kitchenTicketPrint';
import { printCupLabels } from '@/lib/cupLabelPrint';
import { getCachedProducts } from '@/lib/offlineDb';
import { buildKotPlainLines, buildReceiptPlainLinesFromPosResponse } from '@/lib/receiptPlainText';
import { confirmDialog, modalError, popupSuccessCenter, toastError, toastInfo, toastSuccess, toastWarning } from '@/lib/swal';
import { mergeReceiptConfig, printThermalReceipt, type ReceiptConfig } from '@/lib/thermalReceipt';
import { dashboard } from '@/routes';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    Banknote,
    Bell,
    CheckCircle2,
    ChefHat,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Circle,
    CircleDollarSign,
    Clock,
    Flame,
    Loader2,
    MessageCircle,
    Minus,
    Package,
    Plus,
    Printer,
    ReceiptText,
    Save,
    ScanLine,
    Search,
    ShoppingBag,
    ShoppingCart,
    Smartphone,
    Table2,
    Tag,
    Trash2,
    WifiOff,
    X,
} from 'lucide-vue-next';
import Swal from 'sweetalert2';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

interface ProductModifierOption {
    key: string;
    label: string;
    price: number;
}

interface ProductVariant {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: number | string | null;
    wholesale_price?: number | string | null;
    stock_quantity: number;
}

interface ProductBundleItemRow {
    id: number;
    quantity: string | number;
    component_product_id: number;
    component_product_variant_id: number | null;
    component_product?: {
        id: number;
        name: string;
        sku?: string;
        stock_quantity: number;
        allows_decimal?: boolean;
        track_stock?: boolean;
        has_variants?: boolean;
        unlimited_stock?: boolean;
    };
    component_variant?: { id: number; name: string; stock_quantity: number } | null;
}

interface SaleConsumption {
    id: number;
    product_variant_id: number | null;
    component_product_id: number;
    quantity: number;
    base_unit: string | null;
    sort_order: number;
    component?: {
        id: number;
        name: string;
        stock_quantity: number;
        track_stock: boolean;
        unlimited_stock: boolean;
        inventory_type: string;
        base_unit?: string | null;
    } | null;
}

interface Product {
    id: number;
    name: string;
    price: number;
    wholesale_price?: number | string | null;
    stock_quantity: number;
    sku: string;
    barcode: string;
    category?: string | null;
    product_category_id?: number | null;
    inventory_type?: string | null;
    unit?: string | null;
    allows_decimal?: boolean;
    has_variants?: boolean;
    is_bundle?: boolean;
    track_stock?: boolean;
    unlimited_stock?: boolean;
    allow_negative_stock?: boolean;
    bundle_items?: ProductBundleItemRow[];
    variants?: ProductVariant[];
    sale_consumptions?: SaleConsumption[];
    image_url?: string;
    modifier_options?: ProductModifierOption[] | null;
    pharmacy_sale_class?: string | null;
}

function stockNum(p: Product): number {
    return Number(p.stock_quantity);
}

function qtyStep(p: Product): number {
    return p.allows_decimal ? 0.25 : 1;
}

function roundCartQty(q: number, p: Product): number {
    if (!p.allows_decimal) {
        return Math.round(q);
    }
    return Math.round(q * 10000) / 10000;
}

function lteStock(qty: number, stock: number, decimal: boolean): boolean {
    const eps = decimal ? 1e-7 : 0;
    return qty <= stock + eps;
}

function formatCartQty(q: number, p: Product): string {
    if (!p.allows_decimal) {
        return String(Math.round(q));
    }
    const r = Math.round(q * 10000) / 10000;
    return String(r);
}

/** Badge stok: teks + warna + level. */
function stockDisplay(product: Product, variant: ProductVariant | null): { text: string; colorClass: string } {
    const stock = lineStock(product, variant);

    // Unlimited
    if (product.unlimited_stock) {
        return { text: 'Stok: Unlimited', colorClass: 'bg-emerald-100 text-emerald-700 border-emerald-200' };
    }

    // Produk tanpa track_stock (berbasis komponen)
    if (!product.track_stock && !product.is_bundle) {
        const rows = variant
            ? (product.sale_consumptions ?? []).filter((r) => r.product_variant_id === null || r.product_variant_id === variant.id)
            : (product.sale_consumptions ?? []).filter((r) => r.product_variant_id === null);
        const hasComponents = rows.some((r) => r.component && r.component.track_stock && !r.component.unlimited_stock);

        if (hasComponents) {
            if (stock <= 0) {
                return { text: 'Stok: Habis', colorClass: 'bg-red-100 text-red-700 border-red-200' };
            }
            if (stock <= 5) {
                return { text: `Sisa ${stock} porsi`, colorClass: 'bg-red-100 text-red-700 border-red-200' };
            }
            if (stock <= 20) {
                return { text: `Sisa ${stock} porsi`, colorClass: 'bg-amber-100 text-amber-700 border-amber-200' };
            }
            return { text: `Sisa ${stock} porsi`, colorClass: 'bg-emerald-100 text-emerald-700 border-emerald-200' };
        }
        return { text: 'Belum ada resep', colorClass: 'bg-slate-100 text-slate-500 border-slate-200' };
    }

    // Bundle
    if (product.is_bundle) {
        return {
            text: `Stok: ${formatCartQty(stock, product)}`,
            colorClass: stock > 0 ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-red-100 text-red-700 border-red-200',
        };
    }

    // Track stock biasa
    if (stock <= 0) {
        return { text: 'Stok: Habis', colorClass: 'bg-red-100 text-red-700 border-red-200' };
    }
    if (stock <= 5) {
        return {
            text: `Stok: ${formatCartQty(stock, product)}${product.unit ? ' ' + product.unit : ''}`,
            colorClass: 'bg-red-100 text-red-700 border-red-200',
        };
    }
    if (stock <= 20) {
        return {
            text: `Stok: ${formatCartQty(stock, product)}${product.unit ? ' ' + product.unit : ''}`,
            colorClass: 'bg-amber-100 text-amber-700 border-amber-200',
        };
    }
    return {
        text: `Stok: ${formatCartQty(stock, product)}${product.unit ? ' ' + product.unit : ''}`,
        colorClass: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    };
}

/** Bagi total rupiah ke N bagian (sen bulat; jumlah = total). */
function splitTotalIntoRupiahShares(total: number, parts: number): number[] {
    if (parts < 2 || !Number.isFinite(total)) {
        return [];
    }
    const cents = Math.round(total * 100);
    const base = Math.floor(cents / parts);
    const rem = cents % parts;
    const out: number[] = [];
    for (let i = 0; i < parts; i++) {
        out.push((base + (i < rem ? 1 : 0)) / 100);
    }
    return out;
}

interface ActiveDiscount {
    id: number;
    name: string;
    code: string | null;
    type: string;
    value: string;
    buy_x?: number | null;
    get_y?: number | null;
    minimum_amount: string | null;
    applicable_products: number[] | null;
    applicable_categories: string[] | null;
}

interface CartItem {
    lineId: string;
    product: Product;
    variant: ProductVariant | null;
    quantity: number;
    subtotal: number;
    selectedModifiers: ProductModifierOption[];
}

interface PreOrderHydrationLine {
    product_id: number;
    product_variant_id: number | null;
    quantity: number;
    modifiers?: unknown;
}

interface PreOrderHydration {
    id: number;
    order_number: string;
    /** DP yang tercatat di pre-order; dipotong saat pelunasan di kasir. */
    deposit_received?: number;
    lines: PreOrderHydrationLine[];
}

interface CashShiftOpen {
    id: number;
    shift_number: string;
    opened_at: string | null;
    opening_cash: number;
    expected_cash: number;
    expected_non_cash: number;
}

interface ProductCategory {
    id: number;
    name: string;
    sort_order: number;
}

interface Props {
    products: Product[];
    popularProductIds: number[];
    categories: ProductCategory[];
    store: {
        id: number;
        name: string;
        currency: string;
        tax_enabled: boolean;
        tax_rate: number;
        tax_name: string;
        business_type?: string;
        fnb_enabled?: boolean;
        fnb_order_types_enabled?: boolean;
        fnb_split_bill_enabled?: boolean;
        open_bill_enabled?: boolean;
        pharmacy_enabled?: boolean;
        default_service_charge_percent?: number;
        receipt?: ReceiptConfig;
        pos_show_barcode?: boolean;
        wholesale_enabled?: boolean;
        online_sales_enabled?: boolean;
        online_commission_gofood?: number;
        online_commission_grabfood?: number;
        online_commission_shopeefood?: number;
    };
    activeDiscounts?: ActiveDiscount[];
    businessTypeLabel?: string;
    preOrderHydration?: PreOrderHydration | null;
    cashShiftOpen?: CashShiftOpen | null;
    cashier_login_mode?: string;
    active_cashier?: { employee_id: number; employee_name: string; logged_in_at?: string } | null;
    cashiers?: Array<{ id: number; name: string; has_pin: boolean }>;
    accountingPeriodLock?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    popularProductIds: () => [],
    categories: () => [],
    activeDiscounts: () => [],
    businessTypeLabel: 'Umum',
    preOrderHydration: null,
    cashShiftOpen: null,
    accountingPeriodLock: null,
});

const allowsAdvancedRetail = useAllowsAdvancedRetail();
const page = usePage();
const scanMejaEnabled = computed(() => Boolean((page.props as { scan_meja_enabled?: boolean }).scan_meja_enabled));
const receivablesEnabled = computed(() => Boolean((page.props as { receivables_enabled?: boolean }).receivables_enabled));
/** Modul Open Bill: aktif untuk F&B atau toko yang mengaktifkannya di pengaturan. */
const openBillEnabled = computed(() => Boolean(props.store?.open_bill_enabled));
const pendingTableOrderCount = computed(() => Number((page.props as { pending_table_order_count?: number }).pending_table_order_count ?? 0));
/** Local reactive copy — diinisialisasi dari prop, bisa di-update via refreshCashShift(). */
const cashShiftOpen = ref(props.cashShiftOpen ?? null);

// PIN Login integration
const cashierLoginMode = computed(() => props.cashier_login_mode ?? 'email');
const activeCashier = computed(() => props.active_cashier ?? null);
const cashierList = computed(() => props.cashiers ?? []);

const showPinLogin = computed(() => {
    if (cashierLoginMode.value !== 'pin') return false;
    if (activeCashier.value) return false;
    return true;
});

function onPinLoginSuccess() {
    router.reload({
        only: ['active_cashier', 'cashiers', 'cashShiftOpen'],
        onFinish: () => {
            // Sync ref lokal dari props yang baru di-reload
            cashShiftOpen.value = props.cashShiftOpen ?? null;
            // Update cashierId untuk transaksi offline sesuai kasir yang baru login.
            setOfflineCashierId(activeCashier.value?.employee_id ?? 0);
            void requireOpenCashShift();
        },
    });
}

function onPinLoginClose() {
    // User batal login kasir → kembali ke dashboard.
    router.visit(dashboard());
}

async function requireOpenCashShift(): Promise<boolean> {
    if (!allowsAdvancedRetail.value || cashShiftOpen.value) {
        return true;
    }

    // Skip saat PIN login modal masih aktif
    if (showPinLogin.value) {
        return false;
    }

    const shouldOpenShift = await confirmDialog('Shift kasir belum dibuka', 'Silakan buka shift kasir terlebih dahulu sebelum melakukan transaksi.', {
        confirmText: 'Buka Kasir',
        cancelText: 'Nanti',
        icon: 'warning',
    });
    if (shouldOpenShift) {
        router.visit('/cash-shifts');
    }

    return false;
}

async function requireOpenAccountingPeriod(): Promise<boolean> {
    if (!props.accountingPeriodLock) {
        return true;
    }
    await modalError('Periode akuntansi tertutup', props.accountingPeriodLock);
    return false;
}

onMounted(() => {
    void requireOpenCashShift();
});

// ─── Cash Shift Close ───
/** Navigasi langsung ke /cash-shifts di semua mode (landscape & potret). */
function closeShiftHandler() {
    router.visit('/cash-shifts', { replace: true });
}

// ─── Offline mode
const { isOnline, pendingCount, isSyncing, syncNow, refreshPendingCount } = useOfflineSync({
    storeId: props.store?.id ?? null,
    cashierId: activeCashier.value?.employee_id ?? 0,
});

// ─── Auto-refresh XSRF-TOKEN cookie setiap 30 menit untuk mencegah token kadaluarsa
// Menggunakan HEAD request ke root (/) — route web selalu ada dan secara otomatis
// mengirim ulang XSRF-TOKEN cookie (tanpa Sanctum).
let csrfRefreshInterval: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    csrfRefreshInterval = setInterval(
        () => {
            fetch('/', { method: 'HEAD', credentials: 'same-origin' })
                .then(() => {
                    if (import.meta.env.DEV) console.log('XSRF cookie refreshed');
                })
                .catch((e) => {
                    if (import.meta.env.DEV) console.warn('Failed to refresh XSRF cookie', e);
                });
        },
        30 * 60 * 1000,
    ); // 30 menit
});

onUnmounted(() => {
    if (csrfRefreshInterval) {
        clearInterval(csrfRefreshInterval);
    }
});

// ─── Printer Bluetooth (terpusat: Android SPP + browser BLE fallback)
const {
    isAvailable: canPrintBluetooth,
    isConnected: isPrinterConnected,
    isPrinting: isPrinterPrinting,
    initialize: initializePrinter,
    printEscPosBytes,
    kickDrawer,
} = usePrinter();

const localProducts = ref<any[]>(props.products || []);
const { syncCatalog } = useProductCache();

// Sinkronisasi produk: gunakan data fresh dari server saat online,
// cache IndexedDB hanya sebagai fallback offline.
onMounted(async () => {
    void initializePrinter();

    // Muat jumlah open bills untuk badge topbar (hanya toko F&B)
    if (props.store?.fnb_enabled && isOnline.value) {
        void fetchOpenBills();
    }

    if (isOnline.value) {
        // Online: sync cache di background untuk mode offline nanti
        syncCatalog(props.store?.id).catch(() => {});

        // Tapi tampilkan data fresh dari server (props.products)
        // karena props punya field terbaru (unlimited_stock, dll)
        if (props.products && props.products.length > 0) {
            localProducts.value = props.products;
        }
    } else {
        // Offline: gunakan cache IndexedDB
        const cached = await getCachedProducts(props.store?.id);
        if (cached && cached.length > 0) {
            localProducts.value = cached;
        } else if (props.products && props.products.length > 0) {
            localProducts.value = props.products;
        }
    }
});

const cart = ref<CartItem[]>([]);
/** Keranjang sebagai bottom sheet di layar mobile (di desktop selalu tampil sebagai sidebar). */
const cartSheetOpen = ref(false);
/**
 * Alur checkout multi-step sebagai popup modal.
 * - false: modal checkout tertutup (tampil keranjang).
 * - true: modal checkout terbuka, gunakan `paymentStep` untuk step aktif.
 */
const checkoutOpen = ref(false);
/** Step aktif di dalam modal checkout: 'method' → 'pay'. */
const paymentStep = ref<'method' | 'pay'>('method');
const activePreOrderId = ref<number | null>(null);
const activePreOrderNumber = ref<string | null>(null);
/** Nilai DP dari pre-order (raw); dipakai hitung sisa bayar. */
const preOrderDepositReceived = ref(0);
const searchQuery = ref('');
// Versi debounced untuk filter grid — menghindari recompute + re-render tiap keystroke.
const searchQueryDebounced = ref('');
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, (v) => {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        searchQueryDebounced.value = v;
        searchDebounceTimer = null;
    }, 200);
});
/** Paksa nilai debounced menyamai input seketika (dipakai sebelum handleSearchEnter). */
function flushSearchDebounce() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }
    searchQueryDebounced.value = searchQuery.value;
}

/** Open Bills (Order Hold) state */
const openBillsDrawerOpen = ref(false);
const openBills = ref<OpenBill[]>([]);
const openBillsLoading = ref(false);
const openBillsSearch = ref('');
const openBillsSelectedId = ref<number | null>(null);
interface OpenBill {
    id: number;
    transaction_number: string;
    table_reference: string | null;
    customer_name: string | null;
    order_type: string | null;
    items_count: number;
    total_amount: number;
    created_at: string;
    elapsed_minutes: number;
}
interface SaveOrderForm {
    items: Array<{
        product_id: number;
        product_variant_id: number | null;
        quantity: number;
        unit_price: number;
        modifiers: any[];
    }>;
    order_type: 'dine_in' | 'takeaway' | 'delivery' | '';
    table_reference: string;
    customer_name: string;
    notes: string;
    print_kot: boolean;
}
const saveOrderDrawerOpen = ref(false);
const saveOrderForm = ref<SaveOrderForm>({
    items: [],
    order_type: '',
    table_reference: '',
    customer_name: '',
    notes: '',
    print_kot: true,
});
const saveOrderLoading = ref(false);

onUnmounted(() => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }
});
const activeCategoryId = ref<number | null>(null);
const categoryScrollRef = ref<HTMLDivElement | null>(null);
const categoryCanScrollLeft = ref(false);
const categoryCanScrollRight = ref(false);

function checkCategoryScroll() {
    const el = categoryScrollRef.value;
    if (!el) return;
    categoryCanScrollLeft.value = el.scrollLeft > 2;
    categoryCanScrollRight.value = el.scrollLeft < el.scrollWidth - el.clientWidth - 2;
}
function scrollCategory(dir: 'left' | 'right') {
    const el = categoryScrollRef.value;
    if (!el) return;
    el.scrollBy({ left: dir === 'left' ? -200 : 200, behavior: 'smooth' });
    setTimeout(checkCategoryScroll, 350);
}

/** Jumlah kartu produk per halaman di grid POS — responsif terhadap viewport.
 *  lg (≥1024px, 4 kolom): 12 = 3×4 rata. 2xl (≥1536px, 5 kolom): 15 = 3×5 rata.
 *  md (768–1023px, 3 kolom): 9 = 3×3 rata. */
const isXlViewport = ref(false);
const is2XlViewport = ref(false);
const productsPerPage = computed(() => {
    if (is2XlViewport.value) return 15;
    if (isXlViewport.value) return 12;
    return 9;
});
let _xlMql: MediaQueryList | null = null;
let _2xlMql: MediaQueryList | null = null;
const _onXlChange = (e: MediaQueryListEvent) => {
    isXlViewport.value = e.matches;
    productGridPage.value = 1;
};
const _on2XlChange = (e: MediaQueryListEvent) => {
    is2XlViewport.value = e.matches;
    productGridPage.value = 1;
};
onMounted(() => {
    _xlMql = window.matchMedia('(min-width: 1024px)');
    isXlViewport.value = _xlMql.matches;
    _xlMql.addEventListener('change', _onXlChange);
    _2xlMql = window.matchMedia('(min-width: 1536px)');
    is2XlViewport.value = _2xlMql.matches;
    _2xlMql.addEventListener('change', _on2XlChange);
});
onUnmounted(() => {
    if (_xlMql) _xlMql.removeEventListener('change', _onXlChange);
    if (_2xlMql) _2xlMql.removeEventListener('change', _on2XlChange);
});
const productGridPage = ref(1);
const paymentMethod = ref<string | null>(null);
const amountPaid = ref(0);
const customerName = ref('');
const customerType = ref<'ecer' | 'grosir'>('ecer');

// ─── State kredit / piutang ───────────────────────────────────────────────
const isCredit = ref(false);
/** 'partial' = bayar sebagian, 'unpaid' = bayar nanti (selalu menghasilkan piutang) */
const settlementMode = ref<'partial' | 'unpaid'>('unpaid');
const creditCustomerId = ref<number | null>(null);
const creditCustomerName = ref('');
const creditCustomerSearch = ref('');
const creditCustomerResults = ref<Array<{ id: number; name: string; phone?: string }>>([]);
const creditCustomerLoading = ref(false);
const debtDueAt = ref('');

/**
 * Apakah metode yang dipilih memerlukan input nominal tunai (step 3)?
 * - Metode tunai non-kredit: input jumlah bayar di step 3.
 * - Kredit partial: input jumlah bayar parsial di step 3.
 * - Kredit unpaid & metode non-tunai: langsung proses pada step 2.
 */
const requiresCashInput = computed(
    () => (!isCredit.value && paymentMethod.value === 'cash') || (isCredit.value && settlementMode.value === 'partial'),
);

/**
 * Dari step "method", lanjut ke step "pay" bila tunai, atau langsung proses
 * pembayaran untuk metode non-tunai / kredit.
 */
function confirmPaymentMethod() {
    if (!paymentMethod.value) return;
    if (requiresCashInput.value) {
        paymentStep.value = 'pay';
        // Isi otomatis nominal pas saat pertama kali masuk step tunai.
        if (!amountPaid.value || amountPaid.value <= 0) {
            amountPaid.value = balanceDue.value;
        }
        return;
    }
    // Non-tunai / kredit: langsung proses tanpa step input nominal.
    processPayment();
}

/**
 * Buka popup modal checkout — mulai dari step metode pembayaran.
 */
function openCheckout() {
    if (cart.length === 0) return;
    checkoutOpen.value = true;
    paymentStep.value = 'method';
    paymentMethod.value = null;
    amountPaid.value = 0;
    cartSheetOpen.value = false;
}

/**
 * Tutup popup modal checkout dan kembali ke tampilan keranjang.
 */
function closeCheckout() {
    checkoutOpen.value = false;
    paymentStep.value = 'method';
}

// ─── State pendaftaran pelanggan baru dari POS ───────────────────────────
const creditCustomerCreating = ref(false);
const newCustomerName = ref('');
const newCustomerPhone = ref('');
const newCustomerCreating = ref(false);

/** Kembali ke mode lunas normal */
function resetCreditMode() {
    isCredit.value = false;
    settlementMode.value = 'unpaid';
    creditCustomerId.value = null;
    creditCustomerName.value = '';
    creditCustomerSearch.value = '';
    creditCustomerResults.value = [];
    debtDueAt.value = '';
    creditCustomerCreating.value = false;
    newCustomerName.value = '';
    newCustomerPhone.value = '';
    newCustomerCreating.value = false;
}

let _customerSearchTimer: ReturnType<typeof setTimeout> | null = null;
async function searchCreditCustomers(q: string) {
    if (_customerSearchTimer) clearTimeout(_customerSearchTimer);
    if (!q || q.length < 2) {
        creditCustomerResults.value = [];
        return;
    }
    _customerSearchTimer = setTimeout(async () => {
        creditCustomerLoading.value = true;
        try {
            const res = await fetch(`/customers/search?q=${encodeURIComponent(q)}&store_id=${props.store?.id ?? ''}`);
            if (res.ok) {
                const data = await res.json();
                creditCustomerResults.value = data.customers ?? data ?? [];
            }
        } catch {
            /* abaikan */
        } finally {
            creditCustomerLoading.value = false;
        }
    }, 300);
}

function selectCreditCustomer(c: { id: number; name: string; phone?: string }) {
    creditCustomerId.value = c.id;
    creditCustomerName.value = c.name;
    creditCustomerSearch.value = c.name;
    creditCustomerResults.value = [];
}

function closeCreditDropdown() {
    setTimeout(() => {
        creditCustomerResults.value = [];
    }, 200);
}

/** Buka form pendaftaran pelanggan baru, pre-fill nama dari pencarian */
function openNewCustomerForm() {
    newCustomerName.value = creditCustomerSearch.value.trim();
    newCustomerPhone.value = '';
    creditCustomerCreating.value = true;
    creditCustomerResults.value = [];
}

/** Daftarkan pelanggan baru via quick-store API lalu auto-select */
async function quickCreateCustomer() {
    const name = newCustomerName.value.trim();
    if (!name) return;
    newCustomerCreating.value = true;
    try {
        const res = await fetch('/customers/quick-store', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfTokenPos(),
                Accept: 'application/json',
            },
            body: JSON.stringify({
                name: name,
                phone: newCustomerPhone.value.trim() || null,
            }),
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({ message: 'Gagal mendaftarkan pelanggan.' }));
            throw new Error(err.message ?? 'Gagal mendaftarkan pelanggan.');
        }
        const data = await res.json();
        selectCreditCustomer(data.customer);
        creditCustomerCreating.value = false;
        newCustomerName.value = '';
        newCustomerPhone.value = '';
        toastSuccess('Pelanggan baru berhasil didaftarkan.', 3000);
    } catch (e: any) {
        await modalError('Gagal Mendaftarkan', e.message ?? 'Tidak dapat mendaftarkan pelanggan baru.');
    } finally {
        newCustomerCreating.value = false;
    }
}

// Saat mode kredit, amountPaid boleh 0 (bayar nanti) atau parsial
watch(settlementMode, (mode) => {
    if (mode === 'unpaid') {
        amountPaid.value = 0;
    }
    // 'partial' dibiarkan diisi manual
});

watch(isCredit, (val) => {
    if (!val) {
        resetCreditMode();
        amountPaid.value = balanceDue.value;
        paymentMethod.value = 'cash';
    } else {
        paymentMethod.value = 'credit';
        settlementMode.value = 'unpaid';
        amountPaid.value = 0;
    }
});

/** Apakah tombol Proses Pembayaran boleh ditekan saat kredit */
const creditReadyToProcess = computed(() => {
    if (!isCredit.value) return true;
    // Pelanggan belum dipilih BUKAN alasan untuk men-disable tombol:
    // kasir tetap bisa menekan tombol untuk dituntun memilih/mendaftarkan pelanggan.
    if (settlementMode.value === 'partial' && amountPaid.value <= 0) return false;
    return true;
});

/** Komentar: setter default tanggal jatuh tempo H+7 */
watch(
    isCredit,
    (val) => {
        if (val && !debtDueAt.value) {
            const d = new Date();
            d.setDate(d.getDate() + 7);
            debtDueAt.value = d.toISOString().slice(0, 10);
        }
    },
    { immediate: false },
);

/** Rujukan resep / catatan penjualan (Fase E — apotek). */
const pharmacySaleReference = ref('');
const prescription = ref({
    reference: '',
    number: '',
    patient_name: '',
    doctor_name: '',
    issued_at: '',
});
const isProcessingPayment = ref(false);
const discountCode = ref('');
const discountError = ref('');
const discountOpen = ref(false);
const appliedDiscount = ref<ActiveDiscount | null>(null);
const promoListOpen = ref(false);
const promoCoupons = computed(() => props.activeDiscounts.filter((d) => d.code && d.code.trim() !== ''));
const selectPromo = (d: ActiveDiscount) => {
    discountCode.value = d.code ?? '';
    promoListOpen.value = false;
    applyDiscountCode();
};

const modifierPickerOpen = ref(false);
const modifierPickerProduct = ref<Product | null>(null);
const modifierPickerSelectedKeys = ref<string[]>([]);
const pendingVariantForModifier = ref<ProductVariant | null>(null);

const variantPickerOpen = ref(false);
const variantPickerProduct = ref<Product | null>(null);

const serviceChargePercent = ref(0);
const tipAmount = ref(0);
const orderType = ref<'dine_in' | 'takeaway' | 'delivery'>('dine_in');
const tableReference = ref('');
/** Sumber pesanan: dari mana order datang (walk_in, gofood, dll). */
const salesChannel = ref<'walk_in' | 'dine_in' | 'takeaway' | 'delivery' | 'gofood' | 'grabfood' | 'shopeefood'>('walk_in');
/** Komisi pesanan online (otomatis terisi saat pilih gofood/grabfood/shopeefood, bisa edit). */
const commissionPercent = ref(0);
/** Daftar sumber pesanan untuk dropdown, dikelompokkan Penjualan Langsung / Pesanan Online.
 *  Pesanan Online (aggregator) hanya tampil jika modul online aktif di pengaturan toko. */
const channelOptions = computed(() => {
    const onlineEnabled = props.store?.online_sales_enabled === true;
    const langsung = [
        { value: 'walk_in', label: 'Walk In' },
        { value: 'takeaway', label: 'Take Away' },
        { value: 'delivery', label: 'Delivery' },
    ];
    const online = [
        { value: 'gofood', label: 'GoFood' },
        { value: 'grabfood', label: 'GrabFood' },
        { value: 'shopeefood', label: 'ShopeeFood' },
    ];
    return [{ group: 'Direct', items: langsung }, ...(onlineEnabled ? [{ group: 'Online', items: online }] : [])];
});
/** Komisi default per aggregator — diambil dari pengaturan toko, fallback ke nilai bawaan. */
const defaultCommissionByChannel = computed<Record<string, number>>(() => ({
    gofood: props.store?.online_commission_gofood ?? 20,
    grabfood: props.store?.online_commission_grabfood ?? 20,
    shopeefood: props.store?.online_commission_shopeefood ?? 15,
}));
/** Update komisi otomatis saat channel diganti. */
function onSalesChannelChange() {
    const ch = salesChannel.value;
    commissionPercent.value = ch in defaultCommissionByChannel.value ? defaultCommissionByChannel.value[ch] : 0;
}
/** Bagi total ke N bagian (merata, rupiah) — F&B. 1 = tidak dibagi. */
const splitBillCount = ref(1);

// Invoice popup state
const showInvoicePopup = ref(false);
const transactionData = ref<any>(null);

const filteredProducts = computed(() => {
    let result = localProducts.value;

    // Filter by category
    if (activeCategoryId.value === -1) {
        // Populer: urutkan berdasarkan frekuensi transaksi
        result = [...result].sort((a, b) => {
            const ai = props.popularProductIds.indexOf(a.id);
            const bi = props.popularProductIds.indexOf(b.id);
            const aRank = ai === -1 ? 999 : ai;
            const bRank = bi === -1 ? 999 : bi;
            return aRank - bRank;
        });
    } else if (activeCategoryId.value !== null) {
        result = result.filter((p) => p.product_category_id === activeCategoryId.value);
    }

    // Filter by search query
    if (!searchQueryDebounced.value) return result;

    const query = searchQueryDebounced.value.toLowerCase();
    return result.filter((product) => {
        if (
            product.name.toLowerCase().includes(query) ||
            (product.sku && product.sku.toLowerCase().includes(query)) ||
            (product.barcode && product.barcode.includes(query))
        ) {
            return true;
        }
        const vars = product.variants ?? [];
        return vars.some(
            (v) => v.name.toLowerCase().includes(query) || (v.sku && v.sku.toLowerCase().includes(query)) || (v.barcode && v.barcode.includes(query)),
        );
    });
});

const productGridTotalPages = computed(() => {
    const n = filteredProducts.value.length;
    if (n === 0) {
        return 1;
    }
    return Math.ceil(n / productsPerPage.value);
});

const productGridPageSafe = computed(() => Math.min(Math.max(1, productGridPage.value), productGridTotalPages.value));

const paginatedProducts = computed(() => {
    const list = filteredProducts.value;
    const page = productGridPageSafe.value;
    const start = (page - 1) * productsPerPage.value;
    return list.slice(start, start + productsPerPage.value);
});

// Cache info stok per produk di halaman aktif: stockDisplay+lineStock dihitung
// sekali per produk saat halaman/filter berubah, bukan tiap re-render (mis. saat
// cart berubah). Menghindari loop sale_consumptions/bundle_items berulang.
const productStockInfo = computed(() => {
    const map = new Map<number, { text: string; colorClass: string; disabled: boolean }>();
    for (const product of paginatedProducts.value) {
        const sd = stockDisplay(product, null);
        map.set(product.id, {
            text: sd.text,
            colorClass: sd.colorClass,
            disabled: lineStock(product, null) <= 0 && !product.allow_negative_stock,
        });
    }
    return map;
});

function stockInfo(product: Product): { text: string; colorClass: string; disabled: boolean } {
    return (
        productStockInfo.value.get(product.id) ?? {
            text: '',
            colorClass: '',
            disabled: false,
        }
    );
}

watch(searchQueryDebounced, () => {
    productGridPage.value = 1;
});

// --- Barcode scanner (POS) ---
const posBarcodeScanError = ref<string | null>(null);
const posBarcodeLookupLoading = ref(false);
const showBarcodeScannerPosCamera = ref(false);

/** Baca XSRF-TOKEN dari cookie, fallback ke meta tag CSRF. */
function csrfTokenPos(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) return decodeURIComponent(match[1]);
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

/** Tampilkan kegagalan scan sebagai toast sekaligus banner inline. */
function reportPosScanFailure(msg: string) {
    posBarcodeScanError.value = msg;
    toastWarning(msg);
}

/** Buka/tutup kamera POS, sekaligus bersihkan pesan scan sebelumnya. */
function togglePosBarcodeCamera() {
    posBarcodeScanError.value = null;
    showBarcodeScannerPosCamera.value = !showBarcodeScannerPosCamera.value;
}

/**
 * Tambahkan hasil scan ke keranjang, dengan alasan eksplisit bila ditolak.
 * Mengembalikan true jika produk masuk keranjang atau membuka picker varian.
 */
function addScannedToCart(product: Product, variant: ProductVariant | null): boolean {
    if (product.inventory_type === 'production_output') {
        reportPosScanFailure(`"${product.name}" adalah bahan produksi dan tidak bisa dijual di kasir.`);
        return false;
    }
    if (variant) {
        if (lineStock(product, variant) <= 0) {
            reportPosScanFailure(`Stok "${product.name} - ${variant.name}" habis.`);
            return false;
        }
        proceedToModifiersOrAdd(product, variant);
        return true;
    }

    const reason = addToCartBlockReason(product);
    if (reason) {
        reportPosScanFailure(reason);
        return false;
    }
    requestAddToCart(product);
    return true;
}

/**
 * Handler utama scan barcode di POS.
 * Didahulukan cari di props.products lokal (latensi nol),
 * fallback ke API jika tidak ketemu (mis. produk ditambah setelah halaman dibuka).
 */
async function handlePosBarcodeScan(code: string) {
    if (posBarcodeLookupLoading.value) return;
    posBarcodeScanError.value = null;

    // --- Cari di data lokal terlebih dahulu ---
    const exactMatches: { product: Product; variant: ProductVariant | null }[] = [];
    for (const p of localProducts.value) {
        if (p.barcode?.toLowerCase() === code.toLowerCase() || p.sku?.toLowerCase() === code.toLowerCase()) {
            exactMatches.push({ product: p, variant: null });
        }
        for (const v of p.variants ?? []) {
            if (v.barcode?.toLowerCase() === code.toLowerCase() || v.sku?.toLowerCase() === code.toLowerCase()) {
                exactMatches.push({ product: p, variant: v });
            }
        }
    }

    if (exactMatches.length === 1) {
        const { product, variant } = exactMatches[0];
        if (addScannedToCart(product, variant)) {
            popupSuccessCenter(`${product.name}${variant ? ' - ' + variant.name : ''} masuk keranjang.`, 1000);
        }
        showBarcodeScannerPosCamera.value = false;
        return;
    }

    if (exactMatches.length > 1) {
        // Barcode ambigu — tampilkan di search agar kasir pilih manual
        searchQuery.value = code;
        showBarcodeScannerPosCamera.value = false;
        reportPosScanFailure(`Barcode "${code}" cocok dengan ${exactMatches.length} produk. Pilih manual dari daftar di bawah.`);
        return;
    }

    // --- Tidak ketemu lokal: fallback ke API ---
    posBarcodeLookupLoading.value = true;
    try {
        const res = await fetch(`/barcode/lookup?code=${encodeURIComponent(code)}&context=pos`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfTokenPos() },
        });
        const data = (await res.json()) as {
            found: boolean;
            barcode: string;
            product?: Product;
            matched_variant_id?: number;
        };

        if (!data.found) {
            showBarcodeScannerPosCamera.value = false;
            posBarcodeScanError.value = `Barcode "${code}" tidak ditemukan di katalog produk.`;
            await modalError('Produk tidak ditemukan', `Barcode "${code}" tidak ditemukan di katalog produk.`);
            return;
        }

        const product = data.product!;
        const variantId = data.matched_variant_id;
        const variant = variantId ? ((product.variants ?? []).find((v) => v.id === variantId) ?? null) : null;
        const stock = variant ? Number(variant.stock_quantity) : Number(product.stock_quantity);

        showBarcodeScannerPosCamera.value = false;

        if (product.track_stock && !product.unlimited_stock && stock <= 0) {
            reportPosScanFailure(`Stok ${product.name}${variant ? ' - ' + variant.name : ''} habis.`);
            return;
        }

        // Produk hanya ada di API (tidak di props) — tampilkan di search
        searchQuery.value = code;
        reportPosScanFailure(`Produk ditemukan: "${product.name}". Muat ulang halaman untuk memperbaruinya atau ketuk produk di grid.`);
    } catch {
        showBarcodeScannerPosCamera.value = false;
        reportPosScanFailure('Gagal menghubungi server. Periksa koneksi.');
    } finally {
        posBarcodeLookupLoading.value = false;
    }
}

function handlePosBarcodeError(msg: string) {
    posBarcodeScanError.value = msg;
}

function handleSearchEnter() {
    flushSearchDebounce();
    const query = searchQuery.value.trim().toLowerCase();
    if (!query) return;

    // Check exact matches for barcode or SKU
    const exactMatches: { product: Product; variant: ProductVariant | null }[] = [];
    for (const p of localProducts.value) {
        if (p.barcode?.toLowerCase() === query || p.sku?.toLowerCase() === query) {
            exactMatches.push({ product: p, variant: null });
        }
        const vars = p.variants ?? [];
        for (const v of vars) {
            if (v.barcode?.toLowerCase() === query || v.sku?.toLowerCase() === query) {
                exactMatches.push({ product: p, variant: v });
            }
        }
    }

    if (exactMatches.length === 1) {
        const { product, variant } = exactMatches[0];
        if (variant) {
            proceedToModifiersOrAdd(product, variant);
        } else {
            requestAddToCart(product);
        }
        searchQuery.value = '';
        return;
    }

    // If no exact match but only one item is visible in the filtered results, add it
    if (filteredProducts.value.length === 1) {
        requestAddToCart(filteredProducts.value[0]);
        searchQuery.value = '';
    }
}

watch(productGridTotalPages, (total) => {
    if (productGridPage.value > total) {
        productGridPage.value = total;
    }
});

function normalizedModifierOptions(product: Product): ProductModifierOption[] {
    const raw = product.modifier_options;
    if (!Array.isArray(raw) || raw.length === 0) {
        return [];
    }
    return raw
        .filter((o) => o && typeof o.key === 'string' && o.key && o.label)
        .map((o) => ({
            key: String(o.key),
            label: String(o.label),
            price: Math.max(0, Number(o.price) || 0),
        }));
}

function hydrationModifiersForProduct(product: Product, raw: unknown): ProductModifierOption[] {
    const opts = normalizedModifierOptions(product);
    if (!Array.isArray(raw) || raw.length === 0) {
        return [];
    }
    const keys = new Set(raw.filter((x) => x && typeof x === 'object' && x !== null && 'key' in x).map((x) => String((x as { key: string }).key)));
    return opts.filter((o) => keys.has(o.key));
}

function modifiersKey(mods: ProductModifierOption[]): string {
    return mods
        .map((m) => m.key)
        .sort()
        .join('|');
}

function variantScopeKey(v: ProductVariant | null): string {
    return v ? `v:${v.id}` : 'v:';
}

function lineUnitPrice(product: Product, variant: ProductVariant | null): number {
    const retail = variant && variant.price != null && variant.price !== '' ? Math.max(0, Number(variant.price)) : Number(product.price) || 0;

    if (customerType.value === 'grosir') {
        const wholesale = variant ? (variant.wholesale_price ?? product.wholesale_price) : product.wholesale_price;
        const wholesaleNum = wholesale != null && wholesale !== '' ? Number(wholesale) : 0;
        if (wholesaleNum > 0) return Math.max(0, wholesaleNum);
    }

    return retail;
}

/** Harga tampilan kartu produk di POS.
 *  - Produk bervarian: harga terendah dari varian (null jika semua 0).
 *  - Produk biasa: harga produk (null jika 0).
 */
function productCardPrice(product: Product): { price: number; fromVariant: boolean } | null {
    if (product.has_variants && product.variants && product.variants.length > 0) {
        const prices = product.variants
            .map((v) => (v.price != null && v.price !== '' ? Number(v.price) : 0))
            .filter((p) => p > 0);
        if (prices.length === 0) return null;
        return { price: Math.min(...prices), fromVariant: prices.length > 1 };
    }
    if (!product.price || product.price <= 0) return null;
    return { price: product.price, fromVariant: false };
}

function lineStock(product: Product, variant: ProductVariant | null): number {
    // Produk unlimited selalu tersedia
    if (product.unlimited_stock) {
        return 999999;
    }

    // Produk sellable tanpa track_stock — hitung dari stok bahan komponen
    if (!product.track_stock && !product.is_bundle) {
        const allRows = product.sale_consumptions;
        if (allRows && allRows.length > 0) {
            // Filter by variant: jika variant dipilih, ambil yang cocok + yang null (semua varian)
            const rows = variant
                ? allRows.filter((r) => r.product_variant_id === null || r.product_variant_id === variant.id)
                : allRows.filter((r) => r.product_variant_id === null);
            if (rows.length > 0) {
                let minUnits = Infinity;
                for (const row of rows) {
                    const c = row.component;
                    if (!c || c.unlimited_stock || !c.track_stock) continue;
                    const need = Number(row.quantity) || 0;
                    if (need <= 0) continue;
                    const st = Number(c.stock_quantity) || 0;
                    const units = Math.floor(st / need);
                    minUnits = Math.min(minUnits, units);
                }
                if (minUnits !== Infinity) {
                    return Math.max(0, minUnits);
                }
            }
        }
        // Tidak ada komponen — belum ada resep, anggap habis
        return 0;
    }

    if (product.is_bundle && (product.bundle_items?.length ?? 0) > 0) {
        let minUnits = Infinity;
        for (const row of product.bundle_items ?? []) {
            const c = row.component_product;
            if (!c?.track_stock || c.unlimited_stock) {
                continue;
            }
            const need = Math.max(0, Number(row.quantity) || 0);
            if (need <= 0) {
                continue;
            }
            const v = row.component_variant;
            const st = c.has_variants && v ? Number(v.stock_quantity) : Number(c.stock_quantity) || 0;
            const units = c.allows_decimal ? st / need : Math.floor(st / need);
            minUnits = Math.min(minUnits, units);
        }
        if (minUnits === Infinity) {
            return 999999;
        }
        if (product.allows_decimal) {
            return Math.max(0, Math.round(minUnits * 10000) / 10000);
        }
        return Math.max(0, Math.floor(minUnits));
    }

    if (product.has_variants && variant) {
        return Number(variant.stock_quantity);
    }
    return stockNum(product);
}

function recalcLine(item: CartItem) {
    const unit = lineUnitPrice(item.product, item.variant) + item.selectedModifiers.reduce((s, m) => s + m.price, 0);
    item.subtotal = Math.round(unit * item.quantity * 100) / 100;
}

function hydrateFromPreOrder(h: PreOrderHydration) {
    cart.value = [];
    activePreOrderId.value = h.id;
    activePreOrderNumber.value = h.order_number;
    preOrderDepositReceived.value = Math.max(0, Number(h.deposit_received ?? 0) || 0);
    for (const line of h.lines) {
        const product = localProducts.value.find((p) => p.id === line.product_id);
        if (!product) {
            continue;
        }
        let variant: ProductVariant | null = null;
        if (product.has_variants) {
            variant = product.variants?.find((v) => v.id === line.product_variant_id) ?? null;
            if (!variant) {
                continue;
            }
        } else if (line.product_variant_id) {
            continue;
        }
        const qty = roundCartQty(Number(line.quantity), product);
        if (qty <= 0) {
            continue;
        }
        const selectedModifiers = hydrationModifiersForProduct(product, line.modifiers);
        const item: CartItem = {
            lineId: crypto.randomUUID(),
            product,
            variant,
            quantity: qty,
            subtotal: 0,
            selectedModifiers,
        };
        recalcLine(item);
        cart.value.push(item);
    }
    if (cart.value.length === 0) {
        activePreOrderId.value = null;
        activePreOrderNumber.value = null;
        preOrderDepositReceived.value = 0;
    } else {
        void nextTick(() => {
            amountPaid.value = balanceDue.value;
        });
    }
}

onMounted(() => {
    if (props.preOrderHydration) {
        hydrateFromPreOrder(props.preOrderHydration);
    }
    nextTick(checkCategoryScroll);
});

watch(
    () => props.categories.length,
    () => nextTick(checkCategoryScroll),
);

function proceedToModifiersOrAdd(product: Product, variant: ProductVariant | null) {
    pendingVariantForModifier.value = variant;
    const opts = normalizedModifierOptions(product);
    if (opts.length === 0) {
        addToCartInternal(product, variant, []);
        pendingVariantForModifier.value = null;
        return;
    }
    modifierPickerProduct.value = product;
    modifierPickerSelectedKeys.value = [];
    modifierPickerOpen.value = true;
}

/**
 * Alasan produk gagal masuk keranjang, atau null jika lolos.
 * Dipakai agar scan barcode tidak pernah gagal secara senyap: kasir selalu
 * mendapat penjelasan kenapa barang tidak masuk ke keranjang.
 */
function addToCartBlockReason(product: Product): string | null {
    if (product.inventory_type === 'production_output') {
        return `"${product.name}" adalah bahan produksi dan tidak bisa dijual di kasir.`;
    }
    if (!product.has_variants) {
        const price = Number(product.price);
        if (product.price === null || product.price === undefined || isNaN(price)) {
            return `Harga "${product.name}" belum diatur. Lengkapi harga produk terlebih dahulu.`;
        }
    }
    if (product.is_bundle) {
        if (lineStock(product, null) <= 0 && !product.allow_negative_stock) {
            const issues = getComponentStockIssues(product, null);
            if (issues.length > 0) {
                const detail = issues.map((i) => `${i.componentName} (tersedia ${i.available}, dibutuhkan ${i.needed})`).join('; ');
                return `Stok paket "${product.name}" habis. Komponen kurang: ${detail}`;
            }
            return `Stok paket "${product.name}" habis.`;
        }
        return null;
    }
    if (stockNum(product) <= 0 && product.track_stock && !product.allow_negative_stock) {
        return `Stok "${product.name}" habis.`;
    }
    if (product.has_variants) {
        const vars = product.variants ?? [];
        if (vars.length === 0) {
            return `"${product.name}" belum memiliki varian aktif.`;
        }
        if (vars.length === 1 && lineStock(product, vars[0]) <= 0 && !product.allow_negative_stock) {
            const issues = getComponentStockIssues(product, vars[0]);
            if (issues.length > 0) {
                const detail = issues.map((i) => `${i.componentName} (tersedia ${i.available}, dibutuhkan ${i.needed})`).join('; ');
                return `Stok "${product.name} — ${vars[0].name}" habis. Komponen kurang: ${detail}`;
            }
            return `Stok "${product.name} — ${vars[0].name}" habis.`;
        }
        return null;
    }
    if (!product.track_stock && lineStock(product, null) <= 0 && !product.allow_negative_stock) {
        const issues = getComponentStockIssues(product, null);
        if (issues.length > 0) {
            const detail = issues.map((i) => `${i.componentName} (tersedia ${i.available} ${i.unit}, dibutuhkan ${i.needed} ${i.unit})`).join('; ');
            return `Bahan untuk "${product.name}" tidak mencukupi: ${detail}`;
        }
        return `Bahan untuk "${product.name}" tidak mencukupi.`;
    }
    return null;
}

/** Detail masalah stok komponen untuk ditampilkan ke kasir. */
interface ComponentStockIssue {
    componentName: string;
    needed: number;
    available: number;
    unit: string;
}

/**
 * Periksa detail stok komponen produk.
 * Mengembalikan daftar komponen yang stoknya habis atau tidak mencukupi.
 */
function getComponentStockIssues(product: Product, variant: ProductVariant | null, saleQty: number = 1): ComponentStockIssue[] {
    const issues: ComponentStockIssue[] = [];

    if (product.is_bundle) {
        for (const row of product.bundle_items ?? []) {
            const c = row.component_product;
            if (!c || !c.track_stock || c.unlimited_stock) continue;
            const need = Math.max(0, (Number(row.quantity) || 0) * saleQty);
            if (need <= 0) continue;
            const v = row.component_variant;
            const available = c.has_variants && v ? Number(v.stock_quantity) : Number(c.stock_quantity) || 0;
            if (available < need) {
                issues.push({
                    componentName: c.name,
                    needed: need,
                    available,
                    unit: 'pcs',
                });
            }
        }
        return issues;
    }

    if (!product.track_stock && !product.is_bundle) {
        const allRows = product.sale_consumptions;
        if (!allRows || allRows.length === 0) return issues;
        const rows = variant
            ? allRows.filter((r) => r.product_variant_id === null || r.product_variant_id === variant.id)
            : allRows.filter((r) => r.product_variant_id === null);
        for (const row of rows) {
            const c = row.component;
            if (!c || !c.track_stock || c.unlimited_stock) continue;
            const need = (Number(row.quantity) || 0) * saleQty;
            if (need <= 0) continue;
            const available = Number(c.stock_quantity) || 0;
            if (available < need) {
                issues.push({
                    componentName: c.name,
                    needed: Math.round(need * 10000) / 10000,
                    available,
                    unit: c.base_unit || row.base_unit || 'pcs',
                });
            }
        }
    }

    return issues;
}

/**
 * Tampilkan modal detail komponen stok tidak mencukupi.
 * Mengembalikan true jika pengguna menekan "Oke, Saya Mengerti" (transaksi boleh dilanjutkan meskipun stok kurang — server tetap akan menolak).
 */
async function showComponentStockIssuesModal(product: Product, issues: ComponentStockIssue[]): Promise<boolean> {
    const rowsHtml = issues
        .map(
            (i) =>
                `<div class="flex justify-between items-center py-1.5 border-b border-slate-100 last:border-0">
                    <span class="font-medium text-slate-800">${i.componentName}</span>
                    <span class="text-red-600 font-semibold text-sm">${i.available} ${i.unit} / ${i.needed} ${i.unit}</span>
                </div>`,
        )
        .join('');

    const result = await Swal.fire({
        icon: 'warning',
        title: `Stok Komponen "${product.name}" Tidak Mencukupi`,
        html: `
            <div class="text-left mt-2">
                <p class="text-sm text-slate-600 mb-3">Komponen berikut tidak memiliki stok yang cukup:</p>
                <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">${rowsHtml}</div>
                <p class="text-xs text-slate-500 mt-3 italic">Format: stok tersedia / stok dibutuhkan</p>
            </div>
        `,
        confirmButtonText: 'Oke, Saya Mengerti',
        confirmButtonColor: '#0891b2',
        customClass: {
            popup: 'rounded-2xl shadow-2xl font-sans',
            title: 'text-lg font-bold text-slate-900',
            htmlContainer: 'text-sm text-slate-600',
            confirmButton: 'px-6 py-2 rounded-xl font-semibold',
        },
    });
    return result.isConfirmed;
}

function addToCartInternal(product: Product, variant: ProductVariant | null, selectedModifiers: ProductModifierOption[]) {
    if (product.has_variants) {
        if (!variant) {
            return;
        }
    } else if (isNaN(product.price) || product.price === null || product.price === undefined) {
        return;
    }
    // Bahan produksi hanya info stok, tidak bisa dijual
    if (product.inventory_type === 'production_output') return;
    const mk = modifiersKey(selectedModifiers);
    const vk = variantScopeKey(variant);
    const existingItem = cart.value.find(
        (item) => item.product.id === product.id && variantScopeKey(item.variant) === vk && modifiersKey(item.selectedModifiers) === mk,
    );
    const step = qtyStep(product);
    const stk = lineStock(product, variant);

    if (existingItem) {
        const next = roundCartQty(existingItem.quantity + step, product);
        if (product.allow_negative_stock || lteStock(next, stk, !!product.allows_decimal)) {
            existingItem.quantity = next;
            recalcLine(existingItem);
        }
    } else {
        const start = roundCartQty(1, product);
        if (!product.allow_negative_stock && !lteStock(start, stk, !!product.allows_decimal)) {
            return;
        }
        const item: CartItem = {
            lineId: crypto.randomUUID(),
            product,
            variant,
            quantity: start,
            subtotal: 0,
            selectedModifiers,
        };
        recalcLine(item);
        cart.value.push(item);
    }
}

function requestAddToCart(product: Product) {
    if (product.is_bundle) {
        if (isNaN(product.price) || product.price === null || product.price === undefined) {
            return;
        }
        if (lineStock(product, null) <= 0 && !product.allow_negative_stock) {
            const issues = getComponentStockIssues(product, null);
            if (issues.length > 0) {
                void showComponentStockIssuesModal(product, issues);
            }
            return;
        }
        proceedToModifiersOrAdd(product, null);
        return;
    }
    if (!product.has_variants) {
        if (isNaN(product.price) || product.price === null || product.price === undefined) {
            return;
        }
    }
    if (stockNum(product) <= 0 && product.track_stock && !product.allow_negative_stock) {
        return;
    }
    // Produk berbasis komponen (sale_consumptions): tampilkan detail stok komponen
    if (!product.track_stock && !product.is_bundle) {
        const stock = lineStock(product, null);
        if (stock <= 0 && !product.allow_negative_stock) {
            const issues = getComponentStockIssues(product, null);
            if (issues.length > 0) {
                void showComponentStockIssuesModal(product, issues);
            }
            return;
        }
    }
    if (product.has_variants) {
        const vars = product.variants ?? [];
        if (vars.length === 0) {
            return;
        }
        if (vars.length === 1) {
            if (lineStock(product, vars[0]) <= 0 && !product.allow_negative_stock) {
                const issues = getComponentStockIssues(product, vars[0]);
                if (issues.length > 0) {
                    void showComponentStockIssuesModal(product, issues);
                } else {
                    toastWarning(`Stok "${product.name} — ${vars[0].name}" habis.`);
                }
                return;
            }
            proceedToModifiersOrAdd(product, vars[0]);
            return;
        }
        variantPickerProduct.value = product;
        variantPickerOpen.value = true;
        return;
    }
    proceedToModifiersOrAdd(product, null);
}

function confirmVariantPick(variant: ProductVariant) {
    const p = variantPickerProduct.value;
    if (!p) return;
    if (lineStock(p, variant) <= 0) return;
    variantPickerOpen.value = false;
    variantPickerProduct.value = null;
    proceedToModifiersOrAdd(p, variant);
}

function cancelVariantPicker() {
    variantPickerOpen.value = false;
    variantPickerProduct.value = null;
}

function toggleModifierPickerKey(key: string) {
    const cur = modifierPickerSelectedKeys.value;
    const i = cur.indexOf(key);
    if (i >= 0) {
        modifierPickerSelectedKeys.value = cur.filter((k) => k !== key);
    } else {
        modifierPickerSelectedKeys.value = [...cur, key];
    }
}

function confirmModifierPicker() {
    const p = modifierPickerProduct.value;
    if (!p) {
        return;
    }
    const opts = normalizedModifierOptions(p);
    const selected = opts.filter((o) => modifierPickerSelectedKeys.value.includes(o.key));
    const v = pendingVariantForModifier.value;
    addToCartInternal(p, v, selected);
    modifierPickerOpen.value = false;
    modifierPickerProduct.value = null;
    modifierPickerSelectedKeys.value = [];
    pendingVariantForModifier.value = null;
}

function cancelModifierPicker() {
    modifierPickerOpen.value = false;
    modifierPickerProduct.value = null;
    modifierPickerSelectedKeys.value = [];
    pendingVariantForModifier.value = null;
}

const removeFromCart = (lineId: string) => {
    const index = cart.value.findIndex((item) => item.lineId === lineId);
    if (index > -1) {
        cart.value.splice(index, 1);
    }
};

const updateQuantity = (lineId: string, newQuantity: number) => {
    const item = cart.value.find((item) => item.lineId === lineId);
    if (item) {
        const p = item.product;
        const rounded = roundCartQty(newQuantity, p);
        const stk = lineStock(p, item.variant);
        const dec = !!p.allows_decimal;
        if (rounded <= 0 || (dec && rounded < 0.0001)) {
            removeFromCart(lineId);
        } else if (lteStock(rounded, stk, dec)) {
            item.quantity = dec ? rounded : Math.round(rounded);
            recalcLine(item);
        }
    }
};

function onLineQtyChange(item: CartItem, raw: string) {
    const v = Number(String(raw).replace(',', '.'));
    if (!Number.isFinite(v)) {
        return;
    }
    updateQuantity(item.lineId, v);
}

watch(
    () => [props.store?.fnb_enabled, props.store?.default_service_charge_percent] as const,
    () => {
        const store = props.store;
        if (store?.fnb_enabled) {
            serviceChargePercent.value = Number(store.default_service_charge_percent) || 0;
        } else {
            serviceChargePercent.value = 0;
            tipAmount.value = 0;
            tableReference.value = '';
            orderType.value = 'dine_in';
            salesChannel.value = 'walk_in';
            commissionPercent.value = 0;
        }
    },
    { immediate: true },
);

const subtotal = computed(() => {
    return cart.value.reduce((sum, item) => sum + Number(item.subtotal), 0);
});

function cartLineMatchesDiscountScope(d: ActiveDiscount, item: CartItem): boolean {
    const pIds = d.applicable_products;
    const cats = d.applicable_categories;
    const hasP = Array.isArray(pIds) && pIds.length > 0;
    const hasC = Array.isArray(cats) && cats.length > 0;
    if (!hasP && !hasC) {
        return true;
    }
    if (hasP && pIds!.includes(item.product.id)) {
        return true;
    }
    if (hasC && item.product.category && cats!.includes(item.product.category)) {
        return true;
    }
    return false;
}

function eligibleSubtotalForDiscount(d: ActiveDiscount): number {
    let el = 0;
    for (const item of cart.value) {
        if (cartLineMatchesDiscountScope(d, item)) {
            el += Number(item.subtotal);
        }
    }
    return el;
}

/** Selaras dengan server: per grup produk+varian, diskon = harga rata-rata × qty gratis. */
function buyXGetYDiscountAmount(d: ActiveDiscount): number {
    const buyX = d.buy_x != null ? Number(d.buy_x) : 0;
    const getY = d.get_y != null ? Number(d.get_y) : 0;
    if (!Number.isFinite(buyX) || !Number.isFinite(getY) || buyX < 1 || getY < 1) {
        return 0;
    }
    const den = buyX + getY;
    const groups = new Map<string, { qty: number; sub: number }>();
    for (const item of cart.value) {
        if (!cartLineMatchesDiscountScope(d, item)) {
            continue;
        }
        const key = `${item.product.id}_${item.variant?.id ?? 0}`;
        const cur = groups.get(key) ?? { qty: 0, sub: 0 };
        cur.qty += item.quantity;
        cur.sub += Number(item.subtotal);
        groups.set(key, cur);
    }
    let total = 0;
    for (const g of groups.values()) {
        if (g.qty <= 0 || g.sub <= 0) {
            continue;
        }
        const bundles = Math.floor(g.qty / den + 1e-9);
        if (bundles < 1) {
            continue;
        }
        const freeUnits = getY * bundles;
        const unitAvg = g.sub / g.qty;
        const disc = Math.min(Math.round(freeUnits * unitAvg * 100) / 100, g.sub);
        total += disc;
    }
    return Math.min(Math.round(total * 100) / 100, subtotal.value);
}

const applyDiscountCode = () => {
    discountError.value = '';
    const raw = discountCode.value.trim();
    if (!raw) {
        appliedDiscount.value = null;
        return;
    }
    const key = raw.toUpperCase();
    const d = props.activeDiscounts.find((x) => x.code && x.code.toUpperCase() === key);
    if (!d) {
        appliedDiscount.value = null;
        discountError.value = 'Kode tidak ditemukan atau tidak aktif.';
        return;
    }
    const elig = eligibleSubtotalForDiscount(d);
    if (d.minimum_amount && elig < Number(d.minimum_amount)) {
        appliedDiscount.value = null;
        discountError.value = 'Subtotal belum memenuhi minimum diskon.';
        return;
    }
    appliedDiscount.value = d;
};

const discountAmount = computed(() => {
    const d = appliedDiscount.value;
    if (!d) return 0;
    const elig = eligibleSubtotalForDiscount(d);
    if (d.minimum_amount && elig < Number(d.minimum_amount)) return 0;
    if (d.type === 'buy_x_get_y') {
        return buyXGetYDiscountAmount(d);
    }
    if (d.type === 'percentage') {
        return Math.round(elig * (Number(d.value) / 100) * 100) / 100;
    }
    return Math.min(Number(d.value), elig);
});

const afterDiscount = computed(() => Math.max(0, Math.round((subtotal.value - discountAmount.value) * 100) / 100));

const serviceChargeAmount = computed(() => {
    if (!props.store?.fnb_enabled) {
        return 0;
    }
    const pct = Number(serviceChargePercent.value) || 0;
    if (pct <= 0) {
        return 0;
    }
    return Math.round(afterDiscount.value * (pct / 100) * 100) / 100;
});

const taxAmount = computed(() => {
    if (!props.store || props.store?.tax_enabled === false || props.store?.tax_enabled === undefined) return 0;

    const taxRate = props.store?.tax_rate || 0;
    if (isNaN(taxRate)) {
        return 0;
    }

    const base = serviceChargeAmount.value > 0 ? afterDiscount.value + serviceChargeAmount.value : afterDiscount.value;

    return Math.round(base * (taxRate / 100) * 100) / 100;
});

const totalAmount = computed(() => {
    const tip = props.store?.fnb_enabled ? Math.max(0, Number(tipAmount.value) || 0) : 0;
    return Math.round((afterDiscount.value + serviceChargeAmount.value + taxAmount.value + tip) * 100) / 100;
});

/** DP yang benar-benar mengurangi tagihan (tidak melebihi total). */
const preOrderDepositApplied = computed(() => {
    if (activePreOrderId.value === null) {
        return 0;
    }
    const dep = preOrderDepositReceived.value;
    return Math.min(Math.max(0, dep), totalAmount.value);
});

/** Sisa yang harus dibayar di kasir (setelah DP pre-order). */
const balanceDue = computed(() => Math.max(0, Math.round((totalAmount.value - preOrderDepositApplied.value) * 100) / 100));

const cartHasPrescriptionLine = computed(
    () => props.store?.pharmacy_enabled === true && cart.value.some((i) => i.product.pharmacy_sale_class === 'prescription'),
);

const cartHasLimitedLine = computed(
    () => props.store?.pharmacy_enabled === true && cart.value.some((i) => i.product.pharmacy_sale_class === 'limited'),
);

const splitBillPreview = computed(() => {
    if (!props.store?.fnb_enabled) {
        return null;
    }
    const n = Math.max(1, Math.min(20, Math.round(Number(splitBillCount.value) || 1)));
    if (n < 2) {
        return null;
    }
    const shares = splitTotalIntoRupiahShares(totalAmount.value, n);
    if (shares.length < 2) {
        return null;
    }
    return { count: n, shares };
});

watch([totalAmount, paymentMethod, balanceDue], () => {
    if (isCredit.value) return; // jangan timpa saat mode kredit
    if (paymentMethod.value && paymentMethod.value !== 'cash') {
        amountPaid.value = balanceDue.value;
    }
});

watch(subtotal, () => {
    const d = appliedDiscount.value;
    if (!d) return;
    if (d.minimum_amount && eligibleSubtotalForDiscount(d) < Number(d.minimum_amount)) {
        appliedDiscount.value = null;
        discountError.value = 'Diskon dihapus: syarat minimum tidak terpenuhi.';
    }
});

watch(customerType, () => {
    for (const item of cart.value) recalcLine(item);
    if (!isCredit.value) amountPaid.value = balanceDue.value;
});

watch(
    () => props.store?.wholesale_enabled,
    (enabled) => {
        if (enabled === false) customerType.value = 'ecer';
    },
    { immediate: true },
);

const changeAmount = computed(() => {
    return Math.max(0, amountPaid.value - balanceDue.value);
});

/** Pecahan uang kertas yang beredar di Indonesia. */
const CASH_DENOMINATIONS = [1000, 2000, 5000, 10000, 20000, 50000, 100000];
/** Kelipatan pembulatan yang lazim dipakai kasir saat menerima gabungan beberapa lembar. */
const CASH_ROUNDING_STEPS = [5000, 10000, 25000, 50000, 100000];

/**
 * Saran nominal bayar yang menyesuaikan total belanja.
 * Hanya nominal di atas tagihan yang ditampilkan, supaya kasir tidak salah input.
 */
const cashSuggestions = computed<number[]>(() => {
    const due = balanceDue.value;
    if (due <= 0) {
        return [];
    }

    const candidates = new Set<number>();
    for (const nominal of CASH_DENOMINATIONS) {
        if (nominal > due) {
            candidates.add(nominal);
        }
    }
    for (const step of CASH_ROUNDING_STEPS) {
        const rounded = Math.ceil(due / step) * step;
        if (rounded > due) {
            candidates.add(rounded);
        }
    }

    return [...candidates].sort((a, b) => a - b).slice(0, 5);
});

/** Isi jumlah bayar dari tombol saran; kembalian ikut terhitung otomatis. */
const applyCashSuggestion = (nominal: number) => {
    amountPaid.value = nominal;
};

const numpadInput = (digit: string): void => {
    const current = String(Math.round(amountPaid.value || 0));
    const next = current === '0' ? digit : current + digit;
    amountPaid.value = Number(next);
};

const numpadBackspace = (): void => {
    const current = String(Math.round(amountPaid.value || 0));
    const next = current.length > 1 ? current.slice(0, -1) : '0';
    amountPaid.value = Number(next);
};

/** Reset keranjang dan state pembayaran setelah transaksi selesai */
function resetCartState() {
    cartSheetOpen.value = false;
    checkoutOpen.value = false;
    paymentStep.value = 'method';
    cart.value = [];
    amountPaid.value = 0;
    customerName.value = '';
    customerType.value = 'ecer';
    customerPhone.value = '';
    pharmacySaleReference.value = '';
    prescription.value = { reference: '', number: '', patient_name: '', doctor_name: '', issued_at: '' };
    discountCode.value = '';
    appliedDiscount.value = null;
    discountError.value = '';
    tipAmount.value = 0;
    tableReference.value = '';
    orderType.value = 'dine_in';
    salesChannel.value = 'walk_in';
    commissionPercent.value = 0;
    splitBillCount.value = 1;
    if (props.store?.fnb_enabled) {
        serviceChargePercent.value = Number(props.store?.default_service_charge_percent) || 0;
    }
    activePreOrderId.value = null;
    activePreOrderNumber.value = null;
    preOrderDepositReceived.value = 0;
    resetCreditMode();
}

/** Update saldo shift secara lokal (optimistic) agar UI langsung terasa. */
function applyOptimisticCashShiftUpdate(paymentMethod: string, totalAmount: number) {
    const shift = cashShiftOpen.value;
    if (!shift) return;
    cashShiftOpen.value = {
        ...shift,
        expected_cash: paymentMethod === 'cash' ? shift.expected_cash + totalAmount : shift.expected_cash,
        expected_non_cash: paymentMethod !== 'cash' ? (shift.expected_non_cash ?? 0) + totalAmount : (shift.expected_non_cash ?? 0),
    };
}

/** Refresh data cash shift dari server (tanpa reload halaman). */
async function refreshCashShift() {
    if (!allowsAdvancedRetail.value) return;
    try {
        const res = await fetch('/pos/cash-shift-current', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfTokenPos() },
        });
        if (!res.ok) return;
        const data = (await res.json()) as { cashShiftOpen: CashShiftOpen | null };
        cashShiftOpen.value = data.cashShiftOpen ?? null;
    } catch {
        /* gagal fetch — biarkan data lama */
    }
}

/** ===== Open Bills (Order Hold) Handlers ===== */

async function fetchOpenBills() {
    if (!props.store?.open_bill_enabled) return;
    openBillsLoading.value = true;
    try {
        const params = new URLSearchParams();
        if (openBillsSearch.value.trim()) params.set('q', openBillsSearch.value.trim());
        const res = await fetch(`/pos/order-holds?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfTokenPos() },
        });
        if (!res.ok) throw new Error('Gagal memuat open bills');
        const data = await res.json();
        if (data.success) {
            openBills.value = data.holds ?? [];
        }
    } catch (e) {
        console.error('[open-bills] fetch error', e);
        toastError('Gagal memuat daftar pesanan');
    } finally {
        openBillsLoading.value = false;
    }
}

function openOpenBillsDrawer() {
    openBillsDrawerOpen.value = true;
    openBillsSearch.value = '';
    fetchOpenBills();
}

function closeOpenBillsDrawer() {
    openBillsDrawerOpen.value = false;
    openBillsSelectedId.value = null;
}

async function loadOpenBillToCart(bill: OpenBill) {
    try {
        const res = await fetch(`/pos/order-holds/${bill.id}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfTokenPos() },
        });
        if (!res.ok) throw new Error('Gagal memuat detail pesanan');
        const data = await res.json();
        if (!data.success) throw new Error(data.message ?? 'Gagal memuat pesanan');

        const hold = data.hold;
        // Populate cart dari hold items — resolve objek product/variant dari katalog
        // agar sama seperti CartItem hasil addToCart (template & payload butuh itu).
        const newCart: CartItem[] = [];
        for (const it of hold.items ?? []) {
            const product = localProducts.value.find((p) => p.id === it.product_id);
            if (!product) continue;
            let variant: ProductVariant | null = null;
            if (product.has_variants) {
                variant = product.variants?.find((v) => v.id === it.product_variant_id) ?? null;
                if (!variant) continue;
            }
            const item: CartItem = {
                lineId: crypto.randomUUID(),
                product,
                variant,
                quantity: roundCartQty(Number(it.quantity), product),
                subtotal: 0,
                selectedModifiers: hydrationModifiersForProduct(product, it.modifiers ?? []),
            };
            recalcLine(item);
            newCart.push(item);
        }
        if (newCart.length === 0 && (hold.items ?? []).length > 0) {
            toastError('Produk pada pesanan tidak ditemukan di katalog saat ini');
            return;
        }
        cart.value = newCart;
        // Set referensi meja & tipe order
        tableReference.value = hold.table_reference ?? '';
        orderType.value = hold.order_type ?? 'dine_in';
        customerName.value = hold.customer_name ?? '';
        activePreOrderId.value = hold.id;
        activePreOrderNumber.value = hold.transaction_number;

        // Tutup drawer & buka keranjang (mobile)
        closeOpenBillsDrawer();
        cartSheetOpen.value = true;
        toastSuccess(`Memuat pesanan ${hold.transaction_number}`, 3000);
    } catch (e: any) {
        console.error('[open-bills] load error', e);
        toastError(e.message ?? 'Gagal memuat pesanan');
    }
}

async function saveOrderHold() {
    if (!saveOrderForm.value.items.length) {
        toastWarning('Keranjang kosong');
        return;
    }
    if (props.store?.fnb_enabled && !saveOrderForm.value.table_reference.trim()) {
        toastWarning('Nomor meja wajib diisi');
        return;
    }

    saveOrderLoading.value = true;
    try {
        const res = await fetch('/pos/order-hold', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfTokenPos(),
            },
            body: JSON.stringify({
                items: saveOrderForm.value.items,
                order_type: saveOrderForm.value.order_type || null,
                table_reference: saveOrderForm.value.table_reference,
                customer_name: saveOrderForm.value.customer_name || null,
                notes: saveOrderForm.value.notes || null,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.message ?? 'Gagal menyimpan pesanan');
        }
        const savedTxNumber: string = data.transaction?.transaction_number ?? '';

        saveOrderDrawerOpen.value = false;
        resetCartState();
        void fetchOpenBills();
        toastSuccess(`Pesanan ${savedTxNumber} tersimpan`, 3000);

        // Cetak KOT setelah simpan (berdasarkan pilihan pengguna di drawer).
        if (saveOrderForm.value.print_kot) {
            try {
                await printKotForTransaction({
                    transactionNumber: savedTxNumber,
                    tableReference: saveOrderForm.value.table_reference,
                    orderType: saveOrderForm.value.order_type,
                    customerName: saveOrderForm.value.customer_name || null,
                    items: data.transaction.items.map((it: any) => ({
                        product_name: it.product_name,
                        quantity: Number(it.quantity),
                        modifiers: it.modifiers ?? [],
                    })),
                });
            } catch (e) {
                console.warn('[open-bills] KOT print failed', e);
            }
        }

        // Reset form setelah selesai
        saveOrderForm.value.items = [];
        saveOrderForm.value.table_reference = '';
        saveOrderForm.value.customer_name = '';
        saveOrderForm.value.notes = '';
    } catch (e: any) {
        console.error('[open-bills] save error', e);
        toastError(e.message ?? 'Gagal menyimpan pesanan');
    } finally {
        saveOrderLoading.value = false;
    }
}

/** Cetak KOT via Bluetooth jika terhubung, fallback ke dialog cetak browser. */
async function printKotForTransaction(opts: {
    transactionNumber: string;
    tableReference: string | null;
    orderType?: string | null;
    customerName?: string | null;
    items: Array<{ product_name: string; quantity: number; modifiers?: Array<{ label?: string }> | null }>;
}) {
    const r = mergeReceiptConfig(null, (props.store?.receipt ?? defaultReceiptFallback()) as Partial<ReceiptConfig>);
    const paper: 58 | 80 = r.paper_width_mm === 58 ? 58 : 80;

    if (isPrinterConnected.value) {
        try {
            const lines = buildKotPlainLines({
                storeName: String(props.store?.receipt?.header_name ?? props.store?.name ?? 'Toko'),
                transactionNumber: opts.transactionNumber,
                completedAt: new Date().toISOString(),
                orderType: opts.orderType ?? null,
                tableReference: opts.tableReference,
                customerName: opts.customerName,
                cashierName: null,
                items: opts.items,
                paperWidthMm: paper,
            });
            const bytes = receiptLinesToEscPos(lines);
            const printed = await printEscPosBytes(bytes, 'kitchen');
            if (printed) {
                void toastSuccess('KOT berhasil dicetak ke printer Bluetooth.', 3000);
                return;
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : String(e);
            console.warn('[kot] Bluetooth gagal, fallback browser:', msg);
        }
    }
    // Fallback: cetak via browser
    printKitchenTicket({
        storeName: String(props.store?.receipt?.header_name ?? props.store?.name ?? 'Toko'),
        transactionNumber: opts.transactionNumber,
        completedAt: new Date().toISOString(),
        orderType: opts.orderType ?? null,
        tableReference: opts.tableReference,
        customerName: opts.customerName,
        items: opts.items,
        paperWidthMm: paper,
    });
}

function openSaveOrderDrawer() {
    if (!cart.value.length) {
        toastWarning('Keranjang kosong');
        return;
    }
    saveOrderForm.value.items = cart.value.map((it) => ({
        product_id: it.product.id,
        product_variant_id: it.variant?.id ?? null,
        quantity: it.quantity,
        unit_price: it.subtotal / Math.max(it.quantity, 0.0001),
        modifiers: (it.selectedModifiers ?? []).map((m) => ({ key: m.key })),
    }));
    saveOrderForm.value.order_type = orderType.value || 'dine_in';
    saveOrderForm.value.table_reference = tableReference.value;
    saveOrderForm.value.customer_name = customerName.value;
    saveOrderForm.value.notes = '';
    saveOrderForm.value.print_kot = false;
    saveOrderDrawerOpen.value = true;
}

async function cancelOpenBill(bill: OpenBill) {
    const reason = window.prompt('Alasan pembatalan (opsional):');
    if (reason === null) return; // user batal prompt

    try {
        const res = await fetch(`/pos/order-holds/${bill.id}/cancel`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfTokenPos(),
            },
            body: JSON.stringify({ reason: reason?.trim() ?? '' }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message ?? 'Gagal membatalkan');
        toastSuccess('Pesanan dibatalkan', 3000);
        fetchOpenBills();
    } catch (e: any) {
        toastError(e.message ?? 'Gagal membatalkan pesanan');
    }
}

// ─── Suara bel berhasil (Web Audio API — tanpa file eksternal)
function playSuccessSound() {
    try {
        const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();

        // Autoplay policy: AudioContext mulai "suspended" sampai gesture user.
        // Setelah await fetch, browser bisa tidak anggap gesture lagi — resume eksplisit.
        if (ctx.state === 'suspended') {
            void ctx.resume();
        }

        // Fungsi bantu: buat satu nada bel dengan harmonik
        function playBell(freq: number, startTime: number, vol: number) {
            // Oscillator utama (fundamental)
            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            const gain = ctx.createGain();

            osc1.type = 'sine';
            osc1.frequency.value = freq;

            // Harmonik ke-2 (oktaf + sedikit detune) untuk karakter "bel"
            osc2.type = 'sine';
            osc2.frequency.value = freq * 2.756; // harmonik partial bel

            const gainMix = ctx.createGain();
            gainMix.gain.value = 0.3;

            osc1.connect(gain);
            osc2.connect(gainMix);
            gainMix.connect(gain);

            // Envelope: serangan cepat, peluruhan panjang seperti bel
            gain.gain.setValueAtTime(0, startTime);
            gain.gain.linearRampToValueAtTime(vol, startTime + 0.005);
            gain.gain.exponentialRampToValueAtTime(vol * 0.3, startTime + 0.1);
            gain.gain.exponentialRampToValueAtTime(0.0001, startTime + 1.2);

            gain.connect(ctx.destination);
            osc1.start(startTime);
            osc2.start(startTime);
            osc1.stop(startTime + 1.3);
            osc2.stop(startTime + 1.3);
        }

        const t = ctx.currentTime;
        // Tiga nada naik: E5 → G#5 → B5 (akord mayor — terdengar "berhasil/sukses")
        playBell(659.25, t, 0.5); // E5
        playBell(830.61, t + 0.18, 0.45); // G#5
        playBell(987.77, t + 0.36, 0.5); // B5

        setTimeout(() => ctx.close(), 2000);
    } catch {
        // Browser tidak mendukung Web Audio API — abaikan dengan senyap
    }
}

const processPayment = async () => {
    if (!(await requireOpenCashShift())) return;
    if (!(await requireOpenAccountingPeriod())) return;
    if (cart.value.length === 0) return;
    // Mode kredit: izinkan amountPaid < balanceDue asal pelanggan sudah dipilih
    if (!isCredit.value && amountPaid.value < balanceDue.value) return;
    if (isCredit.value && !creditCustomerId.value) {
        await modalError(
            'Pelanggan wajib dipilih',
            'Penjualan kredit memerlukan data pelanggan. Cari nama pelanggan di kolom pencarian. Jika pelanggan belum terdaftar, klik tombol "Daftarkan Pelanggan Baru" untuk mendaftarkan secara instan tanpa meninggalkan halaman ini.',
        );
        return;
    }
    if (
        cartHasPrescriptionLine.value &&
        !String(pharmacySaleReference.value).trim() &&
        !prescription.value.reference.trim() &&
        !prescription.value.number.trim()
    ) {
        await modalError('Rujukan resep wajib', 'Keranjang berisi obat resep. Isi nomor resep atau rujukan di kolom khusus apotek.');
        return;
    }

    // Pre-validasi stok komponen sebelum kirim ke server
    for (const item of cart.value) {
        const issues = getComponentStockIssues(item.product, item.variant, item.quantity);
        if (issues.length > 0) {
            await showComponentStockIssuesModal(item.product, issues);
            return;
        }
    }

    // Open bill aktif → bayar via endpoint khusus agar transaksi asli tertutup
    // (bukan membuat transaksi baru).
    if (activePreOrderId.value !== null) {
        await payActiveOpenBill();
        return;
    }

    isProcessingPayment.value = true;

    // ─── Bangun payload transaksi (sama untuk online & offline)
    const paymentData: Record<string, unknown> = {
        storeId: props.store?.id,
        items: cart.value.map((item) => ({
            product_id: item.product.id,
            product_variant_id: item.variant?.id ?? null,
            quantity: item.quantity,
            unit_price: lineUnitPrice(item.product, item.variant),
            subtotal: item.subtotal,
            modifiers: item.selectedModifiers.map((m) => ({ key: m.key })),
            product_name: item.product.name,
            variant_name: item.variant?.name ?? null,
        })),
        payment_method: paymentMethod.value,
        amount_paid: amountPaid.value,
        total_amount: totalAmount.value,
        discount_amount: discountAmount.value,
        customer_name: customerName.value || (isCredit.value ? creditCustomerName.value : null) || null,
        customer_type: customerType.value,
        discount_code: appliedDiscount.value?.code || null,
        created_at: new Date().toISOString(),
        // ── Kredit / piutang
        is_credit: isCredit.value || false,
        customer_id: isCredit.value ? creditCustomerId.value : null,
        debt_due_at: isCredit.value && debtDueAt.value ? debtDueAt.value : null,
    };
    if (props.store?.fnb_enabled) {
        paymentData.order_type = orderType.value;
        paymentData.table_reference = tableReference.value.trim() || null;
        paymentData.service_charge_percent = serviceChargePercent.value;
        paymentData.tip_amount = tipAmount.value;
        const sb = Math.max(1, Math.min(20, Math.round(Number(splitBillCount.value) || 1)));
        if (sb >= 2) paymentData.split_bill_count = sb;
    }
    // Sumber pesanan & komisi online berlaku untuk semua jenis toko.
    paymentData.sales_channel = salesChannel.value;
    if (salesChannel.value in defaultCommissionByChannel.value) {
        paymentData.commission_percent = commissionPercent.value;
    }
    if (activePreOrderId.value !== null) paymentData.pre_order_id = activePreOrderId.value;
    if (props.store?.pharmacy_enabled) {
        const pr = String(pharmacySaleReference.value).trim();
        const prescriptionPayload = Object.fromEntries(Object.entries(prescription.value).filter(([, value]) => String(value).trim()));
        if (cartHasPrescriptionLine.value) {
            prescriptionPayload.items = cart.value
                .filter((item) => item.product.pharmacy_sale_class === 'prescription')
                .map((item) => ({ product_id: item.product.id, quantity: item.quantity }));
        }
        if (pr) paymentData.pharmacy_sale_reference = pr;
        if (Object.keys(prescriptionPayload).length) paymentData.prescription = prescriptionPayload;
    }

    // ─── Mode OFFLINE: simpan ke IndexedDB dan tampilkan struk lokal
    if (!isOnline.value) {
        try {
            const localId = await queueOfflineSale({
                ...(paymentData as unknown as Parameters<typeof queueOfflineSale>[0]),
                storeId: props.store?.id,
                cashierId: 0,
            });
            void toastInfo(
                'Transaksi disimpan offline',
                `Transaksi disimpan lokal (${localId.slice(0, 8)}...). Akan otomatis sync saat koneksi pulih.`,
            );
            // Tampilkan struk lokal (tanpa data server)
            transactionData.value = {
                id: null,
                sale_number: `OFFLINE-${localId.slice(0, 8).toUpperCase()}`,
                items: paymentData.items,
                payment_method: paymentData.payment_method,
                amount_paid: paymentData.amount_paid,
                total_amount: paymentData.total_amount,
                change_amount: changeAmount.value,
                customer_name: paymentData.customer_name,
                payment_details:
                    props.store?.pharmacy_enabled &&
                    (cartHasPrescriptionLine.value || cartHasLimitedLine.value || paymentData.prescription || paymentData.pharmacy_sale_reference)
                        ? {
                              pharmacy: {
                                  sale_reference:
                                      paymentData.pharmacy_sale_reference ||
                                      prescription.value.reference.trim() ||
                                      prescription.value.number.trim() ||
                                      null,
                                  prescription: paymentData.prescription ?? null,
                                  has_prescription_lines: cartHasPrescriptionLine.value,
                                  has_limited_lines: cartHasLimitedLine.value,
                              },
                          }
                        : null,
                created_at: paymentData.created_at,
            } as unknown as typeof transactionData.value;
            playSuccessSound();
            lastCustomerPhone.value = customerPhone.value;
            showInvoicePopup.value = true;
            applyOptimisticCashShiftUpdate(paymentData.payment_method as string, paymentData.total_amount as number);
            resetCartState();
        } catch (e) {
            await modalError('Gagal Simpan Offline', e instanceof Error ? e.message : 'Tidak dapat menyimpan transaksi secara offline.');
        } finally {
            isProcessingPayment.value = false;
        }
        return;
    }

    // ─── Mode ONLINE: kirim ke server seperti biasa
    try {
        const response = await fetch('/pos/process-payment', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfTokenPos(),
                Accept: 'application/json',
            },
            body: JSON.stringify(paymentData),
        });

        // Server bisa mengembalikan HTML (mis. 419 CSRF, 500, atau redirect ke login)
        // alih-alih JSON. Parse dengan aman agar pesan error asli tetap terbaca.
        const rawBody = await response.text();
        let result: Record<string, unknown> = {};
        try {
            result = rawBody ? JSON.parse(rawBody) : {};
        } catch {
            // Bukan JSON — susun pesan berdasarkan status HTTP.
            if (response.status === 419) {
                // Auto-reload halaman untuk mendapatkan token baru
                await modalError('Sesi Berakhir', 'Token keamanan telah kedaluwarsa. Halaman akan dimuat ulang...');
                setTimeout(() => window.location.reload(), 2000);
            } else if (response.status === 401 || response.status === 403) {
                await modalError('Tidak Diizinkan', 'Sesi login berakhir. Silakan login kembali.');
            } else {
                await modalError('Transaksi Gagal!', `Server mengembalikan respons tidak valid (HTTP ${response.status}).`);
            }
            return;
        }

        if (result.success) {
            transactionData.value = result.transaction;
            playSuccessSound();
            lastCustomerPhone.value = customerPhone.value;
            showInvoicePopup.value = true;
            applyOptimisticCashShiftUpdate(paymentMethod.value, totalAmount.value);
            resetCartState();
            void refreshCashShift();
        } else {
            const errors = result.errors as Record<string, string[]> | undefined;
            const allErrors = errors
                ? Object.entries(errors)
                      .map(([field, msgs]) => `${field}: ${msgs[0]}`)
                      .join('\n')
                : '';
            const firstError = errors ? Object.values(errors)[0]?.[0] : undefined;
            await modalError('Transaksi Gagal!', (result.message as string) || firstError || 'Gagal memproses pembayaran');
            if (import.meta.env.DEV && allErrors) console.error('Validation errors:', allErrors);
        }
    } catch (error) {
        if (import.meta.env.DEV) console.error('Payment processing error:', error);
        // "Failed to fetch" (TypeError) = kegagalan jaringan. Di WebView Android
        // navigator.onLine sering keliru melaporkan true, jadi jangan bergantung
        // padanya — perlakukan setiap network error sebagai offline dan antrikan.
        const isNetworkError = error instanceof TypeError || !navigator.onLine;
        if (isNetworkError) {
            try {
                const localId = await queueOfflineSale({
                    ...(paymentData as unknown as Parameters<typeof queueOfflineSale>[0]),
                    storeId: props.store?.id,
                    cashierId: 0,
                });
                void toastInfo(
                    'Transaksi disimpan offline',
                    `Koneksi terputus. Transaksi disimpan lokal (${localId.slice(0, 8)}...). Akan otomatis sync saat koneksi pulih.`,
                );
                // Tampilkan struk lokal agar kasir bisa lanjut melayani.
                transactionData.value = {
                    id: null,
                    sale_number: `OFFLINE-${localId.slice(0, 8).toUpperCase()}`,
                    items: paymentData.items,
                    payment_method: paymentData.payment_method,
                    amount_paid: paymentData.amount_paid,
                    total_amount: paymentData.total_amount,
                    change_amount: changeAmount.value,
                    customer_name: paymentData.customer_name,
                    payment_details:
                        props.store?.pharmacy_enabled &&
                        (cartHasPrescriptionLine.value || cartHasLimitedLine.value || paymentData.prescription || paymentData.pharmacy_sale_reference)
                            ? {
                                  pharmacy: {
                                      sale_reference:
                                          paymentData.pharmacy_sale_reference ||
                                          prescription.value.reference.trim() ||
                                          prescription.value.number.trim() ||
                                          null,
                                      prescription: paymentData.prescription ?? null,
                                      has_prescription_lines: cartHasPrescriptionLine.value,
                                      has_limited_lines: cartHasLimitedLine.value,
                                  },
                              }
                            : null,
                    created_at: paymentData.created_at,
                } as unknown as typeof transactionData.value;
                playSuccessSound();
                lastCustomerPhone.value = customerPhone.value;
                showInvoicePopup.value = true;
                applyOptimisticCashShiftUpdate(paymentData.payment_method as string, paymentData.total_amount as number);
                resetCartState();
            } catch {
                await modalError('Terjadi Kesalahan!', 'Gagal memproses pembayaran dan tidak dapat menyimpan offline.');
            }
        } else {
            const detail = error instanceof Error ? error.message : String(error);
            await modalError('Terjadi Kesalahan!', `Terjadi kesalahan saat memproses pembayaran: ${detail}`);
        }
    } finally {
        isProcessingPayment.value = false;
    }
};

/** Bayar open bill aktif via /pos/order-holds/{id}/pay — menutup transaksi asli. */
async function payActiveOpenBill() {
    if (activePreOrderId.value === null) return;
    isProcessingPayment.value = true;
    try {
        const payload: Record<string, unknown> = {
            payment_method: paymentMethod.value,
            amount_paid: amountPaid.value,
            discount_code: appliedDiscount.value?.code || null,
        };
        if (props.store?.fnb_enabled) {
            payload.service_charge_percent = serviceChargePercent.value;
            payload.tip_amount = tipAmount.value;
            const sb = Math.max(1, Math.min(20, Math.round(Number(splitBillCount.value) || 1)));
            if (sb >= 2) payload.split_bill_count = sb;
        }

        const res = await fetch(`/pos/order-holds/${activePreOrderId.value}/pay`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfTokenPos(),
            },
            body: JSON.stringify(payload),
        });
        const rawBody = await res.text();
        let result: Record<string, unknown> = {};
        try {
            result = rawBody ? JSON.parse(rawBody) : {};
        } catch {
            if (res.status === 419) {
                await modalError('Sesi Berakhir', 'Token keamanan telah kedaluwarsa. Halaman akan dimuat ulang...');
                setTimeout(() => window.location.reload(), 2000);
            } else {
                await modalError('Transaksi Gagal!', `Server mengembalikan respons tidak valid (HTTP ${res.status}).`);
            }
            return;
        }
        if (!res.ok || !result.success) {
            await modalError('Pembayaran Gagal!', String(result.message ?? 'Gagal memproses pembayaran pesanan'));
            return;
        }

        transactionData.value = result.transaction;
        playSuccessSound();
        showInvoicePopup.value = true;
        applyOptimisticCashShiftUpdate(paymentMethod.value, totalAmount.value);
        resetCartState();
        void refreshCashShift();
        void fetchOpenBills();
    } catch (error) {
        const detail = error instanceof Error ? error.message : String(error);
        await modalError('Terjadi Kesalahan!', `Gagal memproses pembayaran pesanan: ${detail}`);
    } finally {
        isProcessingPayment.value = false;
    }
}

const clearCart = async () => {
    if (cart.value.length > 0) {
        const ok = await confirmDialog(
            'Kosongkan Keranjang?',
            `${cart.value.length} item akan dibuang dari keranjang. Tindakan ini tidak bisa dibatalkan.`,
            { confirmText: 'Kosongkan', cancelText: 'Batal', icon: 'warning', confirmColor: '#dc2626' },
        );
        if (!ok) return;
    }
    cartSheetOpen.value = false;
    checkoutOpen.value = false;
    paymentStep.value = 'method';
    cart.value = [];
    amountPaid.value = 0;
    customerName.value = '';
    customerType.value = 'ecer';
    customerPhone.value = '';
    pharmacySaleReference.value = '';
    prescription.value = { reference: '', number: '', patient_name: '', doctor_name: '', issued_at: '' };
    discountCode.value = '';
    appliedDiscount.value = null;
    discountError.value = '';
    tipAmount.value = 0;
    tableReference.value = '';
    orderType.value = 'dine_in';
    if (props.store?.fnb_enabled) {
        serviceChargePercent.value = Number(props.store?.default_service_charge_percent) || 0;
    }
    activePreOrderId.value = null;
    activePreOrderNumber.value = null;
    preOrderDepositReceived.value = 0;
    resetCreditMode();
};

// Format currency helper
// ponytail: formatter di-cache di level modul — cegah alokasi Intl.NumberFormat tiap render.
const idIDFormatter = new Intl.NumberFormat('id-ID', {
    style: 'decimal',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
// Variant default (0–3 fract) untuk baris struk yang perlu menampilkan sen.
const idIDDefaultFormatter = new Intl.NumberFormat('id-ID');
const formatCurrency = (amount: number) => {
    if (isNaN(amount) || amount === null || amount === undefined) {
        return '0';
    }
    return idIDFormatter.format(amount);
};

function pharmacySaleLabel(code: string | null | undefined): string {
    if (code === 'prescription') return 'Resep';
    if (code === 'limited') return 'Terbatas';
    if (code === 'otc') return 'Bebas';
    return '';
}

function showPharmacyBadge(product: Product): boolean {
    return props.store?.pharmacy_enabled === true && !!product.pharmacy_sale_class && product.pharmacy_sale_class !== 'otc';
}

function defaultReceiptFallback(): ReceiptConfig {
    const s = props.store;
    return {
        header_name: s?.name ?? 'Toko',
        subtitle: '',
        footer: 'Terima kasih atas kunjungan Anda.',
        footer_line2: '',
        show_address: true,
        show_phone: true,
        show_logo: true,
        logo_url: null,
        paper_width_mm: 80,
        address: '',
        phone: '',
    };
}

// Invoice popup functions
const closeInvoicePopup = () => {
    showInvoicePopup.value = false;
    transactionData.value = null;
    customerPhone.value = '';
    lastCustomerPhone.value = '';
};

/**
 * Siapkan logo struk sebagai perintah raster ESC/POS untuk cetak Bluetooth.
 * Printer thermal tidak mengerti HTML/img, jadi logo harus dikirim sebagai bitmap.
 */
const buildLogoRasterForBluetooth = async (tx: Record<string, unknown>): Promise<Uint8Array | null> => {
    const r = mergeReceiptConfig(
        (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
        (props.store?.receipt ?? defaultReceiptFallback()) as Partial<ReceiptConfig>,
    );
    if (!r.show_logo || !r.logo_url) {
        if (import.meta.env.DEV) console.log('[POS] Logo dilewati: show_logo =', r.show_logo, ', logo_url =', r.logo_url);
        return null;
    }
    const logoSrc = r.logo_url.startsWith('http') ? r.logo_url : `${window.location.origin}${r.logo_url.startsWith('/') ? '' : '/'}${r.logo_url}`;
    const maxDots = printableDotsFor(r.paper_width_mm === 58 ? 58 : 80);
    // Batasi tinggi agar logo tidak menghabiskan kertas
    const raster = await imageUrlToEscPosRaster(logoSrc, Math.floor(maxDots * 0.7), 120);
    if (import.meta.env.DEV) console.log('[POS] Logo raster:', raster ? `${raster.length} byte dari ${logoSrc}` : `gagal dimuat: ${logoSrc}`);
    return raster;
};

const printInvoice = async () => {
    if (import.meta.env.DEV) console.log('[POS] printInvoice called');
    const tx = transactionData.value;
    if (!tx) {
        if (import.meta.env.DEV) console.warn('[POS] printInvoice: no transaction data');
        return;
    }

    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

    // Jika printer terhubung ATAU user di perangkat mobile (kita asumsikan mobile butuh Bluetooth, bukan dialog PDF Chrome)
    if (isPrinterConnected.value || isMobile) {
        try {
            if (import.meta.env.DEV) console.log('[POS] Building receipt lines...');
            const logoRaster = await buildLogoRasterForBluetooth(tx as Record<string, unknown>);
            const lines = buildReceiptPlainLinesFromPosResponse(tx as Record<string, unknown>, props.store, logoRaster);
            const bytes = receiptLinesToEscPos(lines);
            if (import.meta.env.DEV) console.log('[POS] Sending', bytes.length, 'bytes to printer...');

            const result = await printEscPosBytes(bytes, 'cashier');
            if (import.meta.env.DEV) console.log('[POS] Print result:', result);
            if (result.success) {
                void toastSuccess('Struk berhasil dicetak ke printer Bluetooth.', 3000);
                kickDrawer();
                return;
            } else {
                // Jika gagal di mobile, tampilkan error (jangan fallback ke browser print yang useless)
                if (isMobile) {
                    await modalError(
                        'Gagal Cetak Bluetooth',
                        result.error ?? 'Pastikan printer menyala dan sudah dihubungkan di Pengaturan → Bluetooth.',
                    );
                    return;
                }
                // Di desktop, kita biarkan jatuh ke fallback
                if (import.meta.env.DEV) console.warn('Cetak Bluetooth gagal, fallback ke cetak browser:', result.error);
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : String(e);

            if (isMobile) {
                await modalError(
                    'Gagal Cetak Bluetooth',
                    msg.includes('belum dipasangkan')
                        ? msg
                        : `Koneksi ke printer gagal: ${msg}. Pastikan printer menyala lalu hubungkan ulang di Pengaturan → Bluetooth.`,
                );
                return;
            }
            if (import.meta.env.DEV) console.warn('Cetak Bluetooth error, fallback ke cetak browser:', msg);
        }
    }

    // Fallback: cetak via browser (PDF dialog)
    // Berguna untuk Desktop PC dengan printer USB biasa
    const r = mergeReceiptConfig(
        (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
        (props.store?.receipt ?? defaultReceiptFallback()) as Partial<ReceiptConfig>,
    );
    printThermalReceipt(tx as Record<string, unknown>, r, {
        taxName: props.store?.tax_name || 'PPN',
        pharmacyEnabled: props.store?.pharmacy_enabled === true,
    });
};

const printKitchenTicketFromResponse = async () => {
    const tx = transactionData.value;
    if (!tx || !props.store?.fnb_enabled) {
        return;
    }
    const r = mergeReceiptConfig(
        (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
        (props.store?.receipt ?? defaultReceiptFallback()) as Partial<ReceiptConfig>,
    );
    const paper: 58 | 80 = r.paper_width_mm === 58 ? 58 : 80;
    const rows = Array.isArray(tx.items) ? (tx.items as Array<Record<string, unknown>>) : [];
    const kotItems = rows.map((i) => ({
        product_name: String(i.product_name ?? ''),
        quantity: (i.quantity as number | string) ?? 0,
        modifiers: i.modifiers as Array<{ label?: string }> | null | undefined,
    }));

    // Jika printer terhubung (Android bridge atau Web BLE), cetak via Bluetooth
    if (isPrinterConnected.value) {
        try {
            const lines = buildKotPlainLines({
                storeName: String(tx.store?.name ?? props.store?.name ?? 'Toko'),
                transactionNumber: String(tx.transaction_number ?? ''),
                completedAt: tx.completed_at ?? null,
                orderType: tx.order_type ?? null,
                tableReference: tx.table_reference ?? null,
                customerName: tx.customer?.name ?? null,
                cashierName: tx.user?.name ?? null,
                items: kotItems,
                paperWidthMm: paper,
            });
            const bytes = receiptLinesToEscPos(lines);
            const printed = await printEscPosBytes(bytes, 'kitchen');
            if (printed) {
                void toastSuccess('KOT berhasil dicetak ke printer Bluetooth.', 3000);
                return;
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : String(e);
            const isPairingError =
                msg.includes('belum dipasangkan') || msg.includes('no longer in range') || msg.includes('GATT') || msg.includes('cancelled');
            if (isPairingError) {
                await modalError(
                    'Gagal Cetak KOT Bluetooth',
                    msg.includes('belum dipasangkan')
                        ? msg
                        : `Koneksi ke printer terputus: ${msg}. Pastikan printer menyala dan dalam jangkauan, lalu hubungkan ulang di Pengaturan → Bluetooth.`,
                );
                return;
            }
            if (import.meta.env.DEV) console.warn('Cetak KOT Bluetooth gagal, fallback ke cetak browser:', msg);
        }
    }

    // Fallback: cetak via browser
    printKitchenTicket({
        storeName: String(tx.store?.name ?? props.store?.name ?? 'Toko'),
        transactionNumber: String(tx.transaction_number ?? ''),
        completedAt: tx.completed_at ?? null,
        orderType: tx.order_type ?? null,
        tableReference: tx.table_reference ?? null,
        customerName: tx.customer?.name ?? null,
        cashierName: tx.user?.name ?? null,
        items: kotItems,
        paperWidthMm: paper,
    });
};

const printCupLabelsFromResponse = () => {
    const tx = transactionData.value;
    if (!tx || !props.store?.cup_label_enabled) {
        return;
    }
    const rows = Array.isArray(tx.items) ? (tx.items as Array<Record<string, unknown>>) : [];
    const cupItems = rows.map((i) => ({
        product_name: String(i.product_name ?? ''),
        quantity: (i.quantity as number | string) ?? 1,
        variant_label: i.variant_name ? String(i.variant_name) : null,
        modifiers: i.modifiers as Array<{ label?: string }> | null | undefined,
        notes: i.notes ? String(i.notes) : null,
    }));

    printCupLabels({
        storeName: String(tx.store?.name ?? props.store?.name ?? 'Toko'),
        transactionNumber: String(tx.transaction_number ?? tx.sale_number ?? ''),
        completedAt: (tx.completed_at as string) ?? (tx.created_at as string) ?? null,
        orderType: (tx.order_type as string) ?? null,
        tableReference: (tx.table_reference as string) ?? null,
        customerName: (tx.customer_name as string) ?? ((tx.customer as Record<string, unknown>)?.name as string) ?? null,
        items: cupItems,
    });
};

const customerPhone = ref('');
const lastCustomerPhone = ref('');
const showWhatsAppField = ref(false);

const sendWhatsAppInvoice = () => {
    const tx = transactionData.value;
    if (!tx) return;
    try {
        const storeName = String(props.store?.receipt?.header_name ?? props.store?.name ?? 'Toko');
        const txNum = String(tx.transaction_number ?? '');
        const date = new Date(String(tx.completed_at ?? tx.created_at ?? '')).toLocaleString('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
        const cashier = String(tx.user?.name ?? tx.cashier_name ?? '');
        const custName = String(tx.customer?.name ?? tx.customer_name ?? '');
        const payLabel = String(tx.payment_method ?? '');
        // ponytail: formatter cached modul — tetap tampilkan sen di struk.
        const fmt = (n: number) => idIDDefaultFormatter.format(n);

        const r = mergeReceiptConfig(
            (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
            (props.store?.receipt ?? defaultReceiptFallback()) as Partial<ReceiptConfig>,
        );

        const lines: string[] = [];

        const logoSrc = r.logo_url
            ? r.logo_url.startsWith('http')
                ? r.logo_url
                : `${window.location.origin}${r.logo_url.startsWith('/') ? '' : '/'}${r.logo_url}`
            : null;

        if (r.show_logo && logoSrc) {
            lines.push(logoSrc);
            lines.push('');
        }
        lines.push(`*${storeName.toUpperCase()}*`);
        if (r.subtitle) lines.push(String(r.subtitle));
        if (r.show_address && r.address) lines.push(String(r.address));
        if (r.show_phone && r.phone) lines.push(`Telp. ${String(r.phone)}`);
        lines.push('─'.repeat(28));
        lines.push(`No. Struk : ${txNum}`);
        lines.push(`Tgl/Jam   : ${date}`);
        lines.push(`Kasir     : ${cashier}`);
        if (custName && custName !== 'Umum') lines.push(`Pelanggan : ${custName}`);
        lines.push('─'.repeat(28));

        const items = Array.isArray(tx.items) ? tx.items : [];
        for (const item of items) {
            const name = String((item as Record<string, unknown>).product_name ?? '');
            const variant = String((item as Record<string, unknown>).variant_name ?? '');
            const qty = Number((item as Record<string, unknown>).quantity ?? 0);
            const price = Number((item as Record<string, unknown>).unit_price ?? 0);
            const total = Number((item as Record<string, unknown>).total_price ?? qty * price);
            const freeQty = Number((item as Record<string, unknown>).free_quantity ?? 0);

            let itemName = name;
            if (variant) itemName += ` (${variant})`;
            lines.push(itemName);
            lines.push(`  ${qty} x ${fmt(price)} = ${fmt(total)}`);

            if (freeQty > 0) {
                lines.push(`  *Gratis ${freeQty} unit*`);
            }
            const mods = (item as Record<string, unknown>).modifiers as Array<{ label?: string; price?: number }> | undefined;
            if (Array.isArray(mods) && mods.length > 0) {
                for (const m of mods) {
                    const mLabel = String(m.label ?? '');
                    const mPrice = Number(m.price ?? 0);
                    lines.push(`  + ${mLabel} ${mPrice > 0 ? fmt(mPrice) : ''}`);
                }
            }
        }

        const preOrderAudit =
            tx.payment_details && typeof tx.payment_details === 'object'
                ? ((tx.payment_details as Record<string, unknown>).pre_order as Record<string, unknown> | undefined)
                : undefined;

        if (preOrderAudit && preOrderAudit.order_number) {
            lines.push('─'.repeat(28));
            lines.push(`Pre-order : ${preOrderAudit.order_number}`);
            lines.push(`Deposit   : ${fmt(Number(preOrderAudit.deposit_applied ?? 0))}`);
            if (Number(preOrderAudit.balance_due) > 0) {
                lines.push(`Sisa Tag  : ${fmt(Number(preOrderAudit.balance_due))}`);
            }
        }

        lines.push('─'.repeat(28));
        lines.push(`Subtotal : *${fmt(Number(tx.subtotal ?? 0))}*`);

        if (Number(tx.discount_amount) > 0) {
            const discName =
                tx.discount && typeof tx.discount === 'object' && (tx.discount as { name?: string }).name
                    ? String((tx.discount as { name?: string }).name)
                    : 'Diskon';
            lines.push(`${discName}   : -${fmt(Number(tx.discount_amount))}`);
        }

        if (Number(tx.service_charge_amount) > 0) {
            lines.push(`Service  : ${fmt(Number(tx.service_charge_amount))}`);
        }

        if (Number(tx.tax_amount) > 0) {
            const taxLabel = String(
                (tx.store && typeof tx.store === 'object' && (tx.store as { tax_name?: string }).tax_name) || props.store?.tax_name || 'PPN',
            );
            lines.push(`${taxLabel}    : ${fmt(Number(tx.tax_amount))}`);
        }

        if (Number(tx.tip_amount) > 0) {
            lines.push(`Tip      : ${fmt(Number(tx.tip_amount))}`);
        }

        lines.push(`*TOTAL    : ${fmt(Number(tx.total_amount ?? 0))}*`);
        lines.push('─'.repeat(28));
        lines.push(`Bayar (${payLabel}) : ${fmt(Number(tx.amount_paid ?? 0))}`);
        if (Number(tx.change_amount) > 0) lines.push(`Kembali   : ${fmt(Number(tx.change_amount))}`);
        lines.push('─'.repeat(28));

        const footer = String(r.footer ?? 'Terima kasih telah berbelanja!');
        lines.push(footer);
        if (r.footer_line2) {
            lines.push(String(r.footer_line2));
        }

        let text = lines.join('\n');

        if (tx.status_token) {
            const pdfUrl = `${window.location.origin}/i/pdf/${tx.status_token}`;
            text += `\n\nUnduh struk PDF:\n${pdfUrl}`;
        }

        let phone = lastCustomerPhone.value.replace(/[^0-9]/g, '');
        // Normalisasi ke format internasional: 08xxx → 628xxx, 8xxx → 628xxx
        if (phone.startsWith('0')) {
            phone = '62' + phone.substring(1);
        } else if (!phone.startsWith('62') && phone.length > 0) {
            phone = '62' + phone;
        }
        const waUrl = phone ? `https://wa.me/${phone}?text=${encodeURIComponent(text)}` : `https://wa.me/?text=${encodeURIComponent(text)}`;

        // WebView Android mem-blokir window.open, jadi biarkan navigasi utama dipancing ke wa.me.
        // Native (shouldOverrideUrlLoading) menangkap URL tersebut lalu membuka via Intent eksternal.
        const isNativeWebView = typeof (window as any)?.BluetoothPrint !== 'undefined';
        if (isNativeWebView) {
            window.location.href = waUrl;
        } else {
            window.open(waUrl, '_blank');
        }
    } catch (e) {
        if (import.meta.env.DEV) console.error('WhatsApp invoice error:', e);
        toastInfo('Gagal menyiapkan pesan WhatsApp.');
    }
};
</script>

<template>
    <AdminLayout title="Point of Sale" :fullscreen="true">
        <!-- ═══ Topbar POS (mode layar penuh) — menggantikan header & sidebar utama ═══ -->
        <div class="sticky top-0 z-30 mb-4 border-b border-slate-200 bg-white/95 px-4 shadow-sm backdrop-blur-md sm:px-6">
            <div class="relative flex h-16 items-center justify-between gap-3">
                <!-- Logo tengah — hanya mobile potret -->
                <img src="/logo-macca.png" alt="logo" class="h-8 w-auto shrink-0 sm:hidden" />
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        type="button"
                        class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900 lg:flex"
                        title="Kembali ke Dashboard"
                        @click="router.visit(dashboard().url)"
                    >
                        <ChevronLeft class="h-5 w-5" />
                    </button>
                    <img src="/logo-macca.png" alt="logo" class="hidden h-9 w-auto sm:block" />
                </div>

                <div class="flex items-center gap-2">
                    <!-- Ringkasan shift + tombol Tutup (tablet/desktop landscape) -->
                    <div
                        v-if="allowsAdvancedRetail && cashShiftOpen"
                        class="relative mr-1 hidden items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 lg:flex"
                    >
                        <span class="relative flex h-2.5 w-2.5 shrink-0">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        </span>
                        <div class="leading-tight">
                            <p class="text-[10px] font-semibold tracking-wide text-emerald-700 uppercase">Shift {{ cashShiftOpen?.shift_number }}</p>
                            <p class="text-xs font-bold text-emerald-900 tabular-nums">{{ formatCurrency(cashShiftOpen?.expected_cash ?? 0) }}</p>
                        </div>
                        <!-- Tombol Tutup Shift -->
                        <button
                            type="button"
                            class="ml-1 rounded-full p-1 text-emerald-600 transition-colors hover:bg-emerald-100 hover:text-emerald-700"
                            @click="closeShiftHandler"
                            title="Tutup shift kasir"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <!-- Open Bills (Pesanan Belum Bayar): tombol + badge jumlah -->
                    <button
                        v-if="openBillEnabled"
                        type="button"
                        class="relative flex h-10 items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-sm font-semibold text-emerald-800 shadow-sm transition-colors hover:bg-emerald-100"
                        title="Pesanan Belum Bayar (Open Bills)"
                        @click="openOpenBillsDrawer"
                    >
                        <ShoppingBag class="h-5 w-5" />
                        <span class="hidden sm:inline">Open Bills</span>
                        <span
                            v-if="openBills.length > 0"
                            class="absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-none font-bold text-white"
                        >
                            {{ openBills.length > 99 ? '99+' : openBills.length }}
                        </span>
                    </button>
                    <!-- Scan Meja: notif badge + banner notifikasi (potret/mobile) -->
                    <Link
                        v-if="scanMejaEnabled && pendingTableOrderCount > 0"
                        href="/table-orders"
                        class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-orange-200 bg-orange-50 text-orange-600 transition-colors hover:bg-orange-100"
                        title="Pesanan masuk"
                    >
                        <Bell class="h-5 w-5" />
                        <span class="absolute -top-1 -right-1 min-w-5 rounded-full bg-red-600 px-1 text-[10px] leading-5 font-bold text-white">{{
                            pendingTableOrderCount > 99 ? '99+' : pendingTableOrderCount
                        }}</span>
                    </Link>
                    <Link
                        href="/pos/transactions"
                        class="hidden h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900 lg:flex"
                        title="Riwayat transaksi"
                    >
                        <ReceiptText class="h-5 w-5" />
                    </Link>
                    <Link
                        href="/dashboard"
                        class="flex h-10 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 shadow-sm transition-colors hover:bg-slate-50 hover:text-slate-900"
                    >
                        Keluar POS
                    </Link>
                </div>
            </div>

            <Link
                v-if="scanMejaEnabled && pendingTableOrderCount > 0"
                href="/table-orders"
                class="-mx-4 flex items-center justify-between gap-2 border-t border-violet-100 bg-violet-50 px-4 py-2 transition-colors hover:bg-violet-100 sm:-mx-6 sm:px-6 lg:hidden"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <span class="relative flex h-2 w-2 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-400 opacity-75" />
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-violet-600" />
                    </span>
                    <p class="truncate text-xs font-bold text-violet-900">{{ pendingTableOrderCount }} pesanan masuk menunggu konfirmasi</p>
                </div>
                <span class="shrink-0 text-[11px] font-bold text-violet-700">Lihat →</span>
            </Link>
        </div>
        <!-- Block POS access when PIN login required -->
        <template v-if="showPinLogin">
            <PinLoginModal
                :cashiers="cashierList"
                :store-name="store?.name ?? ''"
                :dismissible="true"
                @success="onPinLoginSuccess"
                @close="onPinLoginClose"
            />
        </template>

        <!-- POS Content - only shown after PIN login or when PIN mode disabled -->
        <template v-else>
            <!-- Banner offline/syncing (hanya tampil saat tidak online atau ada pending) -->
            <div
                v-if="!isOnline"
                class="mb-3 flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
                role="alert"
                aria-live="assertive"
            >
                <WifiOff class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                Mode offline aktif - transaksi disimpan lokal
                <span v-if="pendingCount > 0" class="font-bold">({{ pendingCount }} pending)</span>
            </div>
            <div
                v-else-if="isSyncing"
                class="mb-3 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                role="status"
            >
                <span class="h-2 w-2 shrink-0 animate-spin rounded-full border border-amber-500 border-t-transparent" />
                Menyinkronkan {{ pendingCount }} transaksi offline...
            </div>

            <div
                v-if="activePreOrderId !== null && activePreOrderNumber"
                class="mx-4 mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:mx-6"
            >
                <p class="font-semibold">Pre-order {{ activePreOrderNumber }}</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-800">
                    Keranjang diisi dari pesanan. Selesaikan pembayaran untuk menutup pre-order. Total mengikuti harga, pajak, dan promo
                    <strong>saat ini</strong> di kasir.
                </p>
                <p v-if="preOrderDepositReceived > 0" class="mt-2 text-xs font-medium text-amber-900 tabular-nums">
                    DP tercatat: {{ formatCurrency(preOrderDepositApplied) }} (maks. sampai total) · Sisa di kasir:
                    {{ formatCurrency(balanceDue) }}
                </p>
            </div>
            <div
                v-if="scanMejaEnabled && pendingTableOrderCount > 0"
                class="mb-4 rounded-lg border border-violet-200 bg-violet-50/80 px-4 py-3 shadow-none lg:hidden"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="rounded-xl bg-violet-600 p-2 text-white"><ShoppingCart class="h-4 w-4" /></div>
                        <div class="min-w-0">
                            <p class="font-bold text-violet-950">Pesanan Masuk Scan Meja</p>
                            <p class="text-xs text-violet-700">{{ pendingTableOrderCount }} pesanan menunggu konfirmasi</p>
                        </div>
                    </div>
                    <Link href="/table-orders" class="shrink-0 rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white hover:bg-violet-700"
                        >Lihat Pesanan</Link
                    >
                </div>
            </div>
            <div
                v-if="accountingPeriodLock"
                class="mx-4 mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:mx-6"
            >
                <p class="font-semibold">Periode akuntansi tertutup — jurnal belum bisa dicatat</p>
                <p class="mt-1 text-xs leading-relaxed text-amber-900">{{ accountingPeriodLock }}</p>
                <Link href="/financial/accounting-periods" class="mt-2 inline-flex text-xs font-bold text-primary hover:underline">
                    Buka pengaturan periode →
                </Link>
            </div>
            <div
                v-if="allowsAdvancedRetail && !cashShiftOpen"
                class="mx-4 mb-4 rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-950 sm:mx-6"
            >
                <p class="font-semibold">Shift kasir belum dibuka</p>
                <p class="mt-1 text-xs leading-relaxed text-orange-900">Transaksi tidak dapat diproses sampai Anda membuka shift kasir.</p>
                <Link href="/cash-shifts" class="mt-2 inline-flex text-xs font-bold text-primary hover:underline">Buka Kasir →</Link>
            </div>
            <div
                v-else-if="allowsAdvancedRetail && cashShiftOpen"
                class="mx-4 mb-4 rounded-2xl border border-emerald-300 bg-gradient-to-r from-emerald-50 to-teal-50 shadow-sm sm:mx-6 lg:hidden"
            >
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <!-- Indicator hijau berkedip -->
                        <span class="relative flex h-3 w-3 shrink-0">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-emerald-900">Shift {{ cashShiftOpen.shift_number }}</p>
                            <p class="text-xs text-emerald-700">Kas awal {{ formatCurrency(cashShiftOpen.opening_cash) }}</p>
                        </div>
                    </div>
                    <Link
                        href="/cash-shifts"
                        class="ml-3 shrink-0 rounded-xl border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm transition-colors hover:bg-emerald-100"
                    >
                        Tutup Kasir
                    </Link>
                </div>
                <div class="flex items-center gap-3 border-t border-emerald-200/60 bg-emerald-100/40 px-4 py-2">
                    <div class="flex-1 text-center">
                        <p class="text-[10px] font-medium tracking-wider text-emerald-600 uppercase">Tunai</p>
                        <p class="text-sm font-bold text-emerald-900 tabular-nums transition-all duration-300">
                            {{ formatCurrency(cashShiftOpen.expected_cash) }}
                        </p>
                    </div>
                    <div class="h-6 w-px bg-emerald-200" />
                    <div class="flex-1 text-center">
                        <p class="text-[10px] font-medium tracking-wider text-emerald-600 uppercase">Non-Tunai</p>
                        <p class="text-sm font-bold text-emerald-900 tabular-nums">{{ formatCurrency(cashShiftOpen.expected_non_cash ?? 0) }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 px-2 pb-16 sm:px-4 lg:h-[calc(100vh-4.5rem)] lg:grid-cols-10 lg:gap-5 lg:pb-4">
                <!-- Product Grid -->
                <div class="lg:col-span-7 lg:h-full lg:min-h-0">
                    <Card class="mb-4 border-0 shadow-lg lg:mb-0 lg:flex lg:h-full lg:flex-col">
                        <CardContent class="pt-4 lg:min-h-0 lg:flex-1 lg:overflow-y-auto">
                            <!-- Barcode Scanner POS -->
                            <div v-if="store.pos_show_barcode === true" class="mb-4 space-y-2 rounded-xl border border-teal-100 bg-teal-50/30 p-3">
                                <div class="flex items-center gap-2">
                                    <ScanLine class="h-4 w-4 shrink-0 text-teal-700" />
                                    <span class="text-sm font-semibold text-teal-800">Scan barcode produk</span>
                                    <span v-if="posBarcodeLookupLoading" class="ml-1 animate-pulse text-xs text-slate-400">Mencari…</span>
                                </div>

                                <div
                                    v-if="posBarcodeScanError"
                                    class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
                                    role="alert"
                                >
                                    <AlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{{ posBarcodeScanError }}</span>
                                </div>

                                <!-- Tombol aktifkan kamera (mobile) -->
                                <button
                                    type="button"
                                    :class="[
                                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                        showBarcodeScannerPosCamera
                                            ? 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100'
                                            : 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100',
                                    ]"
                                    @click="togglePosBarcodeCamera"
                                >
                                    <ScanLine class="h-4 w-4" />
                                    <span>{{ showBarcodeScannerPosCamera ? 'Tutup kamera' : 'Scan via kamera' }}</span>
                                </button>

                                <!-- Scanner kamera: hanya tampil saat tombol diklik -->
                                <BarcodeScanner
                                    v-if="showBarcodeScannerPosCamera"
                                    mode="camera"
                                    :active="showBarcodeScannerPosCamera"
                                    @scanned="handlePosBarcodeScan"
                                    @error="handlePosBarcodeError"
                                />
                            </div>

                            <!-- Scanner USB/keyboard wedge: SELALU dipasang agar alat scan fisik tetap
                                         berfungsi meski tampilan "Scan barcode produk" disembunyikan
                                         (store.pos_show_barcode = false). Mode keyboard tidak menampilkan UI. -->
                            <BarcodeScanner mode="keyboard" :active="true" @scanned="handlePosBarcodeScan" @error="handlePosBarcodeError" />

                            <!-- Search -->
                            <div class="mb-6">
                                <div class="relative">
                                    <Search class="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 transform text-gray-400" />
                                    <Input
                                        v-model="searchQuery"
                                        placeholder="Cari produk, SKU, atau barcode..."
                                        class="h-9 border-gray-300 pl-9 text-sm focus:border-primary/70 focus:ring-primary/70"
                                        @keyup.enter="handleSearchEnter"
                                    />
                                </div>
                            </div>

                            <!-- Category Slider -->
                            <div v-if="props.categories.length > 0" class="relative mb-4">
                                <!-- Left arrow -->
                                <button
                                    v-if="categoryCanScrollLeft"
                                    type="button"
                                    class="absolute top-1/2 left-0 z-10 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-800"
                                    @click="scrollCategory('left')"
                                >
                                    <ChevronLeft class="h-4 w-4" />
                                </button>

                                <div ref="categoryScrollRef" class="scrollbar-hide -mx-1 overflow-x-auto px-1" @scroll="checkCategoryScroll">
                                    <div class="flex gap-2 pb-1">
                                        <button
                                            type="button"
                                            class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-colors"
                                            :class="
                                                activeCategoryId === null
                                                    ? 'border-primary bg-primary text-white'
                                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                                            "
                                            @click="
                                                activeCategoryId = null;
                                                productGridPage = 1;
                                            "
                                        >
                                            Semua
                                        </button>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-colors"
                                            :class="
                                                activeCategoryId === -1
                                                    ? 'border-primary bg-primary text-white'
                                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                                            "
                                            @click="
                                                activeCategoryId = -1;
                                                productGridPage = 1;
                                            "
                                        >
                                            <span class="flex items-center gap-1"><Flame class="h-3.5 w-3.5 text-orange-500" /> Populer</span>
                                        </button>
                                        <button
                                            v-for="cat in props.categories"
                                            :key="cat.id"
                                            type="button"
                                            class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-colors"
                                            :class="
                                                activeCategoryId === cat.id
                                                    ? 'border-primary bg-primary text-white'
                                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                                            "
                                            @click="
                                                activeCategoryId = cat.id;
                                                productGridPage = 1;
                                            "
                                        >
                                            {{ cat.name }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Right arrow -->
                                <button
                                    v-if="categoryCanScrollRight"
                                    type="button"
                                    class="absolute top-1/2 right-0 z-10 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-800"
                                    @click="scrollCategory('right')"
                                >
                                    <ChevronRight class="h-4 w-4" />
                                </button>
                            </div>

                            <!-- Product Grid/List (6 per halaman) -->
                            <div v-if="filteredProducts.length > 0" class="space-y-4">
                                <div class="grid grid-cols-1 gap-2 md:grid-cols-3 md:gap-3 lg:grid-cols-4 2xl:grid-cols-5">
                                    <Card
                                        v-for="product in paginatedProducts"
                                        :key="product.id"
                                        class="cursor-pointer border border-gray-200 py-0 shadow-none transition-all duration-200 hover:border-blue-300 hover:shadow-sm"
                                        @click="requestAddToCart(product)"
                                    >
                                        <CardContent class="p-2 md:p-2.5">
                                            <!-- Mobile List View -->
                                            <div class="flex items-center gap-2.5 md:hidden">
                                                <div
                                                    class="flex h-16 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 sm:h-20 sm:w-20"
                                                >
                                                    <div v-if="product.image_url" class="h-full w-full">
                                                        <img
                                                            :src="product.image_url"
                                                            :alt="product.name"
                                                            loading="lazy"
                                                            decoding="async"
                                                            class="h-full w-full rounded-xl object-cover"
                                                        />
                                                    </div>
                                                    <div v-else class="text-gray-400">
                                                        <Package class="h-6 w-6 sm:h-7 sm:w-7" />
                                                    </div>
                                                </div>

                                                <div class="min-w-0 flex-1">
                                                    <h3 class="mb-0.5 truncate text-sm font-semibold text-gray-900">
                                                        {{ product.name }}
                                                    </h3>
                                                    <Badge
                                                        v-if="product.inventory_type === 'production_output'"
                                                        class="mb-0.5 border-blue-200 bg-blue-50 px-1.5 py-0 text-[10px] text-blue-700"
                                                    >
                                                        Bahan Produksi
                                                    </Badge>
                                                    <div v-if="showPharmacyBadge(product)" class="mb-0.5 flex flex-wrap gap-1">
                                                        <Badge
                                                            :variant="product.pharmacy_sale_class === 'prescription' ? 'destructive' : 'secondary'"
                                                            class="px-1.5 py-0 text-[10px]"
                                                        >
                                                            {{ pharmacySaleLabel(product.pharmacy_sale_class) }}
                                                        </Badge>
                                                    </div>
                                                    <p v-if="product.inventory_type !== 'production_output' && productCardPrice(product)" class="text-base font-bold text-primary">
                                                        <span v-if="productCardPrice(product)!.fromVariant" class="text-xs font-normal text-slate-400">Mulai </span>{{ formatCurrency(productCardPrice(product)!.price) }}
                                                    </p>
                                                    <p v-else-if="product.inventory_type !== 'production_output'" class="text-xs text-slate-400">Pilih varian</p>
                                                    <p v-else class="text-xs text-gray-400">Stok cair</p>
                                                    <Badge
                                                        v-if="product.unlimited_stock"
                                                        class="mt-0.5 border-emerald-200 bg-emerald-100 text-xs text-emerald-700"
                                                    >
                                                        Stok: Unlimited
                                                    </Badge>
                                                    <Badge v-else :class="stockInfo(product).colorClass" class="mt-0.5 border text-xs">
                                                        {{ stockInfo(product).text }}
                                                    </Badge>
                                                </div>

                                                <Button
                                                    v-if="product.inventory_type !== 'production_output'"
                                                    size="sm"
                                                    :disabled="stockInfo(product).disabled"
                                                    @click.stop="requestAddToCart(product)"
                                                    class="flex-shrink-0 self-center bg-primary text-white hover:bg-primary/90"
                                                >
                                                    <Plus class="h-4 w-4" />
                                                </Button>
                                            </div>

                                            <!-- Desktop Grid View -->
                                            <div class="hidden md:block">
                                                <div
                                                    class="mb-2 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg bg-gray-100"
                                                >
                                                    <div v-if="product.image_url" class="h-full w-full">
                                                        <img
                                                            :src="product.image_url"
                                                            :alt="product.name"
                                                            loading="lazy"
                                                            decoding="async"
                                                            class="h-full w-full object-cover"
                                                        />
                                                    </div>
                                                    <div v-else class="text-gray-300">
                                                        <Package class="h-8 w-8" />
                                                    </div>
                                                </div>

                                                <h3 class="mb-0.5 line-clamp-2 min-h-[2em] text-[11px] leading-tight font-semibold text-gray-900">
                                                    {{ product.name }}
                                                </h3>

                                                <!-- Badge: Bahan Produksi (read-only) -->
                                                <Badge
                                                    v-if="product.inventory_type === 'production_output'"
                                                    class="mb-1 border-blue-200 bg-blue-50 px-1.5 py-0 text-[10px] text-blue-700"
                                                >
                                                    Bahan Produksi
                                                </Badge>
                                                <div v-if="showPharmacyBadge(product)" class="mb-1 flex flex-wrap gap-1">
                                                    <Badge
                                                        :variant="product.pharmacy_sale_class === 'prescription' ? 'destructive' : 'secondary'"
                                                        class="px-1.5 py-0 text-[10px]"
                                                    >
                                                        {{ pharmacySaleLabel(product.pharmacy_sale_class) }}
                                                    </Badge>
                                                </div>

                                                <p
                                                    v-if="product.inventory_type !== 'production_output' && productCardPrice(product)"
                                                    class="mb-1.5 text-xs font-bold text-primary"
                                                >
                                                    <span v-if="productCardPrice(product)!.fromVariant" class="font-normal text-slate-400">Mulai </span>{{ formatCurrency(productCardPrice(product)!.price) }}
                                                </p>
                                                <p v-else-if="product.inventory_type !== 'production_output'" class="mb-1.5 text-[10px] text-slate-400">Pilih varian</p>
                                                <p v-else class="mb-1.5 text-[10px] text-gray-400">Stok cair</p>

                                                <div class="flex items-center justify-between gap-1">
                                                    <Badge
                                                        v-if="product.unlimited_stock"
                                                        class="border-emerald-200 bg-emerald-100 px-1 py-0 text-[9px] text-emerald-700"
                                                    >
                                                        Stok: Unlimited
                                                    </Badge>
                                                    <Badge
                                                        v-else
                                                        :class="stockInfo(product).colorClass"
                                                        class="max-w-[calc(100%-2.25rem)] truncate border px-1 py-0 text-[9px]"
                                                    >
                                                        {{ stockInfo(product).text }}
                                                    </Badge>

                                                    <Button
                                                        v-if="product.inventory_type !== 'production_output'"
                                                        size="icon"
                                                        :disabled="stockInfo(product).disabled"
                                                        @click.stop="requestAddToCart(product)"
                                                        class="h-6 w-6 shrink-0 bg-primary text-white hover:bg-primary/90"
                                                    >
                                                        <Plus class="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>

                                <div
                                    v-if="productGridTotalPages > 1"
                                    class="flex flex-col gap-3 border-t border-gray-100 pt-2 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <p class="text-xs text-gray-500 tabular-nums">
                                        Menampilkan
                                        {{ (productGridPageSafe - 1) * productsPerPage + 1 }}–{{
                                            Math.min(productGridPageSafe * productsPerPage, filteredProducts.length)
                                        }}
                                        dari {{ filteredProducts.length }}
                                    </p>
                                    <div class="flex items-center justify-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-9 gap-1"
                                            :disabled="productGridPageSafe <= 1"
                                            @click="productGridPage = Math.max(1, productGridPage - 1)"
                                        >
                                            <ChevronLeft class="h-4 w-4" />
                                            Sebelumnya
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-9 gap-1"
                                            :disabled="productGridPageSafe >= productGridTotalPages"
                                            @click="productGridPage = Math.min(productGridTotalPages, productGridPage + 1)"
                                        >
                                            Berikutnya
                                            <ChevronRight class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Empty State -->
                            <div v-if="filteredProducts.length === 0" class="py-12 text-center">
                                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 p-4">
                                    <Package class="h-8 w-8 text-gray-400" />
                                </div>
                                <p class="text-lg text-gray-500">Tidak ada produk ditemukan</p>
                                <p class="mt-2 text-sm text-gray-400">Coba kata kunci pencarian yang berbeda</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Cart Sidebar -->
                <div class="mb-4 lg:col-span-3 lg:mb-0 lg:h-full lg:min-h-0">
                    <Card
                        :class="
                            [
                                'border-0 shadow-lg',
                                /* desktop: full height sidebar */
                                'lg:flex lg:h-full lg:flex-col',
                                /* mobile: hidden unless sheet open */
                                cartSheetOpen
                                    ? 'fixed top-1/2 left-1/2 z-50 max-h-[80vh] w-[calc(100%-1.5rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl border border-gray-200 shadow-2xl lg:relative lg:inset-auto lg:top-auto lg:left-auto lg:z-auto lg:max-h-none lg:w-auto lg:translate-x-0 lg:translate-y-0 lg:rounded-none lg:border-t lg:border-gray-200 lg:shadow-lg'
                                    : 'hidden lg:flex lg:flex-col',
                            ].join(' ')
                        "
                    >
                        <CardHeader class="pb-2">
                            <div class="flex items-center justify-between">
                                <div>
                                    <CardTitle class="text-xl font-bold text-gray-900">Keranjang</CardTitle>
                                    <CardDescription class="text-gray-600">
                                        {{ cart.length }} item{{ cart.length !== 1 ? 's' : '' }}
                                    </CardDescription>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="rounded-2xl bg-primary/10 p-2">
                                        <ShoppingCart class="h-5 w-5 text-primary" />
                                    </div>
                                    <!-- Tombol tutup: hanya tampil di mobile (sheet mode) -->
                                    <button
                                        type="button"
                                        class="rounded-full p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 lg:hidden"
                                        @click="cartSheetOpen = false"
                                    >
                                        <X class="h-5 w-5" />
                                    </button>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent class="flex-1 space-y-4 lg:flex lg:flex-col lg:overflow-hidden">
                            <!-- Cart Items -->
                            <div class="max-h-[28rem] space-y-3 lg:max-h-none lg:flex-1 lg:overflow-y-auto">
                                <div v-if="cart.length === 0" class="py-8 text-center text-gray-500">
                                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 p-4">
                                        <ShoppingCart class="h-8 w-8 text-gray-400" />
                                    </div>
                                    <p class="text-lg font-medium">Keranjang kosong</p>
                                    <p class="text-sm">Pilih produk untuk memulai</p>
                                </div>

                                <!-- Layout: Thumbnail | Nama+Harga+Qty (vertikal) -->
                                <div
                                    v-for="item in cart"
                                    :key="item.lineId"
                                    class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-2.5"
                                >
                                    <!-- Thumbnail produk -->
                                    <div class="h-12 w-12 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-gray-100">
                                        <img
                                            v-if="item.product.image_url"
                                            :src="item.product.image_url"
                                            :alt="item.product.name"
                                            class="h-full w-full object-cover"
                                        />
                                        <div
                                            v-else
                                            class="flex h-full w-full items-center justify-center bg-gradient-to-br from-gray-200 to-gray-100"
                                        >
                                            <ShoppingCart class="h-5 w-5 text-gray-400" />
                                        </div>
                                    </div>

                                    <!-- Nama produk + Harga + Qty (vertikal) -->
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <h4 class="text-sm leading-tight font-semibold text-gray-900">{{ item.product.name }}</h4>
                                            <template v-if="activePreOrderId !== null">
                                                <span
                                                    class="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-bold text-emerald-800"
                                                >
                                                    <Circle class="h-1.5 w-1.5" />
                                                    OPEN
                                                </span>
                                            </template>
                                        </div>
                                        <p v-if="item.variant" class="mt-0.5 truncate text-[10px] font-medium text-slate-500">
                                            {{ item.variant.name }}
                                        </p>
                                        <ul v-if="item.selectedModifiers.length > 0" class="mt-0.5 space-y-0.5 text-[10px] text-violet-600">
                                            <li v-for="m in item.selectedModifiers" :key="m.key">+ {{ m.label }} (+{{ formatCurrency(m.price) }})</li>
                                        </ul>
                                        <!-- Harga + Kontrol Qty sejajar di bawah -->
                                        <div class="mt-1 flex items-center justify-between gap-2">
                                            <p class="text-sm font-bold text-gray-900 tabular-nums">
                                                {{
                                                    formatCurrency(
                                                        lineUnitPrice(item.product, item.variant) +
                                                            item.selectedModifiers.reduce((s, m) => s + m.price, 0),
                                                    )
                                                }}
                                            </p>
                                            <!-- Kontrol Qty: - | angka | + -->
                                            <div class="flex shrink-0 items-center gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="updateQuantity(item.lineId, item.quantity - qtyStep(item.product))"
                                                    class="h-6 w-6 rounded-full border-gray-300 p-0 hover:border-gray-400 hover:bg-gray-100"
                                                >
                                                    <Minus class="h-3 w-3" />
                                                </Button>

                                                <span class="min-w-[1.5rem] text-center text-sm font-bold text-gray-900 tabular-nums">
                                                    {{ formatCartQty(item.quantity, item.product) }}
                                                </span>

                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    :disabled="
                                                        !lteStock(
                                                            roundCartQty(item.quantity + qtyStep(item.product), item.product),
                                                            lineStock(item.product, item.variant),
                                                            !!item.product.allows_decimal,
                                                        )
                                                    "
                                                    @click="updateQuantity(item.lineId, item.quantity + qtyStep(item.product))"
                                                    class="h-6 w-6 rounded-full border-gray-300 p-0 hover:border-primary hover:bg-primary hover:text-white disabled:opacity-40"
                                                >
                                                    <Plus class="h-3 w-3" />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tombol Lanjut Bayar → buka popup modal checkout -->
                            <div v-if="cart.length > 0 && !checkoutOpen" class="pt-2">
                                <Button
                                    @click="openCheckout"
                                    :disabled="cart.length === 0"
                                    class="h-12 w-full bg-primary font-medium text-white hover:bg-primary/90 disabled:opacity-50"
                                >
                                    <span class="truncate">Lanjut Bayar</span>
                                    <ArrowRight class="ml-1.5 h-4 w-4 shrink-0" />
                                </Button>
                            </div>

                            <!-- ═══ Popup Modal Checkout (ala POS besar Indonesia) ═══ -->
                            <Dialog
                                :open="checkoutOpen"
                                @update:open="
                                    (v) => {
                                        if (!v) closeCheckout();
                                    }
                                "
                            >
                                <DialogContent class="pos-checkout-dialog flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-2xl">
                                    <!-- Tombol X ditangani oleh DialogContent bawaan -->

                                    <!-- ═══ Step 2: Pilih metode pembayaran ═══ -->
                                    <div v-if="paymentStep === 'method'" class="flex-1 space-y-4 overflow-y-auto px-5 pt-12 pb-4">
                                        <!-- Nama Pelanggan -->
                                        <div class="space-y-3">
                                            <Label for="customer" class="text-sm font-medium text-gray-700">Nama Pelanggan (Opsional)</Label>
                                            <Input
                                                id="customer"
                                                v-model="customerName"
                                                placeholder="Masukkan nama pelanggan"
                                                class="h-10 border-gray-300 focus:border-primary/70 focus:ring-primary/70"
                                            />
                                        </div>

                                        <!-- Toggle penjualan kredit -->
                                        <div v-if="receivablesEnabled" class="overflow-hidden rounded-xl border border-gray-200">
                                            <button
                                                type="button"
                                                class="flex w-full items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                                                @click="isCredit = !isCredit"
                                            >
                                                <span class="flex items-center gap-2">
                                                    <span
                                                        :class="[
                                                            'inline-block h-4 w-4 flex-shrink-0 rounded border-2 transition-colors',
                                                            isCredit ? 'border-primary bg-primary' : 'border-gray-400',
                                                        ]"
                                                    >
                                                        <svg v-if="isCredit" viewBox="0 0 16 16" fill="none" class="h-full w-full text-white">
                                                            <path
                                                                d="M3 8l3.5 3.5 6.5-7"
                                                                stroke="currentColor"
                                                                stroke-width="2"
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                            />
                                                        </svg>
                                                    </span>
                                                    Penjualan Kredit / Piutang
                                                </span>
                                                <ChevronDown
                                                    :class="['h-4 w-4 text-gray-400 transition-transform duration-200', isCredit && 'rotate-180']"
                                                />
                                            </button>

                                            <div v-if="isCredit" class="space-y-3 border-t border-gray-100 bg-amber-50/40 px-3 pt-2 pb-3">
                                                <div class="space-y-1.5">
                                                    <Label class="text-sm font-medium text-gray-700">
                                                        Pelanggan <span class="text-red-500">*</span>
                                                    </Label>
                                                    <div class="relative">
                                                        <Input
                                                            v-model="creditCustomerSearch"
                                                            placeholder="Cari nama / nomor pelanggan..."
                                                            class="h-10 border-gray-300 pr-8"
                                                            @input="searchCreditCustomers(creditCustomerSearch)"
                                                            @blur="closeCreditDropdown"
                                                        />
                                                        <div v-if="creditCustomerLoading" class="absolute top-1/2 right-2 -translate-y-1/2">
                                                            <div
                                                                class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent"
                                                            ></div>
                                                        </div>
                                                        <div
                                                            v-if="creditCustomerResults.length > 0"
                                                            class="absolute top-full right-0 left-0 z-50 mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                                                        >
                                                            <button
                                                                v-for="c in creditCustomerResults"
                                                                :key="c.id"
                                                                type="button"
                                                                class="flex w-full flex-col px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                                @mousedown.prevent="selectCreditCustomer(c)"
                                                            >
                                                                <span class="font-medium">{{ c.name }}</span>
                                                                <span v-if="c.phone" class="text-xs text-gray-500">{{ c.phone }}</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <p v-if="creditCustomerId" class="flex items-center gap-1 text-xs text-green-700">
                                                        <svg viewBox="0 0 16 16" fill="none" class="h-3.5 w-3.5 flex-shrink-0">
                                                            <path
                                                                d="M3 8l3.5 3.5 6.5-7"
                                                                stroke="currentColor"
                                                                stroke-width="2"
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                            />
                                                        </svg>
                                                        {{ creditCustomerName }}
                                                    </p>
                                                    <p v-else class="text-xs text-amber-700">
                                                        Wajib dipilih untuk penjualan kredit. Jika pelanggan belum terdaftar, klik tombol di bawah
                                                        untuk mendaftarkan secara instan.
                                                    </p>

                                                    <button
                                                        v-if="!creditCustomerId && !creditCustomerCreating && creditCustomerSearch.trim().length >= 2"
                                                        type="button"
                                                        class="mt-1 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-amber-400 bg-amber-50/60 px-3 py-2 text-sm font-medium text-amber-700 transition-colors hover:bg-amber-100"
                                                        @click="openNewCustomerForm"
                                                    >
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <circle cx="12" cy="12" r="10" />
                                                            <path d="M12 8v8M8 12h8" />
                                                        </svg>
                                                        Daftarkan Pelanggan Baru
                                                    </button>

                                                    <div
                                                        v-if="creditCustomerCreating"
                                                        class="mt-2 space-y-2 rounded-lg border border-amber-300 bg-amber-50/70 p-3"
                                                    >
                                                        <p class="text-xs font-medium text-amber-800">Daftarkan Pelanggan Baru</p>
                                                        <div class="space-y-2">
                                                            <Input
                                                                v-model="newCustomerName"
                                                                placeholder="Nama pelanggan *"
                                                                class="h-9 border-amber-300 bg-white text-sm"
                                                            />
                                                            <Input
                                                                v-model="newCustomerPhone"
                                                                placeholder="Nomor telepon (opsional)"
                                                                class="h-9 border-amber-300 bg-white text-sm"
                                                            />
                                                        </div>
                                                        <div class="flex gap-2">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                class="h-9 flex-1 bg-amber-600 text-sm text-white hover:bg-amber-700"
                                                                :disabled="!newCustomerName.trim() || newCustomerCreating"
                                                                @click="quickCreateCustomer"
                                                            >
                                                                <div
                                                                    v-if="newCustomerCreating"
                                                                    class="mr-1.5 h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"
                                                                ></div>
                                                                {{ newCustomerCreating ? 'Mendaftarkan...' : 'Daftarkan' }}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                class="h-9 border-gray-300 text-sm text-gray-600"
                                                                :disabled="newCustomerCreating"
                                                                @click="
                                                                    creditCustomerCreating = false;
                                                                    newCustomerName = '';
                                                                    newCustomerPhone = '';
                                                                "
                                                            >
                                                                Batal
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="space-y-1.5">
                                                    <Label class="text-sm font-medium text-gray-700">Mode Pembayaran</Label>
                                                    <div class="grid grid-cols-2 gap-1.5">
                                                        <button
                                                            v-for="m in [
                                                                { key: 'partial', label: 'Sebagian' },
                                                                { key: 'unpaid', label: 'Nanti' },
                                                            ]"
                                                            :key="m.key"
                                                            type="button"
                                                            :class="[
                                                                'h-9 rounded-lg border text-xs font-medium transition-colors',
                                                                settlementMode === m.key
                                                                    ? 'border-primary bg-primary text-white'
                                                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50',
                                                            ]"
                                                            @click="settlementMode = m.key as 'partial' | 'unpaid'"
                                                        >
                                                            {{ m.label }}
                                                        </button>
                                                    </div>
                                                    <p class="text-xs text-gray-500">
                                                        <template v-if="settlementMode === 'partial'"
                                                            >Bayar sebagian — sisa menjadi piutang.</template
                                                        >
                                                        <template v-else>Tidak bayar sekarang — seluruh total menjadi piutang.</template>
                                                    </p>
                                                </div>

                                                <div class="space-y-1.5">
                                                    <Label class="text-sm font-medium text-gray-700">Jatuh Tempo</Label>
                                                    <Input v-model="debtDueAt" type="date" class="h-10 border-gray-300" />
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Tipe Pelanggan (wholesale) -->
                                        <div v-if="store.wholesale_enabled" class="space-y-3">
                                            <Label for="customer_type" class="text-sm font-medium text-gray-700">Tipe Pelanggan</Label>
                                            <select
                                                id="customer_type"
                                                v-model="customerType"
                                                class="flex h-10 w-full rounded-md border border-gray-300 bg-background px-3 py-2 text-sm focus:border-primary/70 focus:ring-primary/70"
                                            >
                                                <option value="ecer">Ecer</option>
                                                <option value="grosir">Grosir</option>
                                            </select>
                                            <p class="text-xs text-muted-foreground">Grosir memakai harga grosir produk/varian (bila diisi).</p>
                                        </div>

                                        <!-- WhatsApp -->
                                        <div class="overflow-hidden rounded-xl border border-gray-200">
                                            <button
                                                type="button"
                                                class="flex w-full items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                                                @click="showWhatsAppField = !showWhatsAppField"
                                            >
                                                <span>No. WhatsApp <span class="font-normal text-gray-400">(Opsional)</span></span>
                                                <ChevronDown
                                                    :class="[
                                                        'h-4 w-4 text-gray-400 transition-transform duration-200',
                                                        showWhatsAppField && 'rotate-180',
                                                    ]"
                                                />
                                            </button>
                                            <div v-if="showWhatsAppField" class="border-t border-gray-100 px-3 pb-3">
                                                <Input
                                                    id="customer_phone"
                                                    v-model="customerPhone"
                                                    placeholder="Contoh: 081234567890"
                                                    class="mt-2 h-10 border-gray-300 focus:border-primary/70 focus:ring-primary/70"
                                                />
                                                <p class="mt-1 text-[11px] text-muted-foreground">Untuk mengirim struk via WhatsApp</p>
                                            </div>
                                        </div>

                                        <!-- Apotek -->
                                        <div
                                            v-if="store && store.pharmacy_enabled && (cartHasPrescriptionLine || cartHasLimitedLine)"
                                            class="space-y-2 rounded-xl border border-violet-200 bg-violet-50/70 p-4"
                                        >
                                            <p class="text-xs font-bold tracking-wide text-violet-950 uppercase">Apotek</p>
                                            <Label for="prescription_number" class="text-sm font-medium text-violet-950">
                                                Nomor resep
                                                <span v-if="cartHasPrescriptionLine" class="text-red-600"> (wajib)</span>
                                                <span v-else class="font-normal text-violet-700">(opsional, obat terbatas)</span>
                                            </Label>
                                            <Input
                                                id="prescription_number"
                                                v-model="prescription.number"
                                                class="border-violet-200 focus-visible:ring-violet-400"
                                                placeholder="Contoh: RX-2026-001"
                                            />
                                            <div class="grid gap-2 sm:grid-cols-2">
                                                <Input v-model="prescription.patient_name" placeholder="Nama pasien" />
                                                <Input v-model="prescription.doctor_name" placeholder="Nama dokter" />
                                                <Input v-model="prescription.issued_at" type="date" aria-label="Tanggal resep" />
                                                <Input v-model="prescription.reference" placeholder="Rujukan tambahan" />
                                            </div>
                                            <div class="space-y-1 border-t border-violet-200 pt-2">
                                                <Label for="pharmacy_ref" class="text-xs text-violet-950">Referensi penjualan lama</Label>
                                                <Input
                                                    id="pharmacy_ref"
                                                    v-model="pharmacySaleReference"
                                                    class="border-violet-200 focus-visible:ring-violet-400"
                                                    placeholder="Opsional; dipertahankan untuk kompatibilitas"
                                                />
                                            </div>
                                            <p class="text-[11px] leading-snug text-violet-900">
                                                Snapshot resep tercatat di transaksi untuk audit penjualan. Penjualan obat tetap mematuhi peraturan
                                                setempat; fitur ini tidak menggantikan kewajiban apoteker.
                                                <span v-if="cartHasPrescriptionLine" class="font-semibold">
                                                    Nomor atau rujukan resep wajib diisi.
                                                </span>
                                            </p>
                                        </div>

                                        <Separator />

                                        <!-- Diskon -->
                                        <Collapsible v-model:open="discountOpen">
                                            <CollapsibleTrigger
                                                class="group flex w-full items-center gap-2 text-sm font-medium text-gray-700 transition-colors hover:text-gray-900"
                                            >
                                                <Tag class="h-4 w-4 text-gray-400 group-hover:text-gray-600" />
                                                <span>Kode diskon</span>
                                                <ChevronDown
                                                    class="ml-auto h-3.5 w-3.5 text-gray-400 transition-transform duration-200 group-hover:text-gray-600"
                                                    :class="discountOpen ? 'rotate-180' : ''"
                                                />
                                            </CollapsibleTrigger>
                                            <CollapsibleContent class="pt-3">
                                                <div class="relative">
                                                    <div class="flex gap-2">
                                                        <Input
                                                            v-model="discountCode"
                                                            class="h-10 border-gray-300 font-mono uppercase"
                                                            placeholder="Contoh: HEMAT10"
                                                            @keyup.enter="applyDiscountCode"
                                                            @focus="promoListOpen = true"
                                                            @blur="promoListOpen = false"
                                                        />
                                                        <Button type="button" variant="outline" class="h-10 shrink-0" @click="applyDiscountCode">
                                                            Terapkan
                                                        </Button>
                                                    </div>
                                                    <div
                                                        v-if="promoListOpen && promoCoupons.length"
                                                        class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg"
                                                    >
                                                        <button
                                                            v-for="d in promoCoupons"
                                                            :key="d.id"
                                                            type="button"
                                                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                            @mousedown.prevent="selectPromo(d)"
                                                        >
                                                            <span class="font-mono font-semibold text-gray-900">{{ d.code }}</span>
                                                            <span class="truncate text-xs text-gray-500">{{ d.name }}</span>
                                                        </button>
                                                    </div>
                                                </div>
                                                <p v-if="discountError" class="mt-2 text-xs text-red-600">{{ discountError }}</p>
                                                <p v-else-if="appliedDiscount" class="mt-2 text-xs text-emerald-700">
                                                    {{ appliedDiscount.name }} dipakai.
                                                </p>
                                            </CollapsibleContent>
                                        </Collapsible>

                                        <Separator />

                                        <!-- F&B -->
                                        <div
                                            v-if="store && store.fnb_enabled"
                                            class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4"
                                        >
                                            <p class="text-xs font-bold tracking-wide text-emerald-900 uppercase">F&amp;B</p>
                                            <div v-if="store.fnb_order_types_enabled" class="space-y-2">
                                                <Label class="text-xs text-gray-700">Tipe pesanan</Label>
                                                <div class="flex flex-wrap gap-1.5">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        :variant="orderType === 'dine_in' ? 'default' : 'outline'"
                                                        class="h-8 text-xs"
                                                        @click="orderType = 'dine_in'"
                                                    >
                                                        Makan di tempat
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        :variant="orderType === 'takeaway' ? 'default' : 'outline'"
                                                        class="h-8 text-xs"
                                                        @click="orderType = 'takeaway'"
                                                    >
                                                        Bungkus
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        :variant="orderType === 'delivery' ? 'default' : 'outline'"
                                                        class="h-8 text-xs"
                                                        @click="orderType = 'delivery'"
                                                    >
                                                        Antar
                                                    </Button>
                                                </div>
                                            </div>
                                            <div class="space-y-1">
                                                <Label for="table_ref" class="text-xs">Meja / nomor (opsional)</Label>
                                                <Input id="table_ref" v-model="tableReference" class="h-9 text-sm" placeholder="Contoh: A12" />
                                            </div>
                                            <div v-if="store.fnb_split_bill_enabled" class="mt-1 space-y-2 border-t border-emerald-200/60 pt-3">
                                                <Label for="split_n" class="text-xs">Bagi tagihan (opsional)</Label>
                                                <Input
                                                    id="split_n"
                                                    v-model.number="splitBillCount"
                                                    type="number"
                                                    min="1"
                                                    max="20"
                                                    step="1"
                                                    class="h-9 max-w-[7rem] text-sm"
                                                />
                                                <p class="text-[11px] leading-snug text-emerald-900/85">
                                                    <strong>1</strong> = tidak dibagi. <strong>2–20</strong> = total dibagi rata (rupiah); pembayaran
                                                    di kasir tetap <strong>satu kali</strong> penuh — untuk patungan tamu di meja.
                                                </p>
                                                <div
                                                    v-if="splitBillPreview"
                                                    class="space-y-1 rounded-lg border border-emerald-100 bg-white/90 px-2.5 py-2 text-[11px]"
                                                >
                                                    <p class="font-bold text-emerald-950">Nominal per bagian</p>
                                                    <ul class="space-y-0.5 text-emerald-900 tabular-nums">
                                                        <li v-for="(a, i) in splitBillPreview.shares" :key="i">
                                                            Bagian {{ i + 1 }}: {{ formatCurrency(a) }}
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Ringkasan Harga -->
                                        <div class="space-y-3">
                                            <div class="flex justify-between text-sm">
                                                <span class="text-gray-600">Subtotal:</span>
                                                <span class="font-medium">{{ formatCurrency(subtotal) }}</span>
                                            </div>
                                            <div v-if="discountAmount > 0" class="flex justify-between text-sm text-emerald-700">
                                                <span>Diskon:</span>
                                                <span class="font-medium">−{{ formatCurrency(discountAmount) }}</span>
                                            </div>
                                            <div v-if="store && store.fnb_enabled && serviceChargeAmount > 0" class="flex justify-between text-sm">
                                                <span class="text-gray-600">Biaya layanan:</span>
                                                <span class="font-medium">{{ formatCurrency(serviceChargeAmount) }}</span>
                                            </div>
                                            <div v-if="store && store.tax_enabled === true" class="flex justify-between text-sm">
                                                <span class="text-gray-600">{{ store.tax_name }} ({{ store.tax_rate }}%):</span>
                                                <span class="font-medium">{{ formatCurrency(taxAmount) }}</span>
                                            </div>
                                            <div v-if="store && store.fnb_enabled && tipAmount > 0" class="flex justify-between text-sm">
                                                <span class="text-gray-600">Tip:</span>
                                                <span class="font-medium">{{ formatCurrency(tipAmount) }}</span>
                                            </div>
                                            <Separator />
                                            <div class="flex justify-between text-lg font-bold">
                                                <span class="text-gray-900">Total:</span>
                                                <span class="text-primary">{{ formatCurrency(totalAmount) }}</span>
                                            </div>
                                            <div
                                                v-if="activePreOrderId !== null && preOrderDepositApplied > 0"
                                                class="space-y-1 rounded-xl border border-amber-200/80 bg-amber-50/90 px-3 py-2 text-sm"
                                            >
                                                <div class="flex justify-between text-amber-950">
                                                    <span>DP pre-order</span>
                                                    <span class="font-medium tabular-nums">−{{ formatCurrency(preOrderDepositApplied) }}</span>
                                                </div>
                                                <div class="flex justify-between font-bold text-amber-950">
                                                    <span>Sisa bayar</span>
                                                    <span class="text-primary tabular-nums">{{ formatCurrency(balanceDue) }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Sumber Pesanan -->
                                        <div v-if="props.store?.online_sales_enabled === true || props.store?.fnb_enabled" class="space-y-2">
                                            <Label class="text-sm font-medium text-gray-700">Sumber Pesanan</Label>
                                            <div class="space-y-2">
                                                <div v-for="grp in channelOptions" :key="grp.group" class="space-y-1.5">
                                                    <p class="text-xs font-medium text-gray-500">{{ grp.group }}</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button
                                                            v-for="opt in grp.items"
                                                            :key="opt.value"
                                                            type="button"
                                                            :class="[
                                                                'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                                                salesChannel === opt.value
                                                                    ? 'border-primary bg-primary text-white'
                                                                    : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400 hover:bg-gray-50',
                                                            ]"
                                                            @click="
                                                                salesChannel = opt.value;
                                                                onSalesChannelChange();
                                                            "
                                                        >
                                                            {{ opt.label }}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                v-if="salesChannel in defaultCommissionByChannel"
                                                class="flex items-center gap-2 rounded-md bg-orange-50 px-3 py-2 text-xs text-orange-800"
                                            >
                                                <span>Komisi {{ commissionPercent }}%</span>
                                                <span class="text-orange-600"
                                                    >≈ Rp {{ formatCurrency((totalAmount * (commissionPercent || 0)) / 100) }}</span
                                                >
                                            </div>
                                        </div>

                                        <!-- Metode Pembayaran -->
                                        <div v-if="!isCredit" class="space-y-3">
                                            <Label class="text-sm font-medium text-gray-700">Metode Pembayaran</Label>
                                            <div class="grid grid-cols-2 gap-2">
                                                <Button
                                                    :variant="paymentMethod === 'cash' ? 'default' : 'outline'"
                                                    size="sm"
                                                    @click="
                                                        paymentMethod = 'cash';
                                                        confirmPaymentMethod();
                                                    "
                                                    class="h-10"
                                                >
                                                    <Banknote class="mr-2 h-4 w-4" />
                                                    Tunai
                                                </Button>
                                                <Button
                                                    :variant="paymentMethod === 'ewallet' ? 'default' : 'outline'"
                                                    size="sm"
                                                    @click="paymentMethod = 'ewallet'"
                                                    class="h-10"
                                                >
                                                    <Smartphone class="mr-2 h-4 w-4" />
                                                    E-Wallet
                                                </Button>
                                                <Button
                                                    :variant="paymentMethod === 'other' ? 'default' : 'outline'"
                                                    size="sm"
                                                    class="col-span-2 h-10"
                                                    @click="paymentMethod = 'other'"
                                                >
                                                    <CircleDollarSign class="mr-2 h-4 w-4" />
                                                    Lainnya
                                                </Button>
                                            </div>
                                        </div>

                                        <!-- Tombol Konfirmasi -->
                                        <Button
                                            @click="confirmPaymentMethod"
                                            :disabled="
                                                !paymentMethod ||
                                                !!accountingPeriodLock ||
                                                (allowsAdvancedRetail && !cashShiftOpen) ||
                                                (isCredit && !creditReadyToProcess) ||
                                                isProcessingPayment ||
                                                (cartHasPrescriptionLine &&
                                                    !String(pharmacySaleReference).trim() &&
                                                    !prescription.reference.trim() &&
                                                    !prescription.number.trim())
                                            "
                                            class="h-12 w-full bg-primary font-medium text-white hover:bg-primary/90 disabled:opacity-50"
                                        >
                                            <div
                                                v-if="isProcessingPayment"
                                                class="mr-1.5 h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-white border-t-transparent"
                                            ></div>
                                            <span class="truncate">
                                                {{
                                                    isProcessingPayment
                                                        ? 'Memproses...'
                                                        : requiresCashInput
                                                          ? isCredit
                                                              ? 'Lanjut Bayar Parsial'
                                                              : 'Lanjut ke Pembayaran'
                                                          : isCredit
                                                            ? 'Proses Transaksi'
                                                            : 'Proses Pembayaran'
                                                }}
                                            </span>
                                            <ArrowRight v-if="!isProcessingPayment" class="ml-1.5 h-4 w-4 shrink-0" />
                                        </Button>
                                    </div>

                                    <!-- ═══ Step 3: Input nominal tunai & proses ═══ -->
                                    <div
                                        v-if="paymentStep === 'pay'"
                                        class="flex flex-1 flex-col overflow-y-auto px-3 pt-12 pb-3 sm:overflow-y-auto sm:px-5 sm:pb-4"
                                    >
                                        <div class="flex min-h-0 flex-1 flex-col gap-3 sm:flex-row sm:gap-4">
                                            <!-- Kolom kiri: Total + Quick Cash + Input + Kembalian -->
                                            <div class="flex min-w-0 flex-1 flex-col gap-2 sm:gap-4">
                                                <!-- Total tagihan -->
                                                <div
                                                    class="flex items-center justify-between rounded-lg bg-red-500 px-3 py-2 text-white sm:rounded-xl sm:px-5 sm:py-4"
                                                >
                                                    <span class="text-sm font-medium sm:text-base">Total tagihan</span>
                                                    <span class="text-lg font-bold tabular-nums sm:text-xl">{{ formatCurrency(balanceDue) }}</span>
                                                </div>

                                                <!-- Quick cash buttons -->
                                                <div class="grid grid-cols-3 gap-1.5 sm:grid-cols-2 sm:gap-2">
                                                    <button
                                                        type="button"
                                                        @click="applyCashSuggestion(balanceDue)"
                                                        :class="[
                                                            'rounded-lg border px-2 py-2 text-xs font-medium transition-colors sm:px-4 sm:py-3 sm:text-base',
                                                            amountPaid === balanceDue
                                                                ? 'border-primary bg-primary/10 text-primary'
                                                                : 'border-gray-200 text-gray-700 hover:bg-gray-50',
                                                        ]"
                                                    >
                                                        Uang Pas
                                                    </button>
                                                    <button
                                                        v-for="nominal in cashSuggestions"
                                                        :key="nominal"
                                                        type="button"
                                                        @click="applyCashSuggestion(nominal)"
                                                        :class="[
                                                            'rounded-lg border px-2 py-2 text-xs font-medium tabular-nums transition-colors sm:px-4 sm:py-3 sm:text-base',
                                                            amountPaid === nominal
                                                                ? 'border-primary bg-primary/10 text-primary'
                                                                : 'border-gray-200 text-gray-700 hover:bg-gray-50',
                                                        ]"
                                                    >
                                                        Rp {{ formatCurrency(nominal) }}
                                                    </button>
                                                </div>

                                                <!-- Input Uang Diterima -->
                                                <div class="space-y-1">
                                                    <label class="text-xs font-medium tracking-wider text-gray-500 uppercase">Uang Diterima</label>
                                                    <input
                                                        type="text"
                                                        readonly
                                                        :value="amountPaid > 0 ? formatCurrency(amountPaid) : '—'"
                                                        :class="[
                                                            'w-full rounded-lg border-2 bg-white px-3 py-2.5 text-right text-xl font-bold tabular-nums transition-colors sm:rounded-xl sm:px-5 sm:py-4 sm:text-2xl',
                                                            amountPaid > 0 ? 'border-primary text-primary' : 'border-gray-300 text-gray-400',
                                                        ]"
                                                    />
                                                </div>

                                                <!-- Kembalian -->
                                                <div
                                                    class="flex items-center justify-between rounded-lg border border-green-200 bg-green-50 px-3 py-2 sm:rounded-xl sm:px-5 sm:py-4"
                                                >
                                                    <span class="text-sm font-medium text-green-700 sm:text-base">Kembalian</span>
                                                    <span class="text-lg font-bold text-green-600 tabular-nums sm:text-xl">{{
                                                        formatCurrency(changeAmount)
                                                    }}</span>
                                                </div>
                                            </div>

                                            <!-- Kolom kanan: Numpad 3x4 Grid -->
                                            <div class="mx-auto flex w-full max-w-[22rem] shrink-0 flex-col gap-1.5 sm:mx-0 sm:w-72 sm:gap-2.5">
                                                <!-- Row 1-3: Angka 1-9 -->
                                                <template
                                                    v-for="row in [
                                                        [1, 2, 3],
                                                        [4, 5, 6],
                                                        [7, 8, 9],
                                                    ]"
                                                    :key="row[0]"
                                                >
                                                    <div class="grid grid-cols-3 gap-1.5 sm:gap-2.5">
                                                        <button
                                                            v-for="n in row"
                                                            :key="n"
                                                            type="button"
                                                            @click="numpadInput(String(n))"
                                                            class="h-11 rounded-lg border-2 border-gray-300 bg-white text-xl font-bold text-gray-800 transition-all hover:border-primary/30 hover:bg-gray-50 active:bg-gray-100 sm:h-14 sm:rounded-xl sm:text-2xl"
                                                        >
                                                            {{ n }}
                                                        </button>
                                                    </div>
                                                </template>

                                                <!-- Row 4: 000, 0, ⌫ -->
                                                <div class="grid grid-cols-3 gap-1.5 sm:gap-2.5">
                                                    <button
                                                        type="button"
                                                        @click="numpadInput('000')"
                                                        class="h-11 rounded-lg border-2 border-gray-300 bg-white text-base font-bold text-gray-800 transition-all hover:border-primary/30 hover:bg-gray-50 active:bg-gray-100 sm:h-14 sm:rounded-xl sm:text-lg"
                                                    >
                                                        000
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="numpadInput('0')"
                                                        class="h-11 rounded-lg border-2 border-gray-300 bg-white text-xl font-bold text-gray-800 transition-all hover:border-primary/30 hover:bg-gray-50 active:bg-gray-100 sm:h-14 sm:rounded-xl sm:text-2xl"
                                                    >
                                                        0
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="numpadBackspace()"
                                                        class="flex h-11 items-center justify-center rounded-lg border-2 border-gray-300 bg-white text-gray-800 transition-all hover:border-red-300 hover:bg-red-50 active:bg-red-100 sm:h-14 sm:rounded-xl"
                                                    >
                                                        <svg
                                                            class="mx-auto h-5 w-5 sm:h-6 sm:w-6"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="2"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                        >
                                                            <path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z" />
                                                            <line x1="18" y1="9" x2="12" y2="15" />
                                                            <line x1="12" y1="9" x2="18" y2="15" />
                                                        </svg>
                                                    </button>
                                                </div>

                                                <!-- Row 5: ← (kembali) + Selesaikan (span 2 cols) -->
                                                <div class="grid grid-cols-3 gap-1.5 sm:gap-2.5">
                                                    <button
                                                        type="button"
                                                        @click="paymentStep = 'method'"
                                                        :disabled="isProcessingPayment"
                                                        class="flex h-11 items-center justify-center rounded-lg border-2 border-gray-300 bg-white text-gray-600 transition-all hover:border-gray-400 hover:bg-gray-50 active:bg-gray-100 disabled:opacity-50 sm:h-14 sm:rounded-xl"
                                                    >
                                                        <ArrowLeft class="mx-auto h-5 w-5 sm:h-6 sm:w-6" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="processPayment"
                                                        :disabled="
                                                            !!accountingPeriodLock ||
                                                            (allowsAdvancedRetail && !cashShiftOpen) ||
                                                            (!isCredit && amountPaid < balanceDue) ||
                                                            !creditReadyToProcess ||
                                                            isProcessingPayment ||
                                                            (cartHasPrescriptionLine &&
                                                                !String(pharmacySaleReference).trim() &&
                                                                !prescription.reference.trim() &&
                                                                !prescription.number.trim())
                                                        "
                                                        class="col-span-2 flex h-11 items-center justify-center gap-2 rounded-lg bg-primary font-bold text-white transition-all hover:bg-primary/90 disabled:opacity-50 sm:h-14 sm:rounded-xl"
                                                    >
                                                        <CheckCircle2 v-if="!isProcessingPayment" class="h-5 w-5 shrink-0 sm:h-6 sm:w-6" />
                                                        <div
                                                            v-else
                                                            class="h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-white border-t-transparent"
                                                        ></div>
                                                        <span class="text-base sm:text-lg">{{
                                                            isProcessingPayment ? 'Memproses...' : 'Selesaikan'
                                                        }}</span>
                                                    </button>
                                                </div>

                                                <!-- Simpan Order (open bill) -->
                                                <button
                                                    v-if="openBillEnabled"
                                                    type="button"
                                                    @click="openSaveOrderDrawer"
                                                    :disabled="isProcessingPayment"
                                                    class="flex h-11 w-full items-center justify-center gap-1.5 rounded-lg border-2 border-emerald-400 bg-emerald-50 text-sm font-semibold text-emerald-800 transition-all hover:bg-emerald-100 disabled:opacity-50 sm:h-14 sm:rounded-xl"
                                                >
                                                    <Save class="h-4 w-4 shrink-0" />
                                                    Simpan Order
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Ringkas Harga di bawah numpad -->
                                        <div class="mt-2 shrink-0 space-y-1 text-xs text-gray-600 sm:mt-3 sm:text-sm">
                                            <div v-if="discountAmount > 0" class="flex justify-between text-emerald-700">
                                                <span>Diskon:</span>
                                                <span class="tabular-nums">-{{ formatCurrency(discountAmount) }}</span>
                                            </div>
                                            <div v-if="store?.tax_enabled === true" class="flex justify-between">
                                                <span>{{ store.tax_name }} ({{ store.tax_rate }}%):</span>
                                                <span class="tabular-nums">{{ formatCurrency(taxAmount) }}</span>
                                            </div>
                                            <div
                                                v-if="activePreOrderId !== null && preOrderDepositApplied > 0"
                                                class="flex justify-between text-amber-700"
                                            >
                                                <span>DP pre-order:</span>
                                                <span class="tabular-nums">-{{ formatCurrency(preOrderDepositApplied) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </DialogContent>
                            </Dialog>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- ═══ Mobile: Floating Cart Popup ═══ -->
            <div
                v-if="cart.length > 0"
                class="fixed inset-x-0 bottom-24 z-40 px-3 lg:hidden"
                :style="{ paddingBottom: 'env(safe-area-inset-bottom)' }"
            >
                <button
                    type="button"
                    class="relative flex w-full items-center justify-between gap-3 rounded-2xl bg-violet-500 px-5 py-3.5 shadow-[0_-2px_24px_rgba(139,92,246,0.25)]"
                    @click="cartSheetOpen = true"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="relative shrink-0">
                            <ShoppingCart class="h-6 w-6 text-white" />
                            <span
                                class="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-white text-[10px] leading-none font-bold text-violet-600"
                            >
                                {{ cart.length }}
                            </span>
                        </div>
                        <span class="truncate text-base font-semibold text-white"> {{ cart.length }} item{{ cart.length !== 1 ? 's' : '' }} </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-bold text-white tabular-nums">
                            {{ formatCurrency(totalAmount) }}
                        </span>
                        <ChevronUp class="h-5 w-5 text-white/80" />
                    </div>
                </button>
            </div>

            <!-- ═══ Mobile: Cart Sheet Overlay ═══ -->
            <Teleport to="body">
                <div v-if="cartSheetOpen" class="fixed inset-0 z-40 bg-slate-900/25 backdrop-blur-md lg:hidden" @click="cartSheetOpen = false" />
            </Teleport>

            <!-- Pilih varian produk -->
            <Teleport to="body">
                <div
                    v-if="variantPickerOpen && variantPickerProduct"
                    class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    @click.self="cancelVariantPicker"
                >
                    <div class="w-full max-w-sm space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" @click.stop>
                        <div>
                            <h3 class="font-bold text-slate-900">{{ variantPickerProduct.name }}</h3>
                            <p class="mt-1 text-xs text-slate-500">Pilih salah satu varian.</p>
                        </div>
                        <div class="max-h-64 space-y-2 overflow-y-auto">
                            <button
                                v-for="v in variantPickerProduct.variants ?? []"
                                :key="v.id"
                                type="button"
                                class="flex w-full flex-col items-stretch gap-1 rounded-xl border border-slate-200 px-3 py-2.5 text-left text-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="lineStock(variantPickerProduct, v) <= 0"
                                @click="confirmVariantPick(v)"
                            >
                                <span class="font-semibold text-slate-900">{{ v.name }}</span>
                                <span class="text-xs text-slate-600">
                                    {{ formatCurrency(lineUnitPrice(variantPickerProduct, v)) }}
                                    · {{ stockDisplay(variantPickerProduct, v).text }}
                                </span>
                            </button>
                        </div>
                        <Button variant="outline" class="w-full" type="button" @click="cancelVariantPicker"> Batal </Button>
                    </div>
                </div>
            </Teleport>

            <!-- Pilih modifier produk F&B -->
            <Teleport to="body">
                <div
                    v-if="modifierPickerOpen && modifierPickerProduct"
                    class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    @click.self="cancelModifierPicker"
                >
                    <div class="w-full max-w-sm space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" @click.stop>
                        <div>
                            <h3 class="font-bold text-slate-900">
                                {{ modifierPickerProduct.name }}{{ pendingVariantForModifier ? ` — ${pendingVariantForModifier.name}` : '' }}
                            </h3>
                            <p class="mt-1 text-xs text-slate-500">Pilih tambahan (boleh tidak pilih).</p>
                        </div>
                        <div class="max-h-56 space-y-2 overflow-y-auto">
                            <label
                                v-for="opt in normalizedModifierOptions(modifierPickerProduct)"
                                :key="opt.key"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-100 p-2 text-sm hover:bg-slate-50"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                    :checked="modifierPickerSelectedKeys.includes(opt.key)"
                                    @change="toggleModifierPickerKey(opt.key)"
                                />
                                <span class="flex-1">{{ opt.label }}</span>
                                <span class="font-medium text-emerald-700">+{{ formatCurrency(opt.price) }}</span>
                            </label>
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" class="flex-1" type="button" @click="cancelModifierPicker"> Batal </Button>
                            <Button class="flex-1" type="button" @click="confirmModifierPicker"> Tambah </Button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- Struk / ringkasan transaksi -->
            <Teleport to="body">
                <div
                    v-if="showInvoicePopup && transactionData"
                    class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="pos-invoice-title"
                    @click.self="closeInvoicePopup"
                >
                    <div class="pos-invoice-popup max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl" @click.stop>
                        <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-4">
                            <div class="flex items-center gap-2">
                                <CheckCircle2 class="h-6 w-6 shrink-0 text-emerald-500" aria-hidden="true" />
                                <h2 id="pos-invoice-title" class="text-lg font-black text-slate-900">Transaksi Berhasil</h2>
                            </div>
                            <Button variant="ghost" size="sm" class="shrink-0 rounded-xl" @click="closeInvoicePopup"> Tutup </Button>
                        </div>
                        <div class="px-4 pt-3 pb-2">
                            <ThermalReceiptStruk
                                :transaction="transactionData as unknown as Record<string, unknown>"
                                :store-receipt-fallback="store.receipt"
                                :tax-name-fallback="store.tax_name"
                                :pharmacy-enabled="store.pharmacy_enabled === true"
                            />
                        </div>
                        <div class="flex flex-col gap-2 p-4 pt-2">
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <Button class="flex-1 gap-2 rounded-xl" type="button" :disabled="isPrinterPrinting" @click="printInvoice">
                                    <Printer class="h-4 w-4" />
                                    {{ isPrinterPrinting ? 'Mencetak…' : 'Cetak struk' }}
                                </Button>
                                <Button class="flex-1 gap-2 rounded-xl" variant="outline" type="button" @click="sendWhatsAppInvoice">
                                    <MessageCircle class="h-4 w-4" />
                                    WhatsApp
                                </Button>
                                <Button
                                    v-if="store?.fnb_enabled"
                                    type="button"
                                    variant="secondary"
                                    class="flex-1 gap-2 rounded-xl"
                                    @click="printKitchenTicketFromResponse"
                                >
                                    <ChefHat class="h-4 w-4" />
                                    Cetak KOT
                                </Button>
                                <Button
                                    v-if="store?.cup_label_enabled"
                                    type="button"
                                    variant="secondary"
                                    class="flex-1 gap-2 rounded-xl"
                                    @click="printCupLabelsFromResponse"
                                >
                                    <Tag class="h-4 w-4" />
                                    Cetak Stiker Cup
                                </Button>
                            </div>
                            <Button variant="outline" class="w-full rounded-xl" @click="closeInvoicePopup"> Selesai </Button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- ═══ Open Bills Modal ═══ -->
            <Teleport to="body">
                <div
                    v-if="openBillsDrawerOpen"
                    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    @click.self="closeOpenBillsDrawer"
                >
                    <div class="fixed inset-0 bg-slate-900/30 backdrop-blur-sm" @click="closeOpenBillsDrawer" />
                    <div
                        class="relative flex h-[calc(100vh-2rem)] max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl lg:w-[36rem]"
                        @click.stop
                    >
                        <div
                            class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur"
                        >
                            <div class="flex items-center gap-2">
                                <ShoppingBag class="h-5 w-5 text-emerald-600" />
                                <h3 class="font-bold text-slate-900">Pesanan Belum Bayar</h3>
                                <span
                                    class="flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-100 px-1.5 text-xs font-bold text-emerald-700"
                                >
                                    {{ openBills.length }}
                                </span>
                            </div>
                            <Button variant="ghost" size="icon" @click="closeOpenBillsDrawer" aria-label="Tutup">
                                <X class="h-5 w-5" />
                            </Button>
                        </div>

                        <div class="flex-1 space-y-2 overflow-y-auto p-3">
                            <div class="px-2">
                                <label for="ob-search" class="sr-only">Cari pesanan</label>
                                <Input
                                    id="ob-search"
                                    v-model="openBillsSearch"
                                    placeholder="Cari meja / nomor / pelanggan…"
                                    class="h-9 w-full text-sm"
                                    @keyup.enter="fetchOpenBills"
                                />
                            </div>

                            <template v-if="openBillsLoading">
                                <div class="flex items-center justify-center py-8">
                                    <Loader2 class="h-6 w-6 animate-spin text-emerald-600" />
                                </div>
                            </template>

                            <template v-else-if="openBills.length === 0">
                                <div class="flex flex-col items-center justify-center py-12 text-center text-slate-500">
                                    <ShoppingBag class="h-12 w-12 text-slate-300" />
                                    <p class="mt-3 font-medium">Belum ada pesanan terbuka</p>
                                    <p class="mt-1 text-xs">Pesanan akan muncul di sini setelah disimpan sebagai "Belum Bayar"</p>
                                </div>
                            </template>

                            <template v-else>
                                <ul class="divide-y divide-slate-100" role="list">
                                    <li
                                        v-for="bill in openBills"
                                        :key="bill.id"
                                        class="group relative rounded-xl px-2 py-3 transition-colors hover:bg-slate-50"
                                        :class="{ 'bg-emerald-50 ring-1 ring-emerald-200': openBillsSelectedId === bill.id }"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <span
                                                    v-if="bill.customer_name"
                                                    class="block max-w-[14rem] truncate text-sm font-semibold text-emerald-700"
                                                >
                                                    {{ bill.customer_name }}
                                                </span>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-mono text-sm font-bold text-slate-900">
                                                        {{ bill.transaction_number }}
                                                    </span>
                                                    <template v-if="bill.table_reference">
                                                        <span
                                                            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                                                        >
                                                            <Table2 class="h-3 w-3" />
                                                            Meja {{ bill.table_reference }}
                                                        </span>
                                                    </template>
                                                    <template v-if="bill.order_type">
                                                        <span class="text-[10px] font-medium tracking-wider text-slate-500 uppercase">
                                                            {{ bill.order_type.replace('_', ' ') }}
                                                        </span>
                                                    </template>
                                                </div>
                                                <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                    <span class="flex items-center gap-1">
                                                        <Clock class="h-3 w-3" />
                                                        {{ bill.elapsed_minutes }} menit lalu
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-2 flex-shrink-0 text-right">
                                                <p class="font-bold text-slate-900 tabular-nums">
                                                    {{ formatCurrency(bill.total_amount) }}
                                                </p>
                                                <p class="text-[10px] text-slate-400">{{ bill.items_count }} item</p>
                                            </div>
                                        </div>

                                        <div class="mt-2 flex items-center gap-2 border-t border-slate-100 pt-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                class="flex-1 gap-1.5"
                                                @click="loadOpenBillToCart(bill)"
                                                :disabled="openBillsLoading"
                                            >
                                                <ArrowRight class="h-3.5 w-3.5" />
                                                Lanjut Bayar
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                class="text-red-600 hover:bg-red-50 hover:text-red-700"
                                                @click="cancelOpenBill(bill)"
                                                :disabled="openBillsLoading"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- ═══ Save Order Drawer (Simpan & Cetak KOT) ═══ -->
            <Teleport to="body">
                <div
                    v-if="saveOrderDrawerOpen"
                    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    @click.self="saveOrderDrawerOpen = false"
                >
                    <div class="fixed inset-0 bg-slate-900/30 backdrop-blur-sm" @click="saveOrderDrawerOpen = false" />
                    <div
                        class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-md flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl lg:w-[28rem]"
                        @click.stop
                    >
                        <div
                            class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur"
                        >
                            <div class="flex items-center gap-2">
                                <Printer class="h-5 w-5 text-emerald-600" />
                                <h3 class="font-bold text-slate-900">Simpan Order</h3>
                            </div>
                            <Button variant="ghost" size="icon" @click="saveOrderDrawerOpen = false" aria-label="Tutup">
                                <X class="h-5 w-5" />
                            </Button>
                        </div>

                        <div class="flex-1 space-y-4 overflow-y-auto p-4">
                            <!-- Ringkasan item -->
                            <div class="max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                                <p class="text-xs font-medium text-slate-600">Ringkasan Pesanan ({{ saveOrderForm.items.length }} item)</p>
                                <ul class="space-y-1.5">
                                    <li v-for="(it, idx) in saveOrderForm.items" :key="idx" class="flex items-center justify-between gap-2 text-sm">
                                        <span class="truncate font-medium text-slate-900"
                                            >{{ it.quantity }}× {{ it.product_name }}{{ it.variant_label ? ` — ${it.variant_label}` : '' }}</span
                                        >
                                        <span class="text-slate-600 tabular-nums">{{ formatCurrency(it.unit_price * it.quantity) }}</span>
                                    </li>
                                </ul>
                                <Separator />
                                <div class="flex justify-between font-medium text-slate-900">
                                    <span>Total</span>
                                    <span>{{ formatCurrency(saveOrderForm.items.reduce((s, i) => s + i.unit_price * i.quantity, 0)) }}</span>
                                </div>
                            </div>

                            <!-- Form -->
                            <div class="space-y-3">
                                <div>
                                    <Label for="so-table" class="text-xs font-medium"
                                        >Nomor Meja <span v-if="store?.fnb_enabled" class="text-red-500">*</span></Label
                                    >
                                    <Input
                                        id="so-table"
                                        v-model="saveOrderForm.table_reference"
                                        type="text"
                                        :placeholder="store?.fnb_enabled ? 'Contoh: 4, A1, VIP-2' : 'Opsional — contoh: A1, Meja 4'"
                                        class="h-10 text-base"
                                        maxlength="64"
                                    />
                                </div>
                                <div>
                                    <Label for="so-customer" class="text-xs font-medium">Nama Pelanggan (opsional)</Label>
                                    <Input
                                        id="so-customer"
                                        v-model="saveOrderForm.customer_name"
                                        type="text"
                                        placeholder="Nama pelanggan / booking"
                                        class="h-10 text-sm"
                                        maxlength="255"
                                    />
                                </div>
                                <div v-if="store?.fnb_enabled">
                                    <Label for="so-type" class="text-xs font-medium">Tipe Pesanan</Label>
                                    <select
                                        id="so-type"
                                        v-model="saveOrderForm.order_type"
                                        class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500"
                                    >
                                        <option value="dine_in">Makan di tempat (Dine-in)</option>
                                        <option value="takeaway">Bawa pulang (Takeaway)</option>
                                        <option value="delivery">Antar (Delivery)</option>
                                    </select>
                                </div>
                                <div>
                                    <Label for="so-notes" class="text-xs font-medium">Catatan (opsional)</Label>
                                    <textarea
                                        id="so-notes"
                                        v-model="saveOrderForm.notes"
                                        rows="2"
                                        placeholder="Alergi, level pedas, permintaan khusus…"
                                        class="h-20 w-full resize-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500"
                                        maxlength="500"
                                    />
                                </div>
                                <Label v-if="store?.fnb_enabled" class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        v-model="saveOrderForm.print_kot"
                                        class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <span class="text-slate-700">Cetak KOT setelah pesanan disimpan</span>
                                </Label>
                            </div>
                        </div>

                        <div class="space-y-2 rounded-b-xl border-t border-slate-200 bg-slate-50/50 p-4">
                            <Button class="w-full gap-2 rounded-xl" :loading="saveOrderLoading" @click="saveOrderHold">
                                <Save class="h-4 w-4" />
                                Simpan Order
                            </Button>
                            <Button variant="secondary" class="w-full" :disabled="saveOrderLoading" @click="saveOrderDrawerOpen = false">
                                Batal
                            </Button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </template>
    </AdminLayout>
</template>
