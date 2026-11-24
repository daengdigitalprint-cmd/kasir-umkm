<script setup lang="ts">
import BackupTab from '@/components/BackupTab.vue';
import SaveBar from '@/components/settings/SaveBar.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import SettingsShell from '@/components/settings/SettingsShell.vue';
import SettingSwitch from '@/components/settings/SettingSwitch.vue';
import TaxSettingsModal from '@/components/TaxSettingsModal.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmDialog, toastSuccess, toastWarning } from '@/lib/swal';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    Bike,
    Bluetooth,
    BookOpenCheck,
    Bot,
    Building,
    CalendarClock,
    EyeOff,
    ChevronRight,
    History,
    KeyRound,
    Package,
    PackageOpen,
    PenLine,
    Percent,
    QrCode,
    ScanBarcode,
    Split,
    Stamp,
    Tag,
    Trash2,
    Undo2,
    Upload,
    UtensilsCrossed,
    Warehouse as WarehouseIcon,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

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

interface StoreProps {
    id: number;
    slug?: string;
    name: string;
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
    delivery_enabled?: boolean;
    delivery_default_fee?: number;
    fnb_order_types_enabled?: boolean;
    fnb_split_bill_enabled?: boolean;
    open_bill_enabled?: boolean;
    direct_stock_edit?: boolean;
    warehouse_enabled?: boolean;
    is_allow_local_purchase?: boolean;
    blind_transfer_receiving?: boolean;
    online_sales_enabled?: boolean;
    online_price_enabled?: boolean;
    online_commission_gofood?: number;
    online_commission_grabfood?: number;
    online_commission_shopeefood?: number;
    barber_commission_mode?: string;
    barber_commission_value?: number;
    cup_label_enabled?: boolean;
    wholesale_enabled?: boolean;
    attendance_enabled?: boolean;
    scan_meja_enabled?: boolean;
    delivery_self_order_enabled?: boolean;
    accounting_enabled?: boolean;
    shift_digital_signature?: boolean;
    stamp_card_enabled?: boolean;
    max_backdate_days?: number;
    product_discount_enabled?: boolean;
    scan_meja_payment_methods?: PaymentMethod[];
    cashier_login_mode?: string;
    location_type?: string;
    organization_id?: number | null;
    organization_name?: string;
}

interface FeatureCard {
    key: string;
    label: string;
    description: string;
    active: boolean;
    in_use: boolean;
    needs_upgrade: boolean;
    toggleable: boolean;
}

type Section = 'akun' | 'profil' | 'kasir' | 'fitur' | 'struk' | 'stok' | 'backup';

const props = withDefaults(
    defineProps<{
        section?: Section;
        user?: { id: number; name: string; email: string; email_verified_at?: string | null };
        mustVerifyEmail?: boolean;
        status?: string;
        store?: StoreProps | null;
        businessTypes?: Record<string, string>;
        joinableOrganizations?: Array<{ id: number; name: string }>;
        featureGroups?: Array<{ key: string; label: string; features: FeatureCard[] }>;
    }>(),
    {
        section: 'akun',
        user: () => ({ id: 0, name: '', email: '', email_verified_at: null }),
        mustVerifyEmail: false,
        status: undefined,
        store: null,
        businessTypes: () => ({}),
        joinableOrganizations: () => [],
        featureGroups: () => [],
    },
);

const page = usePage();
const pageProps = page.props as {
    plan_limits?: { allows_advanced_retail?: boolean };
    plan_locked_settings?: string[];
};

const SECTION_META: Record<Section, { title: string; description: string }> = {
    akun: { title: 'Profil saya', description: 'Nama dan email yang Anda pakai untuk masuk.' },
    profil: { title: 'Profil toko', description: 'Identitas toko, kontak, dan grup usaha.' },
    kasir: { title: 'Kasir & pembayaran', description: 'Pajak, biaya layanan, tombol di kasir, dan cara pelanggan membayar.' },
    fitur: { title: 'Fitur', description: 'Nyalakan hanya fitur yang Anda pakai. Perubahan langsung tersimpan.' },
    struk: { title: 'Struk', description: 'Tampilan struk yang dicetak kasir.' },
    stok: { title: 'Stok & gudang', description: 'Cara stok dicatat, batch & kedaluwarsa, serta gudang pusat.' },
    backup: { title: 'Backup data', description: 'Unduh, unggah, dan pulihkan cadangan data toko.' },
};
const meta = computed(() => SECTION_META[props.section] ?? SECTION_META.akun);

const s = computed(() => props.store);
const isFnB = computed(() => s.value?.business_type === 'fnb');
const isPharmacy = computed(() => s.value?.business_type === 'pharmacy');
const isBarbershop = computed(() => s.value?.business_type === 'barbershop');
const isCentralWarehouse = computed(() => s.value?.location_type === 'central_warehouse');
const lockedSettings = computed(() => pageProps.plan_locked_settings ?? []);
const allowsAdvancedRetail = computed(() => Boolean(pageProps.plan_limits?.allows_advanced_retail));

// ───────────────────────── Akun ─────────────────────────
const form = useForm({ name: props.user?.name ?? '', email: props.user?.email ?? '' });

function submitProfile() {
    form.patch('/settings/profile', {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            void toastSuccess('Profil disimpan');
        },
    });
}

// ───────────────────────── Form toko (per bagian) ─────────────────────────
const defaultPaymentMethods: PaymentMethod[] = [
    { type: 'cash', label: 'Tunai', active: true, instructions: 'Bayar langsung di kasir' },
    { type: 'qris', label: 'QRIS', active: true, image: null },
    { type: 'bank_transfer', label: 'Transfer Bank', active: false, bank_name: '', account_number: '', account_name: '' },
];

const storeForm = useForm({
    name: s.value?.name ?? '',
    business_type: s.value?.business_type ?? 'general',
    // Profil toko
    description: s.value?.description ?? '',
    address: s.value?.address ?? '',
    phone: s.value?.phone ?? '',
    alert_whatsapp_numbers: s.value?.alert_whatsapp_numbers ?? '',
    email: s.value?.email ?? '',
    website: s.value?.website ?? '',
    organization_name: s.value?.organization_name ?? '',
    organization_attach_mode: '' as '' | 'new' | 'join',
    attach_organization_name: '',
    attach_organization_id: null as number | null,
    // Kasir & pembayaran
    default_service_charge_percent: Number(s.value?.default_service_charge_percent ?? 0),
    fnb_order_types_enabled: s.value?.fnb_order_types_enabled !== false,
    fnb_split_bill_enabled: s.value?.fnb_split_bill_enabled !== false,
    open_bill_enabled: s.value?.open_bill_enabled === true,
    product_discount_enabled: s.value?.product_discount_enabled === true,
    pos_show_barcode: s.value?.pos_show_barcode === true,
    cup_label_enabled: s.value?.cup_label_enabled === true,
    wholesale_enabled: s.value?.wholesale_enabled === true,
    delivery_default_fee: Number(s.value?.delivery_default_fee ?? 0),
    online_sales_enabled: s.value?.online_sales_enabled === true,
    online_price_enabled: s.value?.online_price_enabled === true,
    online_commission_gofood: Number(s.value?.online_commission_gofood ?? 20),
    online_commission_grabfood: Number(s.value?.online_commission_grabfood ?? 20),
    online_commission_shopeefood: Number(s.value?.online_commission_shopeefood ?? 15),
    scan_meja_payment_methods: JSON.parse(JSON.stringify(s.value?.scan_meja_payment_methods ?? defaultPaymentMethods)) as PaymentMethod[],
    qris_image: null as File | null,
    barber_commission_mode: s.value?.barber_commission_mode ?? 'percent',
    barber_commission_value: Number(s.value?.barber_commission_value ?? 0),
    // Struk
    receipt_header_name: s.value?.receipt_header_name ?? '',
    receipt_subtitle: s.value?.receipt_subtitle ?? '',
    receipt_footer: s.value?.receipt_footer ?? 'Terima kasih atas kunjungan Anda.',
    receipt_footer_line2: s.value?.receipt_footer_line2 ?? '',
    receipt_logo: null as File | null,
    remove_receipt_logo: false,
    receipt_show_address: s.value?.receipt_show_address !== false,
    receipt_show_phone: s.value?.receipt_show_phone !== false,
    receipt_show_logo: s.value?.receipt_show_logo !== false,
    receipt_paper_width: s.value?.receipt_paper_width ?? '80',
    // Stok & gudang
    batch_tracking_enabled: s.value?.batch_tracking_enabled === true || isPharmacy.value,
    batch_expiry_warn_days: Number(s.value?.batch_expiry_warn_days ?? 30),
    batch_expiry_email_enabled: s.value?.batch_expiry_email_enabled === true,
    direct_stock_edit: s.value?.direct_stock_edit === true,
    warehouse_enabled: s.value?.warehouse_enabled === true,
    is_allow_local_purchase: s.value?.is_allow_local_purchase === true,
    blind_transfer_receiving: s.value?.blind_transfer_receiving === true,
});

/** Kolom yang dikirim tiap bagian — bagian lain tidak ikut tersimpan/tertimpa. */
const SECTION_FIELDS: Partial<Record<Section, string[]>> = {
    profil: [
        'description',
        'address',
        'phone',
        'alert_whatsapp_numbers',
        'email',
        'website',
        'organization_name',
        'organization_attach_mode',
        'attach_organization_name',
        'attach_organization_id',
    ],
    kasir: [
        'default_service_charge_percent',
        'fnb_order_types_enabled',
        'fnb_split_bill_enabled',
        'open_bill_enabled',
        'product_discount_enabled',
        'pos_show_barcode',
        'cup_label_enabled',
        'wholesale_enabled',
        'delivery_default_fee',
        'online_sales_enabled',
        'online_price_enabled',
        'online_commission_gofood',
        'online_commission_grabfood',
        'online_commission_shopeefood',
        'scan_meja_payment_methods',
        'qris_image',
        'barber_commission_mode',
        'barber_commission_value',
    ],
    struk: [
        'receipt_header_name',
        'receipt_subtitle',
        'receipt_footer',
        'receipt_footer_line2',
        'receipt_logo',
        'remove_receipt_logo',
        'receipt_show_address',
        'receipt_show_phone',
        'receipt_show_logo',
        'receipt_paper_width',
    ],
    stok: [
        'batch_tracking_enabled',
        'batch_expiry_warn_days',
        'batch_expiry_email_enabled',
        'direct_stock_edit',
        'warehouse_enabled',
        'is_allow_local_purchase',
        'blind_transfer_receiving',
    ],
};

const hasStoreForm = computed(() => Boolean(SECTION_FIELDS[props.section]));

function submitStore() {
    const fields = SECTION_FIELDS[props.section] ?? [];
    storeForm
        .transform((data) => {
            const all = data as Record<string, unknown>;
            const d: Record<string, unknown> = { name: all.name, business_type: all.business_type, _method: 'put' };
            for (const f of fields) d[f] = all[f];
            if (s.value?.organization_id) {
                delete d.organization_attach_mode;
                delete d.attach_organization_name;
                delete d.attach_organization_id;
            }
            if ('batch_expiry_email_enabled' in d && !all.batch_tracking_enabled) d.batch_expiry_email_enabled = false;
            if (d.qris_image === null) delete d.qris_image;
            if (d.receipt_logo === null) delete d.receipt_logo;
            return d;
        })
        .post('/settings/store', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearPendingReceiptLogo();
                clearPendingQris();
                storeForm.remove_receipt_logo = false;
                storeForm.defaults();
                void toastSuccess('Pengaturan disimpan');
            },
            onError: () => void toastWarning('Belum tersimpan. Periksa isian yang ditandai merah.'),
        });
}

function discardStore() {
    storeForm.reset();
    storeForm.clearErrors();
    clearPendingReceiptLogo();
    clearPendingQris();
}

// Profil toko
const canJoinExistingOrg = computed(() => (props.joinableOrganizations?.length ?? 0) > 0);

// Kasir: PPN
const isTaxModalOpen = ref(false);
const taxSummary = computed(() =>
    s.value?.tax_enabled ? `${s.value.tax_name || 'PPN'} ${Number(s.value.tax_rate ?? 0)}% aktif` : 'Tidak memungut pajak',
);

// Kasir: QRIS menu digital
const qrisInputRef = ref<HTMLInputElement | null>(null);
const qrisPreview = ref<string | null>(null);
const qrisImageUrl = computed(() => {
    if (qrisPreview.value) return qrisPreview.value;
    const image = storeForm.scan_meja_payment_methods.find((m) => m.type === 'qris')?.image;
    return image ? `/storage/${image}` : null;
});
function onQrisFile(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        void toastWarning('Ukuran gambar QRIS maksimal 2MB');
        target.value = '';
        return;
    }
    if (qrisPreview.value) URL.revokeObjectURL(qrisPreview.value);
    qrisPreview.value = URL.createObjectURL(file);
    storeForm.qris_image = file;
}
function clearPendingQris() {
    if (qrisPreview.value) URL.revokeObjectURL(qrisPreview.value);
    qrisPreview.value = null;
    storeForm.qris_image = null;
    if (qrisInputRef.value) qrisInputRef.value.value = '';
}
function removeQris() {
    clearPendingQris();
    const m = storeForm.scan_meja_payment_methods.find((x) => x.type === 'qris');
    if (m) m.image = null;
}

// Struk: logo
const receiptLogoInputRef = ref<HTMLInputElement | null>(null);
const receiptLogoObjectUrl = ref<string | null>(null);
const receiptLogoSrc = computed(() => {
    if (storeForm.remove_receipt_logo) return null;
    return receiptLogoObjectUrl.value ?? s.value?.receipt_logo_preview_url ?? null;
});
function clearPendingReceiptLogo() {
    storeForm.receipt_logo = null;
    if (receiptLogoInputRef.value) receiptLogoInputRef.value.value = '';
    if (receiptLogoObjectUrl.value) URL.revokeObjectURL(receiptLogoObjectUrl.value);
    receiptLogoObjectUrl.value = null;
}
function onReceiptLogoFile(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    storeForm.remove_receipt_logo = false;
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        void toastWarning('Ukuran gambar maksimal 2MB');
        target.value = '';
        return;
    }
    storeForm.receipt_logo = file;
    if (receiptLogoObjectUrl.value) URL.revokeObjectURL(receiptLogoObjectUrl.value);
    receiptLogoObjectUrl.value = URL.createObjectURL(file);
}
function removeReceiptLogo() {
    if (receiptLogoObjectUrl.value || storeForm.receipt_logo) {
        clearPendingReceiptLogo();
        return;
    }
    if (s.value?.receipt_logo_preview_url) storeForm.remove_receipt_logo = true;
}

// ───────────────────────── Fitur (langsung tersimpan) ─────────────────────────
const uiMode = computed(() => ((page.props as { ui_mode?: string }).ui_mode === 'simple' ? 'simple' : 'full'));
const savingUiMode = ref(false);
function setUiMode(mode: 'simple' | 'full') {
    if (savingUiMode.value || uiMode.value === mode) return;
    savingUiMode.value = true;
    router.put('/settings/ui-mode', { ui_mode: mode }, { preserveScroll: true, onFinish: () => (savingUiMode.value = false) });
}

const savingFeature = ref<string | null>(null);
function toggleFeature(f: FeatureCard) {
    if (savingFeature.value) return;
    savingFeature.value = f.key;
    router.put(`/settings/features/${f.key}`, { enabled: !f.active }, { preserveScroll: true, onFinish: () => (savingFeature.value = null) });
}
const activeFeatureCount = computed(
    () => props.featureGroups.reduce((n, g) => n + g.features.filter((f) => f.active).length, 0) + Object.values(modules).filter(Boolean).length,
);

/** Modul yang tidak ada di katalog fitur; disimpan satu per satu lewat form toko. */
const modules = reactive({
    ai_chat_enabled: s.value?.ai_chat_enabled === true,
    attendance_enabled: s.value?.attendance_enabled === true,
    accounting_enabled: s.value?.accounting_enabled === true,
    shift_digital_signature: s.value?.shift_digital_signature === true,
    stamp_card_enabled: s.value?.stamp_card_enabled === true,
});
watch(
    () => props.store,
    (st) => {
        modules.ai_chat_enabled = st?.ai_chat_enabled === true;
        modules.attendance_enabled = st?.attendance_enabled === true;
        modules.accounting_enabled = st?.accounting_enabled === true;
        modules.shift_digital_signature = st?.shift_digital_signature === true;
        modules.stamp_card_enabled = st?.stamp_card_enabled === true;
        backdateDays.value = Number(st?.max_backdate_days ?? 7);
    },
);
const savingModule = ref<string | null>(null);

function saveStoreFields(key: string, data: Record<string, unknown>, onFail?: () => void) {
    savingModule.value = key;
    router.put(
        '/settings/store',
        { name: s.value?.name ?? '', business_type: s.value?.business_type ?? 'general', ...data },
        {
            preserveScroll: true,
            onSuccess: () => void toastSuccess('Tersimpan'),
            onError: () => {
                onFail?.();
                void toastWarning('Belum tersimpan, coba lagi.');
            },
            onFinish: () => (savingModule.value = null),
        },
    );
}

async function setModule(key: keyof typeof modules, value: boolean) {
    if (key === 'accounting_enabled') {
        const ok = await confirmDialog(
            value ? 'Aktifkan akuntansi?' : 'Matikan akuntansi?',
            value
                ? 'Setiap transaksi akan dijurnal otomatis. Daftar akun standar dan periode bulan berjalan dibuat. Kasir hanya bisa menerima pembayaran bila periode bulan berjalan terbuka.'
                : 'Penjurnalan otomatis berhenti. Jurnal yang sudah ada tetap tersimpan dan muncul lagi saat akuntansi diaktifkan kembali.',
            { confirmText: value ? 'Aktifkan' : 'Matikan', icon: 'warning' },
        );
        if (!ok) return;
    }
    const before = modules[key];
    modules[key] = value;
    saveStoreFields(key, { [key]: value }, () => (modules[key] = before));
}

const backdateDays = ref(Number(s.value?.max_backdate_days ?? 7));
function saveBackdate() {
    saveStoreFields('max_backdate_days', { max_backdate_days: Math.max(0, Math.min(30, Number(backdateDays.value) || 0)) });
}

// ───────────────────────── Umum ─────────────────────────
const inputClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
</script>

<template>
    <SettingsShell :active="section" :title="meta.title" :description="meta.description">
        <!-- ═════════════ Profil saya ═════════════ -->
        <template v-if="section === 'akun'">
            <SettingsCard>
                <form class="space-y-4" @submit.prevent="submitProfile">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="name">Nama lengkap</Label>
                            <Input id="name" v-model="form.name" type="text" class="h-11" autocomplete="name" />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="email">Email</Label>
                            <Input id="email" v-model="form.email" type="email" class="h-11" autocomplete="email" />
                            <p class="text-xs text-slate-500">Jika email diganti, Anda perlu memverifikasinya lagi.</p>
                            <p v-if="form.errors.email" class="text-sm text-red-600">{{ form.errors.email }}</p>
                        </div>
                    </div>
                    <div
                        v-if="mustVerifyEmail && !user?.email_verified_at"
                        class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        Email Anda belum diverifikasi.
                        <Link href="/email/verification-notification" method="post" as="button" class="font-semibold underline">
                            Kirim ulang email verifikasi
                        </Link>
                        <p v-if="status === 'verification-link-sent'" class="mt-1 text-emerald-700">Link verifikasi baru sudah dikirim.</p>
                    </div>
                </form>
            </SettingsCard>
            <SaveBar :dirty="form.isDirty" :processing="form.processing" @save="submitProfile" @discard="form.reset()" />
        </template>

        <!-- ═════════════ Profil toko ═════════════ -->
        <template v-else-if="section === 'profil'">
            <SettingsCard title="Identitas toko">
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="store_name">Nama toko</Label>
                            <Input id="store_name" v-model="storeForm.name" type="text" class="h-11" />
                            <p v-if="storeForm.errors.name" class="text-sm text-red-600">{{ storeForm.errors.name }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="business_type">Jenis usaha</Label>
                            <select id="business_type" v-model="storeForm.business_type" :class="[inputClass, 'h-11']">
                                <option v-for="(label, key) in businessTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p class="text-xs text-slate-500">Menentukan saran kategori, satuan, dan fitur khusus usaha.</p>
                            <p v-if="storeForm.errors.business_type" class="text-sm text-red-600">{{ storeForm.errors.business_type }}</p>
                        </div>
                    </div>
                    <p
                        v-if="storeForm.business_type === 'pharmacy'"
                        class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600"
                    >
                        <strong class="text-slate-800">Mode apotek.</strong> Aplikasi membantu mencatat SKU, golongan obat, dan nomor izin edar. Ini
                        tidak menggantikan izin usaha maupun kewenangan apoteker; kepatuhan tetap tanggung jawab pengguna.
                    </p>
                    <div class="space-y-1.5">
                        <Label for="store_description">Deskripsi</Label>
                        <textarea id="store_description" v-model="storeForm.description" rows="2" :class="inputClass" />
                        <p v-if="storeForm.errors.description" class="text-sm text-red-600">{{ storeForm.errors.description }}</p>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard title="Kontak & alamat" description="Alamat dan telepon bisa ditampilkan di struk.">
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="store_address">Alamat</Label>
                        <textarea id="store_address" v-model="storeForm.address" rows="2" :class="inputClass" />
                        <p v-if="storeForm.errors.address" class="text-sm text-red-600">{{ storeForm.errors.address }}</p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="store_phone">Telepon</Label>
                            <Input id="store_phone" v-model="storeForm.phone" type="tel" class="h-11" />
                            <p v-if="storeForm.errors.phone" class="text-sm text-red-600">{{ storeForm.errors.phone }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="store_email">Email toko</Label>
                            <Input id="store_email" v-model="storeForm.email" type="email" class="h-11" />
                            <p v-if="storeForm.errors.email" class="text-sm text-red-600">{{ storeForm.errors.email }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="store_website">Website</Label>
                            <Input id="store_website" v-model="storeForm.website" type="url" class="h-11" placeholder="https://" />
                            <p v-if="storeForm.errors.website" class="text-sm text-red-600">{{ storeForm.errors.website }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="alert_whatsapp_numbers">WhatsApp untuk peringatan</Label>
                            <Input
                                id="alert_whatsapp_numbers"
                                v-model="storeForm.alert_whatsapp_numbers"
                                type="text"
                                class="h-11"
                                placeholder="62812xxxx, 62813xxxx"
                            />
                            <p class="text-xs text-slate-500">Peringatan stok, omzet, dan margin dikirim ke nomor ini. Pisahkan dengan koma.</p>
                            <p v-if="storeForm.errors.alert_whatsapp_numbers" class="text-sm text-red-600">
                                {{ storeForm.errors.alert_whatsapp_numbers }}
                            </p>
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard title="Grup usaha (multi-outlet)" description="Grup diperlukan untuk transfer stok dan gudang pusat antar outlet.">
                <div v-if="s?.organization_id" class="space-y-1.5">
                    <Label for="org_name">Nama grup</Label>
                    <Input id="org_name" v-model="storeForm.organization_name" type="text" class="h-11" />
                    <p class="text-xs text-slate-500">Berlaku untuk semua outlet dalam grup ini.</p>
                    <p v-if="storeForm.errors.organization_name" class="text-sm text-red-600">{{ storeForm.errors.organization_name }}</p>
                </div>
                <div v-else class="space-y-3">
                    <p class="text-sm text-slate-600">Toko ini belum masuk grup usaha.</p>
                    <div class="flex flex-col gap-2 text-sm text-slate-700">
                        <label class="flex items-center gap-2"
                            ><input v-model="storeForm.organization_attach_mode" type="radio" value="" class="text-primary" /> Biarkan dulu</label
                        >
                        <label class="flex items-center gap-2"
                            ><input v-model="storeForm.organization_attach_mode" type="radio" value="new" class="text-primary" /> Buat grup usaha
                            baru</label
                        >
                        <label class="flex items-center gap-2" :class="{ 'text-slate-400': !canJoinExistingOrg }">
                            <input
                                v-model="storeForm.organization_attach_mode"
                                type="radio"
                                value="join"
                                :disabled="!canJoinExistingOrg"
                                class="text-primary"
                            />
                            Gabung grup yang sudah ada
                            <span v-if="!canJoinExistingOrg" class="text-xs">(belum ada grup lain yang Anda kelola)</span>
                        </label>
                    </div>
                    <div v-if="storeForm.organization_attach_mode === 'new'" class="space-y-1.5">
                        <Label for="attach_org_name">Nama grup baru</Label>
                        <Input
                            id="attach_org_name"
                            v-model="storeForm.attach_organization_name"
                            type="text"
                            class="h-11"
                            placeholder="Kosongkan untuk memakai nama toko"
                        />
                        <p v-if="storeForm.errors.attach_organization_name" class="text-sm text-red-600">
                            {{ storeForm.errors.attach_organization_name }}
                        </p>
                    </div>
                    <div v-if="storeForm.organization_attach_mode === 'join'" class="space-y-1.5">
                        <Label for="attach_org_id">Pilih grup</Label>
                        <select id="attach_org_id" v-model.number="storeForm.attach_organization_id" :class="[inputClass, 'h-11']">
                            <option :value="null" disabled>Pilih grup…</option>
                            <option v-for="o in joinableOrganizations" :key="o.id" :value="o.id">{{ o.name }}</option>
                        </select>
                        <p v-if="storeForm.errors.attach_organization_id" class="text-sm text-red-600">
                            {{ storeForm.errors.attach_organization_id }}
                        </p>
                    </div>
                    <p v-if="storeForm.errors.organization_attach_mode" class="text-sm text-red-600">
                        {{ storeForm.errors.organization_attach_mode }}
                    </p>
                </div>
            </SettingsCard>
        </template>

        <!-- ═════════════ Kasir & pembayaran ═════════════ -->
        <template v-else-if="section === 'kasir'">
            <SettingsCard title="Pajak & biaya layanan">
                <div class="divide-y divide-slate-100">
                    <SettingSwitch :model-value="Boolean(s?.tax_enabled)" :icon="Percent" title="Pajak (PPN)" :description="taxSummary">
                        <template #control>
                            <Button type="button" variant="outline" size="sm" class="shrink-0" @click="isTaxModalOpen = true">Atur pajak</Button>
                        </template>
                    </SettingSwitch>
                    <div v-if="isFnB" class="py-3.5">
                        <Label for="default_service_charge_percent">Biaya layanan bawaan (%)</Label>
                        <Input
                            id="default_service_charge_percent"
                            v-model.number="storeForm.default_service_charge_percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.5"
                            class="mt-1.5 h-11 max-w-[10rem]"
                        />
                        <p class="mt-1 text-xs text-slate-500">Bisa diubah per transaksi. Bila pajak aktif, pajak dihitung setelah biaya layanan.</p>
                        <p v-if="storeForm.errors.default_service_charge_percent" class="text-sm text-red-600">
                            {{ storeForm.errors.default_service_charge_percent }}
                        </p>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard title="Tombol & pilihan di kasir">
                <div class="divide-y divide-slate-100">
                    <template v-if="isFnB">
                        <SettingSwitch
                            v-model="storeForm.fnb_order_types_enabled"
                            :icon="UtensilsCrossed"
                            title="Pilihan tipe pesanan"
                            description="Tombol Makan di tempat / Bungkus / Antar di kasir."
                        />
                        <SettingSwitch
                            v-model="storeForm.fnb_split_bill_enabled"
                            :icon="Split"
                            title="Bagi tagihan (split bill)"
                            description="Membagi total tagihan rata ke beberapa tamu."
                        />
                    </template>
                    <SettingSwitch
                        :model-value="isFnB || storeForm.open_bill_enabled"
                        :icon="History"
                        title="Open Bill"
                        description="Simpan pesanan yang belum dibayar, lalu panggil lagi saat pelanggan membayar."
                        :disabled="isFnB"
                        :note="isFnB ? 'Selalu aktif untuk usaha F&B.' : undefined"
                        @update:model-value="storeForm.open_bill_enabled = $event"
                    />
                    <SettingSwitch
                        v-model="storeForm.product_discount_enabled"
                        :icon="Tag"
                        title="Diskon per produk"
                        description="Kasir bisa memberi potongan manual pada tiap produk di keranjang. Kode promo tidak terpengaruh."
                    />
                    <SettingSwitch
                        v-model="storeForm.pos_show_barcode"
                        :icon="ScanBarcode"
                        title="Pemindai barcode"
                        description="Tampilkan kolom scan barcode di halaman kasir."
                    />
                    <SettingSwitch
                        v-model="storeForm.wholesale_enabled"
                        :icon="PackageOpen"
                        title="Harga grosir"
                        description="Kolom harga grosir di produk dan pilihan pelanggan Ecer/Grosir di kasir."
                    />
                    <SettingSwitch
                        v-model="storeForm.cup_label_enabled"
                        :icon="Tag"
                        title="Stiker label pesanan"
                        description="Tombol cetak stiker per cup/item, cocok untuk kedai kopi."
                    />
                </div>
                <Link
                    href="/settings/cashier-pin"
                    class="mt-2 flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm hover:bg-slate-50"
                >
                    <span class="flex items-center gap-3">
                        <KeyRound class="h-[18px] w-[18px] text-slate-400" />
                        <span>
                            <span class="block font-semibold text-slate-800">Login kasir dengan PIN</span>
                            <span class="block text-xs text-slate-500">
                                {{ s?.cashier_login_mode === 'pin' ? 'Aktif' : 'Tidak aktif' }} · atur mode dan PIN tiap kasir
                            </span>
                        </span>
                    </span>
                    <ChevronRight class="h-4 w-4 text-slate-400" />
                </Link>
            </SettingsCard>

            <SettingsCard v-if="s?.delivery_enabled" title="Pesanan antar">
                <Label for="delivery_default_fee">Ongkir bawaan (Rp)</Label>
                <Input
                    id="delivery_default_fee"
                    v-model.number="storeForm.delivery_default_fee"
                    type="number"
                    min="0"
                    step="500"
                    class="mt-1.5 h-11 max-w-[12rem]"
                />
                <p class="mt-1 text-xs text-slate-500">Terisi otomatis saat kasir memilih pesanan antar; bisa diubah per pesanan.</p>
            </SettingsCard>

            <SettingsCard title="Pesanan online (GoFood, GrabFood, ShopeeFood)">
                <SettingSwitch
                    v-model="storeForm.online_sales_enabled"
                    :icon="Bike"
                    title="Terima pesanan dari aplikasi ojek online"
                    description="Kasir bisa memilih sumber pesanan, dan potongan komisinya tercatat otomatis."
                >
                    <div class="grid max-w-md grid-cols-3 gap-3">
                        <div
                            v-for="c in [
                                { key: 'online_commission_gofood', label: 'GoFood' },
                                { key: 'online_commission_grabfood', label: 'GrabFood' },
                                { key: 'online_commission_shopeefood', label: 'ShopeeFood' },
                            ] as const"
                            :key="c.key"
                            class="space-y-1"
                        >
                            <Label :for="c.key" class="text-xs">{{ c.label }} (%)</Label>
                            <Input :id="c.key" v-model.number="storeForm[c.key]" type="number" min="0" max="100" step="0.5" class="h-10" />
                            <p v-if="storeForm.errors[c.key]" class="text-xs text-red-600">{{ storeForm.errors[c.key] }}</p>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Komisi bawaan; kasir masih bisa mengubahnya saat transaksi.</p>
                    <div class="mt-3 rounded-xl border border-slate-200 px-4">
                        <SettingSwitch
                            v-model="storeForm.online_price_enabled"
                            title="Harga khusus pesanan online"
                            description="Tiap produk bisa diberi harga GoFood, GrabFood, dan ShopeeFood sendiri di form produk. Kasir otomatis memakai harga itu saat memilih sumber pesanannya; yang kosong tetap memakai harga toko."
                        />
                    </div>
                </SettingSwitch>
            </SettingsCard>

            <SettingsCard
                v-if="s?.scan_meja_enabled || s?.delivery_self_order_enabled"
                title="Pembayaran menu digital"
                description="Cara bayar yang muncul saat pelanggan memesan lewat QR meja atau tautan pesan antar."
            >
                <input ref="qrisInputRef" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onQrisFile" />
                <div class="space-y-3">
                    <div
                        v-for="(method, idx) in storeForm.scan_meja_payment_methods"
                        :key="method.type"
                        class="rounded-xl border border-slate-200 p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <Banknote v-if="method.type === 'cash'" class="h-4 w-4 text-slate-400" />
                                <QrCode v-else-if="method.type === 'qris'" class="h-4 w-4 text-slate-400" />
                                <Building v-else class="h-4 w-4 text-slate-400" />
                                {{ method.label }}
                            </span>
                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                <input v-model="storeForm.scan_meja_payment_methods[idx].active" type="checkbox" class="rounded text-primary" />
                                Aktif
                            </label>
                        </div>
                        <div v-if="method.active" class="mt-3 space-y-2">
                            <div v-if="method.type === 'qris'" class="flex items-start gap-3">
                                <div
                                    class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                                >
                                    <img v-if="qrisImageUrl" :src="qrisImageUrl" class="h-full w-full object-contain" alt="QRIS" />
                                    <QrCode v-else class="h-7 w-7 text-slate-300" />
                                </div>
                                <div class="space-y-1.5">
                                    <div class="flex flex-wrap gap-2">
                                        <Button type="button" variant="outline" size="sm" class="gap-1.5" @click="qrisInputRef?.click()">
                                            <Upload class="h-3.5 w-3.5" /> Unggah QRIS
                                        </Button>
                                        <Button
                                            v-if="qrisImageUrl"
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1.5 text-red-600"
                                            @click="removeQris"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" /> Hapus
                                        </Button>
                                    </div>
                                    <p class="text-xs text-slate-400">JPG/PNG/WEBP, maks. 2MB</p>
                                </div>
                            </div>
                            <div v-if="method.type === 'bank_transfer'" class="grid gap-2 sm:grid-cols-3">
                                <Input v-model="storeForm.scan_meja_payment_methods[idx].bank_name" placeholder="Nama bank" class="h-10" />
                                <Input v-model="storeForm.scan_meja_payment_methods[idx].account_number" placeholder="No. rekening" class="h-10" />
                                <Input v-model="storeForm.scan_meja_payment_methods[idx].account_name" placeholder="Atas nama" class="h-10" />
                            </div>
                            <Input
                                v-model="storeForm.scan_meja_payment_methods[idx].instructions"
                                :placeholder="
                                    method.type === 'cash' ? 'Instruksi, mis. Bayar langsung di kasir' : 'Instruksi pembayaran untuk pelanggan'
                                "
                                class="h-10"
                            />
                        </div>
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard v-if="isBarbershop" title="Komisi barber" description="Dipakai untuk menghitung komisi; tidak dibayar otomatis.">
                <div class="grid max-w-md gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="barber_commission_mode">Cara hitung</Label>
                        <select id="barber_commission_mode" v-model="storeForm.barber_commission_mode" :class="[inputClass, 'h-11']">
                            <option value="percent">Persen dari tagihan</option>
                            <option value="fixed">Tarif tetap per layanan</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="barber_commission_value">{{ storeForm.barber_commission_mode === 'fixed' ? 'Tarif (Rp)' : 'Persen (%)' }}</Label>
                        <Input
                            id="barber_commission_value"
                            v-model.number="storeForm.barber_commission_value"
                            type="number"
                            min="0"
                            :step="storeForm.barber_commission_mode === 'fixed' ? 1000 : 0.5"
                            class="h-11"
                        />
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">Tarif per produk (bila diisi) menggantikan nilai ini.</p>
            </SettingsCard>
        </template>

        <!-- ═════════════ Fitur ═════════════ -->
        <template v-else-if="section === 'fitur'">
            <SettingsCard title="Tampilan menu" description="Data dan pencatatan tidak berubah; Anda bisa berganti kapan saja.">
                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="m in [
                            {
                                key: 'simple',
                                label: 'Mode Sederhana',
                                desc: 'Hanya menu inti: kasir, produk, stok, pembelian, pelanggan, laporan, dan biaya. Fitur lain dinyalakan satu per satu di bawah.',
                            },
                            { key: 'full', label: 'Mode Lengkap', desc: 'Semua menu tampil. Fitur yang punya saklar tetap bisa dimatikan di bawah.' },
                        ] as const"
                        :key="m.key"
                        type="button"
                        :disabled="savingUiMode"
                        :aria-pressed="uiMode === m.key"
                        class="rounded-xl border p-4 text-left transition disabled:opacity-60"
                        :class="uiMode === m.key ? 'border-primary bg-primary/5' : 'border-slate-200 hover:border-slate-300'"
                        @click="setUiMode(m.key)"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-slate-900">{{ m.label }}</span>
                            <span v-if="uiMode === m.key" class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-white"
                                >Dipakai</span
                            >
                        </span>
                        <span class="mt-1 block text-sm text-slate-500">{{ m.desc }}</span>
                    </button>
                </div>
            </SettingsCard>

            <p class="px-1 text-sm text-slate-500">{{ activeFeatureCount }} fitur aktif. Menu fitur yang dinyalakan langsung muncul di sidebar.</p>

            <SettingsCard v-for="g in featureGroups" :key="g.key" :title="g.label">
                <div class="divide-y divide-slate-100">
                    <SettingSwitch
                        v-for="f in g.features"
                        :key="f.key"
                        :model-value="f.active"
                        :title="f.label"
                        :description="f.description"
                        :loading="savingFeature === f.key"
                        :locked="f.needs_upgrade && !f.active"
                        :disabled="!f.toggleable || (f.active && f.in_use) || (savingFeature !== null && savingFeature !== f.key)"
                        :note="
                            !f.toggleable
                                ? 'Selalu tampil di Mode Lengkap.'
                                : f.active && f.in_use
                                  ? 'Masih ada data yang berjalan, jadi belum bisa dimatikan.'
                                  : undefined
                        "
                        @update:model-value="toggleFeature(f)"
                    >
                        <template v-if="f.needs_upgrade && !f.active" #extra>
                            <Link href="/subscription" class="mt-1 inline-block text-xs font-semibold text-primary hover:underline">Lihat paket</Link>
                        </template>
                    </SettingSwitch>
                </div>
            </SettingsCard>

            <SettingsCard title="Lainnya">
                <div class="divide-y divide-slate-100">
                    <SettingSwitch
                        :model-value="modules.ai_chat_enabled"
                        :icon="Bot"
                        title="Asisten Chat AI"
                        description="Pengguna toko bisa bertanya ke asisten AI tentang penjualan dan stok."
                        :locked="lockedSettings.includes('ai_chat_enabled')"
                        :loading="savingModule === 'ai_chat_enabled'"
                        @update:model-value="setModule('ai_chat_enabled', $event)"
                    />
                    <SettingSwitch
                        :model-value="modules.attendance_enabled"
                        :icon="CalendarClock"
                        title="Absensi"
                        description="Menu Absensi di sidebar. Perlu paket Absensi + Payroll yang aktif."
                        :locked="lockedSettings.includes('attendance_enabled')"
                        :loading="savingModule === 'attendance_enabled'"
                        @update:model-value="setModule('attendance_enabled', $event)"
                    />
                    <SettingSwitch
                        :model-value="modules.shift_digital_signature"
                        :icon="PenLine"
                        title="Tanda tangan digital laporan shift"
                        description="Kasir dan pengelola tanda tangan di layar pada laporan tutup shift. Bila dimatikan, laporan hanya berisi kolom tanda tangan manual."
                        :locked="lockedSettings.includes('shift_digital_signature')"
                        :loading="savingModule === 'shift_digital_signature'"
                        @update:model-value="setModule('shift_digital_signature', $event)"
                    />
                    <SettingSwitch
                        :model-value="modules.stamp_card_enabled"
                        :icon="Stamp"
                        title="Kartu stempel digital"
                        description="Pengganti kartu stempel kertas: kasir cari pelanggan lewat no. WA atau QR, stempel bertambah otomatis, kartu penuh jadi hadiah. Aturannya di menu Pelanggan & Promo → Kartu stempel."
                        :locked="lockedSettings.includes('stamp_card_enabled')"
                        :loading="savingModule === 'stamp_card_enabled'"
                        @update:model-value="setModule('stamp_card_enabled', $event)"
                    />
                    <SettingSwitch
                        :model-value="modules.accounting_enabled"
                        :icon="BookOpenCheck"
                        title="Akuntansi (jurnal otomatis)"
                        description="Neraca, jurnal, dan tutup buku. Kasir hanya bisa menerima pembayaran bila periode bulan berjalan terbuka."
                        :locked="lockedSettings.includes('accounting_enabled')"
                        :loading="savingModule === 'accounting_enabled'"
                        @update:model-value="setModule('accounting_enabled', $event)"
                    >
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="space-y-1">
                                <Label for="max_backdate_days" class="text-xs">Batas catat mundur (hari)</Label>
                                <Input id="max_backdate_days" v-model.number="backdateDays" type="number" min="0" max="30" class="h-10 w-28" />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-10"
                                :disabled="savingModule !== null || backdateDays === Number(s?.max_backdate_days ?? 7)"
                                @click="saveBackdate"
                            >
                                Simpan
                            </Button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">0 = tidak boleh mencatat tanggal mundur. Bawaan 7 hari.</p>
                    </SettingSwitch>
                </div>
            </SettingsCard>
        </template>

        <!-- ═════════════ Struk ═════════════ -->
        <template v-else-if="section === 'struk'">
            <SettingsCard title="Isi struk">
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="receipt_header_name">Nama di struk</Label>
                            <Input
                                id="receipt_header_name"
                                v-model="storeForm.receipt_header_name"
                                class="h-11"
                                :placeholder="s?.name || 'Nama toko'"
                            />
                            <p class="text-xs text-slate-500">Kosongkan untuk memakai nama toko.</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="receipt_subtitle">Slogan</Label>
                            <Input id="receipt_subtitle" v-model="storeForm.receipt_subtitle" class="h-11" placeholder="Opsional" />
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Logo</Label>
                        <div class="flex flex-wrap items-center gap-4">
                            <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                <img v-if="receiptLogoSrc" :src="receiptLogoSrc" alt="Logo struk" class="max-h-full max-w-full object-contain" />
                                <span v-else class="px-2 text-center text-xs text-slate-400">Belum ada</span>
                            </div>
                            <div class="space-y-1.5">
                                <input
                                    ref="receiptLogoInputRef"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                    class="hidden"
                                    @change="onReceiptLogoFile"
                                />
                                <div class="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" size="sm" class="gap-1.5" @click="receiptLogoInputRef?.click()">
                                        <Upload class="h-3.5 w-3.5" /> Unggah logo
                                    </Button>
                                    <Button
                                        v-if="receiptLogoSrc"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="text-red-600"
                                        @click="removeReceiptLogo"
                                        >Hapus</Button
                                    >
                                </div>
                                <p class="text-xs text-slate-400">JPG, PNG, WebP, atau GIF. Maks. 2MB.</p>
                                <p v-if="storeForm.errors.receipt_logo" class="text-sm text-red-600">{{ storeForm.errors.receipt_logo }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="receipt_footer">Pesan penutup</Label>
                        <textarea id="receipt_footer" v-model="storeForm.receipt_footer" rows="2" :class="inputClass" />
                        <Input id="receipt_footer_line2" v-model="storeForm.receipt_footer_line2" class="h-11" placeholder="Baris kedua (opsional)" />
                    </div>
                </div>
            </SettingsCard>

            <SettingsCard title="Tata letak">
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="receipt_paper_width">Lebar kertas</Label>
                        <select id="receipt_paper_width" v-model="storeForm.receipt_paper_width" :class="[inputClass, 'h-11 max-w-xs']">
                            <option value="80">80 mm (umum di minimarket)</option>
                            <option value="58">58 mm</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-2 text-sm text-slate-700">
                        <label class="flex items-center gap-2"
                            ><input v-model="storeForm.receipt_show_logo" type="checkbox" class="rounded text-primary" /> Tampilkan logo</label
                        >
                        <label class="flex items-center gap-2"
                            ><input v-model="storeForm.receipt_show_address" type="checkbox" class="rounded text-primary" /> Tampilkan alamat
                            toko</label
                        >
                        <label class="flex items-center gap-2"
                            ><input v-model="storeForm.receipt_show_phone" type="checkbox" class="rounded text-primary" /> Tampilkan telepon
                            toko</label
                        >
                    </div>
                </div>
            </SettingsCard>

            <Link
                href="/settings/bluetooth-printer"
                class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm hover:bg-slate-50"
            >
                <span class="flex items-center gap-3">
                    <Bluetooth class="h-[18px] w-[18px] text-slate-400" />
                    <span>
                        <span class="block font-semibold text-slate-800">Printer Bluetooth</span>
                        <span class="block text-xs text-slate-500">Hubungkan printer struk di perangkat ini</span>
                    </span>
                </span>
                <ChevronRight class="h-4 w-4 text-slate-400" />
            </Link>
        </template>

        <!-- ═════════════ Stok & gudang ═════════════ -->
        <template v-else-if="section === 'stok'">
            <SettingsCard title="Pencatatan stok">
                <div class="divide-y divide-slate-100">
                    <SettingSwitch
                        v-model="storeForm.batch_tracking_enabled"
                        :icon="Package"
                        title="Batch & tanggal kedaluwarsa"
                        description="Catat batch dan ED per produk; stok yang paling cepat kedaluwarsa keluar duluan (FEFO)."
                        :disabled="isPharmacy"
                        :note="isPharmacy ? 'Selalu aktif untuk apotek; setiap batch wajib punya tanggal kedaluwarsa.' : undefined"
                    >
                        <div class="space-y-3">
                            <div class="space-y-1">
                                <Label for="batch_expiry_warn_days" class="text-xs">Ingatkan sebelum kedaluwarsa (hari)</Label>
                                <Input
                                    id="batch_expiry_warn_days"
                                    v-model.number="storeForm.batch_expiry_warn_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    class="h-10 w-28"
                                />
                                <p v-if="storeForm.errors.batch_expiry_warn_days" class="text-sm text-red-600">
                                    {{ storeForm.errors.batch_expiry_warn_days }}
                                </p>
                            </div>
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input v-model="storeForm.batch_expiry_email_enabled" type="checkbox" class="mt-0.5 rounded text-primary" />
                                <span>
                                    Kirim email ringkasan tiap pagi
                                    <span class="block text-xs text-slate-500"
                                        >Ke email toko dan anggota Admin/Manajer bila ada batch yang mendekati kedaluwarsa.</span
                                    >
                                </span>
                            </label>
                        </div>
                    </SettingSwitch>
                    <SettingSwitch
                        v-if="allowsAdvancedRetail"
                        v-model="storeForm.direct_stock_edit"
                        :icon="Undo2"
                        title="Ubah stok langsung di produk"
                        description="Stok bisa diedit di form produk tanpa dokumen penyesuaian atau stok opname. Lebih cepat, tapi jejak perubahannya lebih sedikit."
                    />
                </div>
            </SettingsCard>

            <SettingsCard v-if="!isCentralWarehouse" title="Gudang pusat">
                <SettingSwitch
                    v-model="storeForm.warehouse_enabled"
                    :icon="WarehouseIcon"
                    title="Belanja lewat gudang pusat"
                    description="Pembelian ke supplier dilakukan oleh gudang pusat grup usaha; outlet meminta stok ke gudang."
                >
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input v-model="storeForm.is_allow_local_purchase" type="checkbox" class="mt-0.5 rounded text-primary" />
                        <span>
                            Outlet tetap boleh belanja sendiri
                            <span class="block text-xs text-slate-500"
                                >Mis. bahan baku harian. Belanja tunai tercatat sebagai uang keluar dari laci kasir.</span
                            >
                        </span>
                    </label>
                </SettingSwitch>
                <p v-if="storeForm.errors.warehouse_enabled" class="text-sm text-red-600">{{ storeForm.errors.warehouse_enabled }}</p>
            </SettingsCard>

            <SettingsCard v-if="allowsAdvancedRetail" title="Terima kiriman">
                <SettingSwitch
                    v-model="storeForm.blind_transfer_receiving"
                    :icon="EyeOff"
                    title="Penerimaan buta"
                    description="Saat menerima transfer stok, penerima menghitung barang dulu tanpa melihat jumlah yang dikirim, baru dicocokkan. Mencegah asal menyetujui jumlah di surat jalan."
                />
            </SettingsCard>
        </template>

        <!-- ═════════════ Backup ═════════════ -->
        <template v-else-if="section === 'backup'">
            <BackupTab :store="store" />
        </template>

        <SaveBar v-if="hasStoreForm" :dirty="storeForm.isDirty" :processing="storeForm.processing" @save="submitStore" @discard="discardStore" />

        <TaxSettingsModal v-if="store" :store="store" :is-open="isTaxModalOpen" @close="isTaxModalOpen = false" />
    </SettingsShell>
</template>
