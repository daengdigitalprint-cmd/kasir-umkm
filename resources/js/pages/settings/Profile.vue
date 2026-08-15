<script setup lang="ts">
import { Head, useForm, Link, usePage, router } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    User, 
    Mail, 
    Save,
    ArrowLeft,
    Store,
    Home,
    Settings,
    LogOut,
    Menu,
    X,
    Package,
    Users,
    BarChart3,
    ShoppingCart,
    History,
    Building2,
    MapPin,
    Phone,
    Globe,
    Upload,
    Truck,
    Undo2,
} from 'lucide-vue-next';
import { ref, computed, watch, onMounted } from 'vue';
import { toastWarning, toastSuccess, toastError, toastInfo, modalError, confirmDialog, confirmDelete } from '@/lib/swal';
import TaxSettingsModal from '@/components/TaxSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import BluetoothTab from '@/components/BluetoothTab.vue';
import BackupTab from '@/components/BackupTab.vue';
import {
    Bluetooth,
    Copy,
    HardDrive,
} from 'lucide-vue-next';

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
        recipes_enabled?: boolean;
        productions_enabled?: boolean;
        kitchen_queue_enabled?: boolean;
        fnb_order_types_enabled?: boolean;
        fnb_split_bill_enabled?: boolean;
        direct_stock_edit?: boolean;
        warehouse_enabled?: boolean;
        queue_module_enabled?: boolean;
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
        email_verified_at: null
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
        tax_rate: 11.00,
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

const canManageStoreSettings = computed(() => Boolean((page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings));

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
    recipes_enabled: props.store?.recipes_enabled === true,
    productions_enabled: props.store?.productions_enabled === true,
    kitchen_queue_enabled: props.store?.kitchen_queue_enabled === true,
    fnb_order_types_enabled: props.store?.fnb_order_types_enabled !== false,
    fnb_split_bill_enabled: props.store?.fnb_split_bill_enabled !== false,
    direct_stock_edit: props.store?.direct_stock_edit === true,
    warehouse_enabled: props.store?.warehouse_enabled === true,
    queue_module_enabled: props.store?.queue_module_enabled === true,
});

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

const submit = async () => {
    if (!form) return;
    const ok = await confirmDialog(
        'Simpan Perubahan Profil?',
        'Nama dan email akan diperbarui. Jika email berubah, Anda perlu verifikasi ulang.',
        { confirmText: 'Simpan', icon: 'question' },
    );
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
                _method: 'put',
            };
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
    const ok = await confirmDialog(
        'Ubah Peran Anggota?',
        `Peran "${member?.name ?? 'anggota'}" akan diubah ke ${memberRoleLabel(role)}.`,
        { confirmText: 'Ubah Peran', icon: 'question' },
    );
    if (!ok) return;
    router.patch(`/stores/${slug}/members/${memberId}`, { role }, { preserveScroll: true });
}

function memberRoleDirty(m: { id: number; role: string }): boolean {
    return memberRoles.value[m.id] !== m.role;
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
                                        'py-2 px-1 border-b-2 font-medium text-sm',
                                        activeTab === 'profile'
                                            ? 'border-blue-500 text-blue-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                                        'py-2 px-1 border-b-2 font-medium text-sm',
                                        activeTab === 'store'
                                            ? 'border-blue-500 text-blue-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                                        'py-2 px-1 border-b-2 font-medium text-sm',
                                        activeTab === 'bluetooth'
                                            ? 'border-blue-500 text-blue-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                                        'py-2 px-1 border-b-2 font-medium text-sm',
                                        activeTab === 'backup'
                                            ? 'border-blue-500 text-blue-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
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
                        <Card class="border-0 shadow-lg mb-6">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <User class="h-5 w-5" />
                                Informasi Profil
                            </CardTitle>
                            <CardDescription>
                                Update informasi nama dan email Anda
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form @submit.prevent="submit" class="space-y-6">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <Label for="name">Nama Lengkap</Label>
                                        <Input
                                            id="name"
                                            v-model="form.name"
                                            type="text"
                                            placeholder="Masukkan nama lengkap"
                                            class="h-12"
                                        />
                                        <div v-if="form.errors.name" class="text-sm text-red-600">
                                            {{ form.errors.name }}
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <Label for="email">Email</Label>
                                        <Input
                                            id="email"
                                            v-model="form.email"
                                            type="email"
                                            placeholder="Masukkan email"
                                            class="h-12"
                                        />
                                        <div v-if="form.errors.email" class="text-sm text-red-600">
                                            {{ form.errors.email }}
                                        </div>
                                    </div>
                                </div>

                                <div v-if="props.mustVerifyEmail && !props.user?.email_verified_at" class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <p class="text-sm text-yellow-800">
                                        Email Anda belum diverifikasi. 
                                        <a href="/email/verification-notification" class="text-blue-600 hover:text-blue-800 underline">
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
                                    
                                    <div v-if="form.recentlySuccessful" class="text-sm text-green-600">
                                        Berhasil disimpan!
                                    </div>
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
                            <CardDescription>
                                Kelola akun dan keamanan Anda
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                    <div>
                                        <h3 class="font-medium text-gray-900">Ubah Password</h3>
                                        <p class="text-sm text-gray-500">Perbarui password untuk keamanan akun</p>
                                    </div>
                                    <Button as="a" href="/settings/password" variant="outline">
                                        Ubah Password
                                    </Button>
                                </div>

                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                    <div>
                                        <h3 class="font-medium text-gray-900">Tema Aplikasi</h3>
                                        <p class="text-sm text-gray-500">Atur tampilan dan tema aplikasi</p>
                                    </div>
                                    <Button as="a" href="/settings/appearance" variant="outline">
                                        Pengaturan Tema
                                    </Button>
                                </div>

                                <div v-if="canManageStoreSettings" class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                    <div>
                                        <h3 class="font-medium text-gray-900">Pengaturan PPN</h3>
                                        <p class="text-sm text-gray-500">Kelola pengaturan pajak pertambahan nilai</p>
                                    </div>
                                    <Button @click="openTaxModal" variant="outline">
                                        Atur PPN
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    </div>

                    <!-- Store Tab Content -->
                    <div v-if="canManageStoreSettings && activeTab === 'store'">
                        <!-- Store Information Card -->
                        <Card class="border-0 shadow-lg mb-6">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <Building2 class="h-5 w-5" />
                                    Informasi Toko
                                </CardTitle>
                                <CardDescription>
                                    Update informasi toko dan kontak bisnis Anda
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form @submit.prevent="submitStore" class="space-y-6">
                                    <div class="space-y-2">
                                        <Label for="business_type">Jenis usaha</Label>
                                        <select
                                            id="business_type"
                                            v-model="storeForm.business_type"
                                            class="flex h-12 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option
                                                v-for="(label, key) in businessTypes"
                                                :key="key"
                                                :value="key"
                                            >
                                                {{ label }}
                                            </option>
                                        </select>
                                        <p class="text-xs text-gray-500">
                                            Menyesuaikan saran kategori & satuan di halaman tambah produk.
                                        </p>
                                        <div v-if="storeForm.errors.business_type" class="text-sm text-red-600">
                                            {{ storeForm.errors.business_type }}
                                        </div>
                                    </div>

                                    <div
                                        v-if="storeForm.business_type === 'pharmacy'"
                                        class="rounded-xl border border-sky-200 bg-sky-50/80 p-4 text-xs text-slate-800 leading-relaxed"
                                    >
                                        <p class="font-semibold text-slate-900">Mode apotek (Fase E)</p>
                                        <p class="mt-2">
                                            Aplikasi membantu pencatatan SKU, klasifikasi obat (bebas / terbatas / resep), dan nomor izin edar per
                                            produk. Ini tidak menggantikan kewajiban hukum, izin usaha, maupun kewenangan apoteker sesuai
                                            peraturan setempat. Pengguna bertanggung jawab atas kepatuhan operasional.
                                        </p>
                                    </div>

                                    <div
                                        v-if="!props.store?.organization_id"
                                        class="rounded-xl border border-amber-200 bg-amber-50/90 p-4 space-y-4"
                                    >
                                        <div>
                                            <p class="text-sm font-semibold text-amber-950">Hubungkan ke grup usaha (multi-outlet)</p>
                                            <p class="text-xs text-amber-900/95 mt-1 leading-relaxed">
                                                Tanpa grup, transfer stok antar outlet tidak tersedia. Anda bisa membuat grup baru atau
                                                menggabungkan toko ini ke grup yang sudah Anda kelola di outlet lain.
                                            </p>
                                        </div>
                                        <div class="space-y-2">
                                            <Label class="text-sm font-medium text-amber-950">Pilihan</Label>
                                            <div class="flex flex-col gap-2">
                                                <label class="flex items-center gap-2 text-sm text-amber-950 cursor-pointer">
                                                    <input
                                                        v-model="storeForm.organization_attach_mode"
                                                        type="radio"
                                                        value=""
                                                        class="rounded-full border-amber-400 text-primary focus:ring-primary"
                                                    />
                                                    <span>Jangan ubah (bisa diatur nanti)</span>
                                                </label>
                                                <label class="flex items-center gap-2 text-sm text-amber-950 cursor-pointer">
                                                    <input
                                                        v-model="storeForm.organization_attach_mode"
                                                        type="radio"
                                                        value="new"
                                                        class="rounded-full border-amber-400 text-primary focus:ring-primary"
                                                    />
                                                    <span>Buat grup usaha baru</span>
                                                </label>
                                                <label
                                                    class="flex items-center gap-2 text-sm cursor-pointer"
                                                    :class="canJoinExistingOrgForAttach ? 'text-amber-950' : 'text-amber-800/50 cursor-not-allowed'"
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
                                            <Label for="attach_org_name" class="text-sm font-medium text-amber-950">
                                                Nama grup baru
                                            </Label>
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
                                            <Input
                                                id="store_name"
                                                v-model="storeForm.name"
                                                type="text"
                                                placeholder="Masukkan nama toko"
                                                class="h-12"
                                            />
                                            <div v-if="storeForm.errors.name" class="text-sm text-red-600">
                                                {{ storeForm.errors.name }}
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <Label for="store_phone">Nomor Telepon</Label>
                                            <Input
                                                id="store_phone"
                                                v-model="storeForm.phone"
                                                type="tel"
                                                placeholder="Masukkan nomor telepon"
                                                class="h-12"
                                            />
                                            <div v-if="storeForm.errors.phone" class="text-sm text-red-600">
                                                {{ storeForm.errors.phone }}
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="props.store?.organization_id"
                                        class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/90 p-4"
                                    >
                                        <Label for="org_name" class="text-sm font-semibold text-slate-800 flex items-center gap-2">
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
                                        <p class="text-xs text-slate-600 leading-relaxed">
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
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        ></textarea>
                                        <div v-if="storeForm.errors.address" class="text-sm text-red-600">
                                            {{ storeForm.errors.address }}
                                        </div>
                                    </div>

                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div class="space-y-2">
                                            <Label for="store_email">Email Toko</Label>
                                            <Input
                                                id="store_email"
                                                v-model="storeForm.email"
                                                type="email"
                                                placeholder="Masukkan email toko"
                                                class="h-12"
                                            />
                                            <div v-if="storeForm.errors.email" class="text-sm text-red-600">
                                                {{ storeForm.errors.email }}
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <Label for="store_website">Website Toko</Label>
                                            <Input
                                                id="store_website"
                                                v-model="storeForm.website"
                                                type="url"
                                                placeholder="Masukkan website toko"
                                                class="h-12"
                                            />
                                            <div v-if="storeForm.errors.website" class="text-sm text-red-600">
                                                {{ storeForm.errors.website }}
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="storeForm.business_type === 'fnb'"
                                        class="rounded-xl border border-dashed border-emerald-200 bg-emerald-50/50 p-5 space-y-3"
                                    >
                                        <h3 class="text-sm font-bold text-slate-900">F&amp;B — biaya layanan (kasir)</h3>
                                        <p class="text-xs text-slate-600">
                                            Persen default untuk <strong>service charge</strong> di POS (bisa diubah per transaksi).
                                            Jika pajak toko aktif, PPN dihitung dari subtotal setelah diskon <strong>ditambah</strong> biaya layanan.
                                        </p>
                                        <div class="space-y-2 max-w-xs">
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
                                            <div
                                                v-if="storeForm.errors.default_service_charge_percent"
                                                class="text-sm text-red-600"
                                            >
                                                {{ storeForm.errors.default_service_charge_percent }}
                                            </div>
                                        </div>
                                        <div class="space-y-2 pt-2 border-t border-emerald-200">
                                            <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                                <input
                                                    v-model="storeForm.fnb_order_types_enabled"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                Tampilkan pilihan tipe pesanan
                                            </label>
                                            <p class="text-xs text-slate-500 ml-6">
                                                Tombol Makan di tempat / Bungkus / Antar di kasir. Nonaktifkan jika tidak diperlukan.
                                            </p>
                                            <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                                <input
                                                    v-model="storeForm.fnb_split_bill_enabled"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                Aktifkan bagi tagihan (split bill)
                                            </label>
                                            <p class="text-xs text-slate-500 ml-6">
                                                Membagi total tagihan ke beberapa bagian (patungan tamu). 1 = tidak dibagi, 2–20 = dibagi rata.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-dashed border-amber-200 bg-amber-50/50 p-5 space-y-3">
                                        <h3 class="text-sm font-bold text-slate-900">Batch &amp; tanggal kedaluwarsa</h3>
                                        <p class="text-xs text-slate-600">
                                            Untuk pangan &amp; retail bertanggal: stok dipecah per batch dengan ED. Penjualan di kasir mengurangi batch
                                            <strong>yang habis edar lebih dulu</strong> (FEFO).
                                        </p>
                                        <p v-if="storeForm.business_type === 'pharmacy'" class="text-xs text-amber-900 font-medium">
                                            Mode apotek: lacak batch selalu aktif; saat menambah atau mengubah produk, setiap batch wajib memiliki tanggal kedaluwarsa (ED).
                                        </p>
                                        <label
                                            class="flex items-center gap-2 text-sm"
                                            :class="storeForm.business_type === 'pharmacy' ? 'cursor-not-allowed opacity-90' : 'cursor-pointer'"
                                        >
                                            <input
                                                v-model="storeForm.batch_tracking_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                                :disabled="storeForm.business_type === 'pharmacy'"
                                            />
                                            Aktifkan lacak batch &amp; tanggal kedaluwarsa per produk
                                        </label>
                                        <div class="space-y-2 max-w-xs pt-1">
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
                                                Batch dengan tanggal kedaluwarsa dalam rentang ini (atau sudah lewat) muncul di dashboard dan status
                                                &quot;peringatan&quot; di laporan stok per batch.
                                            </p>
                                            <div
                                                v-if="storeForm.errors.batch_expiry_warn_days"
                                                class="text-sm text-red-600"
                                            >
                                                {{ storeForm.errors.batch_expiry_warn_days }}
                                            </div>
                                        </div>
                                        <label
                                            v-if="storeForm.batch_tracking_enabled"
                                            class="flex items-start gap-2 text-sm pt-2 cursor-pointer max-w-lg"
                                        >
                                            <input
                                                v-model="storeForm.batch_expiry_email_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300 mt-0.5"
                                            />
                                            <span>
                                                <span class="font-medium">Kirim email ringkasan batch</span>
                                                <span class="block text-xs text-slate-600 font-normal mt-0.5">
                                                    Sekali sehari (±07:00 server) jika ada batch dalam jendela peringatan di atas.
                                                    Penerima: email toko ini (jika valid) serta anggota dengan peran Admin/Manajer.
                                                    Pastikan pengiriman email dikonfigurasi di server (<code class="text-xs">.env</code>).
                                                </span>
                                            </span>
                                        </label>
                                    </div>

                                    <div
                                        v-if="$page.props.plan_limits?.allows_advanced_retail"
                                        class="rounded-xl border border-dashed border-amber-200 bg-amber-50/20 p-5 space-y-3"
                                    >
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900">Koreksi Stok</h3>
                                            <p class="text-xs text-slate-600 mt-1">
                                                Pilih cara mengoreksi selisih antara stok sistem dan fisik aktual.
                                            </p>
                                        </div>
                                        <label class="flex items-start gap-2 text-sm cursor-pointer select-none max-w-xl">
                                            <input
                                                v-model="storeForm.direct_stock_edit"
                                                type="checkbox"
                                                class="rounded border-gray-300 mt-0.5"
                                            />
                                            <span>
                                                <span class="font-medium">Izinkan edit stok langsung di form Edit Produk</span>
                                                <span class="block text-xs text-slate-600 font-normal mt-0.5">
                                                    Aktifkan jika Anda tidak membutuhkan alur dokumen penyesuaian stok.
                                                    Stok bisa diubah langsung saat mengedit produk, tetapi tidak ada jejak audit perubahan.
                                                    Nonaktifkan (default) agar setiap koreksi stok wajib lewat dokumen penyesuaian atau stock opname.
                                                </span>
                                            </span>
                                        </label>
                                    </div>

                                    <div class="rounded-xl border border-dashed border-teal-200 bg-teal-50/20 p-5 space-y-3">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900">Pengaturan POS (Kasir)</h3>
                                            <p class="text-xs text-slate-600 mt-1">
                                                Sesuaikan tampilan dan fitur halaman penjualan POS.
                                            </p>
                                        </div>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input
                                                v-model="storeForm.pos_show_barcode"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                            />
                                            Tampilkan barcode scanner di halaman POS
                                        </label>
                                    </div>

                                    <div class="rounded-xl border border-dashed border-rose-200 bg-rose-50/30 p-5 space-y-3">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900">Modul Fitur</h3>
                                            <p class="text-xs text-slate-600 mt-1">
                                                Aktifkan atau nonaktifkan modul Resep dan Produksi sesuai kebutuhan usaha Anda.
                                                Secara default aktif untuk F&B / Bakery, nonaktif untuk tipe lain.
                                            </p>
                                        </div>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input
                                                v-model="storeForm.recipes_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                            />
                                            Aktifkan menu Resep
                                        </label>
                                        <p v-if="storeForm.recipes_enabled" class="text-xs text-slate-500 ml-6">
                                            Tersedia menu Resep di sidebar untuk membuat resep bahan baku.
                                        </p>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input
                                                v-model="storeForm.productions_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                            />
                                            Aktifkan menu Produksi
                                        </label>
                                        <p v-if="storeForm.productions_enabled" class="text-xs text-slate-500 ml-6">
                                            Tersedia menu Produksi di sidebar untuk mengelola hasil produksi.
                                        </p>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input
                                                v-model="storeForm.kitchen_queue_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                            />
                                            Aktifkan menu Antrian Dapur
                                        </label>
                                        <p v-if="storeForm.kitchen_queue_enabled" class="text-xs text-slate-500 ml-6">
                                            Tersedia menu Antrian Dapur di sidebar untuk mengelola antrian pesanan dapur.
                                        </p>
                                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                            <input
                                                v-model="storeForm.queue_module_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300"
                                            />
                                            Aktifkan Modul Antrian
                                        </label>
                                        <p v-if="storeForm.queue_module_enabled" class="text-xs text-slate-500 ml-6">
                                            Tersedia menu Antrian di sidebar untuk membuat dan mengelola antrian pelanggan (kasir, layanan, dll).
                                        </p>
                                        <template v-if="props.store?.location_type !== 'central_warehouse'">
                                            <div class="border-t border-rose-100 pt-3 mt-3">
                                                <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                                                    <input
                                                        v-model="storeForm.warehouse_enabled"
                                                        type="checkbox"
                                                        class="rounded border-gray-300"
                                                    />
                                                    Aktifkan sistem gudang
                                                </label>
                                                <p v-if="storeForm.warehouse_enabled" class="text-xs text-slate-500 ml-6">
                                                    Pembelian supplier outlet dialihkan ke Gudang Pusat grup usaha; toko ini tidak dapat membeli mandiri.
                                                </p>
                                                <p v-else class="text-xs text-slate-500 ml-6">
                                                    Toko dapat melakukan pembelian mandiri tanpa melalui Gudang Pusat.
                                                </p>
                                                <div v-if="storeForm.errors.warehouse_enabled" class="text-sm text-red-600 ml-6">
                                                    {{ storeForm.errors.warehouse_enabled }}
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="rounded-xl border border-dashed border-violet-200 bg-violet-50/40 p-5 space-y-4">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-900">Tampilan struk (kasir)</h3>
                                            <p class="text-xs text-slate-600 mt-1">
                                                Atur logo, judul, dan teks kaki struk. Lebar kertas mengikuti thermal 58 mm atau 80 mm (gaya minimarket).
                                            </p>
                                        </div>

                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2 md:col-span-2">
                                                <Label for="receipt_paper_width">Lebar kertas thermal</Label>
                                                <select
                                                    id="receipt_paper_width"
                                                    v-model="storeForm.receipt_paper_width"
                                                    class="flex h-12 w-full max-w-xs rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                                                        <span v-else class="px-2 text-center text-xs text-gray-400">
                                                            Belum ada logo
                                                        </span>
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
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                class="gap-2"
                                                                @click="receiptLogoInputRef?.click()"
                                                            >
                                                                <Upload class="h-4 w-4" />
                                                                Unggah gambar
                                                            </Button>
                                                            <Button
                                                                v-if="
                                                                    receiptLogoDisplaySrc ||
                                                                    storeForm.receipt_logo ||
                                                                    (props.store?.receipt_logo_preview_url &&
                                                                        !storeForm.remove_receipt_logo)
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
                                                        <div
                                                            v-if="storeForm.errors.receipt_logo"
                                                            class="text-sm text-red-600"
                                                        >
                                                            {{ storeForm.errors.receipt_logo }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-6">
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input
                                                    v-model="storeForm.receipt_show_logo"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                Tampilkan logo
                                            </label>
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input
                                                    v-model="storeForm.receipt_show_address"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                Tampilkan alamat (dari data toko di atas)
                                            </label>
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input
                                                    v-model="storeForm.receipt_show_phone"
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                />
                                                Tampilkan telepon
                                            </label>
                                        </div>

                                        <div class="space-y-2">
                                            <Label for="receipt_footer">Pesan penutup baris 1</Label>
                                            <textarea
                                                id="receipt_footer"
                                                v-model="storeForm.receipt_footer"
                                                rows="2"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                                        
                                        <div v-if="storeForm.recentlySuccessful" class="text-sm text-green-600">
                                            Berhasil disimpan!
                                        </div>
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
                                <CardDescription>
                                    Kelola pengaturan toko dan sistem
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                        <div>
                                            <h3 class="font-medium text-gray-900">Pengaturan PPN</h3>
                                            <p class="text-sm text-gray-500">Kelola pengaturan pajak pertambahan nilai</p>
                                        </div>
                                        <Button @click="openTaxModal" variant="outline">
                                            Atur PPN
                                        </Button>
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
                    <TaxSettingsModal
                        :store="store"
                        :is-open="isTaxModalOpen"
                        @close="closeTaxModal"
                    />
        </AdminLayout>
</template>
