<script setup lang="ts">
import PwaInstallBanner from '@/components/PwaInstallBanner.vue';
import UnclosedPeriodBanner from '@/components/UnclosedPeriodBanner.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import MainMenu from '@/components/MainMenu.vue';
import ConnectionStatus from '@/components/ConnectionStatus.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { resolveProfileImageSrc } from '@/lib/profileAvatar';
import { Head, usePage, router } from '@inertiajs/vue3';
import { BarChart3, Bell, CalendarCheck, Crown, History, Home, Menu, Package, Settings, ShoppingCart, Store, Users, X, PanelLeft, Shirt, ReceiptText, Clock, ArrowRightLeft } from 'lucide-vue-next';
import { computed, ref, onMounted, onUnmounted } from 'vue';

interface AccessibleStoreRow {
    id: number;
    name: string;
    slug: string;
    organization_id?: number | null;
    organization_name?: string | null;
}

interface Props {
    title: string;
    /** Mode layar penuh: sembunyikan sidebar, header, & bottom nav (khusus halaman POS tablet/desktop). */
    fullscreen?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    fullscreen: false,
});
const page = usePage();

const authUser = computed(() => page.props.auth?.user);
const pendingTableOrderCount = computed(() => Number((page.props as { pending_table_order_count?: number }).pending_table_order_count ?? 0));

/** Store aktif user login — dipakai driver sinkronisasi offline global. */
const activeStoreId = computed(() => Number((authUser.value as { store_id?: number | null } | undefined)?.store_id ?? 0));
const activeUserId = computed(() => Number((authUser.value as { id?: number | null } | undefined)?.id ?? 0));

const accessibleStores = computed(() => page.props.accessible_stores ?? []);

const storeSelectGroups = computed((): { label: string; options: AccessibleStoreRow[] }[] => {
    const raw = accessibleStores.value as AccessibleStoreRow[];
    if (!raw.length) {
        return [];
    }
    const withOrg = new Map<number, AccessibleStoreRow[]>();
    const without: AccessibleStoreRow[] = [];
    for (const s of raw) {
        if (s.organization_id != null && s.organization_name) {
            const list = withOrg.get(s.organization_id) ?? [];
            list.push(s);
            withOrg.set(s.organization_id, list);
        } else {
            without.push(s);
        }
    }
    const out: { label: string; options: AccessibleStoreRow[] }[] = [];
    const orgIds = [...withOrg.keys()].sort((a, b) => {
        const na = withOrg.get(a)?.[0]?.organization_name ?? '';
        const nb = withOrg.get(b)?.[0]?.organization_name ?? '';
        return na.localeCompare(nb, 'id');
    });
    for (const id of orgIds) {
        const opts = (withOrg.get(id) ?? []).slice().sort((a, b) => a.name.localeCompare(b.name, 'id'));
        const label = opts[0]?.organization_name ?? 'Grup';
        out.push({ label, options: opts });
    }
    if (without.length) {
        out.push({
            label: 'Lainnya',
            options: without.slice().sort((a, b) => a.name.localeCompare(b.name, 'id')),
        });
    }
    if (!out.length) {
        return [{ label: 'Toko', options: raw.slice().sort((a, b) => a.name.localeCompare(b.name, 'id')) }];
    }
    return out;
});

const canBackoffice = computed(() => Boolean((page.props as { abilities?: { backoffice?: boolean } }).abilities?.backoffice));

const canInventory = computed(() => Boolean((page.props as { abilities?: { inventory?: boolean } }).abilities?.inventory));

const canPos = computed(() => Boolean((page.props as { abilities?: { pos?: boolean } }).abilities?.pos));
const isOwner = computed(() => Boolean((page.props as { abilities?: { owner?: boolean } }).abilities?.owner));
const isCashier = computed(() => Boolean((page.props as { abilities?: { cashier?: boolean } }).abilities?.cashier));
const laundryEnabled = computed(() => Boolean((page.props as { laundry_enabled?: boolean }).laundry_enabled));
const attendanceEnabled = computed(() => Boolean((page.props as { attendance_enabled?: boolean }).attendance_enabled));

function onSwitchStore(ev: Event) {
    const el = ev.target as HTMLSelectElement;
    const id = Number(el.value);
    if (!id || id === authUser.value?.store_id) {
        return;
    }
    router.post('/stores/switch', { store_id: id }, { preserveScroll: true });
}

const isMobileMenuOpen = ref(false);

// Track landscape orientation on mobile/tablet
const isLandscape = ref(false);
const isMobileOrTablet = ref(false);

const checkOrientation = () => {
    isLandscape.value = window.matchMedia('(orientation: landscape)').matches;
    // Consider mobile/tablet as screens < 1024px (lg breakpoint)
    isMobileOrTablet.value = window.innerWidth < 1024;
};

// Desktop sidebar hanya tampil di desktop (lg breakpoint >= 1024px)
// Mobile/tablet selalu pakai hamburger menu drawer, termasuk landscape
const showDesktopSidebar = computed(() => {
    return !isMobileOrTablet.value;
});

onMounted(() => {
    checkOrientation();
    window.addEventListener('resize', checkOrientation);
    window.addEventListener('orientationchange', checkOrientation);
    pendingOrderPollId = setInterval(refreshPendingOrders, 10000);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkOrientation);
    window.removeEventListener('orientationchange', checkOrientation);
    if (pendingOrderPollId !== null) {
        clearInterval(pendingOrderPollId);
    }
});

// Toggle desktop sidebar dengan persistensi localStorage
const SIDEBAR_LS_KEY = 'sidebar_state';
const isDesktopSidebarOpen = ref(
    typeof localStorage !== 'undefined'
        ? localStorage.getItem(SIDEBAR_LS_KEY) !== 'false'
        : true,
);
const toggleDesktopSidebar = () => {
    isDesktopSidebarOpen.value = !isDesktopSidebarOpen.value;
    if (typeof localStorage !== 'undefined') {
        localStorage.setItem(SIDEBAR_LS_KEY, String(isDesktopSidebarOpen.value));
    }
};

const toggleMobileMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
};

const closeMobileMenu = () => {
    isMobileMenuOpen.value = false;
};

let pendingOrderPollId: ReturnType<typeof setInterval> | null = null;

const refreshPendingOrders = () => {
    try {
        router.reload({ only: ['pending_table_order_count'], preserveScroll: true, preserveState: true });
    } catch (e) {
        console.debug('Refresh pesanan tertunda gagal:', e);
    }
};

const isCurrentRoute = (href: string) => {
    if (href === '/dashboard' && page.url !== '/dashboard') {
        return false;
    }
    // `/pos/transactions` jangan match `/pos`
    if (href === '/pos' && page.url.startsWith('/pos/')) {
        return false;
    }
    return page.url.startsWith(href);
};
</script>

<template>
    <Head :title="title + ' - UMKM Kasir'">
        <meta
            name="description"
            content="UMKM Kasir — aplikasi kasir untuk UMKM: POS, produk & stok, laporan, dan pengaturan toko dalam satu panel."
        />
    </Head>

    <div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <!-- Mobile Header -->
        <!-- Disembunyikan saat desktop sidebar terlihat (landscape di tablet) -->
        <div 
            class="lg:hidden bg-white border-b border-slate-200 sticky top-0 z-40"
            v-if="!showDesktopSidebar && !props.fullscreen"
        >
            <div class="px-4 py-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img src="/logo-macca.png" alt="logo" style="height:47px;width:auto" />
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="pendingTableOrderCount > 0"
                            variant="ghost"
                            size="sm"
                            class="relative size-9 rounded-2xl p-0 text-orange-600"
                            title="Pesanan masuk"
                            @click="router.visit('/table-orders')"
                        >
                            <Bell class="h-5 w-5" />
                            <span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{{ pendingTableOrderCount > 99 ? '99+' : pendingTableOrderCount }}</span>
                            <span class="sr-only">{{ pendingTableOrderCount }} pesanan masuk</span>
                        </Button>
                        <Button
                            v-if="pendingTableOrderCount > 0"
                            variant="ghost"
                            size="sm"
                            class="relative size-9 rounded-2xl p-0 text-orange-600"
                            title="Pesanan masuk"
                            @click="router.visit('/table-orders')"
                        >
                            <Bell class="h-5 w-5" />
                            <span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{{ pendingTableOrderCount > 99 ? '99+' : pendingTableOrderCount }}</span>
                            <span class="sr-only">{{ pendingTableOrderCount }} pesanan masuk</span>
                        </Button>
                        <slot name="mobile-header-actions" />
                        <DropdownMenu v-if="authUser">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="relative size-9 rounded-2xl p-0 ring-offset-2 focus-visible:ring-2 focus-visible:ring-primary"
                                >
                                    <Avatar class="size-9 overflow-hidden rounded-2xl border border-slate-200">
                                        <AvatarImage
                                            :src="resolveProfileImageSrc(authUser.avatar)"
                                            :alt="authUser.name"
                                        />
                                        <AvatarFallback
                                            class="rounded-2xl bg-primary/10 text-xs font-bold text-primary"
                                        >
                                            {{ getInitials(authUser?.name) }}
                                        </AvatarFallback>
                                    </Avatar>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                class="z-[60] w-64 rounded-2xl border border-slate-200 p-2 shadow-lg"
                            >
                                <UserMenuContent :user="authUser" />
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button
                            @click="toggleMobileMenu"
                            variant="ghost"
                            size="sm"
                            class="p-2"
                        >
                            <Menu v-if="!isMobileMenuOpen" class="h-5 w-5" />
                            <X v-else class="h-5 w-5" />
                        </Button>
                    </div>
                </div>
                <div v-if="accessibleStores.length > 1" class="mt-3 border-t border-slate-100 pt-3">
                    <label class="sr-only" for="mobile-active-store">Toko aktif</label>
                    <select
                        id="mobile-active-store"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                        :value="authUser?.store_id ?? ''"
                        @change="onSwitchStore"
                    >
                        <template v-for="grp in storeSelectGroups" :key="grp.label">
                            <optgroup :label="grp.label">
                                <option v-for="s in grp.options" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </optgroup>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex w-full">
            <!-- Desktop Sidebar -->
            <div
                v-if="showDesktopSidebar && !props.fullscreen"
                :class="[
                    'bg-white border-r border-slate-200 sticky top-0 h-screen overflow-y-auto overflow-x-hidden transition-[width] duration-200 ease-linear shrink-0 sidebar-scroll',
                    isDesktopSidebarOpen ? 'w-60' : 'w-0 overflow-hidden border-r-0',
                ]"
            >
                <div class="px-3 py-5">
                    <div class="mb-5">
                        <img src="/logo-macca.png" alt="logo" style="height:42px;width:auto" />
                    </div>
                    <div v-if="accessibleStores.length > 1" class="mb-5 pb-5 border-b border-slate-100">
                        <label
                            for="sidebar-active-store"
                            class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2"
                        >
                            Toko aktif
                        </label>
                        <select
                            id="sidebar-active-store"
                            class="w-full max-w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary/40 truncate"
                            :value="authUser?.store_id ?? ''"
                            @change="onSwitchStore"
                        >
                            <template v-for="grp in storeSelectGroups" :key="grp.label">
                                <optgroup :label="grp.label">
                                    <option v-for="s in grp.options" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </optgroup>
                            </template>
                        </select>
                    </div>
                    <MainMenu @close-mobile-menu="closeMobileMenu" />
                </div>
            </div>

            <!-- Mobile Sidebar Overlay -->
            <div 
                v-if="isMobileMenuOpen && !showDesktopSidebar" 
                class="lg:hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm transition-opacity"
                @click="closeMobileMenu"
            ></div>

            <!-- Mobile Sidebar -->
            <div 
                v-if="!showDesktopSidebar"
                :class="[
                    'lg:hidden fixed top-0 left-0 z-50 w-64 h-full bg-white transform transition-transform duration-300 ease-in-out shadow-2xl overflow-y-auto overflow-x-hidden sidebar-scroll',
                    isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="p-6">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-3">
                            <img src="/logo-macca.png" alt="logo" style="height:47px;width:auto" />
                        </div>
                        <Button 
                            @click="closeMobileMenu" 
                            variant="ghost" 
                            size="sm"
                            class="p-2 -mr-2 bg-slate-50 hover:bg-red-50 text-slate-400 hover:text-red-500 rounded-full"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </div>

                    <div v-if="accessibleStores.length > 1" class="mb-6 pb-6 border-b border-slate-100">
                        <label
                            for="drawer-active-store"
                            class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2"
                        >
                            Toko aktif
                        </label>
                        <select
                            id="drawer-active-store"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary/40"
                            :value="authUser?.store_id ?? ''"
                            @change="onSwitchStore"
                        >
                            <template v-for="grp in storeSelectGroups" :key="grp.label">
                                <optgroup :label="grp.label">
                                    <option v-for="s in grp.options" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </optgroup>
                            </template>
                        </select>
                    </div>
                    
                    <MainMenu @close-mobile-menu="closeMobileMenu" />
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 lg:ml-0 overflow-x-hidden min-w-0">
                <!-- Desktop Header -->
                <!-- Tampil saat desktop sidebar terlihat (lg+ atau landscape di tablet) -->
                <div 
                    v-if="showDesktopSidebar && !props.fullscreen"
                    class="bg-white/80 backdrop-blur-md border-b border-slate-200/60 sticky top-0 z-10"
                >
                    <div class="max-w-7xl mx-auto px-6 lg:px-8">
                        <div class="flex items-center justify-between h-20">
                            <div class="flex items-center gap-4 flex-wrap min-w-0">
                                <!-- Tombol toggle sidebar desktop -->
                                <button
                                    @click="toggleDesktopSidebar"
                                    class="hidden lg:flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 hover:text-slate-900 transition-colors shrink-0"
                                >
                                    <PanelLeft class="h-4 w-4" />
                                </button>
                                <h1 class="text-2xl font-black text-slate-900 tracking-tight">{{ title }}</h1>
                            </div>
                            <div class="flex items-center gap-6">
                                <ConnectionStatus :store-id="activeStoreId" :cashier-id="activeUserId" />
                                <Button
                                    v-if="pendingTableOrderCount > 0"
                                    variant="ghost"
                                    size="sm"
                                    class="relative size-10 rounded-2xl p-0 text-orange-600"
                                    title="Pesanan masuk"
                                    @click="router.visit('/table-orders')"
                                >
                                    <Bell class="h-5 w-5" />
                                    <span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{{ pendingTableOrderCount > 99 ? '99+' : pendingTableOrderCount }}</span>
                                    <span class="sr-only">{{ pendingTableOrderCount }} pesanan masuk</span>
                                </Button>
                                <slot name="header-actions" />
                                
                                <div v-if="authUser" class="flex items-center gap-3 border-l border-slate-200 pl-6">
                                    <div class="hidden text-right sm:block">
                                        <p class="text-sm font-bold text-slate-800">{{ authUser.name }}</p>
                                    </div>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                class="relative h-10 gap-2 rounded-2xl px-1.5 hover:bg-slate-100"
                                            >
                                                <Avatar class="size-10 overflow-hidden rounded-2xl border border-slate-200">
                                                    <AvatarImage
                                                        :src="resolveProfileImageSrc(authUser.avatar)"
                                                        :alt="authUser.name"
                                                    />
                                                    <AvatarFallback
                                                        class="rounded-2xl bg-primary/10 text-sm font-bold text-primary"
                                                    >
                                                        {{ getInitials(authUser.name) }}
                                                    </AvatarFallback>
                                                </Avatar>
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-64 rounded-2xl border border-slate-200 p-2 shadow-lg"
                                        >
                                            <UserMenuContent :user="authUser" />
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page Body Content — jarak konsisten di bawah header (mobile & desktop) -->
                <div
                    :class="[
                        props.fullscreen
                            ? ''
                            : 'max-w-7xl px-4 sm:px-6 lg:px-8 pt-5 sm:pt-6 lg:pt-8 pb-24 lg:pb-8',
                        showDesktopSidebar && !props.fullscreen ? 'ml-auto' : 'mx-auto',
                    ]"
                >
                    <UnclosedPeriodBanner v-if="!props.fullscreen" />
                    <slot />
                </div>
            </div>
        </div>

        <!-- Mobile Bottom Nav -->
        <!-- Disembunyikan di mode landscape pada mobile/tablet -->
        <div 
            class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-100 z-30 shadow-[0_-10px_20px_rgba(0,0,0,0.03)] selection:bg-transparent transition-opacity duration-200"
            :class="{
                'opacity-0 pointer-events-none': (isLandscape && isMobileOrTablet) || props.fullscreen
            }"
        >
            <!-- Owner Mobile Nav -->
            <div v-if="isOwner" class="flex items-center justify-around h-20 px-4">
                <Button as="a" href="/owner/dashboard" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/owner/dashboard') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                    <Crown class="!h-6 !w-6 mb-1" />
                    <span class="text-[10px] font-bold">Dashboard</span>
                </Button>
                <Button as="a" href="/products" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/products') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                    <Package class="!h-6 !w-6 mb-1" />
                    <span class="text-[10px] font-bold">Produk</span>
                </Button>
                <Button as="a" href="/reports" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/reports') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                    <BarChart3 class="!h-6 !w-6 mb-1" />
                    <span class="text-[10px] font-bold">Laporan</span>
                </Button>
                <Button as="a" href="/settings" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/settings') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                    <Settings class="!h-6 !w-6 mb-1" />
                    <span class="text-[10px] font-bold">Pengaturan</span>
                </Button>
            </div>

            <!-- Non-Owner Mobile Nav -->
            <!-- Kasir: 5 menu (dinamis berdasarkan status modul absensi) -->
            <div v-else-if="isCashier" class="flex items-center justify-between h-20 px-2 sm:px-6">
                <div class="flex items-center space-x-1">
                    <Button as="a" href="/dashboard" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/dashboard') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <Home class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Beranda</span>
                    </Button>
                    <!-- Absensi: tampil jika modul aktif -->
                    <Button v-if="attendanceEnabled" as="a" href="/attendance" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/attendance') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <CalendarCheck class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Absensi</span>
                    </Button>
                    <!-- Shift: tampil jika modul absensi tidak aktif -->
                    <Button v-else as="a" href="/cash-shifts" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/cash-shifts') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <Clock class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Shift</span>
                    </Button>
                </div>

                <!-- POS (tombol floating) -->
                <div class="flex flex-col items-center relative -top-6">
                    <Button as="a" href="/pos" class="flex flex-col items-center justify-center h-16 w-16 rounded-full shadow-lg shadow-primary/30 bg-primary hover:bg-primary/90 text-white border-[6px] border-white">
                        <ShoppingCart class="!h-6 !w-6" />
                    </Button>
                </div>

                <div class="flex items-center space-x-1">
                    <Button as="a" href="/pos/transactions" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/pos/transactions') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <ReceiptText class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Riwayat</span>
                    </Button>
                    <!-- Absensi aktif: Setelan diganti Stok agar tetap 5 menu -->
                    <Button v-if="attendanceEnabled" as="a" href="https://www.kasirumkm.web.id/stock-overview" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('https://www.kasirumkm.web.id/stock-overview') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <History class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Stok</span>
                    </Button>
                    <!-- Absensi tidak aktif: tampilkan Setelan -->
                    <Button v-else as="a" href="/settings" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/settings') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <Settings class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Setelan</span>
                    </Button>
                </div>
            </div>

            <!-- Non-Kasir / Non-Owner: footer default -->
            <div v-else-if="!isOwner" class="flex items-center justify-between h-20 px-2 sm:px-6">
                <div class="flex items-center space-x-1">
                    <Button as="a" href="/dashboard" variant="ghost" :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/dashboard') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']">
                        <Home class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Beranda</span>
                    </Button>
                    <Button
                        v-if="canInventory"
                        as="a"
                        href="/products"
                        variant="ghost"
                        :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/products') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']"
                    >
                        <Package class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Produk</span>
                    </Button>
                </div>
                
                <!-- POS (kasir/manajer/admin) atau pintu stok (admin gudang) -->
                <div class="flex flex-col items-center relative -top-6">
                    <Button
                        v-if="canPos && laundryEnabled"
                        as="a"
                        href="/laundry-orders"
                        class="flex flex-col items-center justify-center h-16 w-16 rounded-full shadow-lg shadow-primary/30 bg-primary hover:bg-primary/90 text-white border-[6px] border-white"
                    >
                        <Shirt class="!h-6 !w-6" />
                    </Button>
                    <Button
                        v-else-if="canPos"
                        as="a"
                        href="/pos"
                        class="flex flex-col items-center justify-center h-16 w-16 rounded-full shadow-lg shadow-primary/30 bg-primary hover:bg-primary/90 text-white border-[6px] border-white"
                    >
                        <ShoppingCart class="!h-6 !w-6" />
                    </Button>
                    <Button
                        v-else-if="canInventory"
                        as="a"
                        href="/stock-movements"
                        class="flex flex-col items-center justify-center h-16 w-16 rounded-full shadow-lg shadow-slate-400/30 bg-slate-800 hover:bg-slate-900 text-white border-[6px] border-white"
                    >
                        <History class="!h-6 !w-6" />
                    </Button>
                </div>

                <div class="flex items-center space-x-1">
                    <Button
                        v-if="canBackoffice"
                        as="a"
                        href="/customers"
                        variant="ghost"
                        :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/customers') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']"
                    >
                        <Users class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Member</span>
                    </Button>
                    <Button
                        v-if="canBackoffice"
                        as="a"
                        href="/reports"
                        variant="ghost"
                        :class="['flex flex-col items-center justify-center h-16 w-16 px-1', isCurrentRoute('/reports') ? 'text-primary' : 'text-slate-400 hover:text-slate-700']"
                    >
                        <BarChart3 class="!h-6 !w-6 mb-1" />
                        <span class="text-[10px] font-bold">Laporan</span>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sidebar-scroll {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.sidebar-scroll::-webkit-scrollbar {
    display: none;
}
</style>
