<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Users, Plus, ArrowLeft, ShieldCheck, UserPlus, Pencil, Trash2, MessageCircle, Copy, KeyRound } from 'lucide-vue-next';
import { ref, watch, computed } from 'vue';
import { confirmDialog, toastSuccess, toastError } from '@/lib/swal';
import AdminLayout from '@/layouts/AdminLayout.vue';

type StoreRole = 'admin' | 'manager' | 'cashier' | 'kitchen' | 'warehouse' | 'owner' | 'barber' | 'staff';

interface TeamMember {
    id: number;
    name: string;
    /** null untuk akun PIN (tanpa email). */
    email: string | null;
    phone?: string | null;
    is_pin_only?: boolean;
    has_pin?: boolean;
    role: string;
}

interface StoreInfo {
    id: number;
    slug: string;
    name: string;
    team_members?: TeamMember[];
    organization_id?: number | null;
    /** Jumlah outlet di grup yang dikelola user ini (termasuk toko aktif). */
    group_store_count?: number;
}

interface Props {
    store: StoreInfo;
}

const props = defineProps<Props>();
const page = usePage();
const user = computed(() => (page.props as { auth?: { user?: { id: number } } }).auth?.user);
const canAssignOwner = computed(() => Boolean((page.props as { abilities?: { owner?: boolean } }).abilities?.owner));
const isSuperAdmin = computed(() => Boolean((page.props as { auth?: { user?: { is_super_admin?: boolean } } }).auth?.user?.is_super_admin));
const canManageStaff = computed(() => Boolean((page.props as { abilities?: { manage_store_settings?: boolean } }).abilities?.manage_store_settings));
const canShowOwnerOption = computed(() => canAssignOwner.value || isSuperAdmin.value || canManageStaff.value);

// --- Tab ---
const activeTab = ref<'karyawan' | 'izin'>('karyawan');

// --- Form buat akun baru ---
const PIN_ROLES: StoreRole[] = ['cashier', 'kitchen', 'warehouse', 'barber', 'staff'];
const pinModeOn = computed(() => String((page.props as { cashier_login_mode?: string }).cashier_login_mode ?? 'email') === 'pin');

const showCreateForm = ref(false);
const createForm = useForm({
    name: '',
    phone: '',
    login_method: 'email' as 'email' | 'pin',
    email: '',
    password: '',
    password_confirmation: '',
    pin: '',
    role: 'cashier' as StoreRole,
});

// Akun PIN hanya untuk peran non-pengelola; pindahkan peran bila perlu.
watch(
    () => createForm.login_method,
    (method) => {
        if (method === 'pin' && !PIN_ROLES.includes(createForm.role)) createForm.role = 'cashier';
    },
);

/** Info login terakhir yang dibuat, untuk dibagikan lewat WhatsApp. Hanya di browser, tidak disimpan. */
interface CreatedInfo {
    name: string;
    phone: string;
    method: 'email' | 'pin';
    email: string;
    password: string;
    pin: string;
}
const lastCreated = ref<CreatedInfo | null>(null);

const submitCreate = async () => {
    if (!(await confirmDialog('Buat akun baru?', `Akun akan dibuat dengan peran ${roleLabel(createForm.role)}.`, { confirmText: 'Buat', icon: 'question' }))) return;
    const snapshot: CreatedInfo = {
        name: createForm.name,
        phone: createForm.phone,
        method: createForm.login_method,
        email: createForm.email,
        password: createForm.password,
        pin: createForm.pin,
    };
    createForm
        .transform((data) => {
            const d = { ...data } as Record<string, unknown>;
            if (d.login_method === 'pin') {
                delete d.email;
                delete d.password;
                delete d.password_confirmation;
            } else {
                delete d.pin;
            }
            return d;
        })
        .post(`/stores/${props.store.slug}/members/create`, {
            preserveScroll: true,
            onSuccess: () => {
                lastCreated.value = snapshot;
                createForm.reset();
                showCreateForm.value = false;
            },
        });
};

function shareMessage(info: CreatedInfo): string {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    if (info.method === 'pin') {
        return [
            `Halo ${info.name}, akun Kasir UMKM Anda di ${props.store.name} sudah dibuat.`,
            '',
            'Cara masuk: di perangkat kasir toko, pilih nama Anda lalu masukkan PIN berikut.',
            `PIN: ${info.pin}`,
            '',
            'Jangan bagikan PIN ini ke orang lain.',
        ].join('\n');
    }
    return [
        `Halo ${info.name}, akun Kasir UMKM Anda di ${props.store.name} sudah dibuat.`,
        '',
        `Masuk di: ${origin}/login`,
        `Email: ${info.email}`,
        `Kata sandi: ${info.password}`,
        '',
        'Segera ganti kata sandi setelah masuk (menu Pengaturan → Kata sandi).',
    ].join('\n');
}

/** Nomor HP Indonesia ke format wa.me (62…); kosong = pilih kontak di WhatsApp. */
function waNumber(phone: string): string {
    let digits = phone.replace(/\D/g, '');
    if (digits.startsWith('0')) digits = '62' + digits.slice(1);
    return digits;
}

const waLink = computed(() => {
    if (!lastCreated.value) return '';
    return `https://wa.me/${waNumber(lastCreated.value.phone)}?text=${encodeURIComponent(shareMessage(lastCreated.value))}`;
});

async function copyShareMessage() {
    if (!lastCreated.value) return;
    try {
        await navigator.clipboard.writeText(shareMessage(lastCreated.value));
        toastSuccess('Pesan disalin.');
    } catch {
        toastError('Gagal menyalin. Salin manual dari WhatsApp.');
    }
}

// --- Peran ---
function roleLabel(role: string): string {
    const map: Record<string, string> = {
        admin: 'Admin',
        manager: 'Manajer',
        cashier: 'Kasir',
        kitchen: 'Dapur',
        warehouse: 'Admin gudang',
        owner: 'Pemilik (Owner)',
        barber: 'Barber',
        staff: 'Karyawan',
    };
    return map[role] ?? role;
}

/** Keterangan singkat tiap peran, ditampilkan di bawah pilihan peran. */
const ROLE_DESCRIPTIONS: Record<string, string> = {
    admin: 'Akses penuh toko: penjualan, stok, laporan, pengaturan, dan tim.',
    manager: 'Operasional, stok, dan laporan. Tidak mengubah pengaturan toko dan tim.',
    cashier: 'Jualan di kasir (POS), shift kas, dan cek stok.',
    kitchen: 'Melihat dan menyelesaikan antrean pesanan dapur.',
    warehouse: 'Stok, transfer, stok opname, dan pembelian. Tidak melihat HPP dan laporan keuangan.',
    barber: 'Melayani antrean dan transaksi layanan.',
    staff: 'Hanya absen, riwayat kehadiran, pengajuan cuti/lembur, dan slip gaji sendiri.',
    owner: 'Pemilik usaha: semua outlet di grup, laporan, dan langganan.',
};
function roleDescription(role: string): string {
    return ROLE_DESCRIPTIONS[role] ?? '';
}

/** Peran Dapur/Barber/Karyawan hanya relevan bila modulnya aktif di toko ini. */
const sharedFlag = (key: string) => Boolean((page.props as Record<string, unknown>)[key]);
const kitchenRelevant = computed(() => sharedFlag('fnb_enabled') || sharedFlag('kitchen_queue_enabled'));
const barberRelevant = computed(() => sharedFlag('barbershop_enabled'));
const staffRelevant = computed(() => sharedFlag('attendance_enabled'));

const baseRoles = computed<StoreRole[]>(() => {
    const roles: StoreRole[] = ['admin', 'manager', 'cashier'];
    if (kitchenRelevant.value) roles.push('kitchen');
    roles.push('warehouse');
    if (barberRelevant.value) roles.push('barber');
    if (staffRelevant.value) roles.push('staff');
    return roles;
});

/** Pilihan peran untuk form; peran saat ini selalu ikut agar tidak hilang saat diedit. */
function roleOptionsFor(current?: string, pinOnly = false): StoreRole[] {
    let roles = [...baseRoles.value];
    if (canShowOwnerOption.value) roles.push('owner');
    if (pinOnly) roles = roles.filter((r) => PIN_ROLES.includes(r));
    if (current && !roles.includes(current as StoreRole)) roles.push(current as StoreRole);
    return roles;
}

const permRoles = computed<StoreRole[]>(() => [...baseRoles.value, 'owner']);

// --- Edit anggota (nama, email, peran, kata sandi opsional) ---
const editingMember = ref<TeamMember | null>(null);
const editForm = useForm({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    pin: '',
    role: 'cashier' as StoreRole,
});

function openEdit(m: TeamMember) {
    editingMember.value = m;
    editForm.clearErrors();
    editForm.name = m.name;
    editForm.email = m.email ?? '';
    editForm.phone = m.phone ?? '';
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.pin = '';
    editForm.role = m.role as StoreRole;
}

async function submitEdit() {
    const slug = props.store?.slug;
    const memberId = editingMember.value?.id;
    if (!slug || !memberId) return;
    if (!(await confirmDialog('Simpan perubahan?', `Data "${editingMember.value?.name ?? ''}" akan diperbarui.`, { confirmText: 'Simpan', icon: 'question' }))) return;
    const pinOnly = Boolean(editingMember.value?.is_pin_only);
    editForm.transform((data) => {
        const d = { ...data } as Record<string, unknown>;
        // Akun PIN tidak punya email/kata sandi; akun email tidak mengirim PIN kosong.
        if (pinOnly) {
            delete d.email;
            delete d.password;
            delete d.password_confirmation;
        }
        if (!d.pin) delete d.pin;
        return d;
    });
    editForm.patch(`/stores/${slug}/members/${memberId}`, {
        preserveScroll: true,
        onSuccess: () => { editingMember.value = null; editForm.reset(); },
    });
}

async function deleteMember(m: TeamMember) {
    const slug = props.store?.slug;
    if (!slug) return;
    if (!(await confirmDialog('Hapus anggota?', `${m.name} akan dihapus dari toko ini dan kehilangan aksesnya.`, { confirmText: 'Hapus', icon: 'warning' }))) return;
    router.delete(`/stores/${slug}/members/${m.id}`, { preserveScroll: true });
}

// --- Menu Permissions ---
interface MenuCatalogItem {
    key: string;
    label: string;
    group: string;
    /** Nama prop Inertia penanda modul aktif; kosong = selalu tersedia. */
    module?: string;
}

const menuCatalog = ref<MenuCatalogItem[]>([]);
const menuPermissions = ref<Record<string, string[]>>({});
const customizedRoles = ref<Record<string, boolean>>({});
const selectedPermRole = ref<string>('cashier');
const permLoading = ref(false);
const permSaving = ref(false);
const selectedPermMenus = ref<string[]>([]);

/** Menu modul yang sedang mati tidak ditampilkan (izinnya tetap tersimpan). */
const visibleMenus = computed(() =>
    menuCatalog.value.filter((m) => !m.module || Boolean((page.props as Record<string, unknown>)[m.module])),
);
const hiddenModuleCount = computed(() => menuCatalog.value.length - visibleMenus.value.length);

const menuGroups = computed(() => {
    const groups: Array<{ name: string; items: MenuCatalogItem[] }> = [];
    for (const item of visibleMenus.value) {
        let group = groups.find((g) => g.name === item.group);
        if (!group) {
            group = { name: item.group, items: [] };
            groups.push(group);
        }
        group.items.push(item);
    }
    return groups;
});

const selectedVisibleCount = computed(() => visibleMenus.value.filter((m) => selectedPermMenus.value.includes(m.key)).length);

watch(selectedPermRole, (role) => {
    selectedPermMenus.value = [...(menuPermissions.value[role] ?? [])];
});

const fetchPermissions = async () => {
    permLoading.value = true;
    try {
        const res = await fetch('/menu-permissions', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            menuCatalog.value = data.menus ?? [];
            menuPermissions.value = data.permissions ?? {};
            customizedRoles.value = data.customized ?? {};
            selectedPermMenus.value = [...(data.permissions?.[selectedPermRole.value] ?? [])];
        } else {
            toastError('Gagal memuat hak akses.');
        }
    } catch {
        toastError('Gagal memuat hak akses.');
    } finally {
        permLoading.value = false;
    }
};

/** Terapkan hak akses yang sama ke semua outlet di grup yang dikelola user ini. */
const otherGroupStores = computed(() => Math.max(0, Number(props.store.group_store_count ?? 1) - 1));
const applyToAllStores = ref(false);

const savePermissions = async () => {
    const scope = applyToAllStores.value && otherGroupStores.value > 0 ? `toko ini dan ${otherGroupStores.value} outlet lain di grup` : 'toko ini';
    if (!(await confirmDialog('Simpan hak akses?', `Menu yang diizinkan untuk peran ${roleLabel(selectedPermRole.value)} di ${scope} akan diperbarui.`, { confirmText: 'Simpan', icon: 'question' }))) return;
    permSaving.value = true;
    try {
        const res = await fetch('/menu-permissions', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                Accept: 'application/json',
            },
            body: JSON.stringify({ role: selectedPermRole.value, menus: selectedPermMenus.value, apply_to_all: applyToAllStores.value }),
        });
        if (res.ok) {
            const data = await res.json().catch(() => ({}));
            const n = Number(data.store_count ?? 1);
            toastSuccess(
                n > 1
                    ? `Hak akses menu tersimpan di ${n} outlet. Pengguna melihat perubahan setelah memuat ulang halaman.`
                    : 'Hak akses menu tersimpan. Pengguna melihat perubahan setelah memuat ulang halaman.',
            );
            await fetchPermissions();
        } else {
            toastError('Gagal menyimpan hak akses.');
        }
    } catch {
        toastError('Gagal menyimpan hak akses.');
    } finally {
        permSaving.value = false;
    }
};

const togglePermMenu = (menuKey: string) => {
    const idx = selectedPermMenus.value.indexOf(menuKey);
    if (idx >= 0) {
        selectedPermMenus.value.splice(idx, 1);
    } else {
        selectedPermMenus.value.push(menuKey);
    }
};

// Fetch permissions saat tab izin dipilih
watch(activeTab, (tab) => {
    if (tab === 'izin' && menuCatalog.value.length === 0) {
        fetchPermissions();
    }
});
</script>

<template>
    <Head title="Tim & Akses" />
    <AdminLayout title="Tim & Akses">
        <template #header-actions>
            <Button variant="outline" size="sm" as-child class="rounded-xl">
                <a href="/settings" class="inline-flex items-center gap-2">
                    <ArrowLeft class="h-4 w-4" />
                    Pengaturan
                </a>
            </Button>
        </template>
        <template #mobile-header-actions>
            <Button variant="ghost" size="sm" as-child class="rounded-xl text-slate-600">
                <a href="/settings" class="inline-flex items-center justify-center">
                    <ArrowLeft class="h-5 w-5" />
                </a>
            </Button>
        </template>

        <!-- Tab Navigation -->
        <div class="flex gap-1 mb-6 rounded-xl bg-slate-100 p-1">
            <button
                type="button"
                @click="activeTab = 'karyawan'"
                :class="[
                    'flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all flex-1 justify-center',
                    activeTab === 'karyawan'
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-500 hover:text-slate-700',
                ]"
            >
                <Users class="h-4 w-4" />
                Karyawan
            </button>
            <button
                type="button"
                @click="activeTab = 'izin'"
                :class="[
                    'flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all flex-1 justify-center',
                    activeTab === 'izin'
                        ? 'bg-white text-slate-900 shadow-sm'
                        : 'text-slate-500 hover:text-slate-700',
                ]"
            >
                <ShieldCheck class="h-4 w-4" />
                Peran & Izin
            </button>
        </div>

        <!-- ==================== TAB: KARYAWAN ==================== -->
        <div v-if="activeTab === 'karyawan'" class="space-y-6">
            <!-- Daftar anggota -->
            <Card class="border-0 shadow-lg">
                <CardHeader class="px-4 sm:px-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Users class="h-5 w-5 text-primary" />
                            <div>
                                <CardTitle class="text-base font-bold text-gray-900">Daftar Anggota</CardTitle>
                                <CardDescription class="text-xs">
                                    Anggota dengan akses ke toko <strong>{{ store.name }}</strong>.
                                    Klik Edit untuk mengubah nama, peran, atau kata sandi. Minimal satu admin harus tetap ada.
                                </CardDescription>
                            </div>
                        </div>
                        <Button type="button" size="sm" class="gap-1.5 rounded-xl" @click="showCreateForm = !showCreateForm">
                            <Plus class="h-4 w-4" />
                            <span class="hidden sm:inline">Tambah</span>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent class="px-4 sm:px-6">
                    <!-- Form buat akun baru (inline) -->
                    <div v-if="showCreateForm" class="mb-4 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/50 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <UserPlus class="h-4 w-4 text-emerald-600" />
                            <span class="text-sm font-bold text-emerald-800">Tambah Anggota Baru</span>
                        </div>
                        <form class="space-y-3" @submit.prevent="submitCreate">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1">
                                    <Label for="create-name" class="text-xs">Nama</Label>
                                    <Input id="create-name" v-model="createForm.name" type="text" class="h-9 text-sm" placeholder="Nama lengkap" required />
                                    <p v-if="createForm.errors.name" class="text-xs text-red-600">{{ createForm.errors.name }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label for="create-phone" class="text-xs">Nomor HP (opsional)</Label>
                                    <Input id="create-phone" v-model="createForm.phone" type="tel" inputmode="tel" class="h-9 text-sm" placeholder="08xxxxxxxxxx" />
                                    <p v-if="createForm.errors.phone" class="text-xs text-red-600">{{ createForm.errors.phone }}</p>
                                    <p v-else class="text-[11px] text-slate-500">Untuk mengirim info masuk lewat WhatsApp.</p>
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <Label class="text-xs">Cara masuk</Label>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label
                                        class="flex cursor-pointer items-start gap-2 rounded-lg border bg-white px-3 py-2 text-sm"
                                        :class="createForm.login_method === 'email' ? 'border-primary ring-1 ring-primary/30' : 'border-gray-200'"
                                    >
                                        <input v-model="createForm.login_method" type="radio" value="email" class="mt-0.5" />
                                        <span>
                                            <span class="block font-semibold text-slate-800">Email &amp; kata sandi</span>
                                            <span class="block text-xs text-slate-500">Masuk dari HP/komputer mana pun.</span>
                                        </span>
                                    </label>
                                    <label
                                        class="flex items-start gap-2 rounded-lg border bg-white px-3 py-2 text-sm"
                                        :class="[
                                            createForm.login_method === 'pin' ? 'border-primary ring-1 ring-primary/30' : 'border-gray-200',
                                            pinModeOn ? 'cursor-pointer' : 'cursor-not-allowed opacity-60',
                                        ]"
                                    >
                                        <input v-model="createForm.login_method" type="radio" value="pin" class="mt-0.5" :disabled="!pinModeOn" />
                                        <span>
                                            <span class="block font-semibold text-slate-800">PIN saja (tanpa email)</span>
                                            <span class="block text-xs text-slate-500">Pilih nama lalu masukkan PIN di perangkat kasir toko.</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="!pinModeOn" class="text-[11px] text-slate-500">
                                    Untuk karyawan tanpa email, aktifkan dulu mode PIN di
                                    <a href="/settings/cashier-pin" class="font-semibold text-primary underline">Pengaturan → Login Kasir</a>.
                                </p>
                            </div>

                            <div v-if="createForm.login_method === 'email'" class="grid gap-3 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <Label for="create-email" class="text-xs">Email</Label>
                                    <Input id="create-email" v-model="createForm.email" type="email" class="h-9 text-sm" placeholder="nama@email.com" required />
                                    <p v-if="createForm.errors.email" class="text-xs text-red-600">{{ createForm.errors.email }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label for="create-pass" class="text-xs">Kata sandi</Label>
                                    <Input id="create-pass" v-model="createForm.password" type="password" class="h-9 text-sm" required />
                                    <p v-if="createForm.errors.password" class="text-xs text-red-600">{{ createForm.errors.password }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label for="create-pass2" class="text-xs">Ulangi sandi</Label>
                                    <Input id="create-pass2" v-model="createForm.password_confirmation" type="password" class="h-9 text-sm" required />
                                </div>
                            </div>
                            <div v-else class="grid gap-3 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <Label for="create-pin" class="text-xs">PIN (4–6 angka)</Label>
                                    <Input
                                        id="create-pin"
                                        v-model="createForm.pin"
                                        type="password"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        maxlength="6"
                                        class="h-9 text-sm tracking-widest"
                                        required
                                    />
                                    <p v-if="createForm.errors.pin" class="text-xs text-red-600">{{ createForm.errors.pin }}</p>
                                    <p v-else class="text-[11px] text-slate-500">Hindari PIN mudah seperti 1234 atau 1111.</p>
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <Label for="create-role" class="text-xs">Peran</Label>
                                    <select id="create-role" v-model="createForm.role" class="flex h-9 w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                                        <option v-for="r in roleOptionsFor(undefined, createForm.login_method === 'pin')" :key="r" :value="r">{{ roleLabel(r) }}</option>
                                    </select>
                                    <p v-if="createForm.errors.role" class="text-xs text-red-600">{{ createForm.errors.role }}</p>
                                </div>
                            </div>
                            <p class="text-xs text-slate-600"><strong>{{ roleLabel(createForm.role) }}:</strong> {{ roleDescription(createForm.role) }}</p>
                            <div class="flex gap-2 justify-end">
                                <Button type="button" variant="ghost" size="sm" @click="showCreateForm = false" class="text-xs">Batal</Button>
                                <Button type="submit" :disabled="createForm.processing" size="sm" class="gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700">
                                    <span v-if="createForm.processing" class="h-3 w-3 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                    {{ createForm.processing ? 'Membuat...' : 'Buat Akun' }}
                                </Button>
                            </div>
                        </form>
                    </div>

                    <!-- Bagikan info masuk ke karyawan baru -->
                    <div v-if="lastCreated" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-emerald-900">Akun {{ lastCreated.name }} sudah dibuat</p>
                                <p class="mt-0.5 text-xs text-emerald-800">
                                    Kirim cara masuknya ke karyawan. Info ini hanya tampil sekarang dan tidak disimpan.
                                </p>
                            </div>
                            <button type="button" class="text-emerald-700/70 hover:text-emerald-900" aria-label="Tutup" @click="lastCreated = null">✕</button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button as-child size="sm" class="gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700">
                                <a :href="waLink" target="_blank" rel="noopener">
                                    <MessageCircle class="h-4 w-4" />
                                    Kirim via WhatsApp
                                </a>
                            </Button>
                            <Button type="button" size="sm" variant="outline" class="gap-1.5 rounded-xl" @click="copyShareMessage">
                                <Copy class="h-4 w-4" />
                                Salin pesan
                            </Button>
                        </div>
                    </div>

                    <!-- Tabel anggota -->
                    <ul v-if="store.team_members?.length" class="divide-y divide-gray-100 rounded-xl border border-gray-200 overflow-hidden">
                        <li
                            v-for="m in store.team_members"
                            :key="m.id"
                            class="flex flex-col gap-3 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="min-w-0 flex-1">
                                <span class="font-medium text-gray-900">{{ m.name }}</span>
                                <span v-if="m.id === user?.id" class="ml-2 text-xs font-medium text-blue-600">(Anda)</span>
                                <p v-if="m.is_pin_only" class="mt-0.5 inline-flex items-center gap-1 text-xs text-gray-500">
                                    <KeyRound class="h-3 w-3" /> Masuk dengan PIN<template v-if="m.phone"> · {{ m.phone }}</template>
                                </p>
                                <p v-else class="text-gray-500 break-all text-xs mt-0.5">
                                    {{ m.email }}<template v-if="m.phone"> · {{ m.phone }}</template>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <span
                                    class="inline-flex h-8 items-center rounded-full bg-primary/10 px-3 text-xs font-bold text-primary"
                                    :title="roleDescription(m.role)"
                                >
                                    {{ roleLabel(m.role) }}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="h-10 gap-1"
                                    @click="openEdit(m)"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                    Edit
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="h-10 gap-1 text-red-600 hover:bg-red-50"
                                    :disabled="m.id === user?.id"
                                    @click="deleteMember(m)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    Hapus
                                </Button>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-500 py-8 text-center">Belum ada anggota.</p>
                </CardContent>
            </Card>
        </div>

        <!-- ==================== MODAL EDIT ANGGOTA ==================== -->
        <div v-if="editingMember" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @click.self="editingMember = null">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h3 class="text-base font-bold text-gray-900">Edit Anggota</h3>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="editingMember = null">✕</button>
                </div>
                <form class="space-y-3 p-5" @submit.prevent="submitEdit">
                    <div class="space-y-1">
                        <Label for="edit-name" class="text-xs">Nama</Label>
                        <Input id="edit-name" v-model="editForm.name" type="text" class="h-9 text-sm" required />
                        <p v-if="editForm.errors.name" class="text-xs text-red-600">{{ editForm.errors.name }}</p>
                    </div>
                    <div v-if="!editingMember.is_pin_only" class="space-y-1">
                        <Label for="edit-email" class="text-xs">Email</Label>
                        <Input id="edit-email" v-model="editForm.email" type="email" class="h-9 text-sm" required />
                        <p v-if="editForm.errors.email" class="text-xs text-red-600">{{ editForm.errors.email }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="edit-phone" class="text-xs">Nomor HP (opsional)</Label>
                        <Input id="edit-phone" v-model="editForm.phone" type="tel" inputmode="tel" class="h-9 text-sm" placeholder="08xxxxxxxxxx" />
                        <p v-if="editForm.errors.phone" class="text-xs text-red-600">{{ editForm.errors.phone }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="edit-role" class="text-xs">Peran</Label>
                        <select id="edit-role" v-model="editForm.role" class="flex h-9 w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                            <option v-for="r in roleOptionsFor(editingMember?.role, Boolean(editingMember?.is_pin_only))" :key="r" :value="r">{{ roleLabel(r) }}</option>
                        </select>
                        <p class="text-xs text-slate-500">{{ roleDescription(editForm.role) }}</p>
                        <p v-if="editForm.errors.role" class="text-xs text-red-600">{{ editForm.errors.role }}</p>
                    </div>
                    <div v-if="editingMember.is_pin_only" class="space-y-1">
                        <Label for="edit-pin" class="text-xs">PIN baru (opsional)</Label>
                        <Input
                            id="edit-pin"
                            v-model="editForm.pin"
                            type="password"
                            inputmode="numeric"
                            autocomplete="off"
                            maxlength="6"
                            class="h-9 text-sm tracking-widest"
                            placeholder="Kosongkan jika tidak diubah"
                        />
                        <p v-if="editForm.errors.pin" class="text-xs text-red-600">{{ editForm.errors.pin }}</p>
                    </div>
                    <div v-else class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <Label for="edit-pass" class="text-xs">Kata sandi baru (opsional)</Label>
                            <Input id="edit-pass" v-model="editForm.password" type="password" class="h-9 text-sm" placeholder="Kosongkan jika tidak diubah" />
                            <p v-if="editForm.errors.password" class="text-xs text-red-600">{{ editForm.errors.password }}</p>
                        </div>
                        <div class="space-y-1">
                            <Label for="edit-pass2" class="text-xs">Ulangi sandi</Label>
                            <Input id="edit-pass2" v-model="editForm.password_confirmation" type="password" class="h-9 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end pt-2">
                        <Button type="button" variant="ghost" size="sm" @click="editingMember = null" class="text-xs">Batal</Button>
                        <Button type="submit" :disabled="editForm.processing" size="sm" class="gap-1.5 rounded-xl">
                            <span v-if="editForm.processing" class="h-3 w-3 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                            {{ editForm.processing ? 'Menyimpan...' : 'Simpan' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================== TAB: PERAN & IZIN ==================== -->
        <div v-if="activeTab === 'izin'">
            <Card class="border-0 shadow-lg">
                <CardHeader class="px-4 sm:px-6">
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="h-5 w-5 text-amber-600" />
                        <div>
                            <CardTitle class="text-base font-bold text-gray-900">Hak Akses Menu</CardTitle>
                            <CardDescription class="text-xs">
                                Atur menu yang bisa dibuka setiap peran di toko <strong>{{ store.name }}</strong>.
                                Berlaku hanya untuk toko ini, setelah pengguna memuat ulang halaman.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="px-4 sm:px-6">
                    <!-- Role selector -->
                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <span class="text-xs font-bold text-slate-500">Pilih Peran:</span>
                        <button
                            v-for="r in permRoles"
                            :key="r"
                            type="button"
                            @click="selectedPermRole = r"
                            :class="[
                                'px-3 py-1.5 rounded-xl text-xs font-bold transition-all',
                                selectedPermRole === r
                                    ? 'bg-amber-500 text-white shadow-md'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
                            ]"
                        >
                            {{ roleLabel(r) }}
                        </button>
                    </div>

                    <!-- Loading -->
                    <div v-if="permLoading" class="py-8 text-center text-sm text-slate-400">
                        Memuat data hak akses...
                    </div>

                    <!-- Menu toggles -->
                    <div v-else class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-slate-500">
                                Centang menu yang boleh dibuka peran <strong>{{ roleLabel(selectedPermRole) }}</strong>.
                                <span v-if="!customizedRoles[selectedPermRole]" class="text-slate-400">(masih memakai pengaturan bawaan)</span>
                            </p>
                            <span class="text-[10px] font-bold text-slate-400">
                                {{ selectedVisibleCount }}/{{ visibleMenus.length }} aktif
                            </span>
                        </div>
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            <strong>{{ roleLabel(selectedPermRole) }}:</strong> {{ roleDescription(selectedPermRole) }}
                            <template v-if="selectedPermRole === 'admin' || selectedPermRole === 'owner'">
                                Peran ini tetap punya akses penuh; centang di sini hanya mengatur menu yang tampil di sidebar.
                            </template>
                        </p>

                        <div v-for="group in menuGroups" :key="group.name" class="space-y-2">
                            <p class="text-[11px] font-bold tracking-wide text-slate-500 uppercase">{{ group.name }}</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <label
                                    v-for="item in group.items"
                                    :key="item.key"
                                    :class="[
                                        'flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 text-sm transition-all',
                                        selectedPermMenus.includes(item.key)
                                            ? 'border-amber-300 bg-amber-50'
                                            : 'border-slate-200 bg-white hover:bg-slate-50',
                                    ]"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="selectedPermMenus.includes(item.key)"
                                        @change="togglePermMenu(item.key)"
                                        class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                    />
                                    <span :class="['font-medium', selectedPermMenus.includes(item.key) ? 'text-amber-800' : 'text-slate-600']">
                                        {{ item.label }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <p v-if="hiddenModuleCount > 0" class="text-xs text-slate-400">
                            {{ hiddenModuleCount }} menu modul tidak ditampilkan karena modulnya belum aktif di Pengaturan.
                        </p>
                        <p class="text-xs text-slate-400">
                            Pengaturan toko, Tim &amp; Akses, outlet, KPI, dan login kasir selalu khusus Admin/Pemilik.
                        </p>

                        <!-- Save button -->
                        <label v-if="otherGroupStores > 0" class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                            <input v-model="applyToAllStores" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
                            <span>
                                <span class="block font-semibold text-slate-800">Terapkan juga ke {{ otherGroupStores }} outlet lain di grup</span>
                                <span class="block text-xs text-slate-500">Hak akses peran {{ roleLabel(selectedPermRole) }} di outlet lain disamakan dengan pilihan ini.</span>
                            </span>
                        </label>
                        <div class="flex justify-end pt-4">
                            <Button
                                type="button"
                                :disabled="permSaving"
                                @click="savePermissions"
                                class="gap-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white"
                            >
                                <span v-if="permSaving" class="h-4 w-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                {{ permSaving ? 'Menyimpan...' : 'Simpan Hak Akses' }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
