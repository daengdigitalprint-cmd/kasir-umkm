<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AiAssistantSidebar from '@/components/ai/AiAssistantSidebar.vue';
import ThermalReceiptStruk from '@/components/pos/ThermalReceiptStruk.vue';
import { mergeReceiptConfig, printThermalReceipt, type ReceiptConfig } from '@/lib/thermalReceipt';
import { imageUrlToEscPosRaster, printableDotsFor, receiptLinesToEscPos } from '@/lib/escPosEncode';
import { buildReceiptPlainLinesFromPosResponse } from '@/lib/receiptPlainText';
import { usePrinter } from '@/composables/usePrinter';
import { modalError, toastSuccess } from '@/lib/swal';
// import { logout } from '@/routes';
// import reports from '@/routes/reports';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import DashboardLowStockCard from '@/components/dashboard/DashboardLowStockCard.vue';
import DashboardExpiringBatchesCard from '@/components/dashboard/DashboardExpiringBatchesCard.vue';
import DashboardTrendChart from '@/components/dashboard/DashboardTrendChart.vue';
import DashboardPeakHours from '@/components/dashboard/DashboardPeakHours.vue';
import DashboardTopProducts from '@/components/dashboard/DashboardTopProducts.vue';
import {
    ShoppingCart,
    Package,
    Users,
    TrendingUp,
    TrendingDown,
    Plus,
    Eye,
    DollarSign,
    AlertTriangle,
    LogOut,
    ArrowRight,
    Crown,
    Clock,
    Calendar,
    Loader2,
    Hourglass,
    Printer,
    Wallet,
    Truck,
    ArrowDownCircle,
    ArrowUpCircle,
    Warehouse,
    ShoppingBag,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const page = usePage();

const subscriptionBannerDismissed = ref(false);
const showAiSidebar = ref(false);
const showAiFullscreen = ref(false);

function closeAiSidebar() {
    showAiSidebar.value = false;
    showAiFullscreen.value = false;
}

// Tampilkan modal ajakan buat produk pertama setelah user berhasil membuat toko baru.
const showProductModal = ref(!!page.props.flash?.show_product_modal);

interface Props {
    store?: {
        id: number;
        name: string;
        description: string;
        currency: string;
        products_count: number;
        customers_count?: number;
        today_sales?: number;
        today_transactions?: number;
        yesterday_sales?: number;
        yesterday_transactions?: number;
        last_month_omset?: number;
        today_stock_in?: number;
        pending_incoming_transfers?: number;
        today_transfers_out?: number;
        location_type?: string;
        subscription_end: string | null;
        is_subscription_active: boolean;
        subscription_days: number;
        tax_name?: string;
        receipt?: ReceiptConfig | null;
        pharmacy_enabled?: boolean;
        fnb_enabled?: boolean;
    };
    recentTransactions?: Array<{
        id: number;
        transaction_number: string;
        total_amount: number;
        created_at: string;
        customer?: {
            name: string;
        };
    }>;
    recentMovements?: Array<{
        id: number;
        type: string;
        quantity_change: number;
        created_at: string;
        product?: {
            name: string;
            sku: string;
        };
    }>;
    lowStockProducts?: Array<{
        id: number;
        name: string;
        stock_quantity: number;
        min_stock_level: number;
    }>;
    lowStockCount?: number;
    expiringBatches?: Array<{
        id: number;
        product_id: number;
        product_name: string;
        batch_code: string | null;
        quantity_remaining: number;
        expiry_date: string;
        expiry_status?: 'expired' | 'warning';
    }>;
    batch_expiry_warn_days?: number | null;
    storeInactive?: boolean;
    storeName?: string;
    /** Pembayaran langganan yang masih pending (dari backend SubscriptionPayment). */
    pendingPayment?: {
        id: number;
        invoice_number: string;
        status: string;
    };
    weeklyTrend?: Array<{
        date: string;
        revenue: number;
        count: number;
    }>;
    peakHours?: Array<{
        hour: number;
        label: string;
        count: number;
        revenue: number;
    }>;
    topProducts?: Array<{
        id: number;
        name: string;
        quantity: number;
        revenue: number;
    }>;
}

const {
    store,
    recentTransactions,
    recentMovements,
    lowStockProducts,
    lowStockCount,
    expiringBatches,
    batch_expiry_warn_days,
    storeInactive,
    storeName,
    pendingPayment,
    weeklyTrend,
    peakHours,
    topProducts,
} = defineProps<Props>();

const isWarehouse = computed(() => {
    const lt = store?.location_type ?? 'outlet';
    return lt === 'central_warehouse' || lt === 'branch_warehouse';
});

const subscription = computed(() => page.props.subscription);

const canBackoffice = computed(() => Boolean(page.props.abilities?.backoffice));

const canInventory = computed(() => Boolean(page.props.abilities?.inventory));

const isKitchen = computed(() => Boolean(page.props.is_kitchen) || String(page.props.auth?.active_role ?? page.props.auth?.user?.role ?? '') === 'kitchen');
const pendingKitchenCount = computed(() => Number(page.props.pending_kitchen_order_count ?? 0));

/** Buka halaman struk (/invoice/...) — sama dengan akses middleware role.invoice */
const canViewInvoice = computed(() =>
    Boolean(page.props.abilities?.pos || page.props.abilities?.backoffice),
);

// ─── Printer Bluetooth (sama seperti halaman POS)
const {
    mode: printerMode,
    isConnected: isPrinterConnected,
    isPrinting: isPrinterPrinting,
    initialize: initializePrinter,
    printEscPosBytes,
    kickDrawer,
} = usePrinter();

onMounted(() => {
    void initializePrinter();
    document.addEventListener('keydown', _aiShortcut);
});
onUnmounted(() => document.removeEventListener('keydown', _aiShortcut));

function _aiShortcut(e: KeyboardEvent) {
    if (e.ctrlKey && e.shiftKey && e.key === 'A') {
        e.preventDefault();
        showAiSidebar.value = !showAiSidebar.value;
    }
}

const receiptModalOpen = ref(false);
const receiptLoading = ref(false);
const receiptPayloadTx = ref<Record<string, unknown> | null>(null);
const receiptPharmacyEnabled = ref(false);

async function openReceiptModal(transactionId: number) {
    receiptLoading.value = true;
    receiptModalOpen.value = true;
    receiptPayloadTx.value = null;
    try {
        const res = await fetch(`/transactions/${transactionId}/receipt-data`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            throw new Error('Gagal memuat struk');
        }
        const data = (await res.json()) as {
            transaction: Record<string, unknown>;
            pharmacy_enabled?: boolean;
        };
        receiptPayloadTx.value = data.transaction;
        receiptPharmacyEnabled.value = Boolean(data.pharmacy_enabled);
    } catch (e) {
        receiptModalOpen.value = false;
        await modalError('Struk transaksi', e instanceof Error ? e.message : 'Gagal memuat data');
    } finally {
        receiptLoading.value = false;
    }
}

function closeReceiptModal() {
    receiptModalOpen.value = false;
    receiptPayloadTx.value = null;
}

async function printDashboardReceipt() {
    const tx = receiptPayloadTx.value;
    if (!tx || !store) {
        return;
    }

    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

    // Coba cetak via Bluetooth selama Web Bluetooth / bridge tersedia.
    // Di desktop (browser), `isPrinterConnected` belum true sebelum cetak pertama kali
    // sukses (state-nya tidak persisten antar reload), jadi jangan jadikan syarat masuknya.
    const bluetoothAvailable = printerMode.value === 'browser' || printerMode.value === 'android';
    if (bluetoothAvailable || isMobile) {
        try {
            // Siapkan logo raster
            const r = mergeReceiptConfig(
                (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
                (store.receipt ?? null) as Partial<ReceiptConfig> | null,
            );
            let logoRaster: Uint8Array | null = null;
            if (r.show_logo && r.logo_url) {
                const logoSrc = r.logo_url.startsWith('http')
                    ? r.logo_url
                    : `${window.location.origin}${r.logo_url.startsWith('/') ? '' : '/'}${r.logo_url}`;
                const maxDots = printableDotsFor(r.paper_width_mm === 58 ? 58 : 80);
                logoRaster = await imageUrlToEscPosRaster(logoSrc, Math.floor(maxDots * 0.7), 120);
            }

            const lines = buildReceiptPlainLinesFromPosResponse(tx as Record<string, unknown>, store, logoRaster);
            const bytes = receiptLinesToEscPos(lines);
            const result = await printEscPosBytes(bytes, 'cashier');

            if (result.success) {
                void toastSuccess('Struk berhasil dicetak ke printer Bluetooth.', 3000);
                kickDrawer();
                return;
            }

            // Gagal di mobile → tampilkan error, jangan fallback ke browser print
            if (isMobile) {
                await modalError(
                    'Gagal Cetak Bluetooth',
                    result.error ?? 'Pastikan printer menyala dan sudah dihubungkan di Pengaturan → Bluetooth.',
                );
                return;
            }
            // Di desktop → fallback ke cetak browser
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
            // Di desktop → fallback ke cetak browser
        }
    }

    // Fallback: cetak via browser (PDF dialog) — untuk Desktop PC dengan printer USB biasa
    const r = mergeReceiptConfig(
        (tx.receipt as Partial<ReceiptConfig> | undefined) ?? null,
        (store.receipt ?? null) as Partial<ReceiptConfig> | null,
    );
    printThermalReceipt(tx, r, {
        taxName: store.tax_name || 'PPN',
        pharmacyEnabled: receiptPharmacyEnabled.value,
    });
}

/** Label paket di badge (bukan "Premium" untuk semua yang aktif). */
const subscriptionPlanBadge = computed((): { label: string; style: 'growup' | 'starter' | 'other' } | null => {
    if (!store?.is_subscription_active) return null;
    const pl = page.props.plan_limits ?? null;
    const slug = pl?.plan_slug ?? '';
    const name = (pl?.plan_name ?? '').trim();
    if (slug === 'starter' || slug === 'starter-tahunan') {
        return { label: 'Starter', style: 'starter' };
    }
    if (slug === 'bisnis' || slug === 'bisnis-tahunan' || slug === 'promo-onboarding-tahunan') {
        return { label: 'GrowUp', style: 'growup' };
    }
    if (name) {
        const short = name.replace(/\s*\(tahunan\)\s*$/i, '').trim() || name;
        return { label: short, style: 'other' };
    }
    return { label: 'Paket aktif', style: 'other' };
});

/** Tampilkan ajakan upgrade hanya untuk Starter atau belum berlangganan aktif. */
const showUpgradePackageButton = computed(() => {
    if (!store?.is_subscription_active) return true;
    return subscriptionPlanBadge.value?.style === 'starter';
});

const daysRemaining = computed(() => {
    if (!store?.subscription_end) return 0;
    const now = new Date();
    const endDate = new Date(store.subscription_end);
    const diffTime = endDate.getTime() - now.getTime();
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return Math.max(0, diffDays);
});

const subscriptionStatus = computed(() => {
    if (!store?.is_subscription_active) return 'inactive';
    if (subscriptionPlanBadge.value?.style === 'starter') return 'active';
    if (!store?.subscription_end) return 'unlimited';
    if (daysRemaining.value <= 0) return 'expired';
    if (daysRemaining.value <= 7) return 'expiring';
    return 'active';
});

const subscriptionStatusText = computed(() => {
    switch (subscriptionStatus.value) {
        case 'inactive': return 'Nonaktif';
        case 'unlimited': return 'Unlimited';
        case 'expired': return 'Expired';
        case 'expiring': return 'Akan Expire';
        case 'active': return 'Aktif';
        default: return 'Unknown';
    }
});

const subscriptionStatusVariant = computed(() => {
    switch (subscriptionStatus.value) {
        case 'inactive': return 'secondary';
        case 'unlimited': return 'default';
        case 'expired': return 'destructive';
        case 'expiring': return 'destructive';
        case 'active': return 'default';
        default: return 'secondary';
    }
});

// Format currency helper
const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

/** Hilangkan trailing .0000 dari angka desimal database. */
const fmtQty = (val: string | number) => {
    const num = Number(val);
    return Number.isInteger(num) ? num.toString() : num.toFixed(4).replace(/\.?0+$/, '');
};

/** Omset bulan lalu (dari backend, range startOfMonth - endOfMonth bulan sebelumnya). */
const lastMonthOmset = computed(() => store?.last_month_omset ?? 0);

/** Growth penjualan hari ini vs kemarin. */
const salesGrowthPercent = computed(() => {
    const today = store?.today_sales ?? 0;
    const yesterday = store?.yesterday_sales ?? 0;
    if (yesterday === 0) return null;
    return ((today - yesterday) / yesterday) * 100;
});

/** Growth jumlah transaksi hari ini vs kemarin. */
const transactionsGrowthPercent = computed(() => {
    const today = store?.today_transactions ?? 0;
    const yesterday = store?.yesterday_transactions ?? 0;
    if (yesterday === 0) return null;
    return ((today - yesterday) / yesterday) * 100;
});

// Format date helper
const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const formatDateShort = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const daysUntilExpiry = (dateString: string) => {
    const d = new Date(dateString);
    d.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.ceil((d.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
};

</script>


<template>
    <AdminLayout title="Dashboard">
        <template v-if="isKitchen">
            <div class="space-y-6">
                <div class="rounded-xl border border-orange-200 bg-orange-50 p-5">
                    <p class="text-sm font-semibold text-orange-700">Area Dapur</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-900">Siap memproses pesanan?</h1>
                    <p class="mt-1 text-sm text-slate-600">Kelola pesanan masuk dan perbarui status produksi dari satu tempat.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent class="p-5">
                            <p class="text-sm text-slate-500">Pesanan menunggu</p>
                            <p class="mt-2 text-3xl font-bold text-orange-600">{{ pendingKitchenCount }}</p>
                            <p class="mt-1 text-xs text-slate-500">Siap diproses dapur</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="p-5">
                            <p class="text-sm text-slate-500">Status kerja</p>
                            <p class="mt-2 text-xl font-bold text-emerald-600">Dapur aktif</p>
                            <p class="mt-1 text-xs text-slate-500">Pantau antrian secara real-time</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="flex h-full flex-col justify-between gap-3 p-5">
                            <div>
                                <p class="text-sm text-slate-500">Aksi cepat</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">Buka antrian dapur</p>
                            </div>
                            <Link href="/kitchen"><Button class="w-full">Lihat Antrian Dapur</Button></Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </template>
        <template v-else>
        <!-- Subscription Alert Banner -->
        <div
            v-if="subscription && store && (subscription.status === 'expiring' || subscription.status === 'expired') && subscriptionPlanBadge?.style !== 'starter' && !subscriptionBannerDismissed"
             :class="[
                 'mb-6 mt-0 rounded-2xl p-4 md:p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3',
                 subscription.status === 'expired' 
                     ? 'bg-gradient-to-r from-red-50 to-rose-50 border border-red-200' 
                     : 'bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200'
             ]">
            <div class="flex items-center gap-3">
                <div :class="[
                    'p-2.5 rounded-xl flex-shrink-0',
                    subscription.status === 'expired' ? 'bg-red-100' : 'bg-amber-100'
                ]">
                    <Crown :class="[
                        'h-5 w-5',
                        subscription.status === 'expired' ? 'text-red-600' : 'text-amber-600'
                    ]" />
                </div>
                <div>
                    <h3 :class="['font-bold text-sm', subscription.status === 'expired' ? 'text-red-900' : 'text-amber-900']">
                        {{ subscription.status === 'expired' ? 'Subscription Expired' : 'Subscription Akan Berakhir' }}
                    </h3>
                    <p :class="['text-xs mt-0.5', subscription.status === 'expired' ? 'text-red-600' : 'text-amber-600']">
                        {{ subscription.status === 'expired' 
                            ? 'Perpanjang sekarang agar toko tetap aktif' 
                            : `Sisa ${subscription.days_remaining} hari lagi. Perpanjang sekarang!` }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <Button 
                    variant="ghost" 
                    size="sm" 
                    class="rounded-xl text-xs font-medium opacity-70 hover:opacity-100"
                    @click="subscriptionBannerDismissed = true"
                >
                    Nanti Saja
                </Button>
                <Button 
                    as="a" 
                    href="/subscription" 
                    :class="[
                        'rounded-xl px-5 py-2 font-bold text-sm shadow-md transition-all duration-300',
                        subscription.status === 'expired' 
                            ? 'bg-gradient-to-r from-red-500 to-rose-600 text-white hover:from-red-600 hover:to-rose-700 shadow-red-200' 
                            : 'bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:from-amber-600 hover:to-orange-600 shadow-amber-200'
                    ]"
                >
                    Perpanjang Sekarang
                </Button>
            </div>
        </div>

        <!-- Pending Payment Indicator -->
        <div v-if="pendingPayment" class="mb-6 mt-0 bg-blue-50 border border-blue-200 rounded-2xl p-4 flex items-center gap-3">
            <div class="p-2 bg-blue-100 rounded-xl flex-shrink-0">
                <Loader2 class="h-4 w-4 text-blue-600 animate-spin" />
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-blue-900">Pembayaran Menunggu Konfirmasi</p>
                <p class="text-xs text-blue-600 truncate">Invoice {{ pendingPayment.invoice_number }}</p>
            </div>
            <Button as="a" href="/subscription" variant="ghost" class="text-blue-700 text-xs font-bold hover:bg-blue-100 rounded-xl">
                Lihat Detail
            </Button>
        </div>

        <!-- Store Inactive Section -->
                    <div v-if="storeInactive" class="text-center py-16">
                        <div class="max-w-md mx-auto">
                            <div class="p-4 bg-red-100 rounded-full w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                                <AlertTriangle class="h-8 w-8 text-red-600" />
                            </div>
                            <h2 class="text-3xl font-bold text-gray-900 mb-4">
                                Toko Dinonaktifkan
                            </h2>
                            <p class="text-lg text-gray-600 mb-4">
                                Toko "{{ storeName }}" telah dinonaktifkan oleh administrator.
                            </p>
                            <p class="text-sm text-gray-500 mb-8">
                                Silakan hubungi administrator untuk mengaktifkan kembali toko Anda.
                            </p>
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                <Button as="a" href="/payment" class="inline-flex items-center gap-2 bg-primary hover:bg-primary/90 text-white px-6 py-3 rounded-2xl">
                                    <Crown class="h-5 w-5" />
                                    Aktifkan Sekarang
                                </Button>
                                <Button as="a" href="/logout" method="post" class="inline-flex items-center gap-2 bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-2xl">
                                    <LogOut class="h-5 w-5" />
                                    Keluar
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Welcome Section -->
                    <div v-else-if="!store" class="text-center py-10 md:py-16">
                        <div class="max-w-md mx-auto">
                            <div class="flex justify-center mb-4">
                                <img
                                    src="/maskot.webp"
                                    alt=""
                                    width="200"
                                    height="200"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-32 md:h-40 w-auto object-contain drop-shadow-[0_12px_24px_rgba(8,145,178,0.18)]"
                                />
                            </div>
                            <h2 class="text-3xl font-bold text-gray-900 mb-4">
                                Selamat Datang di UMKM Kasir
                            </h2>
                            <p class="text-lg text-gray-600 mb-8">
                                Mulai perjalanan digitalisasi toko Anda dengan sistem kasir modern yang mudah digunakan.
                            </p>
                            <Button as="a" href="/stores/create" class="inline-flex items-center gap-2 bg-primary hover:bg-primary/90 text-primary-foreground px-6 py-3 rounded-2xl btn-premium">
                                <Plus class="h-5 w-5" />
                                Buat Toko Pertama
                                <ArrowRight class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    <div v-else>
                        <!-- Store Info (di atas kartu sambutan) -->
                        <div class="mb-3 md:mb-4">
                            <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-start sm:items-center justify-between gap-3 mb-1.5"
                                    >
                                        <div class="flex flex-wrap items-center gap-2 sm:gap-3 min-w-0">
                                            <h2 class="text-2xl sm:text-3xl font-bold text-gray-900">{{ store.name }}</h2>
                                            <Badge
                                                v-if="subscriptionPlanBadge"
                                                variant="secondary"
                                                :class="
                                                    subscriptionPlanBadge.style === 'growup'
                                                        ? 'px-3 py-1 bg-gradient-to-r from-yellow-400 to-yellow-500 text-yellow-900 border-0'
                                                        : 'px-3 py-1 border bg-slate-100 text-slate-800 border-slate-200'
                                                "
                                            >
                                                <Crown
                                                    v-if="subscriptionPlanBadge.style === 'growup'"
                                                    class="h-3 w-3 mr-1"
                                                />
                                                {{ subscriptionPlanBadge.label }}
                                            </Badge>
                                        </div>
                                        <Button
                                            v-if="showUpgradePackageButton"
                                            as="a"
                                            href="/subscription"
                                            size="sm"
                                            class="shrink-0 bg-gradient-to-r from-orange-500 to-amber-500 text-white hover:from-orange-600 hover:to-amber-600 rounded-xl px-4 py-2 font-bold text-sm shadow-md"
                                        >
                                            <Crown class="h-4 w-4 mr-1.5" />
                                            Upgrade paket
                                        </Button>
                                    </div>
                                    <p v-if="store.description" class="text-base sm:text-lg text-gray-600">
                                        {{ store.description }}
                                    </p>

                                    <div class="flex flex-wrap items-center gap-2 sm:gap-4 mt-2 sm:mt-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Badge :variant="subscriptionStatusVariant" class="px-3 py-1">
                                                <Calendar class="h-3 w-3 mr-1" />
                                                {{ subscriptionStatusText }}
                                            </Badge>
                                            <span
                                                v-if="store.subscription_end && daysRemaining > 0 && !subscription?.starter_free && subscriptionPlanBadge?.style !== 'starter'"
                                                class="text-sm text-gray-600 flex items-center gap-1"
                                            >
                                                <Clock class="h-3 w-3 shrink-0" />
                                                {{ daysRemaining }} hari lagi
                                            </span>
                                        </div>
                                    </div>
                                </div>
                        </div>

                        <!-- Maskot & sambutan -->
                        <div class="relative z-0 mb-5 md:mb-6">
                            <div
                                class="relative overflow-visible rounded-xl border border-violet-200/80 bg-gradient-to-br from-violet-100 via-fuchsia-50 to-purple-100"
                            >
                                <!-- Aksen gradasi (terpotong radius kartu) -->
                                <div
                                    class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl"
                                    aria-hidden="true"
                                >
                                    <div
                                        class="absolute -left-12 -top-10 h-28 w-28 rounded-full bg-violet-400/20 blur-xl"
                                    />
                                    <div
                                        class="absolute -right-6 top-1/2 h-28 w-28 -translate-y-1/2 rounded-full bg-fuchsia-400/25 blur-xl"
                                    />
                                </div>
                                <div
                                    class="relative z-10 flex flex-col-reverse sm:flex-row items-center sm:justify-between gap-2 sm:gap-3 px-4 py-3 md:px-5 md:py-4"
                                >
                                    <div class="flex flex-1 flex-col justify-center min-w-0 max-w-xl text-center sm:text-left">
                                        <p
                                            class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.16em] text-violet-700 mb-0.5"
                                        >
                                            UMKM Kasir
                                        </p>
                                        <h3
                                                class="text-lg sm:text-xl md:text-2xl font-black font-display leading-[1.2] text-transparent bg-clip-text bg-gradient-to-r from-violet-800 via-purple-800 to-fuchsia-700"
                                            >
                                                {{ isWarehouse ? 'Kelola stok gudang Anda' : 'Halo! Siap jualan hari ini?' }}
                                            </h3>
                                            <p
                                                class="mt-0.5 text-xs md:text-sm leading-tight text-violet-950/75"
                                            >
                                                {{ isWarehouse
                                                    ? 'Pantau stok masuk, transfer, dan ketersediaan barang dari gudang.'
                                                    : 'Pantau omzet, stok, dan transaksi dari satu tempat—semangat melayani pelanggan.'
                                                }}
                                        </p>
                                    </div>
                                    <!-- Tinggi slot tetap kecil agar kartu tidak melar; gambar lebih besar & overflow keluar -->
                                    <div
                                        class="relative flex h-[4.75rem] sm:h-[5rem] md:h-[5.25rem] w-[min(55%,300px)] shrink-0 min-h-0 items-end self-end sm:self-auto justify-end overflow-visible"
                                    >
                                        <img
                                            src="/maskot.webp"
                                            alt=""
                                            width="320"
                                            height="320"
                                            loading="lazy"
                                            decoding="async"
                                            class="pointer-events-none select-none h-[9.75rem] sm:h-[11.25rem] md:h-[12.25rem] w-auto max-w-[min(100%,240px)] object-contain object-bottom origin-bottom scale-100 sm:scale-105 translate-y-2 sm:translate-y-3 md:translate-y-4"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stats Cards — Gudang -->
                        <div v-if="isWarehouse" class="grid @container/card-grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8 overflow-hidden dashboard-stat-cards">
                            <!-- Stok Masuk Hari Ini -->
                            <div class="p-[2px] bg-gradient-to-br from-green-400/80 to-emerald-400/60 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-gradient-to-br from-green-50 to-white rounded-[2rem] border-0 h-full">
                                    <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                        <div class="flex items-start gap-3 mb-3">
                                            <div class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-green-500/15 rounded-full shrink-0">
                                                <ArrowDownCircle class="h-4 w-4 md:h-5 md:w-5 text-green-600" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="label-metadata mb-1">Stok Masuk Hari Ini</p>
                                                <p class="text-base sm:text-lg md:text-xl lg:text-2xl font-black text-green-700 font-display truncate">{{ fmtQty(store?.today_stock_in ?? 0) }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-green-700 bg-green-100 px-2 py-1 rounded-full text-xs font-bold">
                                                <ArrowDownCircle class="h-3 w-3 mr-1" />
                                                <span>Unit</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Transfer Menunggu Diterima -->
                            <div class="p-[2px] bg-gradient-to-br from-amber-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full">
                                    <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                        <div class="flex items-start gap-3 mb-3">
                                            <div class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-amber-500/10 rounded-full shrink-0">
                                                <Truck class="h-4 w-4 md:h-5 md:w-5 text-amber-600" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="label-metadata mb-1">Transfer Masuk Pending</p>
                                                <p class="text-base sm:text-lg md:text-xl lg:text-2xl font-black text-amber-600 font-display truncate">{{ store?.pending_incoming_transfers ?? 0 }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-amber-600 bg-amber-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <Truck class="h-3 w-3 mr-1" />
                                                <span>Transfer</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Transfer Keluar Hari Ini -->
                            <div class="p-[2px] bg-gradient-to-br from-red-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full">
                                    <CardContent class="p-4 md:p-6">
                                        <div class="flex items-start gap-3 mb-3">
                                            <div class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-red-500/10 rounded-full shrink-0">
                                                <ArrowUpCircle class="h-4 w-4 md:h-5 md:w-5 text-red-600" />
                                            </div>
                                        </div>
                                            <div class="flex-1 min-w-0">
                                            <p class="label-metadata mb-1">Transfer Keluar Hari Ini</p>
                                            <p class="text-base sm:text-lg md:text-xl lg:text-2xl font-black text-red-600 font-display truncate">{{ store?.today_transfers_out ?? 0 }}</p>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-red-600 bg-red-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <ArrowUpCircle class="h-3 w-3 mr-1" />
                                                <span>Kirim</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Total Produk -->
                            <div class="p-[2px] bg-gradient-to-br from-purple-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full">
                                    <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                        <div class="flex items-start gap-3 mb-3">
                                            <div class="flex items-center justify-center w-8 h-8 md:w-10 md:h-10 bg-purple-500/10 rounded-full shrink-0">
                                                <Warehouse class="h-4 w-4 md:h-5 md:w-5 text-purple-600" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="label-metadata mb-1">Total Produk</p>
                                                <p class="text-base sm:text-lg md:text-xl lg:text-2xl font-black text-foreground font-display truncate">{{ store?.products_count ?? 0 }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-purple-600 bg-purple-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <Package class="h-3 w-3 mr-1" />
                                                <span>Di gudang</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        <!-- Stats Cards — Outlet/POS -->
                        <div v-else-if="!isWarehouse" class="grid @container/card-grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-8 overflow-hidden dashboard-stat-cards">
                            <!-- Total Pendapatan -->
                            <div class="p-[2px] bg-gradient-to-br from-emerald-400/80 to-cyan-400/60 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300 lg:col-span-1">
                                <Card class="bg-gradient-to-br from-emerald-50 to-white rounded-[2rem] border-0 h-full relative overflow-hidden">
                                    <div class="absolute -right-4 -top-4 flex items-center justify-center w-24 h-24 md:w-32 md:h-32 bg-emerald-500/[.03] rounded-full pointer-events-none">
                                        <Wallet class="h-12 w-12 md:h-16 md:w-16 text-emerald-500/[.08]" />
                                    </div>
                                    <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                        <div class="relative min-w-0 mb-2">
                                            <p class="label-metadata mb-0.5 text-xs">Omset Bulan Lalu</p>
                                            <p class="text-lg sm:text-xl md:text-2xl font-black text-emerald-700 font-display truncate">Rp {{ formatCurrency(lastMonthOmset) }}</p>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-emerald-700 bg-emerald-100 px-2 py-1 rounded-full text-xs font-bold">
                                                <TrendingUp class="h-3 w-3 mr-1" />
                                                <span>Bulan lalu</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Today's Sales -->
                            <div class="p-[2px] bg-gradient-to-br from-green-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full relative overflow-hidden">
                                    <div class="absolute -right-4 -top-4 flex items-center justify-center w-24 h-24 md:w-32 md:h-32 bg-green-500/[.03] rounded-full pointer-events-none">
                                        <DollarSign class="h-12 w-12 md:h-16 md:w-16 text-green-500/[.08]" />
                                    </div>
                                    <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                        <div class="relative min-w-0 mb-2">
                                            <p class="label-metadata mb-0.5 text-xs">Penjualan Hari Ini</p>
                                            <p class="text-lg sm:text-xl md:text-2xl font-black text-foreground font-display truncate">{{ formatCurrency(store.today_sales ?? 0) }}</p>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div
                                                v-if="salesGrowthPercent !== null"
                                                class="flex items-center text-xs font-bold px-2 py-1 rounded-full"
                                                :class="salesGrowthPercent >= 0
                                                    ? 'text-emerald-600 bg-emerald-50'
                                                    : 'text-red-600 bg-red-50'"
                                            >
                                                <component :is="salesGrowthPercent >= 0 ? TrendingUp : TrendingDown" class="h-3 w-3 mr-1" />
                                                <span>{{ salesGrowthPercent >= 0 ? '+' : '' }}{{ salesGrowthPercent.toFixed(1) }}%</span>
                                            </div>
                                            <div v-else class="flex items-center text-green-600 bg-green-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <TrendingUp class="h-3 w-3 mr-1" />
                                                <span>Hari ini</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Today's Transactions -->
                            <div class="p-[2px] bg-gradient-to-br from-cyan-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full relative overflow-hidden">
                                    <div class="absolute -right-4 -top-4 flex items-center justify-center w-24 h-24 md:w-32 md:h-32 bg-cyan-500/[.03] rounded-full pointer-events-none">
                                        <ShoppingBag class="h-12 w-12 md:h-16 md:w-16 text-cyan-500/[.08]" />
                                    </div>
                                    <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                        <div class="relative min-w-0 mb-2">
                                            <p class="label-metadata mb-0.5 text-xs">Transaksi Hari Ini</p>
                                            <p class="text-lg sm:text-xl md:text-2xl font-black text-foreground font-display truncate">{{ store.today_transactions ?? 0 }}</p>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div
                                                v-if="transactionsGrowthPercent !== null"
                                                class="flex items-center text-xs font-bold px-2 py-1 rounded-full"
                                                :class="transactionsGrowthPercent >= 0
                                                    ? 'text-emerald-600 bg-emerald-50'
                                                    : 'text-red-600 bg-red-50'"
                                            >
                                                <component :is="transactionsGrowthPercent >= 0 ? TrendingUp : TrendingDown" class="h-3 w-3 mr-1" />
                                                <span>{{ transactionsGrowthPercent >= 0 ? '+' : '' }}{{ transactionsGrowthPercent.toFixed(1) }}%</span>
                                            </div>
                                            <div v-else class="flex items-center text-cyan-600 bg-cyan-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <ShoppingBag class="h-3 w-3 mr-1" />
                                                <span>Hari ini</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Low Stock Alert -->
                            <div class="p-[2px] bg-gradient-to-br from-orange-400/70 to-white/10 rounded-[2.2rem] shadow-lg hover:shadow-xl transition-all duration-300">
                                <Card class="bg-white rounded-[2rem] border-0 h-full relative overflow-hidden">
                                    <div class="absolute -right-4 -top-4 flex items-center justify-center w-24 h-24 md:w-32 md:h-32 bg-orange-500/[.03] rounded-full pointer-events-none">
                                        <AlertTriangle class="h-12 w-12 md:h-16 md:w-16 text-orange-500/[.08]" />
                                    </div>
                                    <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                        <div class="relative min-w-0 mb-2">
                                            <p class="label-metadata mb-0.5 text-xs">Stok Rendah</p>
                                            <p class="text-lg sm:text-xl md:text-2xl font-black text-orange-600 font-display truncate">{{ lowStockCount ?? 0 }}</p>
                                        </div>
                                        <div class="flex items-center justify-end">
                                            <div class="flex items-center text-orange-600 bg-orange-50 px-2 py-1 rounded-full text-xs font-bold">
                                                <AlertTriangle class="h-3 w-3 mr-1" />
                                                <span>Perhatian</span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        <!-- Main Content Grid — Gudang -->
                        <div v-if="isWarehouse" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            <div class="lg:col-span-2">
                                <Card class="border-0 shadow-lg">
                                    <CardHeader class="pb-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <CardTitle class="text-xl font-bold text-gray-900">Riwayat Mutasi Stok</CardTitle>
                                                <CardDescription class="text-gray-600">5 aktivitas stok terakhir di gudang ini</CardDescription>
                                            </div>
                                            <Link v-if="canInventory" href="/stock-movements" as="button"
                                                class="inline-flex items-center px-2 py-1 md:px-3 md:py-2 text-xs md:text-sm font-medium text-primary bg-white border border-blue-200 rounded-md hover:bg-blue-50 transition-colors">
                                                <Eye class="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
                                                <span class="hidden sm:inline">Lihat Semua</span>
                                                <span class="sm:hidden">Lihat</span>
                                            </Link>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div v-if="recentMovements && recentMovements.length > 0" class="space-y-2 md:space-y-4">
                                            <div v-for="m in recentMovements" :key="m.id"
                                                class="flex items-center justify-between p-2 md:p-4 bg-gray-50 rounded-2xl">
                                                <div class="flex items-center space-x-2 md:space-x-4 min-w-0 flex-1">
                                                    <div :class="['w-2 h-2 md:w-3 md:h-3 rounded-full flex-shrink-0', m.quantity_change > 0 ? 'bg-green-500' : 'bg-red-500']"></div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-medium text-gray-900 text-xs md:text-sm truncate">{{ m.product?.name ?? '-' }}</p>
                                                        <p class="text-xs md:text-sm text-gray-500 truncate">{{ m.type }}</p>
                                                    </div>
                                                </div>
                                                <div class="text-right flex-shrink-0 ml-2">
                                                    <p :class="['font-semibold text-xs md:text-sm tabular-nums', m.quantity_change > 0 ? 'text-green-600' : 'text-red-600']">
                                                        {{ m.quantity_change > 0 ? '+' : '' }}{{ fmtQty(m.quantity_change) }}
                                                    </p>
                                                    <p class="text-xs text-gray-500">{{ formatDate(m.created_at) }}</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="text-center py-12">
                                            <div class="p-4 bg-gray-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                                <Warehouse class="h-8 w-8 text-gray-400" />
                                            </div>
                                            <p class="text-gray-500 text-lg">Belum ada aktivitas stok</p>
                                            <p class="text-gray-400 text-sm mt-2">Mulai dengan membuat pembelian atau transfer</p>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Sidebar: Stok Rendah + Batch ED -->
                            <div class="space-y-8">
                                <DashboardLowStockCard :products="lowStockProducts" :low-stock-count="lowStockCount" />

                                <DashboardExpiringBatchesCard
                                    :batches="expiringBatches"
                                    :batch-expiry-warn-days="batch_expiry_warn_days"
                                />
                            </div>
                        </div>

                        <!-- Dashboard Insight Widgets — Outlet/POS -->
                        <div v-if="!isWarehouse" class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                            <DashboardTrendChart :data="weeklyTrend ?? []" />
                            <DashboardPeakHours :data="peakHours ?? []" />
                        </div>

                        <!-- Main Content Grid — Outlet/POS -->
                        <div v-if="!isWarehouse" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            <!-- Recent Transactions -->
                            <div class="lg:col-span-2">
                                <Card class="border-0 shadow-lg">
                                    <CardHeader class="pb-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <CardTitle class="text-xl font-bold text-gray-900">Transaksi Terbaru</CardTitle>
                                                <CardDescription class="text-gray-600">Daftar transaksi terbaru hari ini</CardDescription>
                                            </div>
                                            <Link
                                                v-if="canBackoffice"
                                                href="/reports"
                                                as="button"
                                                class="inline-flex items-center px-2 py-1 md:px-3 md:py-2 text-xs md:text-sm font-medium text-primary bg-white border border-blue-200 rounded-md hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                                            >
                                                <Eye class="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
                                                <span class="hidden sm:inline">Lihat Semua</span>
                                                <span class="sm:hidden">Lihat</span>
                                            </Link>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div v-if="recentTransactions && recentTransactions.length > 0" class="space-y-2 md:space-y-4">
                                            <div
                                                v-for="transaction in recentTransactions"
                                                :key="transaction.id"
                                                role="button"
                                                tabindex="0"
                                                class="flex items-center justify-between p-2 md:p-4 bg-gray-50 rounded-2xl transition-colors"
                                                :class="
                                                    canViewInvoice
                                                        ? 'hover:bg-gray-100 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40'
                                                        : ''
                                                "
                                                @click="canViewInvoice && openReceiptModal(transaction.id)"
                                                @keydown.enter.prevent="canViewInvoice && openReceiptModal(transaction.id)"
                                                @keydown.space.prevent="canViewInvoice && openReceiptModal(transaction.id)"
                                            >
                                                <div class="flex items-center space-x-2 md:space-x-4 min-w-0 flex-1">
                                                    <div class="w-2 h-2 md:w-3 md:h-3 bg-green-500 rounded-full flex-shrink-0"></div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-medium text-gray-900 text-xs md:text-sm truncate">
                                                            {{ transaction.transaction_number }}
                                                        </p>
                                                        <p class="text-xs md:text-sm text-gray-500 truncate">
                                                            {{ transaction.customer?.name || 'Walk-in Customer' }}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="text-right flex-shrink-0 ml-2 flex flex-col items-end gap-1">
                                                    <p class="font-semibold text-gray-900 text-xs md:text-sm tabular-nums">
                                                        {{ formatCurrency(transaction.total_amount) }}
                                                    </p>
                                                    <p class="text-xs md:text-sm text-gray-500">
                                                        {{ formatDate(transaction.created_at) }}
                                                    </p>
                                                    <span
                                                        v-if="canViewInvoice"
                                                        class="text-[10px] md:text-xs font-semibold text-primary"
                                                    >
                                                        Lihat struk →
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="text-center py-12">
                                            <div class="p-4 bg-gray-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                                <ShoppingCart class="h-8 w-8 text-gray-400" />
                                            </div>
                                            <p class="text-gray-500 text-lg">Belum ada transaksi hari ini</p>
                                            <p class="text-gray-400 text-sm mt-2">Mulai dengan membuka POS</p>
                                            <Button as="a" href="/pos" class="mt-4 rounded-xl">
                                                <ShoppingCart class="h-4 w-4 mr-2" />
                                                Mulai Jualan
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <!-- Low Stock & Expiring Batches -->
                            <div class="space-y-8">
                                <DashboardTopProducts :data="topProducts ?? []" />

                                <DashboardLowStockCard :products="lowStockProducts" :low-stock-count="lowStockCount" />

                                <DashboardExpiringBatchesCard
                                    :batches="expiringBatches"
                                    :batch-expiry-warn-days="batch_expiry_warn_days"
                                >
                                    <Button
                                        v-if="canInventory"
                                        as="a"
                                        href="/products"
                                        variant="outline"
                                        size="sm"
                                        class="w-full rounded-xl text-amber-900 border-amber-300"
                                    >
                                        Kelola produk & batch
                                    </Button>
                                </DashboardExpiringBatchesCard>
                            </div>
                        </div>
                    </div>

        <!-- Modal Struk -->
        <Dialog :open="receiptModalOpen" @update:open="receiptModalOpen = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Struk transaksi</DialogTitle>
                </DialogHeader>
                <div class="px-4 pt-3 pb-2 min-h-[120px] flex items-center justify-center">
                    <Loader2 v-if="receiptLoading" class="h-8 w-8 animate-spin text-primary" />
                    <ThermalReceiptStruk
                        v-else-if="receiptPayloadTx"
                        :transaction="receiptPayloadTx"
                        :store-receipt-fallback="store?.receipt"
                        :tax-name-fallback="store?.tax_name"
                        :pharmacy-enabled="receiptPharmacyEnabled"
                    />
                </div>
                <div v-if="!receiptLoading && receiptPayloadTx" class="px-4 pb-4 flex flex-col gap-2">
                    <Button class="w-full rounded-xl gap-2" type="button" :disabled="isPrinterPrinting" @click="printDashboardReceipt">
                        <Loader2 v-if="isPrinterPrinting" class="h-4 w-4 animate-spin" />
                        <Printer v-else class="h-4 w-4" />
                        {{ isPrinterPrinting ? 'Mencetak...' : 'Cetak struk' }}
                    </Button>
                    <Button variant="outline" class="w-full rounded-xl" type="button" @click="receiptModalOpen = false">
                        Selesai
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Modal ajakan buat produk pertama -->
        <Dialog :open="showProductModal" @update:open="showProductModal = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Toko Berhasil Dibuat!</DialogTitle>
                    <DialogDescription>
                        Selamat, toko Anda sudah aktif. Saatnya menambahkan produk pertama agar Anda bisa mulai berjualan.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="mt-4 items-center sm:justify-end">
                    <Button variant="secondary" @click="showProductModal = false">
                        Nanti Saja
                    </Button>
                    <Link href="/products/create">
                        <Button type="button">
                            Buat Produk
                        </Button>
                    </Link>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        </template>
    </AdminLayout>

    <!-- Floating AI Assistant Button -->
    <button
        type="button"
        class="fixed bottom-24 right-4 z-50 flex h-20 w-20 items-center justify-center transition-all hover:scale-105 sm:bottom-24 sm:right-6 sm:h-20 sm:w-20 lg:bottom-6 lg:h-24 lg:w-24"
        title="AI Assistant (Ctrl+Shift+A)"
        @click="showAiSidebar = !showAiSidebar"
    >
        <img src="/mas.webp" alt="AI Assistant" class="h-16 w-16 object-contain sm:h-18 sm:w-18 lg:h-20 lg:w-20" />
    </button>

    <!-- AI Assistant Sidebar -->
    <AiAssistantSidebar
        :is-open="showAiSidebar"
        :fullscreen="showAiFullscreen"
        @expand="showAiFullscreen = true"
        @close="closeAiSidebar"
    />
</template>

<style scoped>
/*
 * Landscape tablet (1024px–1279px): sidebar aktif (w-60 = 240px) membuat
 * area konten sempit. Grid 4 kolom + gap-6 menghasilkan kartu ~164px.
 * Skala font diturunkan proporsional agar semua teks tetap dalam batas kartu.
 */
@media (min-width: 768px) and (max-width: 1023px) {
  .dashboard-stat-cards {
    gap: 0.75rem;
  }

  .dashboard-stat-cards > div {
    min-width: 0;
    overflow: hidden;
  }

  .dashboard-stat-cards :deep(.label-metadata) {
    font-size: 10px;
    line-height: 1.3;
  }

  .dashboard-stat-cards :deep(.font-display.font-black) {
    font-size: 1rem !important;
    line-height: 1.2;
    word-wrap: break-word;
  }

  .dashboard-stat-cards :deep(.flex.items-center.justify-end > .rounded-full) {
    padding: 0.25rem 0.5rem !important;
    font-size: 10px !important;
  }

  .dashboard-stat-cards :deep(.flex.items-center.justify-end > .rounded-full > svg) {
    width: 10px !important;
    height: 10px !important;
  }
}

@media (min-width: 1024px) and (max-width: 1279px) and (orientation: landscape) {
  .dashboard-stat-cards {
    gap: 1rem;
  }

  /* Kartu individu: overflow-safe */
  .dashboard-stat-cards > div {
    min-width: 0;
    overflow: hidden;
  }

  /* Label metadata: teks balance + wrap */
  .dashboard-stat-cards :deep(.label-metadata) {
    font-size: 9px;
    text-wrap: balance;
    line-height: 1.3;
  }

  /* Angka utama: turunkan dari text-2xl (24px) → 16px */
  .dashboard-stat-cards :deep(.font-display.font-black) {
    font-size: 1rem !important;
    line-height: 1.2;
    overflow-wrap: break-word;
    word-break: break-word;
  }

  /* Badge bawah kartu (Bulan lalu, Hari ini, Tersedia, Perhatian) */
  .dashboard-stat-cards :deep(.flex.items-center.justify-end > .rounded-full) {
    padding: 0.2rem 0.45rem !important;
    font-size: 9px !important;
  }

  /* Ikon dalam badge */
  .dashboard-stat-cards :deep(.flex.items-center.justify-end > .rounded-full > svg) {
    width: 10px !important;
    height: 10px !important;
  }
}
</style>

