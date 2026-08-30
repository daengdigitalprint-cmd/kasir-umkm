<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Users, Plus, ArrowLeft, ShieldCheck, UserPlus, Pencil, Trash2 } from 'lucide-vue-next';
import { ref, watch, computed } from 'vue';
import { confirmDialog, toastSuccess, toastError } from '@/lib/swal';
import AdminLayout from '@/layouts/AdminLayout.vue';

type StoreRole = 'admin' | 'manager' | 'cashier' | 'warehouse';

interface TeamMember {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface StoreInfo {
    id: number;
    slug: string;
    name: string;
    team_members?: TeamMember[];
    organization_id?: number | null;
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
const showCreateForm = ref(false);
const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'cashier' as StoreRole,
});

const submitCreate = async () => {
    if (!(await confirmDialog('Buat akun baru?', `Akun akan dibuat dengan peran ${roleLabel(createForm.role)}.`, { confirmText: 'Buat', icon: 'question' }))) return;
    createForm.post(`/stores/${props.store.slug}/members/create`, {
        preserveScroll: true,
        onSuccess: () => { createForm.reset(); showCreateForm.value = false; },
    });
};

// --- Ubah peran ---
const memberRoles = ref<Record<number, StoreRole>>({});

watch(
    () => props.store?.team_members,
    (list) => {
        const next: Record<number, StoreRole> = {};
        for (const m of list ?? []) next[m.id] = m.role as StoreRole;
        memberRoles.value = next;
    },
    { immediate: true },
);

function roleDirty(m: TeamMember): boolean {
    return memberRoles.value[m.id] !== m.role;
}

async function saveRole(memberId: number) {
    const slug = props.store?.slug;
    if (!slug) return;
    const member = props.store.team_members?.find((m) => m.id === memberId);
    if (!(await confirmDialog('Ubah peran?', `Peran "${member?.name ?? ''}" akan diubah ke ${roleLabel(memberRoles.value[memberId])}.`, { confirmText: 'Simpan', icon: 'question' }))) return;
    router.patch(`/stores/${slug}/members/${memberId}`, { role: memberRoles.value[memberId] }, { preserveScroll: true });
}

function roleLabel(role: string): string {
    const map: Record<string, string> = { admin: 'Admin', manager: 'Manajer', cashier: 'Kasir', warehouse: 'Admin gudang', owner: 'Pemilik (Owner)' };
    return map[role] ?? role;
}

// --- Edit anggota (nama, email, peran, kata sandi opsional) ---
const editingMember = ref<TeamMember | null>(null);
const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'cashier' as StoreRole,
});

function openEdit(m: TeamMember) {
    editingMember.value = m;
    editForm.clearErrors();
    editForm.name = m.name;
    editForm.email = m.email;
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.role = m.role as StoreRole;
}

async function submitEdit() {
    const slug = props.store?.slug;
    const memberId = editingMember.value?.id;
    if (!slug || !memberId) return;
    if (!(await confirmDialog('Simpan perubahan?', `Data "${editingMember.value?.name ?? ''}" akan diperbarui.`, { confirmText: 'Simpan', icon: 'question' }))) return;
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
const allMenuKeys = ref<string[]>([]);
const menuPermissions = ref<Record<string, string[]>>({});
const selectedPermRole = ref<string>('cashier');
const permLoading = ref(false);
const permSaving = ref(false);

const menuLabels: Record<string, string> = {
    dashboard: 'Dashboard',
    pos: 'Point of Sale (POS)',
    pos_transactions: 'Riwayat Transaksi',
    cash_shifts: 'Shift Kasir',
    cash_transactions: 'Uang Masuk/Keluar',
    kitchen_queue: 'Antrian Dapur',
    queues: 'Papan Pesanan',
    laundry_orders: 'Order Laundry',
    stock_overview: 'Lihat Stok',
    products: 'Produk',
    product_categories: 'Kategori Produk',
    stock_movements: 'Riwayat Stok',
    stock_adjustment: 'Penyesuaian Stok',
    stock_opnames: 'Stok Opname',
    recipes: 'Resep',
    productions: 'Produksi',
    stock_transfers: 'Transfer Stok',
    purchases: 'Pembelian',
    purchase_returns: 'Retur Pembelian',
    sales_returns: 'Retur Penjualan',
    pre_orders: 'Pre-order',
    discounts: 'Diskon',
    customers: 'Pelanggan',
    loyalty: 'Loyalitas',
    reports: 'Laporan',
    expenses: 'Biaya Usaha',
    manage_stores: 'Tambah Outlet/Gudang',
    manage_products: 'Kelola Produk',
    manage_staff: 'Tim & Akses',
    settings: 'Pengaturan',
};

const selectedPermMenus = ref<string[]>([]);

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
            allMenuKeys.value = data.all_menus;
            menuPermissions.value = data.permissions;
            selectedPermMenus.value = [...(data.permissions[selectedPermRole.value] ?? [])];
        }
    } finally {
        permLoading.value = false;
    }
};

const savePermissions = async () => {
    if (!(await confirmDialog('Simpan hak akses?', `Menu yang diizinkan untuk role ${roleLabel(selectedPermRole.value)} akan diperbarui.`, { confirmText: 'Simpan', icon: 'question' }))) return;
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
            body: JSON.stringify({ role: selectedPermRole.value, menus: selectedPermMenus.value }),
        });
        if (res.ok) {
            toastSuccess('Hak akses menu berhasil disimpan. User akan melihat perubahan setelah refresh.');
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
    if (tab === 'izin' && allMenuKeys.value.length === 0) {
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
                                    Ubah peran lalu simpan. Minimal satu admin harus tetap ada.
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
                                    <Label for="create-email" class="text-xs">Email</Label>
                                    <Input id="create-email" v-model="createForm.email" type="email" class="h-9 text-sm" placeholder="nama@email.com" required />
                                    <p v-if="createForm.errors.email" class="text-xs text-red-600">{{ createForm.errors.email }}</p>
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <Label for="create-pass" class="text-xs">Kata sandi</Label>
                                    <Input id="create-pass" v-model="createForm.password" type="password" class="h-9 text-sm" required />
                                    <p v-if="createForm.errors.password" class="text-xs text-red-600">{{ createForm.errors.password }}</p>
                                </div>
                                <div class="space-y-1">
                                    <Label for="create-pass2" class="text-xs">Ulangi sandi</Label>
                                    <Input id="create-pass2" v-model="createForm.password_confirmation" type="password" class="h-9 text-sm" required />
                                </div>
                                <div class="space-y-1">
                                    <Label for="create-role" class="text-xs">Peran</Label>
                                    <select id="create-role" v-model="createForm.role" class="flex h-9 w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                                        <option value="admin">Admin</option>
                                        <option value="manager">Manajer</option>
                                        <option value="cashier">Kasir</option>
                                        <option value="kitchen">Dapur</option>
                                        <option value="warehouse">Admin gudang</option>
                                        <option value="owner" v-if="canShowOwnerOption">Pemilik (Owner)</option>
                                    </select>
                                    <p v-if="createForm.errors.role" class="text-xs text-red-600">{{ createForm.errors.role }}</p>
                                </div>
                            </div>
                            <div class="flex gap-2 justify-end">
                                <Button type="button" variant="ghost" size="sm" @click="showCreateForm = false" class="text-xs">Batal</Button>
                                <Button type="submit" :disabled="createForm.processing" size="sm" class="gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700">
                                    <span v-if="createForm.processing" class="h-3 w-3 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                    {{ createForm.processing ? 'Membuat...' : 'Buat Akun' }}
                                </Button>
                            </div>
                        </form>
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
                                <p class="text-gray-500 break-all text-xs mt-0.5">{{ m.email }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <select
                                    v-model="memberRoles[m.id]"
                                    class="flex h-10 min-w-[10rem] rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manajer</option>
                                    <option value="cashier">Kasir</option>
                                    <option value="kitchen">Dapur</option>
                                    <option value="warehouse">Admin gudang</option>
                                    <option value="owner" v-if="canShowOwnerOption">Pemilik (Owner)</option>
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="h-10"
                                    :disabled="!roleDirty(m)"
                                    @click="saveRole(m.id)"
                                >
                                    Simpan
                                </Button>
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
                    <div class="space-y-1">
                        <Label for="edit-email" class="text-xs">Email</Label>
                        <Input id="edit-email" v-model="editForm.email" type="email" class="h-9 text-sm" required />
                        <p v-if="editForm.errors.email" class="text-xs text-red-600">{{ editForm.errors.email }}</p>
                    </div>
                    <div class="space-y-1">
                        <Label for="edit-role" class="text-xs">Peran</Label>
                        <select id="edit-role" v-model="editForm.role" class="flex h-9 w-full rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                            <option value="admin">Admin</option>
                            <option value="manager">Manajer</option>
                            <option value="cashier">Kasir</option>
                            <option value="kitchen">Dapur</option>
                            <option value="warehouse">Admin gudang</option>
                            <option value="owner" v-if="canShowOwnerOption">Pemilik (Owner)</option>
                        </select>
                        <p v-if="editForm.errors.role" class="text-xs text-red-600">{{ editForm.errors.role }}</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
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
                                Atur menu mana saja yang bisa diakses oleh setiap peran.
                                Perubahan berlaku setelah user refresh halaman.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="px-4 sm:px-6">
                    <!-- Role selector -->
                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <span class="text-xs font-bold text-slate-500">Pilih Peran:</span>
                        <button
                            v-for="r in ['admin', 'manager', 'cashier', 'kitchen', 'warehouse', 'owner']"
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
                    <div v-else class="space-y-1">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs text-slate-500">
                                Centang menu yang ingin diizinkan untuk role <strong>{{ roleLabel(selectedPermRole) }}</strong>
                            </p>
                            <span class="text-[10px] font-bold text-slate-400">
                                {{ selectedPermMenus.length }}/{{ allMenuKeys.length }} aktif
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            <label
                                v-for="key in allMenuKeys"
                                :key="key"
                                :class="[
                                    'flex items-center gap-3 rounded-xl border px-3 py-2.5 cursor-pointer transition-all text-sm',
                                    selectedPermMenus.includes(key)
                                        ? 'border-amber-300 bg-amber-50'
                                        : 'border-slate-200 bg-white hover:bg-slate-50',
                                ]"
                            >
                                <input
                                    type="checkbox"
                                    :checked="selectedPermMenus.includes(key)"
                                    @change="togglePermMenu(key)"
                                    class="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                />
                                <span :class="['font-medium', selectedPermMenus.includes(key) ? 'text-amber-800' : 'text-slate-600']">
                                    {{ menuLabels[key] ?? key }}
                                </span>
                            </label>
                        </div>

                        <!-- Save button -->
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
