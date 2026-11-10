<script setup lang="ts">
import AiAssistantSidebar from '@/components/ai/AiAssistantSidebar.vue';
import ThermalReceiptStruk from '@/components/pos/ThermalReceiptStruk.vue';
import { usePrinter } from '@/composables/usePrinter';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { imageUrlToEscPosRaster, printableDotsFor, receiptLinesToEscPos } from '@/lib/escPosEncode';
import { buildReceiptPlainLinesFromPosResponse } from '@/lib/receiptPlainText';
import { modalError, toastSuccess } from '@/lib/swal';
import { mergeReceiptConfig, printThermalReceipt, type ReceiptConfig } from '@/lib/thermalReceipt';
import { Link, usePage } from '@inertiajs/vue3';
// import { logout } from '@/routes';
// import reports from '@/routes/reports';
import DashboardExpiringBatchesCard from '@/components/dashboard/DashboardExpiringBatchesCard.vue';
import DashboardLowStockCard from '@/components/dashboard/DashboardLowStockCard.vue';
import DashboardPeakHours from '@/components/dashboard/DashboardPeakHours.vue';
import DashboardTopProducts from '@/components/dashboard/DashboardTopProducts.vue';
import DashboardTrendChart from '@/components/dashboard/DashboardTrendChart.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowRight,
    ArrowUpCircle,
    Calendar,
    Clock,
    Crown,
    DollarSign,
    Eye,
    Loader2,
    LogOut,
    Package,
    Plus,
    Printer,
    ShoppingBag,
    ShoppingCart,
    TrendingDown,
    TrendingUp,
    Truck,
    Wallet,
    Warehouse,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const page = usePage();
const isAiChatEnabled = computed(() => Boolean(page.props.ai_chat_enabled));

const subscriptionBannerDismissed = ref(false);
const showAiSidebar = ref(false);
const showAiFullscreen = ref(false);

function closeAiSidebar() {
    showAiSidebar.value = false;
    showAiFullscreen.value = false;
}

watch(isAiChatEnabled, (enabled) => {
    if (!enabled) {
        closeAiSidebar();
    }
});

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

const isKitchen = computed(
    () => Boolean(page.props.is_kitchen) || String(page.props.auth?.active_role ?? page.props.auth?.user?.role ?? '') === 'kitchen',
);
const pendingKitchenCount = computed(() => Number(page.props.pending_kitchen_order_count ?? 0));

/** Buka halaman struk (/invoice/...) — sama dengan akses middleware role.invoice */
const canViewInvoice = computed(() => Boolean(page.props.abilities?.pos || page.props.abilities?.backoffice));

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
    if (isAiChatEnabled.value && e.ctrlKey && e.shiftKey && e.key === 'A') {
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
        case 'inactive':
            return 'Nonaktif';
        case 'unlimited':
            return 'Unlimited';
        case 'expired':
            return 'Expired';
        case 'expiring':
            return 'Akan Expire';
        case 'active':
            return 'Aktif';
        default:
            return 'Unknown';
    }
});

const subscriptionStatusVariant = computed(() => {
    switch (subscriptionStatus.value) {
        case 'inactive':
            return 'secondary';
        case 'unlimited':
            return 'default';
        case 'expired':
            return 'destructive';
        case 'expiring':
            return 'destructive';
        case 'active':
            return 'default';
        default:
            return 'secondary';
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
        minute: '2-digit',
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
                v-if="
                    subscription &&
                    store &&
                    (subscription.status === 'expiring' || subscription.status === 'expired') &&
                    subscriptionPlanBadge?.style !== 'starter' &&
                    !subscriptionBannerDismissed
                "
                :class="[
                    'mt-0 mb-6 flex flex-col items-start justify-between gap-3 rounded-2xl p-4 sm:flex-row sm:items-center md:p-5',
                    subscription.status === 'expired'
                        ? 'border border-red-200 bg-gradient-to-r from-red-50 to-rose-50'
                        : 'border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50',
                ]"
            >
                <div class="flex items-center gap-3">
                    <div :class="['flex-shrink-0 rounded-xl p-2.5', subscription.status === 'expired' ? 'bg-red-100' : 'bg-amber-100']">
                        <Crown :class="['h-5 w-5', subscription.status === 'expired' ? 'text-red-600' : 'text-amber-600']" />
                    </div>
                    <div>
                        <h3 :class="['text-sm font-bold', subscription.status === 'expired' ? 'text-red-900' : 'text-amber-900']">
                            {{ subscription.status === 'expired' ? 'Subscription Expired' : 'Subscription Akan Berakhir' }}
                        </h3>
                        <p :class="['mt-0.5 text-xs', subscription.status === 'expired' ? 'text-red-600' : 'text-amber-600']">
                            {{
                                subscription.status === 'expired'
                                    ? 'Perpanjang sekarang agar toko tetap aktif'
                                    : `Sisa ${subscription.days_remaining} hari lagi. Perpanjang sekarang!`
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
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
                            'rounded-xl px-5 py-2 text-sm font-bold shadow-md transition-all duration-300',
                            subscription.status === 'expired'
                                ? 'bg-gradient-to-r from-red-500 to-rose-600 text-white shadow-red-200 hover:from-red-600 hover:to-rose-700'
                                : 'bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-amber-200 hover:from-amber-600 hover:to-orange-600',
                        ]"
                    >
                        Perpanjang Sekarang
                    </Button>
                </div>
            </div>

            <!-- Pending Payment Indicator -->
            <div v-if="pendingPayment" class="mt-0 mb-6 flex items-center gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4">
                <div class="flex-shrink-0 rounded-xl bg-blue-100 p-2">
                    <Loader2 class="h-4 w-4 animate-spin text-blue-600" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-blue-900">Pembayaran Menunggu Konfirmasi</p>
                    <p class="truncate text-xs text-blue-600">Invoice {{ pendingPayment.invoice_number }}</p>
                </div>
                <Button as="a" href="/subscription" variant="ghost" class="rounded-xl text-xs font-bold text-blue-700 hover:bg-blue-100">
                    Lihat Detail
                </Button>
            </div>

            <!-- Store Inactive Section -->
            <div v-if="storeInactive" class="py-16 text-center">
                <div class="mx-auto max-w-md">
                    <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-red-100 p-4">
                        <AlertTriangle class="h-8 w-8 text-red-600" />
                    </div>
                    <h2 class="mb-4 text-3xl font-bold text-gray-900">Toko Dinonaktifkan</h2>
                    <p class="mb-4 text-lg text-gray-600">Toko "{{ storeName }}" telah dinonaktifkan oleh administrator.</p>
                    <p class="mb-8 text-sm text-gray-500">Silakan hubungi administrator untuk mengaktifkan kembali toko Anda.</p>
                    <div class="flex flex-col justify-center gap-4 sm:flex-row">
                        <Button
                            as="a"
                            href="/payment"
                            class="inline-flex items-center gap-2 rounded-2xl bg-primary px-6 py-3 text-white hover:bg-primary/90"
                        >
                            <Crown class="h-5 w-5" />
                            Aktifkan Sekarang
                        </Button>
                        <Button
                            as="a"
                            href="/logout"
                            method="post"
                            class="inline-flex items-center gap-2 rounded-2xl bg-gray-600 px-6 py-3 text-white hover:bg-gray-700"
                        >
                            <LogOut class="h-5 w-5" />
                            Keluar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Welcome Section -->
            <div v-else-if="!store" class="py-10 text-center md:py-16">
                <div class="mx-auto max-w-md">
                    <div class="mb-4 flex justify-center">
                        <img
                            src="/maskot.webp"
                            alt=""
                            width="200"
                            height="200"
                            loading="lazy"
                            decoding="async"
                            class="h-32 w-auto object-contain drop-shadow-[0_12px_24px_rgba(8,145,178,0.18)] md:h-40"
                        />
                    </div>
                    <h2 class="mb-4 text-3xl font-bold text-gray-900">Selamat Datang di UMKM Kasir</h2>
                    <p class="mb-8 text-lg text-gray-600">Mulai perjalanan digitalisasi toko Anda dengan sistem kasir modern yang mudah digunakan.</p>
                    <Button
                        as="a"
                        href="/stores/create"
                        class="btn-premium inline-flex items-center gap-2 rounded-2xl bg-primary px-6 py-3 text-primary-foreground hover:bg-primary/90"
                    >
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
                        <div class="mb-1.5 flex flex-wrap items-start justify-between gap-3 sm:items-center">
                            <div class="flex min-w-0 flex-wrap items-center gap-2 sm:gap-3">
                                <h2 class="text-2xl font-bold text-gray-900 sm:text-3xl">{{ store.name }}</h2>
                                <Badge
                                    v-if="subscriptionPlanBadge"
                                    variant="secondary"
                                    :class="
                                        subscriptionPlanBadge.style === 'growup'
                                            ? 'border-0 bg-gradient-to-r from-yellow-400 to-yellow-500 px-3 py-1 text-yellow-900'
                                            : 'border border-slate-200 bg-slate-100 px-3 py-1 text-slate-800'
                                    "
                                >
                                    <Crown v-if="subscriptionPlanBadge.style === 'growup'" class="mr-1 h-3 w-3" />
                                    {{ subscriptionPlanBadge.label }}
                                </Badge>
                            </div>
                            <Button
                                v-if="showUpgradePackageButton"
                                as="a"
                                href="/subscription"
                                size="sm"
                                class="shrink-0 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-sm font-bold text-white shadow-md hover:from-orange-600 hover:to-amber-600"
                            >
                                <Crown class="mr-1.5 h-4 w-4" />
                                Upgrade paket
                            </Button>
                        </div>
                        <p v-if="store.description" class="text-base text-gray-600 sm:text-lg">
                            {{ store.description }}
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2 sm:mt-3 sm:gap-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="subscriptionStatusVariant" class="px-3 py-1">
                                    <Calendar class="mr-1 h-3 w-3" />
                                    {{ subscriptionStatusText }}
                                </Badge>
                                <span
                                    v-if="
                                        store.subscription_end &&
                                        daysRemaining > 0 &&
                                        !subscription?.starter_free &&
                                        subscriptionPlanBadge?.style !== 'starter'
                                    "
                                    class="flex items-center gap-1 text-sm text-gray-600"
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
                        <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl" aria-hidden="true">
                            <div class="absolute -top-10 -left-12 h-28 w-28 rounded-full bg-violet-400/20 blur-xl" />
                            <div class="absolute top-1/2 -right-6 h-28 w-28 -translate-y-1/2 rounded-full bg-fuchsia-400/25 blur-xl" />
                        </div>
                        <div
                            class="relative z-10 flex flex-col-reverse items-center gap-2 px-4 py-3 sm:flex-row sm:justify-between sm:gap-3 md:px-5 md:py-4"
                        >
                            <div class="flex max-w-xl min-w-0 flex-1 flex-col justify-center text-center sm:text-left">
                                <p class="mb-0.5 text-[10px] font-black tracking-[0.16em] text-violet-700 uppercase sm:text-[11px]">UMKM Kasir</p>
                                <h3
                                    class="bg-gradient-to-r from-violet-800 via-purple-800 to-fuchsia-700 bg-clip-text font-display text-lg leading-[1.2] font-black text-transparent sm:text-xl md:text-2xl"
                                >
                                    {{ isWarehouse ? 'Kelola stok gudang Anda' : 'Halo! Siap jualan hari ini?' }}
                                </h3>
                                <p class="mt-0.5 text-xs leading-tight text-violet-950/75 md:text-sm">
                                    {{
                                        isWarehouse
                                            ? 'Pantau stok masuk, transfer, dan ketersediaan barang dari gudang.'
                                            : 'Pantau omzet, stok, dan transaksi dari satu tempat—semangat melayani pelanggan.'
                                    }}
                                </p>
                            </div>
                            <!-- Tinggi slot tetap kecil agar kartu tidak melar; gambar lebih besar & overflow keluar -->
                            <div
                                class="relative flex h-[4.75rem] min-h-0 w-[min(55%,300px)] shrink-0 items-end justify-end self-end overflow-visible sm:h-[5rem] sm:self-auto md:h-[5.25rem]"
                            >
                                <img
                                    src="/maskot.webp"
                                    alt=""
                                    width="320"
                                    height="320"
                                    loading="lazy"
                                    decoding="async"
                                    class="pointer-events-none h-[9.75rem] w-auto max-w-[min(100%,240px)] origin-bottom translate-y-2 scale-100 object-contain object-bottom select-none sm:h-[11.25rem] sm:translate-y-3 sm:scale-105 md:h-[12.25rem] md:translate-y-4"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards — Gudang -->
                <div
                    v-if="isWarehouse"
                    class="dashboard-stat-cards @container/card-grid mb-8 grid grid-cols-2 gap-4 overflow-hidden md:grid-cols-2 md:gap-6 lg:grid-cols-4"
                >
                    <!-- Stok Masuk Hari Ini -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-green-400/80 to-emerald-400/60 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="h-full rounded-[2rem] border-0 bg-gradient-to-br from-green-50 to-white">
                            <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                <div class="mb-3 flex items-start gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-500/15 md:h-10 md:w-10">
                                        <ArrowDownCircle class="h-4 w-4 text-green-600 md:h-5 md:w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="label-metadata mb-1">Stok Masuk Hari Ini</p>
                                        <p class="truncate font-display text-base font-black text-green-700 sm:text-lg md:text-xl lg:text-2xl">
                                            {{ fmtQty(store?.today_stock_in ?? 0) }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-bold text-green-700">
                                        <ArrowDownCircle class="mr-1 h-3 w-3" />
                                        <span>Unit</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Transfer Menunggu Diterima -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-amber-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="h-full rounded-[2rem] border-0 bg-white">
                            <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                <div class="mb-3 flex items-start gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/10 md:h-10 md:w-10">
                                        <Truck class="h-4 w-4 text-amber-600 md:h-5 md:w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="label-metadata mb-1">Transfer Masuk Pending</p>
                                        <p class="truncate font-display text-base font-black text-amber-600 sm:text-lg md:text-xl lg:text-2xl">
                                            {{ store?.pending_incoming_transfers ?? 0 }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-600">
                                        <Truck class="mr-1 h-3 w-3" />
                                        <span>Transfer</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Transfer Keluar Hari Ini -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-red-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="h-full rounded-[2rem] border-0 bg-white">
                            <CardContent class="p-4 md:p-6">
                                <div class="mb-3 flex items-start gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-500/10 md:h-10 md:w-10">
                                        <ArrowUpCircle class="h-4 w-4 text-red-600 md:h-5 md:w-5" />
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="label-metadata mb-1">Transfer Keluar Hari Ini</p>
                                    <p class="truncate font-display text-base font-black text-red-600 sm:text-lg md:text-xl lg:text-2xl">
                                        {{ store?.today_transfers_out ?? 0 }}
                                    </p>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-bold text-red-600">
                                        <ArrowUpCircle class="mr-1 h-3 w-3" />
                                        <span>Kirim</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Total Produk -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-purple-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="h-full rounded-[2rem] border-0 bg-white">
                            <CardContent class="px-3 py-2 md:px-4 md:py-3">
                                <div class="mb-3 flex items-start gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-purple-500/10 md:h-10 md:w-10">
                                        <Warehouse class="h-4 w-4 text-purple-600 md:h-5 md:w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="label-metadata mb-1">Total Produk</p>
                                        <p class="truncate font-display text-base font-black text-foreground sm:text-lg md:text-xl lg:text-2xl">
                                            {{ store?.products_count ?? 0 }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-purple-50 px-2 py-1 text-xs font-bold text-purple-600">
                                        <Package class="mr-1 h-3 w-3" />
                                        <span>Di gudang</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Stats Cards — Outlet/POS -->
                <div
                    v-else-if="!isWarehouse"
                    class="dashboard-stat-cards @container/card-grid mb-8 grid grid-cols-2 gap-4 overflow-hidden md:grid-cols-4 md:gap-6"
                >
                    <!-- Total Pendapatan -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-emerald-400/80 to-cyan-400/60 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl lg:col-span-1"
                    >
                        <Card class="relative h-full overflow-hidden rounded-[2rem] border-0 bg-gradient-to-br from-emerald-50 to-white">
                            <div
                                class="pointer-events-none absolute -top-4 -right-4 flex h-24 w-24 items-center justify-center rounded-full bg-emerald-500/[.03] md:h-32 md:w-32"
                            >
                                <Wallet class="h-12 w-12 text-emerald-500/[.08] md:h-16 md:w-16" />
                            </div>
                            <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                <div class="relative mb-2 min-w-0">
                                    <p class="label-metadata mb-0.5 text-xs">Omset Bulan Lalu</p>
                                    <p class="truncate font-display text-lg font-black text-emerald-700 sm:text-xl md:text-2xl">
                                        Rp {{ formatCurrency(lastMonthOmset) }}
                                    </p>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700">
                                        <TrendingUp class="mr-1 h-3 w-3" />
                                        <span>Bulan lalu</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Today's Sales -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-green-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="relative h-full overflow-hidden rounded-[2rem] border-0 bg-white">
                            <div
                                class="pointer-events-none absolute -top-4 -right-4 flex h-24 w-24 items-center justify-center rounded-full bg-green-500/[.03] md:h-32 md:w-32"
                            >
                                <DollarSign class="h-12 w-12 text-green-500/[.08] md:h-16 md:w-16" />
                            </div>
                            <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                <div class="relative mb-2 min-w-0">
                                    <p class="label-metadata mb-0.5 text-xs">Penjualan Hari Ini</p>
                                    <p class="truncate font-display text-lg font-black text-foreground sm:text-xl md:text-2xl">
                                        {{ formatCurrency(store.today_sales ?? 0) }}
                                    </p>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        v-if="salesGrowthPercent !== null"
                                        class="flex items-center rounded-full px-2 py-1 text-xs font-bold"
                                        :class="salesGrowthPercent >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'"
                                    >
                                        <component :is="salesGrowthPercent >= 0 ? TrendingUp : TrendingDown" class="mr-1 h-3 w-3" />
                                        <span>{{ salesGrowthPercent >= 0 ? '+' : '' }}{{ salesGrowthPercent.toFixed(1) }}%</span>
                                    </div>
                                    <div v-else class="flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-bold text-green-600">
                                        <TrendingUp class="mr-1 h-3 w-3" />
                                        <span>Hari ini</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Today's Transactions -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-cyan-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="relative h-full overflow-hidden rounded-[2rem] border-0 bg-white">
                            <div
                                class="pointer-events-none absolute -top-4 -right-4 flex h-24 w-24 items-center justify-center rounded-full bg-cyan-500/[.03] md:h-32 md:w-32"
                            >
                                <ShoppingBag class="h-12 w-12 text-cyan-500/[.08] md:h-16 md:w-16" />
                            </div>
                            <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                <div class="relative mb-2 min-w-0">
                                    <p class="label-metadata mb-0.5 text-xs">Transaksi Hari Ini</p>
                                    <p class="truncate font-display text-lg font-black text-foreground sm:text-xl md:text-2xl">
                                        {{ store.today_transactions ?? 0 }}
                                    </p>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div
                                        v-if="transactionsGrowthPercent !== null"
                                        class="flex items-center rounded-full px-2 py-1 text-xs font-bold"
                                        :class="transactionsGrowthPercent >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'"
                                    >
                                        <component :is="transactionsGrowthPercent >= 0 ? TrendingUp : TrendingDown" class="mr-1 h-3 w-3" />
                                        <span>{{ transactionsGrowthPercent >= 0 ? '+' : '' }}{{ transactionsGrowthPercent.toFixed(1) }}%</span>
                                    </div>
                                    <div v-else class="flex items-center rounded-full bg-cyan-50 px-2 py-1 text-xs font-bold text-cyan-600">
                                        <ShoppingBag class="mr-1 h-3 w-3" />
                                        <span>Hari ini</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Low Stock Alert -->
                    <div
                        class="rounded-[2.2rem] bg-gradient-to-br from-orange-400/70 to-white/10 p-[2px] shadow-lg transition-all duration-300 hover:shadow-xl"
                    >
                        <Card class="relative h-full overflow-hidden rounded-[2rem] border-0 bg-white">
                            <div
                                class="pointer-events-none absolute -top-4 -right-4 flex h-24 w-24 items-center justify-center rounded-full bg-orange-500/[.03] md:h-32 md:w-32"
                            >
                                <AlertTriangle class="h-12 w-12 text-orange-500/[.08] md:h-16 md:w-16" />
                            </div>
                            <CardContent class="relative px-3 py-1.5 md:px-4 md:py-2">
                                <div class="relative mb-2 min-w-0">
                                    <p class="label-metadata mb-0.5 text-xs">Stok Rendah</p>
                                    <p class="truncate font-display text-lg font-black text-orange-600 sm:text-xl md:text-2xl">
                                        {{ lowStockCount ?? 0 }}
                                    </p>
                                </div>
                                <div class="flex items-center justify-end">
                                    <div class="flex items-center rounded-full bg-orange-50 px-2 py-1 text-xs font-bold text-orange-600">
                                        <AlertTriangle class="mr-1 h-3 w-3" />
                                        <span>Perhatian</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Main Content Grid — Gudang -->
                <div v-if="isWarehouse" class="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <Card class="border-0 shadow-lg">
                            <CardHeader class="pb-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <CardTitle class="text-xl font-bold text-gray-900">Riwayat Mutasi Stok</CardTitle>
                                        <CardDescription class="text-gray-600">5 aktivitas stok terakhir di gudang ini</CardDescription>
                                    </div>
                                    <Link
                                        v-if="canInventory"
                                        href="/stock-movements"
                                        as="button"
                                        class="inline-flex items-center rounded-md border border-blue-200 bg-white px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-blue-50 md:px-3 md:py-2 md:text-sm"
                                    >
                                        <Eye class="mr-1 h-3 w-3 md:mr-2 md:h-4 md:w-4" />
                                        <span class="hidden sm:inline">Lihat Semua</span>
                                        <span class="sm:hidden">Lihat</span>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div v-if="recentMovements && recentMovements.length > 0" class="space-y-2 md:space-y-4">
                                    <div
                                        v-for="m in recentMovements"
                                        :key="m.id"
                                        class="flex items-center justify-between rounded-2xl bg-gray-50 p-2 md:p-4"
                                    >
                                        <div class="flex min-w-0 flex-1 items-center space-x-2 md:space-x-4">
                                            <div
                                                :class="[
                                                    'h-2 w-2 flex-shrink-0 rounded-full md:h-3 md:w-3',
                                                    m.quantity_change > 0 ? 'bg-green-500' : 'bg-red-500',
                                                ]"
                                            ></div>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-xs font-medium text-gray-900 md:text-sm">{{ m.product?.name ?? '-' }}</p>
                                                <p class="truncate text-xs text-gray-500 md:text-sm">{{ m.type }}</p>
                                            </div>
                                        </div>
                                        <div class="ml-2 flex-shrink-0 text-right">
                                            <p
                                                :class="[
                                                    'text-xs font-semibold tabular-nums md:text-sm',
                                                    m.quantity_change > 0 ? 'text-green-600' : 'text-red-600',
                                                ]"
                                            >
                                                {{ m.quantity_change > 0 ? '+' : '' }}{{ fmtQty(m.quantity_change) }}
                                            </p>
                                            <p class="text-xs text-gray-500">{{ formatDate(m.created_at) }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="py-12 text-center">
                                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 p-4">
                                        <Warehouse class="h-8 w-8 text-gray-400" />
                                    </div>
                                    <p class="text-lg text-gray-500">Belum ada aktivitas stok</p>
                                    <p class="mt-2 text-sm text-gray-400">Mulai dengan membuat pembelian atau transfer</p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Sidebar: Stok Rendah + Batch ED -->
                    <div class="space-y-8">
                        <DashboardLowStockCard :products="lowStockProducts" :low-stock-count="lowStockCount" />

                        <DashboardExpiringBatchesCard :batches="expiringBatches" :batch-expiry-warn-days="batch_expiry_warn_days" />
                    </div>
                </div>

                <!-- Dashboard Insight Widgets — Outlet/POS -->
                <div v-if="!isWarehouse" class="mb-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
                    <DashboardTrendChart :data="weeklyTrend ?? []" />
                    <DashboardPeakHours :data="peakHours ?? []" />
                </div>

                <!-- Main Content Grid — Outlet/POS -->
                <div v-if="!isWarehouse" class="grid grid-cols-1 gap-8 lg:grid-cols-3">
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
                                        class="inline-flex items-center rounded-md border border-blue-200 bg-white px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-blue-50 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none md:px-3 md:py-2 md:text-sm"
                                    >
                                        <Eye class="mr-1 h-3 w-3 md:mr-2 md:h-4 md:w-4" />
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
                                        class="flex items-center justify-between rounded-2xl bg-gray-50 p-2 transition-colors md:p-4"
                                        :class="
                                            canViewInvoice
                                                ? 'cursor-pointer hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40'
                                                : ''
                                        "
                                        @click="canViewInvoice && openReceiptModal(transaction.id)"
                                        @keydown.enter.prevent="canViewInvoice && openReceiptModal(transaction.id)"
                                        @keydown.space.prevent="canViewInvoice && openReceiptModal(transaction.id)"
                                    >
                                        <div class="flex min-w-0 flex-1 items-center space-x-2 md:space-x-4">
                                            <div class="h-2 w-2 flex-shrink-0 rounded-full bg-green-500 md:h-3 md:w-3"></div>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-xs font-medium text-gray-900 md:text-sm">
                                                    {{ transaction.transaction_number }}
                                                </p>
                                                <p class="truncate text-xs text-gray-500 md:text-sm">
                                                    {{ transaction.customer?.name || 'Walk-in Customer' }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="ml-2 flex flex-shrink-0 flex-col items-end gap-1 text-right">
                                            <p class="text-xs font-semibold text-gray-900 tabular-nums md:text-sm">
                                                {{ formatCurrency(transaction.total_amount) }}
                                            </p>
                                            <p class="text-xs text-gray-500 md:text-sm">
                                                {{ formatDate(transaction.created_at) }}
                                            </p>
                                            <span v-if="canViewInvoice" class="text-[10px] font-semibold text-primary md:text-xs">
                                                Lihat struk →
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="py-12 text-center">
                                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 p-4">
                                        <ShoppingCart class="h-8 w-8 text-gray-400" />
                                    </div>
                                    <p class="text-lg text-gray-500">Belum ada transaksi hari ini</p>
                                    <p class="mt-2 text-sm text-gray-400">Mulai dengan membuka POS</p>
                                    <Button as="a" href="/pos" class="mt-4 rounded-xl">
                                        <ShoppingCart class="mr-2 h-4 w-4" />
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

                        <DashboardExpiringBatchesCard :batches="expiringBatches" :batch-expiry-warn-days="batch_expiry_warn_days">
                            <Button
                                v-if="canInventory"
                                as="a"
                                href="/products"
                                variant="outline"
                                size="sm"
                                class="w-full rounded-xl border-amber-300 text-amber-900"
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
                    <div class="flex min-h-[120px] items-center justify-center px-4 pt-3 pb-2">
                        <Loader2 v-if="receiptLoading" class="h-8 w-8 animate-spin text-primary" />
                        <ThermalReceiptStruk
                            v-else-if="receiptPayloadTx"
                            :transaction="receiptPayloadTx"
                            :store-receipt-fallback="store?.receipt"
                            :tax-name-fallback="store?.tax_name"
                            :pharmacy-enabled="receiptPharmacyEnabled"
                        />
                    </div>
                    <div v-if="!receiptLoading && receiptPayloadTx" class="flex flex-col gap-2 px-4 pb-4">
                        <Button class="w-full gap-2 rounded-xl" type="button" :disabled="isPrinterPrinting" @click="printDashboardReceipt">
                            <Loader2 v-if="isPrinterPrinting" class="h-4 w-4 animate-spin" />
                            <Printer v-else class="h-4 w-4" />
                            {{ isPrinterPrinting ? 'Mencetak...' : 'Cetak struk' }}
                        </Button>
                        <Button variant="outline" class="w-full rounded-xl" type="button" @click="receiptModalOpen = false"> Selesai </Button>
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
                        <Button variant="secondary" @click="showProductModal = false"> Nanti Saja </Button>
                        <Link href="/products/create">
                            <Button type="button"> Buat Produk </Button>
                        </Link>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </template>
    </AdminLayout>

    <!-- Floating AI Assistant Button -->
    <button
        v-if="isAiChatEnabled"
        type="button"
        class="fixed right-4 bottom-24 z-50 flex h-20 w-20 items-center justify-center transition-all hover:scale-105 sm:right-6 sm:bottom-24 sm:h-20 sm:w-20 lg:bottom-6 lg:h-24 lg:w-24"
        title="AI Assistant (Ctrl+Shift+A)"
        @click="showAiSidebar = !showAiSidebar"
    >
        <img src="/mas.webp" alt="AI Assistant" class="h-16 w-16 object-contain sm:h-18 sm:w-18 lg:h-20 lg:w-20" />
    </button>

    <!-- AI Assistant Sidebar -->
    <AiAssistantSidebar
        v-if="isAiChatEnabled"
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
