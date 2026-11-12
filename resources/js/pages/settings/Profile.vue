<script setup lang="ts">
import BackupTab from '@/components/BackupTab.vue';
import BluetoothTab from '@/components/BluetoothTab.vue';
import TaxSettingsModal from '@/components/TaxSettingsModal.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDialog, toastWarning } from '@/lib/swal';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    Bike,
    Blocks,
    Bluetooth,
    BookOpenCheck,
    Bot,
    Building,
    Building2,
    CalendarClock,
    ChefHat,
    CookingPot,
    CreditCard,
    Factory,
    HardDrive,
    History,
    KeyRound,
    Package,
    PackageOpen,
    Phone,
    QrCode,
    Save,
    ScanQrCode,
    Settings,
    ShoppingCart,
    Tag,
    Trash2,
    Undo2,
    Upload,
    User,
    Warehouse as WarehouseIcon,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PaymentMethod {
    type: string;
    label: string;
    active: boolean;
    instructions?: string;
    image?: string | null;
    bank_name?: string;
    account_number?: string;
    account_name?: string;
}

interface Props {
    user?: {
        id: number;
        name: string;
        email: string;
        email_verified_at?: string;
    };
    mustVerifyEmail?: boolean;
    status?: string;
    store?: {
        id: number;
        slug?: string;
        name: string;
        team_members?: Array<{ id: number; name: string; email: string; role: string }>;
        description?: string;
        address?: string;
        phone?: string;
        alert_whatsapp_numbers?: string;
        email?: string;
        website?: string;
        business_type?: string;
        tax_enabled: boolean;
        tax_rate: number;
        tax_name: string;
        receipt_header_name?: string;
        receipt_subtitle?: string;
        receipt_footer?: string;
        receipt_footer_line2?: string;
        receipt_logo_preview_url?: string | null;
        receipt_show_address?: boolean;
        receipt_show_phone?: boolean;
        receipt_show_logo?: boolean;
        receipt_paper_width?: string;
        batch_tracking_enabled?: boolean;
        batch_expiry_warn_days?: number;
        batch_expiry_email_enabled?: boolean;
        default_service_charge_percent?: number;
        pos_show_barcode?: boolean;
        ai_chat_enabled?: boolean;
        recipes_enabled?: boolean;
        productions_enabled?: boolean;
        kitchen_queue_enabled?: boolean;
        fnb_order_types_enabled?: boolean;
        fnb_split_bill_enabled?: boolean;
        open_bill_enabled?: boolean;
        direct_stock_edit?: boolean;
        warehouse_enabled?: boolean;
        is_allow_local_purchase?: boolean;
        online_sales_enabled?: boolean;
        online_commission_gofood?: number;
        online_commission_grabfood?: number;
        online_commission_shopeefood?: number;
        barber_commission_mode?: string;
        barber_commission_value?: number;
        queue_module_enabled?: boolean;
        attendance_enabled?: boolean;
        scan_meja_enabled?: boolean;
        accounting_enabled?: boolean;
        max_backdate_days?: number;
        receivables_enabled?: boolean;
        product_discount_enabled?: boolean;
        scan_meja_payment_methods?: PaymentMethod[];
        cashier_login_mode?: string;
        location_type?: string;
        organization_id?: number | null;
        organization_name?: string;
    };
    businessTypes?: Record<string, string>;
    joinableOrganizations?: Array<{ id: number; name: string }>;
    scheduledBackups?: Array<{ filename: string; size: number; created_at: number }>;
}

const page = usePage();

const props = withDefaults(defineProps<Props>(), {
    user: () => ({
        id: 0,
        name: 'User',
        email: 'user@example.com',
        email_verified_at: null,
    }),
    mustVerifyEmail: false,
    status: undefined,
    store: () => ({
        id: 0,
        slug: '',
        name: 'Store',
        team_members: [],
        description: '',
        address: '',
        phone: '',
        email: '',
        website: '',
        business_type: 'general',
        tax_enabled: false,
        tax_rate: 11.0,
        tax_name: 'PPN',
        receipt_header_name: '',
        receipt_subtitle: '',
        receipt_footer: 'Terima kasih atas kunjungan Anda.',
        receipt_footer_line2: '',
        receipt_logo_preview_url: null,
        receipt_show_address: true,
        receipt_show_phone: true,
        receipt_show_logo: true,
        receipt_paper_width: '80',
        batch_tracking_enabled: false,
        batch_expiry_warn_days: 30,
        batch_expiry_email_enabled: false,
        default_service_charge_percent: 0,
        pos_show_barcode: false,
        ai_chat_enabled: true,
        recipes_enabled: false,
        productions_enabled: false,
        kitchen_queue_enabled: false,
        organization_id: null,
        organization_name: '',
    }),
    businessTypes: () => ({}),
    joinableOrganizations: () => [],
});

// Mobile menu state
const isMobileMenuOpen = ref(false);

// Tax settings modal state
const isTaxModalOpen = ref(false);

// Tab state
const activeTab = ref('profile');

// Mode tampilan (Sederhana / Lengkap): disimpan langsung, terpisah dari form toko.
const uiMode = computed(() => ((page.props as { ui_mode?: string }).ui_mode === 'simple' ? 'simple' : 'full'));
const savingUiMode = ref(false);
function setUiMode(mode: 'simple' | 'full') {
    if (savingUiMode.value || uiMode.value === mode) return;
    savingUiMode.value = true;
    router.put('/settings/ui-mode', { ui_mode: mode }, { preserveScroll: true, onFinish: () => (savingUiMode.value = false) });
}
// Mode Sederhana: blok "Modul Fitur" dilipat sampai pengguna membukanya (nilai form tetap terkirim).
const showAdvancedSettings = ref(false);
const advancedSettingsVisible = computed(() => uiMode.value === 'full' || showAdvancedSettings.value);

const canManageStoreSettings = computed(() =>
    Boolean((page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings),
);

const activeModuleCount = computed(
    () =>
        [
            storeForm.recipes_enabled,
            storeForm.productions_enabled,
            storeForm.kitchen_queue_enabled,
            storeForm.queue_module_enabled,
            storeForm.wholesale_enabled,
            storeForm.attendance_enabled,
            storeForm.scan_meja_enabled,
            storeForm.accounting_enabled,
            storeForm.receivables_enabled,
            storeForm.product_discount_enabled,
            storeForm.cashier_pin_enabled,
            storeForm.warehouse_enabled,
            storeForm.online_sales_enabled,
            storeForm.ai_chat_enabled,
        ].filter(Boolean).length,
);

const canJoinExistingOrgForAttach = computed(() => (props.joinableOrganizations?.length ?? 0) > 0);
watch(canManageStoreSettings, (ok) => {
    if (!ok && activeTab.value === 'store') {
        activeTab.value = 'profile';
    }
});

// Toggle mobile menu
const toggleMobileMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
};

// Close mobile menu
const closeMobileMenu = () => {
    isMobileMenuOpen.value = false;
};

// Tax modal functions
const openTaxModal = () => {
    isTaxModalOpen.value = true;
};

const closeTaxModal = () => {
    isTaxModalOpen.value = false;
};

// Form
const form = useForm({
    name: props.user?.name || '',
    email: props.user?.email || '',
});

const receiptLogoInputRef = ref<HTMLInputElement | null>(null);
const receiptLogoObjectUrl = ref<string | null>(null);

const storeForm = useForm({
    name: props.store?.name || '',
    description: props.store?.description || '',
    address: props.store?.address || '',
    phone: props.store?.phone || '',
    alert_whatsapp_numbers: props.store?.alert_whatsapp_numbers || '',
    email: props.store?.email || '',
    website: props.store?.website || '',
    business_type: props.store?.business_type || 'general',
    receipt_header_name: props.store?.receipt_header_name || '',
    receipt_subtitle: props.store?.receipt_subtitle || '',
    receipt_footer: props.store?.receipt_footer || 'Terima kasih atas kunjungan Anda.',
    receipt_footer_line2: props.store?.receipt_footer_line2 || '',
    receipt_logo: null as File | null,
    remove_receipt_logo: false,
    receipt_show_address: props.store?.receipt_show_address !== false,
    receipt_show_phone: props.store?.receipt_show_phone !== false,
    receipt_show_logo: props.store?.receipt_show_logo !== false,
    receipt_paper_width: props.store?.receipt_paper_width || '80',
    batch_tracking_enabled: props.store?.batch_tracking_enabled === true,
    batch_expiry_warn_days: Number(props.store?.batch_expiry_warn_days ?? 30),
    batch_expiry_email_enabled: props.store?.batch_expiry_email_enabled === true,
    default_service_charge_percent: Number(props.store?.default_service_charge_percent ?? 0),
    organization_name: props.store?.organization_name || '',
    organization_attach_mode: '' as '' | 'new' | 'join',
    attach_organization_name: '',
    attach_organization_id: null as number | null,
    pos_show_barcode: props.store?.pos_show_barcode === true,
    ai_chat_enabled: props.store?.ai_chat_enabled !== false,
    recipes_enabled: props.store?.recipes_enabled === true,
    productions_enabled: props.store?.productions_enabled === true,
    kitchen_queue_enabled: props.store?.kitchen_queue_enabled === true,
    fnb_order_types_enabled: props.store?.fnb_order_types_enabled !== false,
    fnb_split_bill_enabled: props.store?.fnb_split_bill_enabled !== false,
    open_bill_enabled: props.store?.open_bill_enabled === true,
    direct_stock_edit: props.store?.direct_stock_edit === true,
    warehouse_enabled: props.store?.warehouse_enabled === true,
    is_allow_local_purchase: props.store?.is_allow_local_purchase === true,
    online_sales_enabled: props.store?.online_sales_enabled === true,
    online_commission_gofood: props.store?.online_commission_gofood ?? 20,
    online_commission_grabfood: props.store?.online_commission_grabfood ?? 20,
    online_commission_shopeefood: props.store?.online_commission_shopeefood ?? 15,
    barber_commission_mode: props.store?.barber_commission_mode ?? 'percent',
    barber_commission_value: props.store?.barber_commission_value ?? 0,
    queue_module_enabled: props.store?.queue_module_enabled === true,
    cup_label_enabled: props.store?.cup_label_enabled === true,
    wholesale_enabled: props.store?.wholesale_enabled === true,
    attendance_enabled: props.store?.attendance_enabled === true,
    scan_meja_enabled: props.store?.scan_meja_enabled === true,
    accounting_enabled: props.store?.accounting_enabled === true,
    max_backdate_days: Number(props.store?.max_backdate_days ?? 7),
    receivables_enabled: props.store?.receivables_enabled === true,
    product_discount_enabled: props.store?.product_discount_enabled === true,
    scan_meja_payment_methods: (props.store?.scan_meja_payment_methods ?? [
        { type: 'cash', label: 'Tunai', active: true, instructions: 'Bayar langsung di kasir' },
        { type: 'qris', label: 'QRIS', active: true, image: null },
        { type: 'bank_transfer', label: 'Transfer Bank', active: false, bank_name: '', account_number: '', account_name: '' },
    ]) as PaymentMethod[],
    cashier_pin_enabled: props.store?.cashier_login_mode === 'pin',
    qris_image: null as File | null,
});

const isFnBType = computed(() => storeForm.business_type === 'fnb');

const featureDefaultsMap: Record<string, { recipes_enabled: boolean; productions_enabled: boolean }> = {
    fnb: { recipes_enabled: true, productions_enabled: true },
    bakery: { recipes_enabled: true, productions_enabled: true },
    pharmacy: { recipes_enabled: true, productions_enabled: false },
    general: { recipes_enabled: false, productions_enabled: false },
    retail: { recipes_enabled: false, productions_enabled: false },
    laundry: { recipes_enabled: false, productions_enabled: false },
};

let businessTypeSkippedInitial = false;

watch(
    () => storeForm.business_type,
    (t) => {
        if (t === 'pharmacy') {
            storeForm.batch_tracking_enabled = true;
        }
        if (!businessTypeSkippedInitial) {
            businessTypeSkippedInitial = true;
            return;
        }
        const defaults = featureDefaultsMap[t] ?? featureDefaultsMap.general;
        storeForm.recipes_enabled = defaults.recipes_enabled;
        storeForm.productions_enabled = defaults.productions_enabled;
    },
    { immediate: true },
);

watch(
    () => storeForm.batch_tracking_enabled,
    (on) => {
        if (!on) {
            storeForm.batch_expiry_email_enabled = false;
        }
    },
);

const receiptLogoDisplaySrc = computed(() => {
    if (storeForm.remove_receipt_logo) {
        return null;
    }
    if (receiptLogoObjectUrl.value) {
        return receiptLogoObjectUrl.value;
    }
    return props.store?.receipt_logo_preview_url || null;
});

function clearPendingReceiptLogo() {
    storeForm.receipt_logo = null;
    if (receiptLogoInputRef.value) {
        receiptLogoInputRef.value.value = '';
    }
    if (receiptLogoObjectUrl.value) {
        URL.revokeObjectURL(receiptLogoObjectUrl.value);
        receiptLogoObjectUrl.value = null;
    }
}

function onReceiptLogoFile(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    storeForm.remove_receipt_logo = false;
    if (!file) {
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        toastWarning('Ukuran gambar maksimal 2MB');
        target.value = '';
        return;
    }
    storeForm.receipt_logo = file;
    if (receiptLogoObjectUrl.value) {
        URL.revokeObjectURL(receiptLogoObjectUrl.value);
    }
    receiptLogoObjectUrl.value = URL.createObjectURL(file);
}

function removeReceiptLogoChoice() {
    if (receiptLogoObjectUrl.value || storeForm.receipt_logo) {
        clearPendingReceiptLogo();
        return;
    }
    if (props.store?.receipt_logo_preview_url) {
        storeForm.remove_receipt_logo = true;
    }
}

// --- QRIS Image ---
const qrisImageFile = ref<File | null>(null);
const qrisImagePreview = ref<string | null>(null);
function openQrisFilePicker() {
    const el = document.getElementById('qris-upload-input') as HTMLInputElement | null;
    el?.click();
}

const qrisImageUrl = computed(() => {
    if (qrisImagePreview.value) return qrisImagePreview.value;
    const qrisMethod = storeForm.scan_meja_payment_methods?.find((m) => m.type === 'qris');
    if (qrisMethod?.image) return `/storage/${qrisMethod.image}`;
    return null;
});

function onQrisImageFile(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        toastWarning('Ukuran gambar QRIS maksimal 2MB');
        target.value = '';
        return;
    }
    qrisImageFile.value = file;
    if (qrisImagePreview.value) URL.revokeObjectURL(qrisImagePreview.value);
    qrisImagePreview.value = URL.createObjectURL(file);
    // Simpan file ke storeForm agar dikirim via forceFormData
    storeForm.qris_image = file;
}

function removeQrisImage() {
    qrisImageFile.value = null;
    if (qrisImagePreview.value) {
        URL.revokeObjectURL(qrisImagePreview.value);
        qrisImagePreview.value = null;
    }
    const el = document.getElementById('qris-upload-input') as HTMLInputElement | null;
    if (el) el.value = '';
    storeForm.qris_image = null;
    const qrisIdx = storeForm.scan_meja_payment_methods?.findIndex((m) => m.type === 'qris') ?? -1;
    if (qrisIdx >= 0) storeForm.scan_meja_payment_methods[qrisIdx].image = null;
}

const submit = async () => {
    if (!form) return;
    const ok = await confirmDialog('Simpan Perubahan Profil?', 'Nama dan email akan diperbarui. Jika email berubah, Anda perlu verifikasi ulang.', {
        confirmText: 'Simpan',
        icon: 'question',
    });
    if (!ok) return;
    form.patch('/settings/profile', {
        preserveScroll: true,
    });
};

const submitStore = async () => {
    const ok = await confirmDialog(
        'Simpan Pengaturan Toko?',
        'Pengaturan toko termasuk nama, alamat, tipe bisnis, pajak, struk, dan batch tracking akan diperbarui.',
        { confirmText: 'Simpan', icon: 'question' },
    );
    if (!ok) return;
    storeForm
        .transform((data) => {
            const d: Record<string, unknown> = {
                ...data,
                batch_tracking_enabled: data.business_type === 'pharmacy' ? true : data.batch_tracking_enabled,
                batch_expiry_email_enabled:
                    data.business_type === 'pharmacy' || data.batch_tracking_enabled ? data.batch_expiry_email_enabled : false,
                cashier_login_mode: data.cashier_pin_enabled ? 'pin' : 'email',
                _method: 'put',
            };
            delete d.cashier_pin_enabled;
            if (props.store?.organization_id) {
                delete d.organization_attach_mode;
                delete d.attach_organization_name;
                delete d.attach_organization_id;
            }
            return d;
        })
        .post('/settings/store', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearPendingReceiptLogo();
                storeForm.remove_receipt_logo = false;
            },
        });
};

const inviteMemberForm = useForm({
    email: '',
    role: 'cashier' as 'admin' | 'manager' | 'cashier' | 'warehouse',
});

type StoreRole = 'admin' | 'manager' | 'cashier' | 'warehouse';

const createMemberForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'cashier' as StoreRole,
});

const memberRoles = ref<Record<number, StoreRole>>({});

watch(
    () => props.store?.team_members,
    (list) => {
        const next: Record<number, StoreRole> = {};
        for (const m of list ?? []) {
            next[m.id] = m.role as StoreRole;
        }
        memberRoles.value = next;
    },
    { immediate: true },
);

function memberRoleLabel(role: string): string {
    const map: Record<string, string> = {
        admin: 'Admin',
        manager: 'Manajer',
        cashier: 'Kasir',
        warehouse: 'Admin gudang',
    };
    return map[role] ?? role;
}

const submitInviteMember = async () => {
    const slug = props.store?.slug;
    if (!slug) {
        return;
    }
    const ok = await confirmDialog(
        'Undang Anggota?',
        `Undangan akan dikirim ke ${inviteMemberForm.email} dengan peran ${memberRoleLabel(inviteMemberForm.role)}.`,
        { confirmText: 'Kirim Undangan', icon: 'question' },
    );
    if (!ok) return;
    inviteMemberForm.post(`/stores/${slug}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            inviteMemberForm.reset('email');
            inviteMemberForm.role = 'cashier';
        },
    });
};

const submitCreateMember = () => {
    const slug = props.store?.slug;
    if (!slug) {
        return;
    }
    createMemberForm.post(`/stores/${slug}/members/create`, {
        preserveScroll: true,
        onSuccess: () => {
            createMemberForm.reset();
            createMemberForm.role = 'cashier';
        },
    });
};

async function saveMemberRole(memberId: number) {
    const slug = props.store?.slug;
    if (!slug) {
        return;
    }
    const role = memberRoles.value[memberId];
    if (!role) {
        return;
    }
    const member = props.store?.team_members?.find((m) => m.id === memberId);
    const ok = await confirmDialog('Ubah Peran Anggota?', `Peran "${member?.name ?? 'anggota'}" akan diubah ke ${memberRoleLabel(role)}.`, {
        confirmText: 'Ubah Peran',
        icon: 'question',
    });
    if (!ok) return;
    router.patch(`/stores/${slug}/members/${memberId}`, { role }, { preserveScroll: true });
}

function memberRoleDirty(m: { id: number; role: string }): boolean {
    return memberRoles.value[m.id] !== m.role;
}

async function toggleAccounting() {
    const enabling = !storeForm.accounting_enabled;
    if (enabling) {
        const ok = await confirmDialog(
            'Aktifkan Modul Akuntansi?',
            'Setiap transaksi akan dijurnal secara otomatis. COA standar dan periode bulan berjalan akan dibuat. Kasir hanya dapat memproses pembayaran jika ada periode akuntansi terbuka.',
            { confirmText: 'Aktifkan', icon: 'warning' },
        );
        if (!ok) return;
    } else {
        const ok = await confirmDialog(
            'Nonaktifkan Modul Akuntansi?',
            'Penjurnalan otomatis akan dihentikan. Histori jurnal yang sudah ada tetap tersimpan dan dapat diakses kembali saat modul diaktifkan ulang.',
            { confirmText: 'Nonaktifkan', icon: 'warning' },
        );
        if (!ok) return;
    }
    storeForm.accounting_enabled = enabling;
}
</script>

<template>
    <AdminLayout title="Pengaturan">
        <!-- Tab Navigation -->
        <div class="mb-6">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                    <button
                        @click="activeTab = 'profile'"
                        :class="[
                            'border-b-2 px-1 py-2 text-sm font-medium',
                            activeTab === 'profile'
                                ? 'border-blue-500 text-blue-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <User class="h-4 w-4" />
                            Profil
                        </div>
                    </button>
                    <button
                        v-if="canManageStoreSettings"
                        type="button"
                        @click="activeTab = 'store'"
                        :class="[
                            'border-b-2 px-1 py-2 text-sm font-medium',
                            activeTab === 'store'
                                ? 'border-blue-500 text-blue-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <Building2 class="h-4 w-4" />
                            Toko
                        </div>
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'bluetooth'"
                        :class="[
                            'border-b-2 px-1 py-2 text-sm font-medium',
                            activeTab === 'bluetooth'
                                ? 'border-blue-500 text-blue-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <Bluetooth class="h-4 w-4" />
                            Bluetooth
                        </div>
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'backup'"
                        :class="[
                            'border-b-2 px-1 py-2 text-sm font-medium',
                            activeTab === 'backup'
                                ? 'border-blue-500 text-blue-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700',
                        ]"
                    >
                        <div class="flex items-center gap-2">
                            <HardDrive class="h-4 w-4" />
                            Backup
                        </div>
                    </button>
                </nav>
            </div>
        </div>

        <!-- Profile Tab Content -->
        <div v-if="activeTab === 'profile'">
            <!-- Profile Information Card -->
            <Card class="mb-6 border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <User class="h-5 w-5" />
                        Informasi Profil
                    </CardTitle>
                    <CardDescription> Update informasi nama dan email Anda </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-6">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Nama Lengkap</Label>
                                <Input id="name" v-model="form.name" type="text" placeholder="Masukkan nama lengkap" class="h-12" />
                                <div v-if="form.errors.name" class="text-sm text-red-600">
                                    {{ form.errors.name }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="email">Email</Label>
                                <Input id="email" v-model="form.email" type="email" placeholder="Masukkan email" class="h-12" />
                                <div v-if="form.errors.email" class="text-sm text-red-600">
                                    {{ form.errors.email }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="props.mustVerifyEmail && !props.user?.email_verified_at"
                            class="rounded-lg border border-yellow-200 bg-yellow-50 p-4"
                        >
                            <p class="text-sm text-yellow-800">
                                Email Anda belum diverifikasi.
                                <a href="/email/verification-notification" class="text-blue-600 underline hover:text-blue-800">
                                    Klik di sini untuk mengirim ulang email verifikasi.
                                </a>
                            </p>
                            <div v-if="props.status === 'verification-link-sent'" class="mt-2 text-sm text-green-600">
                                Link verifikasi baru telah dikirim ke email Anda.
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <Button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2">
                                <Save class="h-4 w-4" />
                                {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </Button>

                            <div v-if="form.recentlySuccessful" class="text-sm text-green-600">Berhasil disimpan!</div>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Account Actions Card -->
            <Card class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Settings class="h-5 w-5" />
                        Aksi Akun
                    </CardTitle>
                    <CardDescription> Kelola akun dan keamanan Anda </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-4">
                            <div>
                                <h3 class="font-medium text-gray-900">Ubah Password</h3>
                                <p class="text-sm text-gray-500">Perbarui password untuk keamanan akun</p>
                            </div>
                            <Button as="a" href="/settings/password" variant="outline"> Ubah Password </Button>
                        </div>

                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-4">
                            <div>
                                <h3 class="font-medium text-gray-900">Tema Aplikasi</h3>
                                <p class="text-sm text-gray-500">Atur tampilan dan tema aplikasi</p>
                            </div>
                            <Button as="a" href="/settings/appearance" variant="outline"> Pengaturan Tema </Button>
                        </div>

                        <div v-if="canManageStoreSettings" class="flex items-center justify-between rounded-lg border border-gray-200 p-4">
                            <div>
                                <h3 class="font-medium text-gray-900">Pengaturan PPN</h3>
                                <p class="text-sm text-gray-500">Kelola pengaturan pajak pertambahan nilai</p>
                            </div>
                            <Button @click="openTaxModal" variant="outline"> Atur PPN </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Store Tab Content -->
        <div v-if="canManageStoreSettings && activeTab === 'store'">
            <!-- Mode Tampilan -->
            <Card class="mb-6 border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Blocks class="h-5 w-5" />
                        Tampilan Aplikasi
                    </CardTitle>
                    <CardDescription>
                        Pilih seberapa banyak menu yang ditampilkan. Data dan pencatatan tidak berubah, dan Anda bisa berganti kapan saja.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            :disabled="savingUiMode"
                            :aria-pressed="uiMode === 'simple'"
                            :class="[
                                'rounded-xl border-2 p-4 text-left transition disabled:opacity-60',
                                uiMode === 'simple' ? 'border-blue-600 bg-blue-50' : 'border-gray-200 hover:border-gray-300',
                            ]"
                            @click="setUiMode('simple')"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-gray-900">Mode Sederhana</span>
                                <span v-if="uiMode === 'simple'" class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-medium text-white"
                                    >Aktif</span
                                >
                            </div>
                            <p class="mt-1 text-sm text-gray-600">
                                Hanya menu inti: kasir, produk, stok, pembelian, pelanggan, laporan, dan biaya. Cocok untuk mulai berjualan.
                            </p>
                        </button>
                        <button
                            type="button"
                            :disabled="savingUiMode"
                            :aria-pressed="uiMode === 'full'"
                            :class="[
                                'rounded-xl border-2 p-4 text-left transition disabled:opacity-60',
                                uiMode === 'full' ? 'border-blue-600 bg-blue-50' : 'border-gray-200 hover:border-gray-300',
                            ]"
                            @click="setUiMode('full')"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-semibold text-gray-900">Mode Lengkap</span>
                                <span v-if="uiMode === 'full'" class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-medium text-white"
                                    >Aktif</span
                                >
                            </div>
                            <p class="mt-1 text-sm text-gray-600">
                                Semua menu tampil: opname, transfer stok, retur, diskon, loyalitas, resep, produksi, dan lainnya.
                            </p>
                        </button>
                    </div>
                    <p class="mt-4 text-sm text-gray-600">
                        Butuh satu-dua fitur saja?
                        <a
                            href="/settings/features"
                            class="font-semibold text-blue-600 hover:underline"
                            @click.prevent="router.visit('/settings/features')"
                        >
                            Buka Fitur Tambahan
                        </a>
                        untuk menyalakannya satu per satu.
                    </p>
                </CardContent>
            </Card>

            <!-- Store Information Card -->
            <Card class="mb-6 border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Building2 class="h-5 w-5" />
                        Informasi Toko
                    </CardTitle>
                    <CardDescription> Update informasi toko dan kontak bisnis Anda </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submitStore" class="space-y-6">
                        <div class="space-y-2">
                            <Label for="business_type">Jenis usaha</Label>
                            <select
                                id="business_type"
                                v-model="storeForm.business_type"
                                class="flex h-12 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            >
                                <option v-for="(label, key) in businessTypes" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <p class="text-xs text-gray-500">Menyesuaikan saran kategori & satuan di halaman tambah produk.</p>
                            <div v-if="storeForm.errors.business_type" class="text-sm text-red-600">
                                {{ storeForm.errors.business_type }}
                            </div>
                        </div>

                        <div
                            v-if="storeForm.business_type === 'pharmacy'"
                            class="rounded-xl border border-sky-200 bg-sky-50/80 p-4 text-xs leading-relaxed text-slate-800"
                        >
                            <p class="font-semibold text-slate-900">Mode apotek (Fase E)</p>
                            <p class="mt-2">
                                Aplikasi membantu pencatatan SKU, klasifikasi obat (bebas / terbatas / resep), dan nomor izin edar per produk. Ini
                                tidak menggantikan kewajiban hukum, izin usaha, maupun kewenangan apoteker sesuai peraturan setempat. Pengguna
                                bertanggung jawab atas kepatuhan operasional.
                            </p>
                        </div>

                        <div v-if="!props.store?.organization_id" class="space-y-4 rounded-xl border border-amber-200 bg-amber-50/90 p-4">
                            <div>
                                <p class="text-sm font-semibold text-amber-950">Hubungkan ke grup usaha (multi-outlet)</p>
                                <p class="mt-1 text-xs leading-relaxed text-amber-900/95">
                                    Tanpa grup, transfer stok antar outlet tidak tersedia. Anda bisa membuat grup baru atau menggabungkan toko ini ke
                                    grup yang sudah Anda kelola di outlet lain.
                                </p>
                            </div>
                            <div class="space-y-2">
                                <Label class="text-sm font-medium text-amber-950">Pilihan</Label>
                                <div class="flex flex-col gap-2">
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-amber-950">
                                        <input
                                            v-model="storeForm.organization_attach_mode"
                                            type="radio"
                                            value=""
                                            class="rounded-full border-amber-400 text-primary focus:ring-primary"
                                        />
                                        <span>Jangan ubah (bisa diatur nanti)</span>
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-amber-950">
                                        <input
                                            v-model="storeForm.organization_attach_mode"
                                            type="radio"
                                            value="new"
                                            class="rounded-full border-amber-400 text-primary focus:ring-primary"
                                        />
                                        <span>Buat grup usaha baru</span>
                                    </label>
                                    <label
                                        class="flex cursor-pointer items-center gap-2 text-sm"
                                        :class="canJoinExistingOrgForAttach ? 'text-amber-950' : 'cursor-not-allowed text-amber-800/50'"
                                    >
                                        <input
                                            v-model="storeForm.organization_attach_mode"
                                            type="radio"
                                            value="join"
                                            :disabled="!canJoinExistingOrgForAttach"
                                            class="rounded-full border-amber-400 text-primary focus:ring-primary disabled:opacity-50"
                                        />
                                        <span>Gabung grup yang sudah ada</span>
                                    </label>
                                </div>
                                <p v-if="!canJoinExistingOrgForAttach" class="text-[11px] text-amber-800/90">
                                    Opsi gabung aktif jika Anda sudah punya toko lain yang termasuk grup.
                                </p>
                                <div v-if="storeForm.errors.organization_attach_mode" class="text-sm text-red-600">
                                    {{ storeForm.errors.organization_attach_mode }}
                                </div>
                            </div>
                            <div v-if="storeForm.organization_attach_mode === 'new'" class="space-y-2">
                                <Label for="attach_org_name" class="text-sm font-medium text-amber-950"> Nama grup baru </Label>
                                <Input
                                    id="attach_org_name"
                                    v-model="storeForm.attach_organization_name"
                                    type="text"
                                    class="h-11 rounded-xl border-amber-200"
                                    placeholder="Kosongkan untuk memakai nama toko"
                                />
                                <div v-if="storeForm.errors.attach_organization_name" class="text-sm text-red-600">
                                    {{ storeForm.errors.attach_organization_name }}
                                </div>
                            </div>
                            <div v-if="storeForm.organization_attach_mode === 'join'" class="space-y-2">
                                <Label for="attach_org_id" class="text-sm font-medium text-amber-950"> Grup * </Label>
                                <select
                                    id="attach_org_id"
                                    v-model.number="storeForm.attach_organization_id"
                                    class="flex h-11 w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"
                                    required
                                >
                                    <option :value="null" disabled>Pilih grup…</option>
                                    <option v-for="o in joinableOrganizations || []" :key="o.id" :value="o.id">
                                        {{ o.name }}
                                    </option>
                                </select>
                                <div v-if="storeForm.errors.attach_organization_id" class="text-sm text-red-600">
                                    {{ storeForm.errors.attach_organization_id }}
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="store_name">Nama Toko</Label>
                                <Input id="store_name" v-model="storeForm.name" type="text" placeholder="Masukkan nama toko" class="h-12" />
                                <div v-if="storeForm.errors.name" class="text-sm text-red-600">
                                    {{ storeForm.errors.name }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="store_phone">Nomor Telepon</Label>
                                <Input id="store_phone" v-model="storeForm.phone" type="tel" placeholder="Masukkan nomor telepon" class="h-12" />
                                <div v-if="storeForm.errors.phone" class="text-sm text-red-600">
                                    {{ storeForm.errors.phone }}
                                </div>
                            </div>
                        </div>

                        <div v-if="props.store?.organization_id" class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/90 p-4">
                            <Label for="org_name" class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <Building2 class="h-4 w-4 text-primary" />
                                Nama grup usaha (multi-outlet)
                            </Label>
                            <Input
                                id="org_name"
                                v-model="storeForm.organization_name"
                                type="text"
                                placeholder="Nama grup untuk semua outlet terkait"
                                class="h-12 rounded-xl border-slate-200"
                            />
                            <p class="text-xs leading-relaxed text-slate-600">
                                Mengubah ini memperbarui label grup di menu pilih toko untuk semua outlet dalam grup yang sama.
                            </p>
                            <div v-if="storeForm.errors.organization_name" class="text-sm text-red-600">
                                {{ storeForm.errors.organization_name }}
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="store_description">Deskripsi Toko</Label>
                            <textarea
                                id="store_description"
                                v-model="storeForm.description"
                                rows="3"
                                placeholder="Masukkan deskripsi toko"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            ></textarea>
                            <div v-if="storeForm.errors.description" class="text-sm text-red-600">
                                {{ storeForm.errors.description }}
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="store_address">Alamat Toko</Label>
                            <textarea
                                id="store_address"
                                v-model="storeForm.address"
                                rows="3"
                                placeholder="Masukkan alamat lengkap toko"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            ></textarea>
                            <div v-if="storeForm.errors.address" class="text-sm text-red-600">
                                {{ storeForm.errors.address }}
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="alert_whatsapp_numbers" class="flex items-center gap-2">
                                <Phone class="h-4 w-4 text-emerald-600" />
                                Nomor WhatsApp admin (peringatan otomatis)
                            </Label>
                            <Input
                                id="alert_whatsapp_numbers"
                                v-model="storeForm.alert_whatsapp_numbers"
                                type="text"
                                placeholder="62812xxxxxxx,62813xxxxxxx"
                                class="h-12"
                            />
                            <p class="text-xs text-gray-500">
                                Bot WhatsApp akan kirim peringatan stok/omzet/margin ke nomor ini (pisah koma jika lebih dari satu). Kosongkan untuk
                                menonaktifkan WA per toko.
                            </p>
                            <div v-if="storeForm.errors.alert_whatsapp_numbers" class="text-sm text-red-600">
                                {{ storeForm.errors.alert_whatsapp_numbers }}
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="store_email">Email Toko</Label>
                                <Input id="store_email" v-model="storeForm.email" type="email" placeholder="Masukkan email toko" class="h-12" />
                                <div v-if="storeForm.errors.email" class="text-sm text-red-600">
                                    {{ storeForm.errors.email }}
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="store_website">Website Toko</Label>
                                <Input id="store_website" v-model="storeForm.website" type="url" placeholder="Masukkan website toko" class="h-12" />
                                <div v-if="storeForm.errors.website" class="text-sm text-red-600">
                                    {{ storeForm.errors.website }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="storeForm.business_type === 'fnb'"
                            class="space-y-3 rounded-xl border border-dashed border-emerald-200 bg-emerald-50/50 p-5"
                        >
                            <h3 class="text-sm font-bold text-slate-900">F&amp;B — biaya layanan (kasir)</h3>
                            <p class="text-xs text-slate-600">
                                Persen default untuk <strong>service charge</strong> di POS (bisa diubah per transaksi). Jika pajak toko aktif, PPN
                                dihitung dari subtotal setelah diskon <strong>ditambah</strong> biaya layanan.
                            </p>
                            <div class="max-w-xs space-y-2">
                                <Label for="default_service_charge_percent">Biaya layanan default (%)</Label>
                                <Input
                                    id="default_service_charge_percent"
                                    v-model.number="storeForm.default_service_charge_percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.5"
                                    class="h-12"
                                />
                                <div v-if="storeForm.errors.default_service_charge_percent" class="text-sm text-red-600">
                                    {{ storeForm.errors.default_service_charge_percent }}
                                </div>
                            </div>
                            <div class="space-y-2 border-t border-emerald-200 pt-2">
                                <label class="flex cursor-pointer items-center gap-2 text-sm select-none">
                                    <input v-model="storeForm.fnb_order_types_enabled" type="checkbox" class="rounded border-gray-300" />
                                    Tampilkan pilihan tipe pesanan
                                </label>
                                <p class="ml-6 text-xs text-slate-500">
                                    Tombol Makan di tempat / Bungkus / Antar di kasir. Nonaktifkan jika tidak diperlukan.
                                </p>
                                <label class="flex cursor-pointer items-center gap-2 text-sm select-none">
                                    <input v-model="storeForm.fnb_split_bill_enabled" type="checkbox" class="rounded border-gray-300" />
                                    Aktifkan bagi tagihan (split bill)
                                </label>
                                <p class="ml-6 text-xs text-slate-500">
                                    Membagi total tagihan ke beberapa bagian (patungan tamu). 1 = tidak dibagi, 2–20 = dibagi rata.
                                </p>
                            </div>
                        </div>

                        <button
                            v-if="uiMode === 'simple'"
                            type="button"
                            :aria-expanded="showAdvancedSettings"
                            class="flex w-full items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-left transition hover:bg-gray-100"
                            @click="showAdvancedSettings = !showAdvancedSettings"
                        >
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">Pengaturan lanjutan</span>
                                <span class="block text-xs text-gray-600">
                                    Modul fitur, batch & kedaluwarsa, gudang, penjualan online, login PIN kasir, dan lainnya.
                                </span>
                            </span>
                            <span class="shrink-0 text-sm font-medium text-blue-600">{{ showAdvancedSettings ? 'Sembunyikan' : 'Tampilkan' }}</span>
                        </button>

                        <div
                            v-show="advancedSettingsVisible"
                            class="space-y-4 rounded-xl border border-dashed border-rose-200 bg-gradient-to-br from-rose-50 via-white to-orange-50/60 p-5"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-rose-500 to-orange-400 shadow-md shadow-rose-200"
                                >
                                    <Blocks class="h-6 w-6 text-white" />
                                </div>
                                <div>
                                    <h3 class="flex flex-wrap items-center gap-2 text-base font-bold text-slate-900">
                                        Modul Fitur
                                        <span
                                            class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[10px] font-bold tracking-wide text-emerald-700 uppercase"
                                        >
                                            {{ activeModuleCount }} aktif
                                        </span>
                                    </h3>
                                    <p class="mt-1 max-w-2xl text-xs leading-relaxed text-slate-600">
                                        Aktifkan atau nonaktifkan modul sesuai kebutuhan usaha Anda. Secara default aktif untuk F&amp;B / Bakery,
                                        nonaktif untuk tipe lain.
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-2.5 sm:grid-cols-2">
                                <div
                                    class="group relative rounded-xl border p-3.5 transition-all duration-200"
                                    :class="
                                        storeForm.open_bill_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="storeForm.open_bill_enabled"
                                        class="flex w-full cursor-pointer items-start gap-3 text-left"
                                        @click="storeForm.open_bill_enabled = !storeForm.open_bill_enabled"
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                            :class="
                                                storeForm.open_bill_enabled
                                                    ? 'bg-emerald-500 text-white'
                                                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                            "
                                        >
                                            <History class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <span
                                                    class="text-sm font-semibold"
                                                    :class="storeForm.open_bill_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                    >Open Bill</span
                                                >
                                                <span
                                                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                    :class="storeForm.open_bill_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                                >
                                                    <span
                                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                        :class="storeForm.open_bill_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                    />
                                                </span>
                                            </div>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500" v-if="!isFnBType">
                                                Simpan keranjang sebagai pesanan belum dibayar, lalu panggil kembali saat pelanggan membayar. Untuk
                                                usaha F&amp;B modul ini selalu aktif otomatis.
                                            </p>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500" v-else>
                                                Modul ini selalu aktif untuk usaha F&amp;B.
                                            </p>
                                        </div>
                                    </button>
                                </div>

                                <div
                                    class="group relative rounded-xl border p-3.5 transition-all duration-200"
                                    :class="
                                        storeForm.batch_tracking_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="storeForm.batch_tracking_enabled"
                                        :disabled="storeForm.business_type === 'pharmacy'"
                                        class="flex w-full items-start gap-3 text-left"
                                        :class="storeForm.business_type === 'pharmacy' ? 'cursor-not-allowed opacity-90' : 'cursor-pointer'"
                                        @click="storeForm.batch_tracking_enabled = !storeForm.batch_tracking_enabled"
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                            :class="
                                                storeForm.batch_tracking_enabled
                                                    ? 'bg-emerald-500 text-white'
                                                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                            "
                                        >
                                            <Package class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <span
                                                    class="text-sm font-semibold"
                                                    :class="storeForm.batch_tracking_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                    >Batch &amp; tanggal kedaluwarsa</span
                                                >
                                                <span
                                                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                    :class="storeForm.batch_tracking_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                                >
                                                    <span
                                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                        :class="storeForm.batch_tracking_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                    />
                                                </span>
                                            </div>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                                Lacak batch dan ED per produk dengan pengurangan stok FEFO.
                                            </p>
                                        </div>
                                    </button>
                                    <p v-if="storeForm.business_type === 'pharmacy'" class="mt-2 text-xs font-medium text-amber-900">
                                        Mode apotek: lacak batch selalu aktif; setiap batch wajib memiliki tanggal kedaluwarsa (ED).
                                    </p>
                                    <div class="mt-3 space-y-2">
                                        <Label for="batch_expiry_warn_days">Peringatan ED (hari)</Label>
                                        <Input
                                            id="batch_expiry_warn_days"
                                            v-model.number="storeForm.batch_expiry_warn_days"
                                            type="number"
                                            min="1"
                                            max="365"
                                            step="1"
                                            class="h-12"
                                        />
                                        <p class="text-xs text-slate-600">
                                            Batch dalam rentang ini atau sudah lewat muncul di dashboard dan laporan stok per batch.
                                        </p>
                                        <div v-if="storeForm.errors.batch_expiry_warn_days" class="text-sm text-red-600">
                                            {{ storeForm.errors.batch_expiry_warn_days }}
                                        </div>
                                    </div>
                                    <label v-if="storeForm.batch_tracking_enabled" class="mt-3 flex cursor-pointer items-start gap-2 text-sm">
                                        <input
                                            v-model="storeForm.batch_expiry_email_enabled"
                                            type="checkbox"
                                            class="mt-0.5 rounded border-gray-300"
                                        />
                                        <span>
                                            <span class="font-medium">Kirim email ringkasan batch</span>
                                            <span class="mt-0.5 block text-xs font-normal text-slate-600"
                                                >Sekali sehari (±07:00 server) jika ada batch dalam jendela peringatan. Penerima: email toko dan
                                                anggota Admin/Manajer.</span
                                            >
                                        </span>
                                    </label>
                                </div>

                                <div
                                    v-if="$page.props.plan_limits?.allows_advanced_retail"
                                    class="group relative rounded-xl border p-3.5 transition-all duration-200"
                                    :class="
                                        storeForm.direct_stock_edit
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="storeForm.direct_stock_edit"
                                        class="flex w-full cursor-pointer items-start gap-3 text-left"
                                        @click="storeForm.direct_stock_edit = !storeForm.direct_stock_edit"
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                            :class="
                                                storeForm.direct_stock_edit
                                                    ? 'bg-emerald-500 text-white'
                                                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                            "
                                        >
                                            <Undo2 class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <span
                                                    class="text-sm font-semibold"
                                                    :class="storeForm.direct_stock_edit ? 'text-emerald-900' : 'text-slate-700'"
                                                    >Koreksi Stok</span
                                                >
                                                <span
                                                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                    :class="storeForm.direct_stock_edit ? 'bg-emerald-500' : 'bg-slate-300'"
                                                    ><span
                                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                        :class="storeForm.direct_stock_edit ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                /></span>
                                            </div>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                                Izinkan edit stok langsung di form Edit Produk tanpa alur dokumen penyesuaian atau stock opname.
                                            </p>
                                        </div>
                                    </button>
                                </div>

                                <div
                                    class="group relative rounded-xl border p-3.5 transition-all duration-200"
                                    :class="
                                        storeForm.pos_show_barcode
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="storeForm.pos_show_barcode"
                                        class="flex w-full cursor-pointer items-start gap-3 text-left"
                                        @click="storeForm.pos_show_barcode = !storeForm.pos_show_barcode"
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                            :class="
                                                storeForm.pos_show_barcode
                                                    ? 'bg-emerald-500 text-white'
                                                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                            "
                                        >
                                            <ShoppingCart class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <span
                                                    class="text-sm font-semibold"
                                                    :class="storeForm.pos_show_barcode ? 'text-emerald-900' : 'text-slate-700'"
                                                    >Pengaturan POS</span
                                                >
                                                <span
                                                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                    :class="storeForm.pos_show_barcode ? 'bg-emerald-500' : 'bg-slate-300'"
                                                    ><span
                                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                        :class="storeForm.pos_show_barcode ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                /></span>
                                            </div>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500">Tampilkan barcode scanner di halaman POS.</p>
                                        </div>
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.ai_chat_enabled"
                                    @click="storeForm.ai_chat_enabled = !storeForm.ai_chat_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.ai_chat_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.ai_chat_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <Bot class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.ai_chat_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Chat AI</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.ai_chat_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.ai_chat_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">Izinkan pengguna toko mengakses asisten Chat AI.</p>
                                    </div>
                                </button>

                                <!-- Resep -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.recipes_enabled"
                                    @click="storeForm.recipes_enabled = !storeForm.recipes_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.recipes_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.recipes_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <ChefHat class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.recipes_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Resep</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.recipes_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.recipes_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Menu Resep di sidebar untuk membuat resep bahan baku.
                                        </p>
                                    </div>
                                </button>

                                <!-- Produksi -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.productions_enabled"
                                    @click="storeForm.productions_enabled = !storeForm.productions_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.productions_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.productions_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <Factory class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.productions_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Produksi</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.productions_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.productions_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Menu Produksi di sidebar untuk mengelola hasil produksi.
                                        </p>
                                    </div>
                                </button>
                                <!-- Antrian Dapur -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.kitchen_queue_enabled"
                                    @click="storeForm.kitchen_queue_enabled = !storeForm.kitchen_queue_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.kitchen_queue_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.kitchen_queue_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <CookingPot class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.kitchen_queue_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Antrian Dapur</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.kitchen_queue_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.kitchen_queue_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Menu Antrian Dapur di sidebar untuk mengelola antrian pesanan dapur.
                                        </p>
                                    </div>
                                </button>

                                <!-- Stiker Cup / Label Pesanan -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.cup_label_enabled"
                                    @click="storeForm.cup_label_enabled = !storeForm.cup_label_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.cup_label_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.cup_label_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <Tag class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.cup_label_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Stiker Cup / Label Pesanan</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.cup_label_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.cup_label_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Aktifkan tombol cetak stiker label per cup/item di kasir untuk warkop atau kafe.
                                        </p>
                                    </div>
                                </button>

                                <!-- Harga Grosir -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.wholesale_enabled"
                                    @click="storeForm.wholesale_enabled = !storeForm.wholesale_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.wholesale_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.wholesale_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <PackageOpen class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.wholesale_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Harga Grosir</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.wholesale_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.wholesale_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Kolom Harga Grosir di Produk/Varian &amp; pilihan pelanggan Ecer/Grosir.
                                        </p>
                                    </div>
                                </button>
                                <!-- Absensi -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.attendance_enabled"
                                    @click="storeForm.attendance_enabled = !storeForm.attendance_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.attendance_enabled
                                            ? 'border-emerald-300 bg-emerald-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.attendance_enabled
                                                ? 'bg-emerald-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <CalendarClock class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.attendance_enabled ? 'text-emerald-900' : 'text-slate-700'"
                                                >Absensi</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.attendance_enabled ? 'bg-emerald-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.attendance_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Menu Absensi di sidebar. Perlu paket Absensi + Payroll yang aktif.
                                        </p>
                                    </div>
                                </button>
                                <!-- Scan Meja -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.scan_meja_enabled"
                                    @click="storeForm.scan_meja_enabled = !storeForm.scan_meja_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200 sm:col-span-2"
                                    :class="
                                        storeForm.scan_meja_enabled
                                            ? 'border-violet-300 bg-violet-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.scan_meja_enabled
                                                ? 'bg-violet-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <ScanQrCode class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.scan_meja_enabled ? 'text-violet-900' : 'text-slate-700'"
                                                >Scan Meja (Menu Digital)</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.scan_meja_enabled ? 'bg-violet-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.scan_meja_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Pelanggan scan QR code di meja untuk melihat menu digital dan memesan. Tersedia untuk F&amp;B / Bakery.
                                        </p>
                                    </div>
                                </button>
                                <!-- Akuntansi -->
                                <div class="sm:col-span-2">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="storeForm.accounting_enabled"
                                        @click="toggleAccounting"
                                        class="group relative flex w-full cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                        :class="
                                            storeForm.accounting_enabled
                                                ? 'rounded-b-none border-b-0 border-blue-300 bg-blue-50/80'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                        "
                                    >
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                            :class="
                                                storeForm.accounting_enabled
                                                    ? 'bg-blue-500 text-white'
                                                    : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                            "
                                        >
                                            <BookOpenCheck class="h-5 w-5" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <span
                                                    class="text-sm font-semibold"
                                                    :class="storeForm.accounting_enabled ? 'text-blue-900' : 'text-slate-700'"
                                                    >Akuntansi (Jurnal)</span
                                                >
                                                <span
                                                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                    :class="storeForm.accounting_enabled ? 'bg-blue-500' : 'bg-slate-300'"
                                                >
                                                    <span
                                                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                        :class="storeForm.accounting_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                    />
                                                </span>
                                            </div>
                                            <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                                Laporan neraca, jurnal, tutup periode. Jika aktif, kasir hanya bisa bayar jika periode bulan berjalan
                                                terbuka.
                                            </p>
                                        </div>
                                    </button>
                                    <div
                                        v-if="storeForm.accounting_enabled"
                                        class="rounded-b-xl border border-t-0 border-dashed border-blue-300 bg-blue-50/30 p-4"
                                    >
                                        <div class="flex items-center gap-3">
                                            <label class="w-1/3 text-sm font-medium text-slate-700">Batas Backdate (Hari)</label>
                                            <div class="flex flex-1 items-center gap-2">
                                                <input
                                                    type="number"
                                                    v-model="storeForm.max_backdate_days"
                                                    min="0"
                                                    max="60"
                                                    class="block w-24 rounded-lg border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                                                />
                                                <span class="text-xs text-slate-500"
                                                    >Maksimal tanggal mundur untuk pencatatan (0 = tidak bisa backdate). Default: 7.</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hutang Piutang -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.receivables_enabled"
                                    @click="storeForm.receivables_enabled = !storeForm.receivables_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.receivables_enabled
                                            ? 'border-amber-300 bg-amber-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.receivables_enabled
                                                ? 'bg-amber-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <CreditCard class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.receivables_enabled ? 'text-amber-900' : 'text-slate-700'"
                                                >Hutang Piutang</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.receivables_enabled ? 'bg-amber-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.receivables_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Pencatatan piutang pelanggan dan transaksi kredit di POS.
                                        </p>
                                    </div>
                                </button>

                                <!-- Diskon per Produk -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.product_discount_enabled"
                                    @click="storeForm.product_discount_enabled = !storeForm.product_discount_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200"
                                    :class="
                                        storeForm.product_discount_enabled
                                            ? 'border-rose-300 bg-rose-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.product_discount_enabled
                                                ? 'bg-rose-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <Tag class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.product_discount_enabled ? 'text-rose-900' : 'text-slate-700'"
                                                >Diskon per Produk</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.product_discount_enabled ? 'bg-rose-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.product_discount_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Kasir bisa memberi diskon manual pada tiap produk di keranjang POS. Kode promo tidak terpengaruh.
                                        </p>
                                    </div>
                                </button>

                                <!-- Manajemen Metode Pembayaran Scan Meja -->
                                <div
                                    v-if="storeForm.scan_meja_enabled"
                                    class="space-y-4 rounded-xl border border-violet-200 bg-violet-50/60 p-4 sm:col-span-2"
                                >
                                    <p class="flex items-center gap-1.5 text-xs font-bold text-slate-700">
                                        <CreditCard class="h-3.5 w-3.5" />
                                        Metode Pembayaran Meja
                                    </p>

                                    <div
                                        v-for="(method, idx) in storeForm.scan_meja_payment_methods"
                                        :key="method.type"
                                        class="space-y-2.5 rounded-xl border border-slate-200 bg-white p-3"
                                    >
                                        <!-- Header baris -->
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <Banknote v-if="method.type === 'cash'" class="h-4 w-4 text-emerald-500" />
                                                <QrCode v-else-if="method.type === 'qris'" class="h-4 w-4 text-violet-500" />
                                                <Building v-else class="h-4 w-4 text-blue-500" />
                                                <span class="text-sm font-semibold text-slate-800">{{ method.label }}</span>
                                            </div>
                                            <label class="flex cursor-pointer items-center gap-1.5 select-none">
                                                <input
                                                    v-model="storeForm.scan_meja_payment_methods[idx].active"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                <span class="text-xs text-slate-500">Aktif</span>
                                            </label>
                                        </div>

                                        <!-- Instruksi (untuk Tunai) -->
                                        <div v-if="method.type === 'cash'">
                                            <label class="text-xs text-slate-500">Instruksi pembayaran</label>
                                            <input
                                                v-model="storeForm.scan_meja_payment_methods[idx].instructions"
                                                type="text"
                                                placeholder="Contoh: Bayar langsung di kasir"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                            />
                                        </div>

                                        <!-- Upload QRIS -->
                                        <div v-if="method.type === 'qris'" class="space-y-2">
                                            <label class="text-xs text-slate-500">Gambar QR QRIS</label>
                                            <div class="flex items-start gap-3">
                                                <div
                                                    class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                                                >
                                                    <img v-if="qrisImageUrl" :src="qrisImageUrl" class="h-full w-full object-contain" alt="QRIS" />
                                                    <QrCode v-else class="h-8 w-8 text-slate-300" />
                                                </div>
                                                <div class="flex-1 space-y-1.5">
                                                    <input
                                                        id="qris-upload-input"
                                                        type="file"
                                                        accept="image/jpeg,image/png,image/webp"
                                                        class="hidden"
                                                        @change="onQrisImageFile"
                                                    />
                                                    <button
                                                        type="button"
                                                        @click="openQrisFilePicker"
                                                        class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-slate-50"
                                                    >
                                                        <Upload class="h-3.5 w-3.5" />
                                                        Upload Gambar QRIS
                                                    </button>
                                                    <button
                                                        v-if="qrisImageUrl"
                                                        type="button"
                                                        @click="removeQrisImage"
                                                        class="flex items-center gap-1.5 rounded-lg border border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100"
                                                    >
                                                        <Trash2 class="h-3.5 w-3.5" />
                                                        Hapus Gambar
                                                    </button>
                                                    <p class="text-[10px] text-slate-400">JPG/PNG/WEBP, maks 2MB</p>
                                                </div>
                                            </div>
                                            <label class="text-xs text-slate-500">Instruksi pembayaran</label>
                                            <input
                                                v-model="storeForm.scan_meja_payment_methods[idx].instructions"
                                                type="text"
                                                placeholder="Contoh: Scan QR di bawah ini untuk membayar"
                                                class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                            />
                                        </div>

                                        <!-- Transfer Bank -->
                                        <div v-if="method.type === 'bank_transfer'" class="space-y-1.5">
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="text-xs text-slate-500">Nama Bank</label>
                                                    <input
                                                        v-model="storeForm.scan_meja_payment_methods[idx].bank_name"
                                                        type="text"
                                                        placeholder="BCA, Mandiri, BNI, dll"
                                                        class="mt-0.5 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                                    />
                                                </div>
                                                <div>
                                                    <label class="text-xs text-slate-500">No. Rekening</label>
                                                    <input
                                                        v-model="storeForm.scan_meja_payment_methods[idx].account_number"
                                                        type="text"
                                                        placeholder="1234567890"
                                                        class="mt-0.5 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <label class="text-xs text-slate-500">Nama Pemilik Rekening</label>
                                                <input
                                                    v-model="storeForm.scan_meja_payment_methods[idx].account_name"
                                                    type="text"
                                                    placeholder="Nama sesuai rekening"
                                                    class="mt-0.5 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                                />
                                            </div>
                                            <label class="text-xs text-slate-500">Instruksi pembayaran</label>
                                            <input
                                                v-model="storeForm.scan_meja_payment_methods[idx].instructions"
                                                type="text"
                                                placeholder="Contoh: Transfer ke rekening di atas, kirim bukti ke kasir"
                                                class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-violet-300 focus:outline-none"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <!-- Login Kasir PIN -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.cashier_pin_enabled"
                                    @click="storeForm.cashier_pin_enabled = !storeForm.cashier_pin_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200 sm:col-span-2"
                                    :class="
                                        storeForm.cashier_pin_enabled
                                            ? 'border-amber-300 bg-amber-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.cashier_pin_enabled
                                                ? 'bg-amber-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <KeyRound class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.cashier_pin_enabled ? 'text-amber-900' : 'text-slate-700'"
                                                >Login Kasir (PIN)</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.cashier_pin_enabled ? 'bg-amber-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.cashier_pin_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Kasir login dengan PIN tanpa logout saat ganti shift.
                                        </p>
                                    </div>
                                </button>

                                <!-- Sistem Gudang -->
                                <button
                                    v-if="props.store?.location_type !== 'central_warehouse'"
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.warehouse_enabled"
                                    @click="storeForm.warehouse_enabled = !storeForm.warehouse_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200 sm:col-span-2"
                                    :class="
                                        storeForm.warehouse_enabled
                                            ? 'border-rose-300 bg-rose-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.warehouse_enabled
                                                ? 'bg-rose-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <WarehouseIcon class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.warehouse_enabled ? 'text-rose-900' : 'text-slate-700'"
                                                >Sistem Gudang</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.warehouse_enabled ? 'bg-rose-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.warehouse_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Aktif: pembelian supplier outlet dialihkan ke Gudang Pusat grup usaha; toko tidak dapat membeli mandiri.
                                            Nonaktif: toko dapat membeli mandiri tanpa melalui Gudang Pusat.
                                        </p>
                                        <div v-if="storeForm.errors.warehouse_enabled" class="mt-1 text-sm text-red-600">
                                            {{ storeForm.errors.warehouse_enabled }}
                                        </div>
                                    </div>
                                </button>

                                <!-- Izinkan pembelian lokal outlet (sub-opsi gudang) -->
                                <label
                                    v-if="props.store?.location_type !== 'central_warehouse' && storeForm.warehouse_enabled"
                                    class="flex cursor-pointer items-start gap-3 rounded-xl border border-rose-100 bg-white/80 p-3.5 text-sm select-none sm:col-span-2"
                                >
                                    <input v-model="storeForm.is_allow_local_purchase" type="checkbox" class="mt-0.5 rounded border-gray-300" />
                                    <span>
                                        <span class="font-medium">Izinkan pembelian lokal outlet</span>
                                        <span class="mt-0.5 block text-xs font-normal text-slate-600">
                                            Saat aktif, outlet tetap dapat membeli persediaan sendiri (mis. bahan baku) meski sistem gudang menyala.
                                            Pembelian tunai otomatis dicatat sebagai pengeluaran kas dari shift kasir. Nonaktif: semua pembelian
                                            supplier outlet wajib melalui Gudang Pusat.
                                        </span>
                                    </span>
                                </label>

                                <!-- Penjualan Online (Aggregator F&B: GoFood/GrabFood/ShopeeFood) -->
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="storeForm.online_sales_enabled"
                                    @click="storeForm.online_sales_enabled = !storeForm.online_sales_enabled"
                                    class="group relative flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-left transition-all duration-200 sm:col-span-2"
                                    :class="
                                        storeForm.online_sales_enabled
                                            ? 'border-orange-300 bg-orange-50/80'
                                            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                    "
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                        :class="
                                            storeForm.online_sales_enabled
                                                ? 'bg-orange-500 text-white'
                                                : 'bg-slate-100 text-slate-400 group-hover:bg-slate-200'
                                        "
                                    >
                                        <Bike class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span
                                                class="text-sm font-semibold"
                                                :class="storeForm.online_sales_enabled ? 'text-orange-900' : 'text-slate-700'"
                                                >Pesanan Online (Aggregator)</span
                                            >
                                            <span
                                                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                                                :class="storeForm.online_sales_enabled ? 'bg-orange-500' : 'bg-slate-300'"
                                            >
                                                <span
                                                    class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                                    :class="storeForm.online_sales_enabled ? 'translate-x-[18px]' : 'translate-x-0.5'"
                                                />
                                            </span>
                                        </div>
                                        <p class="mt-0.5 text-xs leading-snug text-slate-500">
                                            Aktif: pilih sumber pesanan GoFood, GrabFood, atau ShopeeFood saat checkout di POS, dengan pencatatan
                                            biaya komisi otomatis. Nonaktif: sumber pesanan hanya Beli Langsung dan Antar Sendiri; transaksi pesanan
                                            online tidak tersedia.
                                        </p>
                                        <div v-if="storeForm.errors.online_sales_enabled" class="mt-1 text-sm text-red-600">
                                            {{ storeForm.errors.online_sales_enabled }}
                                        </div>
                                    </div>
                                </button>

                                <!-- Komisi default per aggregator (hanya tampil saat pesanan online aktif) -->
                                <div
                                    v-if="storeForm.online_sales_enabled"
                                    class="ml-6 grid grid-cols-3 gap-3 rounded-lg border border-orange-200 bg-orange-50/60 p-3"
                                >
                                    <div class="space-y-1">
                                        <label class="text-xs font-medium text-orange-900">GoFood (%)</label>
                                        <input
                                            v-model.number="storeForm.online_commission_gofood"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.5"
                                            class="h-8 w-full rounded border border-orange-200 bg-white px-2 text-sm tabular-nums focus:border-orange-400 focus:ring-1 focus:ring-orange-400 focus:outline-none"
                                        />
                                        <div v-if="storeForm.errors.online_commission_gofood" class="text-xs text-red-600">
                                            {{ storeForm.errors.online_commission_gofood }}
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-medium text-orange-900">GrabFood (%)</label>
                                        <input
                                            v-model.number="storeForm.online_commission_grabfood"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.5"
                                            class="h-8 w-full rounded border border-orange-200 bg-white px-2 text-sm tabular-nums focus:border-orange-400 focus:ring-1 focus:ring-orange-400 focus:outline-none"
                                        />
                                        <div v-if="storeForm.errors.online_commission_grabfood" class="text-xs text-red-600">
                                            {{ storeForm.errors.online_commission_grabfood }}
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-medium text-orange-900">ShopeeFood (%)</label>
                                        <input
                                            v-model.number="storeForm.online_commission_shopeefood"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.5"
                                            class="h-8 w-full rounded border border-orange-200 bg-white px-2 text-sm tabular-nums focus:border-orange-400 focus:ring-1 focus:ring-orange-400 focus:outline-none"
                                        />
                                        <div v-if="storeForm.errors.online_commission_shopeefood" class="text-xs text-red-600">
                                            {{ storeForm.errors.online_commission_shopeefood }}
                                        </div>
                                    </div>
                                    <p class="col-span-3 text-xs text-orange-700">
                                        Komisi default per channel. Kasir masih dapat mengubah nilainya saat checkout.
                                    </p>
                                </div>

                                <!-- Komisi teknisi barbershop (khusus business_type barbershop) -->
                                <div
                                    v-if="storeForm.business_type === 'barbershop'"
                                    class="grid grid-cols-2 gap-3 rounded-xl border border-violet-200 bg-violet-50/60 p-3.5 sm:col-span-2"
                                >
                                    <div class="space-y-1">
                                        <label class="text-xs font-medium text-violet-900">Mode komisi teknisi</label>
                                        <select
                                            v-model="storeForm.barber_commission_mode"
                                            class="h-10 w-full rounded-md border border-violet-200 bg-white px-3 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400 focus:outline-none"
                                        >
                                            <option value="percent">Persen dari total tagihan (%)</option>
                                            <option value="fixed">Tarif tetap per item (Rp)</option>
                                        </select>
                                        <div v-if="storeForm.errors.barber_commission_mode" class="text-xs text-red-600">
                                            {{ storeForm.errors.barber_commission_mode }}
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        <label class="text-xs font-medium text-violet-900">
                                            {{ storeForm.barber_commission_mode === 'fixed' ? 'Tarif per item (Rp)' : 'Persentase (%)' }}
                                        </label>
                                        <input
                                            v-model.number="storeForm.barber_commission_value"
                                            type="number"
                                            min="0"
                                            :max="storeForm.barber_commission_mode === 'fixed' ? 100000000 : 100"
                                            :step="storeForm.barber_commission_mode === 'fixed' ? 1000 : 0.5"
                                            class="h-10 w-full rounded-md border border-violet-200 bg-white px-3 text-sm tabular-nums focus:border-violet-400 focus:ring-1 focus:ring-violet-400 focus:outline-none"
                                        />
                                        <div v-if="storeForm.errors.barber_commission_value" class="text-xs text-red-600">
                                            {{ storeForm.errors.barber_commission_value }}
                                        </div>
                                    </div>
                                    <p class="col-span-2 text-xs text-violet-700">
                                        Berlaku untuk semua transaksi dengan teknisi; produk dapat menimpanya lewat tarif komisi per produk. Nilai
                                        bersifat informatif untuk perhitungan gaji — komisi tidak dibayar otomatis.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4 rounded-xl border border-dashed border-violet-200 bg-violet-50/40 p-5">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">Tampilan struk (kasir)</h3>
                                <p class="mt-1 text-xs text-slate-600">
                                    Atur logo, judul, dan teks kaki struk. Lebar kertas mengikuti thermal 58 mm atau 80 mm (gaya minimarket).
                                </p>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2 md:col-span-2">
                                    <Label for="receipt_paper_width">Lebar kertas thermal</Label>
                                    <select
                                        id="receipt_paper_width"
                                        v-model="storeForm.receipt_paper_width"
                                        class="flex h-12 w-full max-w-xs rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    >
                                        <option value="80">80 mm (standar minimarket)</option>
                                        <option value="58">58 mm</option>
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <Label for="receipt_header_name">Nama di struk</Label>
                                    <Input
                                        id="receipt_header_name"
                                        v-model="storeForm.receipt_header_name"
                                        type="text"
                                        placeholder="Kosongkan = pakai nama toko"
                                        class="h-12"
                                    />
                                    <p class="text-xs text-gray-500">Teks besar di atas struk.</p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="receipt_subtitle">Slogan / subtitle</Label>
                                    <Input
                                        id="receipt_subtitle"
                                        v-model="storeForm.receipt_subtitle"
                                        type="text"
                                        placeholder="Contoh: Barang Berkualitas Harga Bersahabat"
                                        class="h-12"
                                    />
                                </div>
                                <div class="space-y-2 md:col-span-2">
                                    <Label>Logo struk</Label>
                                    <div class="flex flex-wrap items-start gap-4">
                                        <div
                                            class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-white"
                                        >
                                            <img
                                                v-if="receiptLogoDisplaySrc"
                                                :src="receiptLogoDisplaySrc"
                                                alt="Logo struk"
                                                class="max-h-full max-w-full object-contain"
                                            />
                                            <span v-else class="px-2 text-center text-xs text-gray-400"> Belum ada logo </span>
                                        </div>
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <input
                                                ref="receiptLogoInputRef"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,image/gif"
                                                class="hidden"
                                                @change="onReceiptLogoFile"
                                            />
                                            <div class="flex flex-wrap gap-2">
                                                <Button type="button" variant="outline" size="sm" class="gap-2" @click="receiptLogoInputRef?.click()">
                                                    <Upload class="h-4 w-4" />
                                                    Unggah gambar
                                                </Button>
                                                <Button
                                                    v-if="
                                                        receiptLogoDisplaySrc ||
                                                        storeForm.receipt_logo ||
                                                        (props.store?.receipt_logo_preview_url && !storeForm.remove_receipt_logo)
                                                    "
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="text-red-600 hover:text-red-700"
                                                    @click="removeReceiptLogoChoice"
                                                >
                                                    Hapus logo
                                                </Button>
                                            </div>
                                            <p class="text-xs text-gray-500">
                                                JPG, PNG, WebP, atau GIF. Maks. 2MB. Disimpan di server dan dipakai saat cetak struk.
                                            </p>
                                            <div v-if="storeForm.errors.receipt_logo" class="text-sm text-red-600">
                                                {{ storeForm.errors.receipt_logo }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-6">
                                <label class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input v-model="storeForm.receipt_show_logo" type="checkbox" class="rounded border-gray-300" />
                                    Tampilkan logo
                                </label>
                                <label class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input v-model="storeForm.receipt_show_address" type="checkbox" class="rounded border-gray-300" />
                                    Tampilkan alamat (dari data toko di atas)
                                </label>
                                <label class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input v-model="storeForm.receipt_show_phone" type="checkbox" class="rounded border-gray-300" />
                                    Tampilkan telepon
                                </label>
                            </div>

                            <div class="space-y-2">
                                <Label for="receipt_footer">Pesan penutup baris 1</Label>
                                <textarea
                                    id="receipt_footer"
                                    v-model="storeForm.receipt_footer"
                                    rows="2"
                                    class="w-full rounded-md border border-gray-300 px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                ></textarea>
                            </div>
                            <div class="space-y-2">
                                <Label for="receipt_footer_line2">Pesan penutup baris 2 (opsional)</Label>
                                <Input
                                    id="receipt_footer_line2"
                                    v-model="storeForm.receipt_footer_line2"
                                    type="text"
                                    placeholder="Barang yang sudah dibeli tidak dapat dikembalikan"
                                    class="h-12"
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <Button type="submit" :disabled="storeForm.processing" class="inline-flex items-center gap-2">
                                <Save class="h-4 w-4" />
                                {{ storeForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                            </Button>

                            <div v-if="storeForm.recentlySuccessful" class="text-sm text-green-600">Berhasil disimpan!</div>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Store Settings Card -->
            <Card class="border-0 shadow-lg">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Settings class="h-5 w-5" />
                        Pengaturan Toko
                    </CardTitle>
                    <CardDescription> Kelola pengaturan toko dan sistem </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-4">
                            <div>
                                <h3 class="font-medium text-gray-900">Pengaturan PPN</h3>
                                <p class="text-sm text-gray-500">Kelola pengaturan pajak pertambahan nilai</p>
                            </div>
                            <Button @click="openTaxModal" variant="outline"> Atur PPN </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Bluetooth Tab Content -->
        <div v-if="activeTab === 'bluetooth'">
            <BluetoothTab />
        </div>

        <!-- Backup Tab Content -->
        <div v-if="activeTab === 'backup'">
            <BackupTab :store="store" />
        </div>

        <!-- Tax Settings Modal -->
        <TaxSettingsModal :store="store" :is-open="isTaxModalOpen" @close="closeTaxModal" />
    </AdminLayout>
</template>
