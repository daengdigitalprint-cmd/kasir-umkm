<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Home, ShoppingCart, Package, Users, BarChart3, Settings, LogOut, Crown, Clock, Tag, History, Truck, ClipboardList, ClipboardCheck, Undo2, ArrowRightLeft, ChefHat, Layers, Shirt, Gift, Building2, Eye, Wallet, ListOrdered } from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

const emit = defineEmits(['close-mobile-menu']);
const page = usePage();

type NavItem = {
    name: string;
    href: string;
    icon: LucideIcon;
    /** Laporan, diskon, pelanggan — admin & manajer saja. */
    backofficeOnly?: boolean;
    /** Stok, produk, pembelian, transfer — termasuk admin gudang. */
    inventoryItem?: boolean;
    /** Menu Penyesuaian stok — peran inventory + kasir. */
    adjustStock?: boolean;
    /** POS & shift kasir. */
    posOnly?: boolean;
    /** Pengelolaan toko hanya untuk admin toko. */
    storeAdminOnly?: boolean;
    /** Warung makan / kafe (tipe toko F&B). */
    fnbOnly?: boolean;
    /** Usaha laundry (tipe toko Laundry). */
    laundryOnly?: boolean;
    /** Disembunyikan untuk toko laundry (mis. POS umum diganti menu Order Laundry). */
    hideOnLaundry?: boolean;
    /** Diskon & transfer antar outlet — paket GrowUp ke atas. */
    advancedRetailOnly?: boolean;
    /** Hanya tampil jika menu Resep diaktifkan di pengaturan toko. */
    recipesOnly?: boolean;
    /** Hanya tampil jika menu Produksi diaktifkan di pengaturan toko. */
    productionsOnly?: boolean;
    /** Hanya tampil jika menu Antrian Dapur diaktifkan di pengaturan toko. */
    kitchenQueueOnly?: boolean;
    /** Hanya tampil jika Modul Antrian diaktifkan di pengaturan toko. */
    queueModuleOnly?: boolean;
    /** Disembunyikan jika toko mengaktifkan direct_stock_edit (koreksi via form produk). */
    hideOnDirectStockEdit?: boolean;
    /** Disembunyikan untuk gudang (pusat/cabang). */
    hideOnWarehouse?: boolean;
    /** Menu "Lihat Stok" — tampil untuk POS & inventory, sembunyi untuk owner. */
    posAndViewStock?: boolean;
    /** Menu khusus owner. */
    ownerOnly?: boolean;
    /** Disembunyikan untuk owner (menu operasional: POS, shift, dll). */
    hideOnOwner?: boolean;
};

type ProcessedNavItem = NavItem & {
    /** Menu disabled karena tidak memiliki akses paket */
    disabled?: boolean;
    /** Tooltip yang menjelaskan kenapa disabled */
    disabledReason?: string;
    /** Jumlah antrian pending untuk badge notifikasi */
    pendingCount?: number;
};

const navigationSource: NavItem[] = [
    { name: 'Dashboard', href: '/dashboard', icon: Home },
    { name: 'POS', href: '/pos', icon: ShoppingCart, posOnly: true, hideOnLaundry: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Shift kasir', href: '/cash-shifts', icon: Clock, posOnly: true, advancedRetailOnly: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Uang Masuk/Keluar', href: '/cash-shifts/transactions', icon: ArrowRightLeft, posOnly: true, advancedRetailOnly: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Antrian dapur', href: '/kitchen', icon: ChefHat, posOnly: true, kitchenQueueOnly: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Antrian', href: '/queues', icon: ListOrdered, posOnly: true, queueModuleOnly: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Order Laundry', href: '/laundry-orders', icon: Shirt, posOnly: true, laundryOnly: true, hideOnWarehouse: true, hideOnOwner: true },
    { name: 'Lihat Stok', href: '/stock-overview', icon: Eye, posAndViewStock: true, hideOnOwner: true },
    { name: 'Produk', href: '/products', icon: Package, inventoryItem: true },
    { name: 'Kategori', href: '/product-categories', icon: Layers, inventoryItem: true },
    { name: 'Riwayat stok', href: '/stock-movements', icon: History, inventoryItem: true, hideOnOwner: true },
    { name: 'Penyesuaian stok', href: '/stock-adjustments', icon: ClipboardCheck, adjustStock: true, advancedRetailOnly: true, hideOnOwner: true, hideOnDirectStockEdit: true },
    { name: 'Stock opname', href: '/stock-opnames', icon: ClipboardList, inventoryItem: true, advancedRetailOnly: true, hideOnOwner: true, hideOnDirectStockEdit: true },
    { name: 'Resep', href: '/recipes', icon: ChefHat, inventoryItem: true, recipesOnly: true },
    { name: 'Produksi', href: '/productions', icon: Layers, inventoryItem: true, productionsOnly: true },
    { name: 'Transfer stok', href: '/stock-transfers', icon: ArrowRightLeft, inventoryItem: true, advancedRetailOnly: true },
    { name: 'Pembelian', href: '/purchases', icon: Truck, inventoryItem: true, advancedRetailOnly: true },
    { name: 'Retur pembelian', href: '/purchase-returns', icon: Undo2, inventoryItem: true, advancedRetailOnly: true },
    { name: 'Pre-order', href: '/pre-orders', icon: ClipboardList, inventoryItem: true, advancedRetailOnly: true },
    { name: 'Diskon', href: '/discounts', icon: Tag, backofficeOnly: true, advancedRetailOnly: true, hideOnWarehouse: true },
    { name: 'Pelanggan', href: '/customers', icon: Users, backofficeOnly: true, hideOnWarehouse: true },
    { name: 'Loyalitas', href: '/loyalty', icon: Gift, backofficeOnly: true, advancedRetailOnly: true, hideOnWarehouse: true },
    { name: 'Laporan', href: '/reports', icon: BarChart3, backofficeOnly: true },
    { name: 'Biaya Usaha', href: '/expenses', icon: Wallet, backofficeOnly: true },
    { name: 'Tambah Outlet/Gudang', href: '/stores/create', icon: Building2, storeAdminOnly: true },
    { name: 'Kelola Produk', href: '/manage-products', icon: Package, storeAdminOnly: true },
    { name: 'Tim & Akses', href: '/manage-staff', icon: Users, storeAdminOnly: true },
    { name: 'Pengaturan', href: '/settings', icon: Settings },
];

const navigationItems = computed((): ProcessedNavItem[] => {
    const backoffice = Boolean((page.props as { abilities?: { backoffice?: boolean } }).abilities?.backoffice);
    const inventory = Boolean((page.props as { abilities?: { inventory?: boolean } }).abilities?.inventory);
    const stockAdjust = Boolean((page.props as { abilities?: { stock_adjustment?: boolean } }).abilities?.stock_adjustment);
    const pos = Boolean((page.props as { abilities?: { pos?: boolean } }).abilities?.pos);
    const isOwner = Boolean((page.props as { abilities?: { owner?: boolean } }).abilities?.owner);
    const manageStoreSettings = Boolean(
        (page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings,
    );
    const fnb = Boolean((page.props as { fnb_enabled?: boolean }).fnb_enabled);
    const laundry = Boolean((page.props as { laundry_enabled?: boolean }).laundry_enabled);
    const recipesEnabled = Boolean((page.props as { recipes_enabled?: boolean }).recipes_enabled);
    const productionsEnabled = Boolean((page.props as { productions_enabled?: boolean }).productions_enabled);
    const kitchenQueueEnabled = Boolean((page.props as { kitchen_queue_enabled?: boolean }).kitchen_queue_enabled);
    const queueModuleEnabled = Boolean((page.props as { queue_module_enabled?: boolean }).queue_module_enabled);
    const pendingQueueCount = Number((page.props as { pending_queue_count?: number }).pending_queue_count ?? 0);
    const locationType = String((page.props as { location_type?: string }).location_type ?? 'outlet');
    const isWarehouse = locationType === 'central_warehouse' || locationType === 'branch_warehouse';
    const planLimits = (page.props as { plan_limits?: { allows_advanced_retail?: boolean } | null }).plan_limits;
    /** Tanpa plan_limits (belum ada konteks toko): biarkan seperti legacy. Ada plan: harus eksplisit true. */
    const allowAdvanced =
        planLimits == null ? true : planLimits.allows_advanced_retail === true;
    const directStockEdit = Boolean((page.props as { direct_stock_edit?: boolean }).direct_stock_edit);
    
    return navigationSource.flatMap((item): ProcessedNavItem[] => {
        // Sembunyikan menu operasional untuk owner
        if (isOwner && item.hideOnOwner) {
            return [];
        }

        // Sembunyikan menu yang tidak punya akses
        if (item.backofficeOnly && !backoffice) {
            return [];
        }
        if (item.inventoryItem && !inventory) {
            return [];
        }
        if (item.adjustStock && !stockAdjust) {
            return [];
        }
        if (item.posOnly && !pos) {
            return [];
        }
        if (item.storeAdminOnly && !manageStoreSettings) {
            return [];
        }
        if (item.ownerOnly && !isOwner) {
            return [];
        }
        if (item.fnbOnly && !fnb) {
            return [];
        }
        if (item.laundryOnly && !laundry) {
            return [];
        }
        if (item.hideOnLaundry && laundry) {
            return [];
        }
        if (item.posAndViewStock && !pos && !inventory) {
            return [];
        }
        if (item.recipesOnly && !recipesEnabled) {
            return [];
        }
        if (item.productionsOnly && !productionsEnabled) {
            return [];
        }
        if (item.kitchenQueueOnly && !kitchenQueueEnabled) {
            return [];
        }
        if (item.queueModuleOnly && !queueModuleEnabled) {
            return [];
        }
        if (item.hideOnWarehouse && isWarehouse) {
            return [];
        }
        if (item.hideOnDirectStockEdit && directStockEdit) {
            return [];
        }

        // Owner: Dashboard mengarah ke /owner/dashboard
        if (isOwner && item.name === 'Dashboard') {
            return [{ ...item, href: '/owner/dashboard' }];
        }
        
        // Filter berdasarkan paket - untuk paket starter, tampilkan tapi disabled
        if (item.advancedRetailOnly && !allowAdvanced) {
            return [{ ...item, disabled: true, disabledReason: 'Upgrade ke paket berbayar untuk mengakses fitur ini' }];
        }
        
        // Tambah badge pending count untuk menu Antrian
        if (item.queueModuleOnly && pendingQueueCount > 0) {
            return [{ ...item, pendingCount: pendingQueueCount }];
        }

        return [item];
    });
});

const isCurrentRoute = (href: string) => {
    if (href === '/dashboard' && page.url !== '/dashboard') {
        return false;
    }
    if (href === '/owner/dashboard') {
        return page.url.startsWith('/owner/dashboard');
    }
    return page.url.startsWith(href);
};

const subscription = computed(() => (page.props as any).subscription);
const planLimits = computed(() => (page.props as { plan_limits?: { plan_slug?: string | null } | null }).plan_limits);
const isStarterPlan = computed(() => {
    const slug = planLimits.value?.plan_slug ?? null;
    return slug === 'starter' || slug === 'starter-tahunan';
});

const subscriptionStatusText = computed(() => {
    if (!subscription.value) return '';
    if (isStarterPlan.value && subscription.value.is_active) return 'Aktif';
    switch (subscription.value.status) {
        case 'active': return 'Aktif';
        case 'expiring':
            return subscription.value.days_remaining != null
                ? `${subscription.value.days_remaining} hari lagi`
                : 'Akan berakhir';
        case 'expired': return 'Expired';
        case 'unlimited': return 'Unlimited';
        default: return 'Nonaktif';
    }
});

/** Kartu ungu pastel (bukan status peringatan merah/oranye) */
const subscriptionCardSoft = computed(() => {
    if (isStarterPlan.value && subscription.value?.is_active) return true;
    const s = subscription.value?.status;
    return s !== 'expiring' && s !== 'expired';
});
</script>

<template>
    <div class="flex flex-col h-full w-full">
        <!-- Section Header -->
        <div class="flex items-center px-4 mb-4 mt-2">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">PLATFORM UTAMA</p>
        </div>
        
        <nav class="space-y-1 relative">
            <div v-for="item in navigationItems" :key="item.name" class="relative group/menu">
                <component
                    :is="item.disabled ? 'div' : Link"
                    :href="item.disabled ? undefined : item.href" 
                    @click="!item.disabled && emit('close-mobile-menu')" 
                    :class="[
                        'block outline-none',
                        item.disabled ? 'cursor-not-allowed' : ''
                    ]"
                >
                    <div
                        :class="[
                            'flex items-center w-full h-12 px-4 rounded-2xl transition-all duration-300 group relative overflow-hidden',
                            item.disabled 
                                ? 'opacity-50 cursor-not-allowed'
                                : isCurrentRoute(item.href) 
                                    ? 'bg-primary/10 text-primary' 
                                    : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800'
                        ]"
                    >
                        <component 
                            :is="item.icon" 
                            :class="[
                                'size-5 mr-4 z-10',
                                item.disabled
                                    ? 'text-slate-300'
                                    : 'transition-transform duration-300 group-hover:scale-110',
                                !item.disabled && isCurrentRoute(item.href) ? 'text-primary' : '',
                                !item.disabled && !isCurrentRoute(item.href) ? 'text-slate-400 group-hover:text-slate-800' : ''
                            ]"
                        />
                        <span 
                            :class="[
                                'font-bold text-sm tracking-tight z-10',
                                item.disabled
                                    ? 'text-slate-400'
                                    : isCurrentRoute(item.href) 
                                        ? 'text-primary' 
                                        : 'text-slate-500 group-hover:text-slate-800'
                            ]"
                        >
                            {{ item.name }}
                        </span>
                        
                        <!-- Badge notifikasi antrian pending -->
                        <span
                            v-if="item.pendingCount && item.pendingCount > 0"
                            class="ml-auto flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-black z-10"
                        >
                            {{ item.pendingCount > 99 ? '99+' : item.pendingCount }}
                        </span>

                        <!-- Badge Premium untuk fitur berbayar -->
                        <Crown 
                            v-if="item.disabled && item.disabledReason?.includes('paket')"
                            class="size-4 ml-auto text-amber-400 z-10"
                        />

                        <!-- Active Indicator side border -->
                        <div 
                            v-if="!item.disabled && isCurrentRoute(item.href)"
                            class="absolute left-[-4px] top-2 bottom-2 w-2 bg-primary rounded-r-full shadow-[2px_0_8px_rgba(124,58,237,0.4)] z-20"
                        ></div>
                    </div>
                </component>
                
                <!-- Tooltip untuk menu disabled -->
                <div 
                    v-if="item.disabled && item.disabledReason"
                    class="absolute right-3 top-1/2 -translate-y-1/2 z-50 pointer-events-none opacity-0 group-hover/menu:opacity-100 transition-opacity duration-200 max-w-[160px]"
                >
                    <div class="bg-slate-900 text-white text-[10px] px-2 py-1 rounded-lg shadow-lg text-right leading-tight">
                        {{ item.disabledReason }}
                    </div>
                </div>
            </div>
        </nav>

        <!-- Subscription Section -->
        <p class="px-4 text-[10px] font-black uppercase tracking-[0.2em] text-violet-400/90 mb-4 mt-8">SUBSCRIPTION</p>
        
        <!-- Subscription Status Card -->
        <Link 
            href="/subscription" 
            @click="emit('close-mobile-menu')" 
            class="block outline-none mx-1"
        >
            <div :class="[
                'relative overflow-hidden rounded-2xl p-4 min-h-[118px] transition-all duration-300 group cursor-pointer ring-1 ring-inset',
                subscriptionCardSoft
                    ? [
                        'bg-gradient-to-br from-violet-100 via-purple-50 to-fuchsia-100/90 ring-violet-200/70',
                        isCurrentRoute('/subscription')
                            ? 'shadow-lg shadow-violet-300/35 scale-[1.02]'
                            : 'hover:shadow-lg hover:shadow-violet-200/50 hover:scale-[1.01]',
                    ]
                    : [
                        'ring-white/15',
                        isCurrentRoute('/subscription')
                            ? 'shadow-lg scale-[1.02]'
                            : 'hover:shadow-lg hover:scale-[1.01]',
                        subscription?.status === 'expiring' ? 'bg-gradient-to-br from-amber-500 to-orange-600' : '',
                        subscription?.status === 'expired' ? 'bg-gradient-to-br from-red-500 to-rose-600' : '',
                    ],
            ]">
                <!-- Decorative circle -->
                <div
                    :class="[
                        'absolute top-0 right-0 w-20 h-20 rounded-full -translate-y-10 translate-x-10 z-[0]',
                        subscriptionCardSoft ? 'bg-violet-300/25' : 'bg-white/10',
                    ]"
                ></div>
                <!-- Maskot -->
                <img
                    src="/subscription.webp"
                    alt=""
                    width="160"
                    height="160"
                    loading="lazy"
                    decoding="async"
                    :class="[
                        'pointer-events-none select-none absolute -right-1 bottom-0 z-[1] h-[5.75rem] w-auto object-contain object-bottom group-hover:scale-105 group-hover:-translate-y-0.5 transition-transform duration-300',
                        subscriptionCardSoft
                            ? 'drop-shadow-[0_4px_12px_rgba(91,33,182,0.15)]'
                            : 'drop-shadow-[0_6px_16px_rgba(0,0,0,0.2)]',
                    ]"
                />
                <div class="relative z-10 max-w-[72%] pr-1 overflow-hidden">
                    <div class="flex items-center gap-2.5 mb-2">
                        <Crown
                            :class="[
                                'h-5 w-5 shrink-0 drop-shadow-sm',
                                subscriptionCardSoft ? 'text-violet-500' : 'text-amber-200',
                            ]"
                        />
                        <span
                            :class="[
                                'font-black text-sm leading-tight',
                                subscriptionCardSoft ? 'text-violet-950' : 'text-white',
                            ]"
                        >
                            Subscription
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <Clock
                                v-if="subscription?.days_remaining != null && subscription.days_remaining > 0 && !subscription?.starter_free && !isStarterPlan"
                                :class="['h-3 w-3 shrink-0', subscriptionCardSoft ? 'text-violet-500/80' : 'text-white/70']"
                            />
                            <span
                            :class="[
                                'text-xs font-semibold leading-snug truncate',
                                subscriptionCardSoft ? 'text-violet-800/90' : 'text-white/90',
                            ]"
                        >
                            {{ subscriptionStatusText || 'Lihat Paket' }}
                        </span>
                        </div>
                        <div
                            v-if="subscription?.has_pending_payment"
                            :class="[
                                'w-2 h-2 rounded-full animate-pulse shrink-0',
                                subscriptionCardSoft ? 'bg-amber-500' : 'bg-yellow-300',
                            ]"
                        ></div>
                    </div>
                </div>
            </div>
        </Link>
        
        <!-- System Section -->
        <p class="px-4 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4 mt-8">SISTEM</p>
        
        <!-- Logout Button -->
        <Link
            href="/logout"
            method="post"
            as="button"
            class="w-full text-left outline-none"
            @click="emit('close-mobile-menu')"
        >
            <div class="flex items-center w-full h-12 px-4 text-red-600 hover:bg-red-50 rounded-2xl transition-all duration-300 group group-hover:scale-105">
                <LogOut class="size-5 mr-4 transition-transform duration-300 group-hover:scale-110" />
                <span class="font-bold text-sm tracking-tight">Keluar</span>
            </div>
        </Link>
    </div>
</template>
