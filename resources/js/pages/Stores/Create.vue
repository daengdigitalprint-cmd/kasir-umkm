<script setup lang="ts">
import { Link, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Star, Store, MapPin, Phone, Mail, Globe, DollarSign, Clock, Building2, CircleDot, Trash2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { confirmDelete, toastSuccess, toastWarning } from '@/lib/swal';

interface JoinableOrganization {
    id: number;
    name: string;
}

interface CentralWarehouseInfo {
    id: number;
    name: string;
    product_count: number;
}

interface ExistingStore {
    id: number;
    slug: string;
    name: string;
    location_type: string;
    location_label: string;
    is_primary: boolean;
    is_active: boolean;
    organization_id: number | null;
    group_name: string | null;
}

interface OutletQuota {
    used: number;
    limit: number | null;
    has_warehouse: boolean;
}

interface CreateProps {
    businessTypes?: Record<string, string>;
    defaultBusinessType?: string;
    joinableOrganizations?: JoinableOrganization[];
    centralWarehouses?: Record<string, CentralWarehouseInfo>;
    outletQuotas?: Record<string, OutletQuota>;
    locationTypes?: Record<string, string>;
    existingStores?: ExistingStore[];
}

const props = withDefaults(defineProps<CreateProps>(), {
    businessTypes: () => ({}),
    defaultBusinessType: 'general',
    joinableOrganizations: () => [],
    centralWarehouses: () => ({}),
    outletQuotas: () => ({}),
    locationTypes: () => ({}),
    existingStores: () => [],
});

const canJoinExistingGroup = computed(() => props.joinableOrganizations.length > 0);

// Kelompokkan existing stores: yang sudah ada grup vs yang belum
const groupedStores = computed(() => {
    const inGroup = (props.existingStores ?? []).filter((s) => s.group_name);
    const standalone = (props.existingStores ?? []).filter((s) => !s.group_name);
    return { inGroup, standalone };
});

// Ringkasan kuota outlet per grup usaha (untuk ditampilkan ke user).
const quotaSummary = computed(() =>
    (props.joinableOrganizations ?? []).map((org) => {
        const q = props.outletQuotas?.[String(org.id)] ?? props.outletQuotas?.[org.id];
        const limit = q?.limit ?? null;
        const used = q?.used ?? 0;
        return {
            id: org.id,
            name: org.name,
            used,
            limit,
            hasWarehouse: q?.has_warehouse ?? false,
            isFull: limit !== null && used >= limit,
            remaining: limit !== null ? Math.max(0, limit - used) : null,
        };
    }),
);
const hasExistingStores = computed(() => (props.existingStores ?? []).length > 0);

const form = useForm({
    name: '',
    business_type: props.defaultBusinessType,
    location_type: 'outlet',
    description: '',
    address: '',
    phone: '',
    email: '',
    currency: 'IDR',
    timezone: 'Asia/Jakarta',
    organization_mode: 'new' as 'new' | 'join',
    organization_name: '',
    organization_id: null as number | null,
    copy_products: false,
});

// Reset copy_products when switching back to 'new' group
watch(() => form.organization_mode, (mode) => {
    if (mode === 'new') {
        form.copy_products = false;
    }
});

// Central warehouse info for selected group
const selectedCentralWarehouse = computed(() => {
    if (form.organization_mode !== 'join' || !form.organization_id) return null;
    return props.centralWarehouses?.[form.organization_id] ?? null;
});

watch(canJoinExistingGroup, (ok) => {
    if (!ok && form.organization_mode === 'join') {
        form.organization_mode = 'new';
    }
});

const submit = () => {
    form.transform((data) => {
        const d = { ...data } as Record<string, unknown>;
        if (d.organization_mode === 'new') {
            d.organization_id = null;
        } else {
            d.organization_name = null;
        }
        return d;
    }).post('/stores');
};

const deleteStore = async (store: ExistingStore) => {
    if (store.is_primary) {
        toastWarning('Outlet utama tidak dapat dihapus. Jadikan outlet lain sebagai utama terlebih dahulu.');
        return;
    }
    if (!(await confirmDelete('Hapus outlet?', `Yakin ingin menghapus "${store.name}"? Semua data terkait juga akan dihapus.`))) return;
    router.delete(`/stores/${store.slug}`, {
        preserveScroll: true,
        onSuccess: () => toastSuccess('Outlet berhasil dihapus!'),
    });
};

const inputClass =
    'h-12 rounded-xl border-slate-200 bg-white focus-visible:border-primary/60 focus-visible:ring-primary/25';
const selectClass =
    'flex h-12 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50';
const labelClass = 'text-sm font-semibold text-slate-700 flex items-center gap-2';
</script>

<template>
    <AdminLayout title="Buat toko">
        <template #mobile-header-actions>
            <Button variant="ghost" size="sm" as-child class="rounded-xl text-slate-600">
                <Link href="/dashboard" class="inline-flex items-center justify-center" aria-label="Kembali ke dashboard">
                    <ArrowLeft class="h-5 w-5" />
                </Link>
            </Button>
        </template>

        <template #header-actions>
            <Button variant="outline" size="sm" as-child class="rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50">
                <Link href="/dashboard" class="inline-flex items-center gap-2">
                    <ArrowLeft class="h-4 w-4" />
                    Kembali ke dashboard
                </Link>
            </Button>
        </template>

        <div class="mx-auto" style="max-width: 90rem">
            <div class="rounded-2xl border border-primary/15 bg-gradient-to-br from-primary/[0.07] to-slate-50/80 px-5 py-6 sm:px-8 sm:py-8 mb-8">
                <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/25"
                    >
                        <Store class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-primary/80 mb-1">Langkah pertama</p>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Buat toko baru</h2>
                        <p class="text-sm text-slate-600 mt-2 leading-relaxed">
                            Isi profil toko Anda. Nanti semua fitur POS, stok, dan laporan akan memakai data ini — bisa diubah di
                            pengaturan.
                        </p>
                    </div>
                </div>
            </div>

            <Card class="border-0 shadow-lg shadow-slate-200/60 rounded-2xl overflow-hidden">
                <CardHeader class="pb-4 px-6 sm:px-8 pt-6 sm:pt-8 border-b border-slate-100 bg-white">
                    <CardTitle class="text-lg font-bold text-slate-900">Informasi toko</CardTitle>
                    <CardDescription class="text-slate-600 text-sm">
                        Kolom bertanda * wajib diisi. Sisanya boleh dikosongkan dan dilengkapi nanti.
                    </CardDescription>
                </CardHeader>
                <CardContent class="px-6 sm:px-8 py-6 sm:py-8 bg-white">
                    <form class="space-y-8" @submit.prevent="submit">
                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name" :class="labelClass">
                                    <Store class="h-4 w-4 text-primary" />
                                    Nama toko *
                                </Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Contoh: Warung Makmur"
                                    required
                                    :class="inputClass"
                                />
                                <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="currency" :class="labelClass">
                                    <DollarSign class="h-4 w-4 text-primary" />
                                    Mata uang *
                                </Label>
                                <select id="currency" v-model="form.currency" required :class="selectClass">
                                    <option value="IDR">IDR — Rupiah Indonesia</option>
                                    <option value="USD">USD — US Dollar</option>
                                    <option value="EUR">EUR — Euro</option>
                                </select>
                                <p v-if="form.errors.currency" class="text-sm text-red-600">{{ form.errors.currency }}</p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="business_type" class="text-sm font-semibold text-slate-700"> Jenis usaha * </Label>
                            <select id="business_type" v-model="form.business_type" required :class="selectClass">
                                <option v-for="(label, key) in businessTypes" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                Dipakai untuk saran kategori &amp; satuan produk. Bisa diubah di <strong>Pengaturan</strong>.
                            </p>
                            <p v-if="form.errors.business_type" class="text-sm text-red-600">{{ form.errors.business_type }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="location_type" class="text-sm font-semibold text-slate-700">Tipe lokasi *</Label>
                            <select id="location_type" v-model="form.location_type" required :class="selectClass">
                                <option v-for="(label, key) in locationTypes" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <p class="text-xs text-slate-500 leading-relaxed">
                                <strong>Outlet</strong> melayani penjualan POS. <strong>Gudang Pusat</strong> menampung pembelian bahan
                                dari supplier lalu mengirimnya ke outlet. Satu grup usaha hanya boleh punya satu Gudang Pusat.
                            </p>
                            <p v-if="form.errors.location_type" class="text-sm text-red-600">{{ form.errors.location_type }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="description" class="text-sm font-semibold text-slate-700">Deskripsi toko</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Ceritakan singkat tentang toko Anda…"
                                :rows="3"
                                class="rounded-xl border-slate-200 focus-visible:border-primary/60 focus-visible:ring-primary/25 min-h-[100px]"
                            />
                            <p v-if="form.errors.description" class="text-sm text-red-600">{{ form.errors.description }}</p>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="address" :class="labelClass">
                                    <MapPin class="h-4 w-4 text-primary" />
                                    Alamat
                                </Label>
                                <Input id="address" v-model="form.address" type="text" placeholder="Alamat lengkap" :class="inputClass" />
                                <p v-if="form.errors.address" class="text-sm text-red-600">{{ form.errors.address }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="phone" :class="labelClass">
                                    <Phone class="h-4 w-4 text-primary" />
                                    Nomor telepon
                                </Label>
                                <Input id="phone" v-model="form.phone" type="tel" placeholder="+62 812 3456 7890" :class="inputClass" />
                                <p v-if="form.errors.phone" class="text-sm text-red-600">{{ form.errors.phone }}</p>
                            </div>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="email" :class="labelClass">
                                    <Mail class="h-4 w-4 text-primary" />
                                    Email toko
                                </Label>
                                <Input id="email" v-model="form.email" type="email" placeholder="toko@example.com" :class="inputClass" />
                                <p v-if="form.errors.email" class="text-sm text-red-600">{{ form.errors.email }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="timezone" :class="labelClass">
                                    <Clock class="h-4 w-4 text-primary" />
                                    Zona waktu *
                                </Label>
                                <select id="timezone" v-model="form.timezone" required :class="selectClass">
                                    <option value="Asia/Jakarta">Asia/Jakarta (WIB)</option>
                                    <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
                                    <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
                                </select>
                                <p v-if="form.errors.timezone" class="text-sm text-red-600">{{ form.errors.timezone }}</p>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-5 py-5 space-y-4">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                                >
                                    <Building2 class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-900 text-sm">Grup usaha (multi-outlet)</h3>
                                    <p class="text-xs text-slate-600 mt-1 leading-relaxed">
                                        Satukan beberapa toko dalam satu grup agar mudah dipilih di sidebar. Outlet pertama biasanya
                                        membentuk grup baru; outlet berikutnya bisa bergabung ke grup yang sudah Anda kelola.
                                    </p>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <Label class="text-sm font-semibold text-slate-700">Penempatan grup</Label>
                                <div class="flex flex-col gap-2">
                                    <label class="flex items-center gap-2 text-sm text-slate-800 cursor-pointer">
                                        <input
                                            v-model="form.organization_mode"
                                            type="radio"
                                            value="new"
                                            class="rounded-full border-slate-300 text-primary focus:ring-primary"
                                        />
                                        <span>Buat grup baru (nama bisa beda dari nama toko)</span>
                                    </label>
                                    <label
                                        class="flex items-center gap-2 text-sm cursor-pointer"
                                        :class="canJoinExistingGroup ? 'text-slate-800' : 'text-slate-400 cursor-not-allowed'"
                                    >
                                        <input
                                            v-model="form.organization_mode"
                                            type="radio"
                                            value="join"
                                            :disabled="!canJoinExistingGroup"
                                            class="rounded-full border-slate-300 text-primary focus:ring-primary disabled:opacity-50"
                                        />
                                        <span>Gabung grup yang sudah ada</span>
                                    </label>
                                </div>
                                <p v-if="!canJoinExistingGroup" class="text-[11px] text-slate-500">
                                    Opsi gabung muncul setelah Anda punya setidaknya satu toko yang sudah masuk grup.
                                </p>
                                <p v-if="form.errors.organization_mode" class="text-sm text-red-600">
                                    {{ form.errors.organization_mode }}
                                </p>
                            </div>

                            <div v-if="form.organization_mode === 'new'" class="space-y-2">
                                <Label for="organization_name" class="text-sm font-semibold text-slate-700">
                                    Nama grup usaha
                                </Label>
                                <Input
                                    id="organization_name"
                                    v-model="form.organization_name"
                                    type="text"
                                    :placeholder="form.name ? `Default: ${form.name}` : 'Contoh: PT Sumber Rejeki'"
                                    :class="inputClass"
                                />
                                <p class="text-xs text-slate-500">Kosongkan untuk memakai nama toko sebagai nama grup.</p>
                                <p v-if="form.errors.organization_name" class="text-sm text-red-600">
                                    {{ form.errors.organization_name }}
                                </p>
                            </div>

                            <div v-else class="space-y-2">
                                <Label for="join_org" class="text-sm font-semibold text-slate-700"> Pilih grup * </Label>
                                <select
                                    id="join_org"
                                    v-model.number="form.organization_id"
                                    required
                                    :class="selectClass"
                                >
                                    <option :value="null" disabled>Pilih grup…</option>
                                    <option v-for="o in joinableOrganizations" :key="o.id" :value="o.id">
                                        {{ o.name }}
                                    </option>
                                </select>
                                <p v-if="form.errors.organization_id" class="text-sm text-red-600">
                                    {{ form.errors.organization_id }}
                                </p>
                            </div>

                            <!-- Opsi salin produk dari Gudang Pusat -->
                            <div v-if="selectedCentralWarehouse" class="space-y-3 p-4 rounded-xl bg-emerald-50 border border-emerald-200">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input
                                        v-model="form.copy_products"
                                        type="checkbox"
                                        class="mt-0.5 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    <div>
                                        <span class="text-sm font-semibold text-emerald-900">Salin produk dari Gudang Pusat</span>
                                        <p class="text-xs text-emerald-700 mt-1">
                                            Salin <strong>{{ selectedCentralWarehouse.product_count }} produk</strong> bahan baku & kemasan dari
                                            <strong>{{ selectedCentralWarehouse.name }}</strong> ke outlet ini (stok awal = 0).
                                        </p>
                                        <p class="text-xs text-emerald-600 mt-1">
                                            Diperlukan agar stok bisa ditransfer dari Gudang Pusat ke outlet ini.
                                        </p>
                                    </div>
                                </label>
                            </div>
                            <div v-else-if="form.organization_mode === 'join' && form.organization_id && !selectedCentralWarehouse" class="p-4 rounded-xl bg-amber-50 border border-amber-200">
                                <p class="text-xs text-amber-700">
                                    Grup ini belum memiliki Gudang Pusat. Produk harus dibuat manual atau melalui halaman Kelola Produk.
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col-reverse sm:flex-row gap-3 pt-6 border-t border-slate-100">
                            <Button
                                type="button"
                                variant="outline"
                                as-child
                                class="h-12 rounded-xl border-slate-200 text-slate-700 hover:bg-slate-50 sm:flex-1"
                            >
                                <Link href="/dashboard">Batal</Link>
                            </Button>
                            <Button
                                type="submit"
                                :disabled="form.processing"
                                class="h-12 rounded-xl bg-primary text-primary-foreground shadow-md shadow-primary/25 hover:bg-primary/90 sm:flex-1 font-bold"
                            >
                                <Store class="h-5 w-5 mr-2" />
                                {{ form.processing ? 'Membuat toko…' : 'Buat toko' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Daftar outlet yang sudah ada -->
            <Card v-if="hasExistingStores" class="mt-6 border-0 shadow-lg shadow-slate-200/60 rounded-2xl overflow-hidden">
                <CardHeader class="pb-4 px-6 sm:px-8 pt-6 sm:pt-8 border-b border-slate-100 bg-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-lg font-bold text-slate-900">Outlet saya</CardTitle>
                            <CardDescription class="text-slate-600 text-sm">
                                Daftar toko yang sudah terdaftar di akun ini.
                            </CardDescription>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary">
                            {{ (props.existingStores ?? []).length }}
                        </span>
                    </div>
                </CardHeader>
                <CardContent class="px-6 sm:px-8 py-6 bg-white space-y-5">
                    <!-- Ringkasan kuota outlet per grup usaha -->
                    <div v-if="quotaSummary.length > 0" class="space-y-2">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Kuota outlet</p>
                        <div
                            v-for="g in quotaSummary"
                            :key="g.id"
                            class="rounded-xl border p-3.5"
                            :class="g.isFull ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-slate-800 truncate">{{ g.name }}</span>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-bold shrink-0"
                                    :class="g.isFull ? 'bg-amber-100 text-amber-700' : 'bg-primary/10 text-primary'"
                                >
                                    {{ g.used }} / {{ g.limit === null ? '∞' : g.limit }} outlet
                                </span>
                            </div>
                            <div v-if="g.limit !== null" class="mt-2 h-2 bg-white rounded-full overflow-hidden border border-slate-200">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="g.isFull ? 'bg-amber-400' : 'bg-primary'"
                                    :style="{ width: Math.min(100, (g.used / g.limit) * 100) + '%' }"
                                />
                            </div>
                            <p class="mt-2 text-xs" :class="g.isFull ? 'text-amber-700' : 'text-slate-500'">
                                <template v-if="g.limit === null">Outlet tidak dibatasi.</template>
                                <template v-else-if="g.isFull">
                                    Kuota outlet penuh. Beli outlet tambahan (Rp85.000/outlet) di halaman
                                    <a href="/subscription" class="font-bold underline">Langganan</a> untuk menambah.
                                </template>
                                <template v-else>Sisa {{ g.remaining }} outlet.</template>
                                <span v-if="!g.hasWarehouse"> Gudang Pusat (1×) gratis & terpisah dari kuota.</span>
                            </p>
                        </div>
                    </div>

                    <!-- Belum ada grup -->
                    <div v-if="groupedStores.standalone.length > 0">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Belum ada grup</p>
                        <div class="rounded-xl border border-slate-200 divide-y divide-slate-100">
                            <div
                                v-for="s in groupedStores.standalone"
                                :key="s.id"
                                class="flex items-center justify-between px-4 py-3 hover:bg-slate-50 transition-colors"
                            >
                                <div class="flex items-center gap-3 min-w-0">
                                    <CircleDot class="h-4 w-4 shrink-0" :class="s.is_active ? 'text-emerald-500' : 'text-slate-300'" />
                                    <span class="text-sm font-semibold text-slate-900 truncate">{{ s.name }}</span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span
                                        v-if="s.is_primary"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700"
                                    >
                                        <Star class="h-3 w-3" />
                                        Utama
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                        {{ s.location_label }}
                                    </span>
                                    <span
                                        v-if="!s.is_active"
                                        class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-600"
                                    >
                                        Nonaktif
                                    </span>
                                    <button
                                        v-if="!s.is_primary"
                                        type="button"
                                        class="ml-1 inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                                        title="Hapus outlet"
                                        @click="deleteStore(s)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sudah ada grup -->
                    <div v-if="groupedStores.inGroup.length > 0">
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Dalam grup</p>
                        <div class="rounded-xl border border-slate-200 divide-y divide-slate-100">
                            <div
                                v-for="s in groupedStores.inGroup"
                                :key="s.id"
                                class="flex items-center justify-between px-4 py-3 hover:bg-slate-50 transition-colors"
                            >
                                <div class="flex items-center gap-3 min-w-0">
                                    <CircleDot class="h-4 w-4 shrink-0" :class="s.is_active ? 'text-emerald-500' : 'text-slate-300'" />
                                    <div class="min-w-0">
                                        <span class="text-sm font-semibold text-slate-900 truncate block">{{ s.name }}</span>
                                        <span class="text-xs text-slate-500">{{ s.group_name }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span
                                        v-if="s.is_primary"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700"
                                    >
                                        <Star class="h-3 w-3" />
                                        Utama
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                        {{ s.location_label }}
                                    </span>
                                    <span
                                        v-if="!s.is_active"
                                        class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-600"
                                    >
                                        Nonaktif
                                    </span>
                                    <button
                                        v-if="!s.is_primary"
                                        type="button"
                                        class="ml-1 inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                                        title="Hapus outlet"
                                        @click="deleteStore(s)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div
                class="mt-8 rounded-2xl border border-primary/20 bg-primary/[0.04] px-5 py-5 sm:px-6 flex gap-4 items-start"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Globe class="h-5 w-5" />
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-sm">Tips</h3>
                    <p class="text-sm text-slate-600 mt-1 leading-relaxed">
                        Nama toko yang jelas memudahkan tim dan laporan. Setelah dibuat, Anda bisa mengatur pajak, logo struk, dan fitur
                        khusus jenis usaha di menu pengaturan.
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
