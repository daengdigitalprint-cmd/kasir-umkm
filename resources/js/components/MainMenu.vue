<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';
import {
    Activity,
    ArrowRight,
    ArrowRightLeft,
    Banknote,
    BarChart3,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarOff,
    ChefHat,
    ClipboardCheck,
    ClipboardList,
    Clock,
    CreditCard,
    Crown,
    Eye,
    FileText,
    Gift,
    History,
    Home,
    KeyRound,
    Layers,
    ListOrdered,
    LogOut,
    MessageCircle,
    Package,
    QrCode,
    ReceiptText,
    Settings,
    Shirt,
    ShoppingCart,
    SlidersHorizontal,
    Tag,
    Truck,
    Undo2,
    UserRound,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const emit = defineEmits(['close-mobile-menu']);
const page = usePage();

const isExternal = (href: string) => /^https?:\/\//.test(href);

type NavItem = {
    name: string;
    href: string;
    icon: LucideIcon;
    /** Key unik untuk hak akses dinamis dari role_menu_permissions. */
    menuKey?: string;
    /** Kelompok menu (header seksi) sesuai standar aplikasi kasir. */
    section?: 'kasir' | 'produk_stok' | 'pembelian' | 'pelanggan_promo' | 'sdm' | 'laporan_keuangan' | 'pengaturan';
    /** Hanya tampil jika Modul Akuntansi diaktifkan di pengaturan toko. */
    accountingOnly?: boolean;
    /** Hanya tampil jika Modul Hutang Piutang diaktifkan di pengaturan toko. */
    receivablesOnly?: boolean;
    /** Laporan, diskon, pelanggan — admin & manajer saja. */
    backofficeOnly?: boolean;
    /** Stok, produk, pembelian, transfer — termasuk admin gudang. */
    inventoryItem?: boolean;
    /** Menu Penyesuaian stok — peran inventory + kasir. */
    adjustStock?: boolean;
    /** POS & shift kasir. */
    posOnly?: boolean;
    /** Hanya tampil jika Modul Scan Meja diaktifkan di pengaturan toko. */
    scanMejaOnly?: boolean;
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
    /** Hanya tampil jika Modul Absensi diaktifkan di pengaturan toko. */
    attendanceOnly?: boolean;
    /** Hanya tampil jika add-on Absensi + Payroll sudah dibeli. */
    payrollOnly?: boolean;
    /** Menu SDM untuk pengelola (admin/owner/back-office), selalu dicek walau role dikonfigurasi. */
    hrAdminOnly?: boolean;
    /** Pengaturan SDM: owner, admin, manajer (ability manage_hr). */
    hrSettingsOnly?: boolean;
    /** Menu absensi pribadi untuk karyawan non back-office (kasir dll). */
    hrStaffOnly?: boolean;
    /** Tambahan path yang membuat menu ini ditandai aktif. */
    activePaths?: string[];
    /** Path yang dikecualikan dari penanda aktif (mis. /payroll/settings milik Pengaturan SDM). */
    excludePaths?: string[];
    /** Hanya tampil jika Modul Login Kasir (PIN) diaktifkan di pengaturan toko. */
    cashierPinOnly?: boolean;
    /** Disembunyikan untuk owner (menu operasional: POS, shift, dll). */
    hideOnOwner?: boolean;
    /** Kelas warna ikon khusus (mis. Bantuan hijau WhatsApp). */
    iconClass?: string;
    /** Kelas warna teks khusus (mis. Bantuan hijau WhatsApp). */
    textClass?: string;
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
    { name: 'Dashboard', href: '/dashboard', icon: Home, menuKey: 'dashboard' },
    // ── Kasir ──
    {
        name: 'POS',
        href: '/pos',
        icon: ShoppingCart,
        menuKey: 'pos',
        section: 'kasir',
        posOnly: true,
        hideOnLaundry: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Order Laundry',
        href: '/laundry-orders',
        icon: Shirt,
        menuKey: 'laundry_orders',
        section: 'kasir',
        posOnly: true,
        laundryOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Pesanan Masuk',
        href: '/table-orders',
        icon: ClipboardList,
        menuKey: 'table_orders',
        section: 'kasir',
        posOnly: true,
        scanMejaOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Antrian dapur',
        href: '/kitchen',
        icon: ChefHat,
        menuKey: 'kitchen_queue',
        section: 'kasir',
        kitchenQueueOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Antrian',
        href: '/queues',
        icon: ListOrdered,
        menuKey: 'queues',
        section: 'kasir',
        posOnly: true,
        queueModuleOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Riwayat Transaksi',
        href: '/pos/transactions',
        icon: ReceiptText,
        menuKey: 'pos_transactions',
        section: 'kasir',
        posOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Shift kasir',
        href: '/cash-shifts',
        icon: Clock,
        menuKey: 'cash_shifts',
        section: 'kasir',
        posOnly: true,
        advancedRetailOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Kas Masuk/Keluar',
        href: '/cash-shifts/transactions',
        icon: ArrowRightLeft,
        menuKey: 'cash_transactions',
        section: 'kasir',
        posOnly: true,
        advancedRetailOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    {
        name: 'Manajemen Meja',
        href: '/tables',
        icon: QrCode,
        menuKey: 'tables',
        section: 'kasir',
        posOnly: true,
        scanMejaOnly: true,
        hideOnWarehouse: true,
        hideOnOwner: true,
    },
    // ── Produk & Stok ──
    {
        name: 'Lihat Stok',
        href: '/stock-overview',
        icon: Eye,
        menuKey: 'stock_overview',
        section: 'produk_stok',
        posAndViewStock: true,
        hideOnOwner: true,
    },
    { name: 'Produk', href: '/products', icon: Package, menuKey: 'products', section: 'produk_stok', inventoryItem: true },
    { name: 'Kategori', href: '/product-categories', icon: Layers, menuKey: 'product_categories', section: 'produk_stok', inventoryItem: true },
    {
        name: 'Riwayat stok',
        href: '/stock-movements',
        icon: History,
        menuKey: 'stock_movements',
        section: 'produk_stok',
        inventoryItem: true,
        hideOnOwner: true,
    },
    {
        name: 'Penyesuaian stok',
        href: '/stock-adjustments',
        icon: ClipboardCheck,
        menuKey: 'stock_adjustment',
        section: 'produk_stok',
        adjustStock: true,
        advancedRetailOnly: true,
        hideOnOwner: true,
        hideOnDirectStockEdit: true,
    },
    {
        name: 'Stok Opname',
        href: '/stock-opnames',
        icon: ClipboardList,
        menuKey: 'stock_opnames',
        section: 'produk_stok',
        inventoryItem: true,
        advancedRetailOnly: true,
        hideOnOwner: true,
        hideOnDirectStockEdit: true,
    },
    { name: 'Resep', href: '/recipes', icon: ChefHat, menuKey: 'recipes', section: 'produk_stok', recipesOnly: true },
    { name: 'Produksi', href: '/productions', icon: Layers, menuKey: 'productions', section: 'produk_stok', productionsOnly: true },
    // ── Pembelian & Distribusi ──
    { name: 'Pembelian', href: '/purchases', icon: Truck, menuKey: 'purchases', section: 'pembelian', inventoryItem: true, advancedRetailOnly: true },
    {
        name: 'Retur pembelian',
        href: '/purchase-returns',
        icon: Undo2,
        menuKey: 'purchase_returns',
        section: 'pembelian',
        inventoryItem: true,
        advancedRetailOnly: true,
    },
    {
        name: 'Retur penjualan',
        href: '/sales-returns',
        icon: Undo2,
        menuKey: 'sales_returns',
        section: 'pembelian',
        inventoryItem: true,
        advancedRetailOnly: true,
    },
    {
        name: 'Pre-order',
        href: '/pre-orders',
        icon: ClipboardList,
        menuKey: 'pre_orders',
        section: 'pembelian',
        inventoryItem: true,
        advancedRetailOnly: true,
    },
    {
        name: 'Transfer stok',
        href: '/stock-transfers',
        icon: ArrowRightLeft,
        menuKey: 'stock_transfers',
        section: 'pembelian',
        inventoryItem: true,
        advancedRetailOnly: true,
    },
    // ── Pelanggan & Promo ──
    {
        name: 'Pelanggan',
        href: '/customers',
        icon: Users,
        menuKey: 'customers',
        section: 'pelanggan_promo',
        backofficeOnly: true,
        hideOnWarehouse: true,
    },
    {
        name: 'Piutang',
        href: '/receivables',
        icon: CreditCard,
        menuKey: 'receivables',
        section: 'pelanggan_promo',
        backofficeOnly: true,
        hideOnWarehouse: true,
        receivablesOnly: true,
    },
    {
        name: 'Loyalitas',
        href: '/loyalty',
        icon: Gift,
        menuKey: 'loyalty',
        section: 'pelanggan_promo',
        backofficeOnly: true,
        advancedRetailOnly: true,
        hideOnWarehouse: true,
    },
    {
        name: 'Diskon',
        href: '/discounts',
        icon: Tag,
        menuKey: 'discounts',
        section: 'pelanggan_promo',
        backofficeOnly: true,
        advancedRetailOnly: true,
        hideOnWarehouse: true,
    },
    // ── Laporan & Keuangan ──
    { name: 'Laporan', href: '/reports', icon: BarChart3, menuKey: 'reports', section: 'laporan_keuangan' },
    { name: 'Biaya Usaha', href: '/expenses', icon: Wallet, menuKey: 'expenses', section: 'laporan_keuangan' },
    { name: 'Log Aktivitas', href: '/activity-logs', icon: FileText, menuKey: 'activity_logs', section: 'laporan_keuangan' },
    { name: 'KPI', href: '/kpi', icon: Activity, menuKey: 'kpi', section: 'laporan_keuangan', storeAdminOnly: true },
    // ── SDM & Gaji (HRIS) ──
    // Karyawan non back-office cukup satu menu: absen, riwayat, pengajuan, slip gaji.
    { name: 'Absensi', href: '/attendance', icon: CalendarCheck, menuKey: 'attendance', section: 'kasir', attendanceOnly: true, hrStaffOnly: true },
    {
        name: 'Kehadiran',
        href: '/attendance/report',
        icon: CalendarCheck,
        menuKey: 'attendance',
        section: 'sdm',
        attendanceOnly: true,
        hrAdminOnly: true,
        activePaths: ['/attendance/history'],
    },
    { name: 'Karyawan', href: '/attendance/employees', icon: UserRound, menuKey: 'attendance', section: 'sdm', attendanceOnly: true, hrAdminOnly: true },
    { name: 'Jadwal Kerja', href: '/attendance/schedules', icon: CalendarClock, menuKey: 'attendance', section: 'sdm', attendanceOnly: true, hrAdminOnly: true },
    {
        name: 'Pengajuan',
        href: '/attendance/leaves',
        icon: CalendarOff,
        menuKey: 'attendance',
        section: 'sdm',
        attendanceOnly: true,
        hrAdminOnly: true,
        activePaths: ['/attendance/overtimes'],
    },
    {
        name: 'Penggajian',
        href: '/payroll',
        icon: Banknote,
        menuKey: 'payroll',
        section: 'sdm',
        payrollOnly: true,
        hrAdminOnly: true,
        excludePaths: ['/payroll/settings'],
    },
    {
        name: 'Pengaturan SDM',
        href: '/attendance/settings',
        icon: SlidersHorizontal,
        menuKey: 'attendance',
        section: 'sdm',
        payrollOnly: true,
        hrAdminOnly: true,
        hrSettingsOnly: true,
        activePaths: ['/payroll/settings'],
    },
    // ── Pengaturan ──
    { name: 'Tambah Outlet/Gudang', href: '/stores/create', icon: Building2, menuKey: 'manage_stores', section: 'pengaturan', storeAdminOnly: true },
    { name: 'Distribusi Produk', href: '/manage-products', icon: Package, menuKey: 'manage_products', section: 'pengaturan', storeAdminOnly: true },
    { name: 'Tim & Akses', href: '/manage-staff', icon: Users, menuKey: 'manage_staff', section: 'pengaturan', storeAdminOnly: true },
    { name: 'Pengaturan', href: '/settings', icon: Settings, menuKey: 'settings', section: 'pengaturan', storeAdminOnly: true },
    {
        name: 'Login Kasir',
        href: '/settings/cashier-pin',
        icon: KeyRound,
        menuKey: 'cashier_pin_settings',
        section: 'pengaturan',
        storeAdminOnly: true,
        cashierPinOnly: true,
    },
    {
        name: 'Bantuan',
        href: 'https://wa.me/6285242766676?text=Halo%2C%20saya%20butuh%20bantuan%20dengan%20aplikasi%20kasir.',
        icon: MessageCircle,
        section: 'pengaturan',
        iconClass: 'text-green-600 group-hover:text-green-700',
        textClass: 'text-green-600 group-hover:text-green-700',
    },
];

const navigationItems = computed((): ProcessedNavItem[] => {
    const backoffice = Boolean((page.props as { abilities?: { backoffice?: boolean } }).abilities?.backoffice);
    const inventory = Boolean((page.props as { abilities?: { inventory?: boolean } }).abilities?.inventory);
    const stockAdjust = Boolean((page.props as { abilities?: { stock_adjustment?: boolean } }).abilities?.stock_adjustment);
    const pos = Boolean((page.props as { abilities?: { pos?: boolean } }).abilities?.pos);
    const isOwner = Boolean((page.props as { abilities?: { owner?: boolean } }).abilities?.owner);
    const manageStoreSettings = Boolean((page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings);
    const manageHr = Boolean((page.props as { abilities?: { manage_hr?: boolean } }).abilities?.manage_hr);
    const canAccessActivityLogs = Boolean((page.props as { can_access_activity_logs?: boolean }).can_access_activity_logs);
    const fnb = Boolean((page.props as { fnb_enabled?: boolean }).fnb_enabled);
    const pharmacy = Boolean((page.props as { pharmacy_enabled?: boolean }).pharmacy_enabled);
    const laundry = Boolean((page.props as { laundry_enabled?: boolean }).laundry_enabled);
    const barbershop = Boolean((page.props as { barbershop_enabled?: boolean }).barbershop_enabled);
    const recipesEnabled = Boolean((page.props as { recipes_enabled?: boolean }).recipes_enabled);
    const productionsEnabled = Boolean((page.props as { productions_enabled?: boolean }).productions_enabled);
    const kitchenQueueEnabled = Boolean((page.props as { kitchen_queue_enabled?: boolean }).kitchen_queue_enabled);
    const queueModuleEnabled = Boolean((page.props as { queue_module_enabled?: boolean }).queue_module_enabled);
    const scanMejaEnabled = Boolean((page.props as { scan_meja_enabled?: boolean }).scan_meja_enabled);
    const attendanceEnabled = Boolean((page.props as { attendance_enabled?: boolean }).attendance_enabled);
    const payrollEnabled = Boolean((page.props as { payroll_enabled?: boolean }).payroll_enabled);
    const cashierPinEnabled = String((page.props as { cashier_login_mode?: string }).cashier_login_mode ?? 'email') === 'pin';
    const accountingEnabled = Boolean((page.props as { accounting_enabled?: boolean }).accounting_enabled);
    const receivablesEnabled = Boolean((page.props as { receivables_enabled?: boolean }).receivables_enabled);
    const pendingQueueCount = Number((page.props as { pending_queue_count?: number }).pending_queue_count ?? 0);
    const pendingTableOrderCount = Number((page.props as { pending_table_order_count?: number }).pending_table_order_count ?? 0);
    const pendingKitchenOrderCount = Number((page.props as { pending_kitchen_order_count?: number }).pending_kitchen_order_count ?? 0);
    const pendingHr = (page.props as { pending_hr_requests?: { leave?: number; overtime?: number; total?: number } }).pending_hr_requests;
    // Total semua outlet yang dikelola (owner multi-outlet), bukan hanya outlet aktif.
    const pendingHrCount = Number(pendingHr?.total ?? Number(pendingHr?.leave ?? 0) + Number(pendingHr?.overtime ?? 0));
    const locationType = String((page.props as { location_type?: string }).location_type ?? 'outlet');
    const isWarehouse = locationType === 'central_warehouse' || locationType === 'branch_warehouse';
    const currentRole = String(
        (page.props as { auth?: { user?: { role?: string }; active_role?: string } }).auth?.active_role ??
            (page.props as { auth?: { user?: { role?: string } } }).auth?.user?.role ??
            '',
    );
    const isKitchen = currentRole === 'kitchen';
    const planLimits = (page.props as { plan_limits?: { allows_advanced_retail?: boolean } | null }).plan_limits;
    /** Tanpa plan_limits (belum ada konteks toko): biarkan seperti legacy. Ada plan: harus eksplisit true. */
    const allowAdvanced = planLimits == null ? true : planLimits.allows_advanced_retail === true;
    const directStockEdit = Boolean((page.props as { direct_stock_edit?: boolean }).direct_stock_edit);
    const allowedMenus = (page.props as { allowed_menus?: string[] }).allowed_menus ?? [];
    // Mode Sederhana: hanya menu inti (daftar dihitung server, lihat App\Support\UiMode).
    const simpleMode = (page.props as { ui_mode?: string }).ui_mode === 'simple';
    const simpleVisibleMenus = (page.props as { simple_visible_menus?: string[] }).simple_visible_menus ?? [];

    return navigationSource.flatMap((item): ProcessedNavItem[] => {
        // Cek apakah menu ini diizinkan secara eksplisit lewat Manage Staff
        // Jika allowedMenus kosong, asumsikan ini legacy/belum setup (bypass filter strict)
        const hasExplicitAccess = item.menuKey && allowedMenus.includes(item.menuKey);
        const isStrictRoleMode = allowedMenus.length > 0;

        // Khusus Log Aktivitas: tampilkan jika user punya hak akses
        if (item.menuKey === 'activity_logs' && !canAccessActivityLogs) {
            return [];
        }

        // 1. FILTER WAJIB (Selalu dieksekusi, bahkan jika user punya akses eksplisit)
        // Owner tidak bisa lihat menu operasional
        if (isOwner && item.hideOnOwner) return [];
        // Menu gudang disembunyikan di outlet
        if (item.hideOnWarehouse && isWarehouse) return [];
        // Modul-modul yang bisa di-toggle di Settings
        if (item.recipesOnly && !recipesEnabled) return [];
        if (item.pharmacyOnly && !pharmacy) return [];
        if (item.productionsOnly && !productionsEnabled) return [];
        if (item.kitchenQueueOnly && !kitchenQueueEnabled && !isKitchen) return [];
        if (item.queueModuleOnly && !queueModuleEnabled) return [];
        if (item.scanMejaOnly && !scanMejaEnabled) return [];
        if (item.attendanceOnly && !attendanceEnabled) return [];
        // Menu SDM: pengelola melihat grup SDM & Gaji, karyawan biasa hanya "Absensi".
        if (item.hrAdminOnly && !backoffice) return [];
        if (item.hrStaffOnly && backoffice) return [];
        if (item.hrSettingsOnly && !manageHr) return [];
        if (item.payrollOnly && !payrollEnabled) return [];
        if (item.cashierPinOnly && !cashierPinEnabled) return [];
        if (item.accountingOnly && !accountingEnabled) return [];
        if (item.receivablesOnly && !receivablesEnabled) return [];
        if (item.fnbOnly && !fnb) return [];
        if (item.laundryOnly && !laundry) return [];
        if (item.hideOnLaundry && laundry) return [];

        // 2. FILTER ROLE & AKSES (Pengecekan hak akses spesifik)
        if (isStrictRoleMode) {
            // Dalam mode strict (role dikonfigurasi), JIKA menu_key ada dan TIDAK diizinkan -> SEMBUNYIKAN
            if (item.menuKey && !hasExplicitAccess) {
                return [];
            }

            // JIKA ini Stok Opname / Penyesuaian Stok DAN store pakai mode directStockEdit,
            // sembunyikan KECUALI jika secara eksplisit diizinkan di role permission
            if (item.hideOnDirectStockEdit && directStockEdit && !hasExplicitAccess) {
                return [];
            }
        } else {
            // Dalam mode legacy (tanpa role_menu_permissions), gunakan filter abilities
            if (item.hideOnDirectStockEdit && directStockEdit) return [];
            if (item.backofficeOnly && !backoffice) return [];
            if (item.inventoryItem && !inventory) return [];
            if (item.adjustStock && !stockAdjust) return [];
            if (item.posOnly && !pos) return [];
            if (item.storeAdminOnly && !manageStoreSettings) return [];
            if (item.ownerOnly && !isOwner) return [];
            if (item.posAndViewStock && !pos && !inventory) return [];
        }

        // 2b. MODE SEDERHANA (saringan terakhir, hanya menyembunyikan tampilan — rute tetap bisa diakses)
        if (simpleMode && item.menuKey && !simpleVisibleMenus.includes(item.menuKey)) {
            return [];
        }

        // 3. TRANSFORMASI & FORMATTING
        // Owner: Dashboard mengarah ke /owner/dashboard
        if (isOwner && item.name === 'Dashboard') {
            return [{ ...item, href: '/owner/dashboard' }];
        }

        // Filter paket berlangganan
        if (item.advancedRetailOnly && !allowAdvanced) {
            return [{ ...item, disabled: true, disabledReason: 'Upgrade ke paket berbayar untuk mengakses fitur ini' }];
        }

        // Badge antrian dapur
        if (item.menuKey === 'kitchen_queue' && pendingKitchenOrderCount > 0) {
            return [{ ...item, pendingCount: pendingKitchenOrderCount }];
        }

        // Badge antrian manual
        if (item.queueModuleOnly && pendingQueueCount > 0) {
            return [{ ...item, pendingCount: pendingQueueCount }];
        }

        // Badge pengajuan cuti + lembur yang menunggu persetujuan
        if (item.href === '/attendance/leaves' && pendingHrCount > 0) {
            return [{ ...item, pendingCount: pendingHrCount }];
        }

        // Badge pesanan meja pending (Scan Meja)
        if (item.menuKey === 'table_orders' && pendingTableOrderCount > 0) {
            return [{ ...item, pendingCount: pendingTableOrderCount }];
        }

        // Barbershop: sebutkan 'Layanan' pada menu Produk
        if (item.menuKey === 'products' && barbershop) {
            return [{ ...item, name: 'Produk & Layanan' }];
        }

        return [item];
    });
});

/** Urutan & label header seksi menu (standar aplikasi kasir Indonesia). */
const SECTION_META: { key: NonNullable<NavItem['section']>; label: string }[] = [
    { key: 'kasir', label: 'Kasir' },
    { key: 'produk_stok', label: 'Produk & Stok' },
    { key: 'pembelian', label: 'Pembelian & Distribusi' },
    { key: 'pelanggan_promo', label: 'Pelanggan & Promo' },
    { key: 'sdm', label: 'SDM & Gaji' },
    { key: 'laporan_keuangan', label: 'Laporan & Keuangan' },
    { key: 'pengaturan', label: 'Pengaturan' },
];

type NavRenderEntry = { kind: 'item'; key: string; item: ProcessedNavItem } | { kind: 'section'; key: string; label: string };

/** Item tanpa seksi (Dashboard) di atas, lalu header seksi + itemnya berurutan. */
const renderItems = computed<NavRenderEntry[]>(() => {
    const out: NavRenderEntry[] = [];
    for (const item of navigationItems.value) {
        if (!item.section) {
            out.push({ kind: 'item', key: item.name, item });
        }
    }
    for (const meta of SECTION_META) {
        const sectionItems = navigationItems.value.filter((i) => i.section === meta.key);
        if (sectionItems.length === 0) {
            continue;
        }
        out.push({ kind: 'section', key: meta.key, label: meta.label });
        for (const item of sectionItems) {
            out.push({ kind: 'item', key: item.name, item });
        }
    }
    return out;
});

const isCurrentRoute = (href: string) => {
    const navItem = navigationSource.find((i) => i.href === href);
    if (navItem?.excludePaths?.some((p) => page.url.startsWith(p))) {
        return false;
    }
    if (navItem?.activePaths?.some((p) => page.url.startsWith(p))) {
        return true;
    }
    if (href === '/attendance') {
        return page.url === '/attendance' || page.url.startsWith('/attendance?') || page.url.startsWith('/attendance/my-') || page.url.startsWith('/attendance/history');
    }
    if (href === '/reports') {
        return page.url.startsWith('/reports') || page.url.startsWith('/financial');
    }
    if (href === '/dashboard' && page.url !== '/dashboard') {
        return false;
    }
    if (href === '/owner/dashboard') {
        return page.url.startsWith('/owner/dashboard');
    }
    if (href === '/pos' && page.url.startsWith('/pos/')) {
        return false;
    }
    if (href === '/settings') {
        return page.url === '/settings';
    }
    if (href === '/cash-shifts') {
        return page.url === '/cash-shifts' || page.url.startsWith('/cash-shifts/shift');
    }
    if (href === '/cash-shifts/transactions') {
        return page.url === '/cash-shifts/transactions' || page.url.startsWith('/cash-shifts/transactions/');
    }
    return page.url.startsWith(href);
};

/** ── Penanda menu aktif (menempel langsung, tanpa animasi perpindahan) ── */
const navRef = ref<HTMLElement | null>(null);
const indRef = ref<HTMLElement | null>(null);
const itemEls = new Map<string, Element>();

const setItemRef = (key: string, el: unknown) => {
    if (el) {
        itemEls.set(key, el as Element);
    } else {
        itemEls.delete(key);
    }
};

/** Menu yang baru diklik — penanda langsung pindah tanpa menunggu halaman berikutnya selesai dimuat. */
const pendingKey = ref<string | null>(null);

const activeKey = computed(() => {
    if (pendingKey.value) {
        return pendingKey.value;
    }
    const entry = renderItems.value.find((e) => e.kind === 'item' && !e.item.disabled && isCurrentRoute(e.item.href));
    return entry ? entry.key : null;
});

let resizeObserver: ResizeObserver | null = null;
let removeStartListener: (() => void) | null = null;
let removeFinishListener: (() => void) | null = null;

const pathOf = (url: string | URL) => {
    try {
        return new URL(url.toString(), window.location.origin).pathname.replace(/\/+$/, '') || '/';
    } catch {
        return '';
    }
};

/** Posisi atas & bawah menu aktif relatif terhadap nav. */
const measure = (): [number, number] | null => {
    const el = activeKey.value ? itemEls.get(activeKey.value) : undefined;
    const nav = navRef.value;
    if (!el || !nav) {
        return null;
    }
    const r = el.getBoundingClientRect();
    const w = nav.getBoundingClientRect();
    return [r.top - w.top, r.bottom - w.top];
};

/** Tempelkan indikator ke menu aktif tanpa animasi perpindahan. */
const syncIndicator = () => {
    const ind = indRef.value;
    const t = measure();
    if (!ind || !t) {
        return;
    }
    const [top, bottom] = t;
    ind.style.transform = `translateY(${top.toFixed(1)}px)`;
    ind.style.height = `${Math.max(4, bottom - top).toFixed(1)}px`;
};

onMounted(async () => {
    await nextTick();
    syncIndicator();
    window.addEventListener('resize', syncIndicator);
    if (navRef.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => syncIndicator());
        resizeObserver.observe(navRef.value);
    }

    removeStartListener = router.on('start', (event) => {
        const path = pathOf(event.detail.visit.url);
        const entry = renderItems.value.find((e) => e.kind === 'item' && !e.item.disabled && pathOf(e.item.href) === path);
        if (entry && entry.key !== activeKey.value) {
            pendingKey.value = entry.key;
        }
    });
    // Kunjungan batal/gagal: kembalikan indikator ke menu halaman yang sedang tampil.
    removeFinishListener = router.on('finish', () => {
        pendingKey.value = null;
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', syncIndicator);
    resizeObserver?.disconnect();
    removeStartListener?.();
    removeFinishListener?.();
});

watch([activeKey, renderItems], async () => {
    await nextTick();
    syncIndicator();
});

const isSimpleMode = computed(() => (page.props as { ui_mode?: string }).ui_mode === 'simple');
const canSwitchUiMode = computed(() =>
    Boolean((page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings),
);
const switchingUiMode = ref(false);
function switchUiMode(mode: 'simple' | 'full') {
    if (switchingUiMode.value) return;
    switchingUiMode.value = true;
    router.put(
        '/settings/ui-mode',
        { ui_mode: mode },
        { preserveScroll: true, onFinish: () => (switchingUiMode.value = false) },
    );
}

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
        case 'active':
            return 'Aktif';
        case 'expiring':
            return subscription.value.days_remaining != null ? `${subscription.value.days_remaining} hari lagi` : 'Akan berakhir';
        case 'expired':
            return 'Expired';
        case 'unlimited':
            return 'Unlimited';
        default:
            return 'Nonaktif';
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
    <div class="flex h-full w-full flex-col">
        <nav ref="navRef" class="relative space-y-1">
            <!-- Penanda menu aktif: langsung menempel ke menu terpilih, tanpa animasi perpindahan -->
            <div
                ref="indRef"
                class="pointer-events-none absolute inset-x-0 top-0 rounded-2xl bg-violet-600 shadow-md shadow-violet-200 transition-opacity duration-200"
                :style="{ opacity: activeKey ? 1 : 0 }"
            ></div>
            <template v-for="entry in renderItems" :key="entry.key">
                <!-- Header seksi menu -->
                <p v-if="entry.kind === 'section'" class="px-4 pt-6 pb-2 text-[10px] font-black tracking-[0.2em] text-slate-400 uppercase">
                    {{ entry.label }}
                </p>

                <!-- Item menu -->
                <div v-else :ref="(el) => setItemRef(entry.key, el)" class="group/menu relative">
                    <component
                        :is="entry.item.disabled ? 'div' : isExternal(entry.item.href) ? 'a' : Link"
                        :href="entry.item.disabled ? undefined : entry.item.href"
                        :target="isExternal(entry.item.href) ? '_blank' : undefined"
                        :rel="isExternal(entry.item.href) ? 'noopener noreferrer' : undefined"
                        @click="!entry.item.disabled && emit('close-mobile-menu')"
                        :class="['block outline-none', entry.item.disabled ? 'cursor-not-allowed' : '']"
                    >
                        <div
                            :class="[
                                'group relative flex h-12 w-full items-center overflow-hidden rounded-2xl px-4 transition-all duration-300',
                                entry.item.disabled
                                    ? 'cursor-not-allowed opacity-50'
                                    : activeKey === entry.key
                                        ? 'text-white'
                                        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800',
                            ]"
                        >
                            <component
                                :is="entry.item.icon"
                                :class="[
                                    'z-10 mr-4 size-5',
                                    entry.item.disabled ? 'text-slate-300' : 'transition-transform duration-300 group-hover:scale-110',
                                    !entry.item.disabled && activeKey === entry.key
                                        ? 'text-white'
                                        : entry.item.iconClass
                                          ? entry.item.iconClass
                                          : [
                                                !entry.item.disabled && activeKey !== entry.key
                                                    ? 'text-slate-400 group-hover:text-slate-800'
                                                    : '',
                                            ],
                                ]"
                            />
                            <span
                                :class="[
                                    'z-10 text-sm font-bold tracking-tight',
                                    entry.item.disabled
                                        ? 'text-slate-400'
                                        : activeKey === entry.key
                                          ? 'text-white'
                                          : entry.item.textClass
                                            ? entry.item.textClass
                                            : 'text-slate-500 group-hover:text-slate-800',
                                ]"
                            >
                                {{ entry.item.name }}
                            </span>
                            <span
                                v-if="entry.item.href === '/pos'"
                                :class="[
                                    'z-10 ml-auto flex size-7 items-center justify-center rounded-full shadow-sm transition-transform duration-300 group-hover:translate-x-0.5',
                                    activeKey === entry.key ? 'bg-white/20 shadow-purple-900/20' : 'bg-violet-600 shadow-violet-200',
                                ]"
                            >
                                <ArrowRight class="size-4 text-white" />
                            </span>

                            <!-- Badge notifikasi antrian pending -->
                            <span
                                v-if="entry.item.pendingCount && entry.item.pendingCount > 0"
                                class="z-10 ml-auto flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-black text-white"
                            >
                                {{ entry.item.pendingCount > 99 ? '99+' : entry.item.pendingCount }}
                            </span>

                            <!-- Badge Premium untuk fitur berbayar -->
                            <Crown
                                v-if="entry.item.disabled && entry.item.disabledReason?.includes('paket')"
                                class="z-10 ml-auto size-4 text-amber-400"
                            />
                        </div>
                    </component>

                    <!-- Tooltip untuk menu disabled -->
                    <div
                        v-if="entry.item.disabled && entry.item.disabledReason"
                        class="pointer-events-none absolute top-1/2 right-3 z-50 max-w-[160px] -translate-y-1/2 opacity-0 transition-opacity duration-200 group-hover/menu:opacity-100"
                    >
                        <div class="rounded-lg bg-slate-900 px-2 py-1 text-right text-[10px] leading-tight text-white shadow-lg">
                            {{ entry.item.disabledReason }}
                        </div>
                    </div>
                </div>
            </template>
        </nav>

        <!-- Fitur Tambahan: nyalakan fitur lanjutan satu per satu (Mode Sederhana) -->
        <Link
            v-if="canSwitchUiMode && isSimpleMode"
            href="/settings/features"
            :class="[
                'mx-1 mt-4 flex w-[calc(100%-0.5rem)] items-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold transition',
                isCurrentRoute('/settings/features')
                    ? 'border-violet-300 bg-violet-100 text-violet-800'
                    : 'border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-100',
            ]"
            @click="emit('close-mobile-menu')"
        >
            <Crown class="h-4 w-4 shrink-0" />
            <span>Fitur Tambahan</span>
        </Link>

        <!-- Pintasan kembali ke Mode Lengkap. Toko Mode Lengkap (termasuk semua toko lama) tidak mendapat tombol apa pun di sidebar. -->
        <button
            v-if="canSwitchUiMode && isSimpleMode"
            type="button"
            :disabled="switchingUiMode"
            class="mx-1 mt-2 flex w-[calc(100%-0.5rem)] items-center gap-2 rounded-xl border border-dashed border-gray-300 px-3 py-2 text-left text-xs font-medium text-gray-500 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700 disabled:opacity-60"
            @click="switchUiMode('full')"
        >
            <Eye class="h-4 w-4 shrink-0" />
            <span>Tampilkan semua menu</span>
        </button>

        <!-- Subscription Section -->
        <p class="mt-8 mb-4 px-4 text-[10px] font-black tracking-[0.2em] text-violet-400/90 uppercase">SUBSCRIPTION</p>

        <!-- Subscription Status Card -->
        <Link href="/subscription" @click="emit('close-mobile-menu')" class="mx-1 block outline-none">
            <div
                :class="[
                    'group relative min-h-[118px] cursor-pointer overflow-hidden rounded-2xl p-4 ring-1 transition-all duration-300 ring-inset',
                    subscriptionCardSoft
                        ? [
                              'bg-gradient-to-br from-violet-100 via-purple-50 to-fuchsia-100/90 ring-violet-200/70',
                              isCurrentRoute('/subscription')
                                  ? 'scale-[1.02] shadow-lg shadow-violet-300/35'
                                  : 'hover:scale-[1.01] hover:shadow-lg hover:shadow-violet-200/50',
                          ]
                        : [
                              'ring-white/15',
                              isCurrentRoute('/subscription') ? 'scale-[1.02] shadow-lg' : 'hover:scale-[1.01] hover:shadow-lg',
                              subscription?.status === 'expiring' ? 'bg-gradient-to-br from-amber-500 to-orange-600' : '',
                              subscription?.status === 'expired' ? 'bg-gradient-to-br from-red-500 to-rose-600' : '',
                          ],
                ]"
            >
                <!-- Decorative circle -->
                <div
                    :class="[
                        'absolute top-0 right-0 z-[0] h-20 w-20 translate-x-10 -translate-y-10 rounded-full',
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
                        'pointer-events-none absolute -right-1 bottom-0 z-[1] h-[5.75rem] w-auto object-contain object-bottom transition-transform duration-300 select-none group-hover:-translate-y-0.5 group-hover:scale-105',
                        subscriptionCardSoft ? 'drop-shadow-[0_4px_12px_rgba(91,33,182,0.15)]' : 'drop-shadow-[0_6px_16px_rgba(0,0,0,0.2)]',
                    ]"
                />
                <div class="relative z-10 max-w-[72%] overflow-hidden pr-1">
                    <div class="mb-2 flex items-center gap-2.5">
                        <Crown :class="['h-5 w-5 shrink-0 drop-shadow-sm', subscriptionCardSoft ? 'text-violet-500' : 'text-amber-200']" />
                        <span :class="['text-sm leading-tight font-black', subscriptionCardSoft ? 'text-violet-950' : 'text-white']">
                            Subscription
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <Clock
                                v-if="
                                    subscription?.days_remaining != null &&
                                    subscription.days_remaining > 0 &&
                                    !subscription?.starter_free &&
                                    !isStarterPlan
                                "
                                :class="['h-3 w-3 shrink-0', subscriptionCardSoft ? 'text-violet-500/80' : 'text-white/70']"
                            />
                            <span
                                :class="[
                                    'truncate text-xs leading-snug font-semibold',
                                    subscriptionCardSoft ? 'text-violet-800/90' : 'text-white/90',
                                ]"
                            >
                                {{ subscriptionStatusText || 'Lihat Paket' }}
                            </span>
                        </div>
                        <div
                            v-if="subscription?.has_pending_payment"
                            :class="['h-2 w-2 shrink-0 animate-pulse rounded-full', subscriptionCardSoft ? 'bg-amber-500' : 'bg-yellow-300']"
                        ></div>
                    </div>
                </div>
            </div>
        </Link>

        <!-- System Section -->
        <p class="mt-8 mb-4 px-4 text-[10px] font-black tracking-[0.2em] text-slate-400 uppercase">SISTEM</p>

        <!-- Logout Button -->
        <Link href="/logout" method="post" as="button" class="w-full text-left outline-none" @click="emit('close-mobile-menu')">
            <div
                class="group flex h-12 w-full items-center rounded-2xl px-4 text-red-600 transition-all duration-300 group-hover:scale-105 hover:bg-red-50"
            >
                <LogOut class="mr-4 size-5 transition-transform duration-300 group-hover:scale-110" />
                <span class="text-sm font-bold tracking-tight">Keluar</span>
            </div>
        </Link>
    </div>
</template>
