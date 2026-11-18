<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete, toastError, toastSuccess } from '@/lib/swal';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleSlash,
    ClipboardList,
    Copy,
    Download,
    ExternalLink,
    LayoutGrid,
    MapPin,
    Pencil,
    Plus,
    Printer,
    QrCode,
    RefreshCw,
    Search,
    StickyNote,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TableItem {
    id: number;
    name: string;
    code: string | null;
    slug: string;
    qr_code_url: string | null;
    is_active: boolean;
    capacity: number | null;
    location: string | null;
    notes: string | null;
    guest_url: string;
    created_at: string;
}

const props = defineProps<{
    tables: TableItem[];
}>();

// --- Ringkasan ---
const activeCount = computed(() => props.tables.filter((t) => t.is_active).length);
const inactiveCount = computed(() => props.tables.length - activeCount.value);
const areas = computed(() => [...new Set(props.tables.map((t) => t.location?.trim()).filter((a): a is string => !!a))].sort());

const statCards = computed(() => [
    { label: 'Total meja', value: props.tables.length, icon: LayoutGrid, tone: 'text-primary bg-primary/10' },
    { label: 'Aktif (bisa dipesan)', value: activeCount.value, icon: CircleCheck, tone: 'text-emerald-600 bg-emerald-50' },
    { label: 'Nonaktif', value: inactiveCount.value, icon: CircleSlash, tone: 'text-slate-500 bg-slate-100' },
    { label: 'Area', value: areas.value.length, icon: MapPin, tone: 'text-sky-600 bg-sky-50' },
]);

// --- Pencarian & filter ---
const search = ref('');
const areaFilter = ref<string | null>(null);
const statusFilter = ref<'all' | 'active' | 'inactive'>('all');

// Urutkan nama secara alami: "Meja 2" sebelum "Meja 10".
const collator = new Intl.Collator('id', { numeric: true, sensitivity: 'base' });
const filteredTables = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.tables
        .filter((t) => {
            if (statusFilter.value === 'active' && !t.is_active) return false;
            if (statusFilter.value === 'inactive' && t.is_active) return false;
            if (areaFilter.value && (t.location?.trim() ?? '') !== areaFilter.value) return false;
            if (!q) return true;
            return [t.name, t.code, t.location, t.notes].some((v) => v?.toLowerCase().includes(q));
        })
        .sort((a, b) => collator.compare(a.name, b.name));
});
const hasFilter = computed(() => !!search.value.trim() || !!areaFilter.value || statusFilter.value !== 'all');
const resetFilter = () => {
    search.value = '';
    areaFilter.value = null;
    statusFilter.value = 'all';
};

// --- Form tambah/ubah ---
const showForm = ref(false);
const editingTable = ref<TableItem | null>(null);
const form = useForm({
    name: '',
    code: '',
    capacity: '' as string | number,
    location: '',
    notes: '',
});

const openCreate = () => {
    editingTable.value = null;
    form.reset();
    form.clearErrors();
    // Bantu isi nama berikutnya, misal "Meja 5" bila sudah ada 4 meja.
    form.name = `Meja ${props.tables.length + 1}`;
    if (areaFilter.value) form.location = areaFilter.value;
    showForm.value = true;
};

const openEdit = (table: TableItem) => {
    editingTable.value = table;
    form.clearErrors();
    form.name = table.name;
    form.code = table.code || '';
    form.capacity = table.capacity || '';
    form.location = table.location || '';
    form.notes = table.notes || '';
    showForm.value = true;
};

const submitForm = () => {
    form.transform((d) => ({ ...d, capacity: d.capacity === '' ? null : d.capacity }));
    if (editingTable.value) {
        form.patch(`/tables/${editingTable.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                toastSuccess('Meja berhasil diperbarui!');
            },
        });
    } else {
        form.post('/tables', {
            preserveScroll: true,
            onSuccess: () => {
                showForm.value = false;
                toastSuccess('Meja berhasil ditambahkan!');
            },
        });
    }
};

// --- Aksi meja ---
const toggleActive = (table: TableItem) => {
    router.patch(
        `/tables/${table.id}/toggle`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => toastSuccess(table.is_active ? `${table.name} dinonaktifkan.` : `${table.name} diaktifkan.`),
            onError: () => toastError('Gagal mengubah status meja.'),
        },
    );
};

const deleteTable = async (table: TableItem) => {
    if (!(await confirmDelete('Hapus meja?', `Meja "${table.name}" akan dihapus dan QR yang sudah dicetak tidak bisa dipakai lagi.`))) return;
    router.delete(`/tables/${table.id}`, {
        preserveScroll: true,
        onSuccess: () => toastSuccess('Meja berhasil dihapus.'),
        onError: () => toastError('Gagal menghapus meja.'),
    });
};

const regenerating = ref(false);
const regenerateQr = (table: TableItem) => {
    router.post(
        `/tables/${table.id}/qr`,
        {},
        {
            preserveScroll: true,
            onStart: () => (regenerating.value = true),
            onFinish: () => (regenerating.value = false),
            onSuccess: () => {
                // Ambil data meja terbaru agar gambar di dialog ikut diperbarui.
                const fresh = props.tables.find((t) => t.id === table.id);
                if (fresh && qrModalTable.value?.id === table.id) qrModalTable.value = fresh;
                qrVersion.value++;
                toastSuccess('Gambar QR berhasil dibuat ulang.');
            },
            onError: () => toastError('Gagal membuat gambar QR.'),
        },
    );
};

// --- Dialog QR ---
const showQrModal = ref(false);
const qrModalTable = ref<TableItem | null>(null);
const qrVersion = ref(0);
const qrSrc = (url: string | null) => (url ? `${url}${url.includes('?') ? '&' : '?'}v=${qrVersion.value}` : null);

const openQrModal = (table: TableItem) => {
    qrModalTable.value = table;
    showQrModal.value = true;
};

const copyGuestUrl = (url: string) => {
    navigator.clipboard
        .writeText(url)
        .then(() => toastSuccess('Link menu meja disalin!'))
        .catch(() => toastError('Gagal menyalin link.'));
};

const downloadQr = async (table: TableItem) => {
    if (!table.qr_code_url) return;
    const fileName = `qr-${table.name.replace(/\s+/g, '-').toLowerCase()}.png`;
    try {
        const res = await fetch(table.qr_code_url);
        if (!res.ok) throw new Error('Gagal mengunduh QR');
        const blob = await res.blob();
        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(blobUrl);
    } catch {
        // Cadangan: buka di tab baru jika fetch gagal (mis. kebijakan CORS)
        window.open(table.qr_code_url, '_blank', 'noopener');
    }
};

// --- Cetak QR (semua meja aktif yang tampil, atau satu meja) ---
const escapeHtml = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const printQr = (list: TableItem[]) => {
    const items = list.filter((t) => t.qr_code_url);
    if (!items.length) {
        toastError('Tidak ada QR yang bisa dicetak.');
        return;
    }
    const win = window.open('', '_blank');
    if (!win) {
        toastError('Jendela cetak diblokir browser. Izinkan pop-up lalu coba lagi.');
        return;
    }
    const cards = items
        .map(
            (t) => `<div class="card">
                <div class="name">${escapeHtml(t.name)}</div>
                ${t.location ? `<div class="area">${escapeHtml(t.location)}</div>` : ''}
                <img src="${escapeHtml(t.qr_code_url!)}" alt="QR ${escapeHtml(t.name)}" />
                <div class="hint">Scan untuk lihat menu &amp; pesan</div>
            </div>`,
        )
        .join('');
    win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>QR Meja</title>
        <style>
            *{box-sizing:border-box} body{font-family:system-ui,sans-serif;margin:16px;color:#0f172a}
            .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
            .card{border:1px dashed #94a3b8;border-radius:12px;padding:14px;text-align:center;break-inside:avoid}
            .name{font-size:20px;font-weight:800} .area{font-size:12px;color:#64748b;margin-top:2px}
            img{width:100%;max-width:200px;aspect-ratio:1;margin:10px auto;display:block}
            .hint{font-size:12px;color:#475569}
            @media print{body{margin:0}}
        </style></head><body><div class="grid">${cards}</div>
        <script>window.onload=function(){setTimeout(function(){window.print()},300)}<\/script></body></html>`);
    win.document.close();
};
const printableCount = computed(() => filteredTables.value.filter((t) => t.is_active && t.qr_code_url).length);
</script>

<template>
    <AdminLayout title="Manajemen Meja">
        <Head title="Manajemen Meja" />

        <div class="mx-auto space-y-5 pb-8" style="max-width: 90rem">
            <!-- Kepala halaman -->
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Meja & QR menu digital</h2>
                    <p class="text-sm text-slate-500">
                        Setiap meja punya QR sendiri. Pelanggan scan QR, memesan dari HP, lalu pesanan masuk ke kasir.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        class="gap-1.5 rounded-xl"
                        :disabled="printableCount === 0"
                        title="Cetak QR semua meja aktif yang sedang tampil"
                        @click="printQr(filteredTables.filter((t) => t.is_active))"
                    >
                        <Printer class="h-4 w-4" /> Cetak QR ({{ printableCount }})
                    </Button>
                    <Button class="gap-1.5 rounded-xl" @click="openCreate"><Plus class="h-4 w-4" /> Tambah meja</Button>
                </div>
            </div>

            <!-- Statistik -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="s in statCards" :key="s.label" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="s.tone">
                        <component :is="s.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-slate-500">{{ s.label }}</p>
                        <p class="text-2xl font-black text-slate-900 tabular-nums">{{ s.value }}</p>
                    </div>
                </div>
            </div>

            <!-- Kosong total -->
            <div v-if="tables.length === 0" class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                    <QrCode class="h-7 w-7 text-primary" />
                </div>
                <p class="mt-3 font-bold text-slate-900">Belum ada meja</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Tambahkan meja pertama Anda. QR code dibuat otomatis, tinggal dicetak dan ditempel di meja.
                </p>
                <div class="mx-auto mt-5 grid max-w-2xl gap-3 text-left sm:grid-cols-3">
                    <div
                        v-for="(step, i) in ['Tambah meja dan beri nama', 'Cetak QR lalu tempel di meja', 'Pesanan masuk di menu Pesanan Masuk']"
                        :key="i"
                        class="rounded-xl bg-slate-50 p-3"
                    >
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">{{ i + 1 }}</span>
                        <p class="mt-2 text-sm font-medium text-slate-700">{{ step }}</p>
                    </div>
                </div>
                <Button class="mt-5 gap-1.5 rounded-xl" @click="openCreate"><Plus class="h-4 w-4" /> Tambah meja pertama</Button>
            </div>

            <template v-else>
                <!-- Alat cari & filter -->
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm lg:flex-row lg:items-center">
                    <div class="relative lg:w-72">
                        <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <Input v-model="search" placeholder="Cari nama, kode, area…" class="h-9 rounded-xl pl-9" />
                    </div>
                    <div class="flex gap-1 rounded-xl bg-slate-100/70 p-1">
                        <button
                            v-for="s in [
                                { key: 'all', label: 'Semua' },
                                { key: 'active', label: 'Aktif' },
                                { key: 'inactive', label: 'Nonaktif' },
                            ] as const"
                            :key="s.key"
                            type="button"
                            class="flex-1 rounded-lg px-3 py-1 text-sm font-semibold whitespace-nowrap transition"
                            :class="statusFilter === s.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                            @click="statusFilter = s.key"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                    <div v-if="areas.length" class="flex flex-wrap gap-2 lg:ml-auto">
                        <button
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                            :class="
                                !areaFilter ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200 text-slate-600 hover:border-slate-300'
                            "
                            @click="areaFilter = null"
                        >
                            Semua area
                        </button>
                        <button
                            v-for="a in areas"
                            :key="a"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                            :class="
                                areaFilter === a
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-slate-200 text-slate-600 hover:border-slate-300'
                            "
                            @click="areaFilter = a"
                        >
                            {{ a }}
                        </button>
                    </div>
                </div>

                <!-- Daftar meja -->
                <div v-if="filteredTables.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    <div
                        v-for="table in filteredTables"
                        :key="table.id"
                        class="flex flex-col rounded-2xl border bg-white p-4 shadow-sm transition hover:shadow-md"
                        :class="table.is_active ? 'border-slate-200' : 'border-dashed border-slate-300 bg-slate-50/60'"
                    >
                        <div class="flex items-start gap-3">
                            <!-- QR kecil: klik untuk memperbesar -->
                            <button
                                type="button"
                                class="shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:ring-2 hover:ring-primary/30"
                                :class="{ 'opacity-50': !table.is_active }"
                                title="Lihat QR"
                                @click="openQrModal(table)"
                            >
                                <img v-if="table.qr_code_url" :src="qrSrc(table.qr_code_url)!" alt="QR Code" class="h-16 w-16 object-cover" />
                                <div v-else class="flex h-16 w-16 items-center justify-center bg-slate-50">
                                    <QrCode class="h-6 w-6 text-slate-300" />
                                </div>
                            </button>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="truncate text-base font-bold text-slate-900">{{ table.name }}</h3>
                                    <!-- Saklar aktif/nonaktif -->
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="table.is_active"
                                        :title="table.is_active ? 'Aktif — klik untuk menonaktifkan' : 'Nonaktif — klik untuk mengaktifkan'"
                                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                                        :class="table.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                                        @click="toggleActive(table)"
                                    >
                                        <span
                                            class="inline-block h-5 w-5 rounded-full bg-white shadow transition"
                                            :class="table.is_active ? 'translate-x-5' : 'translate-x-0.5'"
                                        />
                                    </button>
                                </div>
                                <p class="mt-0.5 text-xs" :class="table.is_active ? 'text-emerald-600' : 'text-slate-500'">
                                    {{ table.is_active ? 'Menerima pesanan' : 'Tidak menerima pesanan' }}
                                    <span v-if="table.code" class="ml-1 rounded bg-slate-100 px-1.5 font-mono text-slate-600">{{ table.code }}</span>
                                </p>
                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                    <span v-if="table.location" class="inline-flex items-center gap-1"
                                        ><MapPin class="h-3.5 w-3.5" /> {{ table.location }}</span
                                    >
                                    <span v-if="table.capacity" class="inline-flex items-center gap-1"
                                        ><Users class="h-3.5 w-3.5" /> {{ table.capacity }} orang</span
                                    >
                                </div>
                            </div>
                        </div>

                        <p v-if="table.notes" class="mt-3 flex items-start gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600">
                            <StickyNote class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" /> {{ table.notes }}
                        </p>

                        <div class="mt-auto flex items-center gap-1.5 border-t border-slate-100 pt-3" :class="table.notes ? '' : 'mt-3'">
                            <Button variant="outline" size="sm" class="h-8 flex-1 gap-1.5 rounded-lg text-xs" @click="openQrModal(table)">
                                <QrCode class="h-3.5 w-3.5" /> Lihat QR
                            </Button>
                            <Button variant="outline" size="sm" class="h-8 flex-1 gap-1.5 rounded-lg text-xs" @click="copyGuestUrl(table.guest_url)">
                                <Copy class="h-3.5 w-3.5" /> Salin link
                            </Button>
                            <Button variant="ghost" size="sm" class="h-8 w-8 rounded-lg p-0" title="Ubah meja" @click="openEdit(table)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-8 w-8 rounded-lg p-0 text-red-600 hover:bg-red-50"
                                title="Hapus meja"
                                @click="deleteTable(table)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Tidak ada hasil filter -->
                <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <p class="font-bold text-slate-900">Tidak ada meja yang cocok</p>
                    <p class="mt-1 text-sm text-slate-500">Coba ubah kata kunci atau filter.</p>
                    <Button v-if="hasFilter" variant="outline" size="sm" class="mt-4 rounded-xl" @click="resetFilter">Tampilkan semua meja</Button>
                </div>

                <!-- Petunjuk alur -->
                <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-4 text-sm text-slate-700">
                    <ClipboardList class="h-5 w-5 shrink-0 text-primary" />
                    <p class="flex-1">
                        Pesanan dari scan meja masuk ke menu <b>Pesanan Masuk</b>. Di sana kasir menerima, mencatat pembayaran, dan menyelesaikan
                        pesanan.
                    </p>
                    <Link href="/table-orders">
                        <Button variant="outline" size="sm" class="rounded-xl bg-white">Buka Pesanan Masuk</Button>
                    </Link>
                </div>
            </template>
        </div>

        <!-- Dialog tambah/ubah meja -->
        <Dialog v-model:open="showForm">
            <DialogContent class="sm:max-w-[480px]">
                <DialogHeader>
                    <DialogTitle>{{ editingTable ? 'Ubah meja' : 'Tambah meja' }}</DialogTitle>
                    <DialogDescription>
                        {{ editingTable ? 'Perubahan tidak mengubah QR yang sudah dicetak.' : 'QR code dibuat otomatis setelah meja disimpan.' }}
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitForm">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="name">Nama meja *</Label>
                            <Input id="name" v-model="form.name" placeholder="Meja 1, VIP A, Outdoor B" class="h-10" required />
                            <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="code">Kode</Label>
                            <Input id="code" v-model="form.code" placeholder="T01" class="h-10" />
                            <p v-if="form.errors.code" class="text-sm text-red-600">{{ form.errors.code }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="location">Area</Label>
                            <Input id="location" v-model="form.location" list="table-areas" placeholder="Indoor, Outdoor, Lantai 2" class="h-10" />
                            <datalist id="table-areas">
                                <option v-for="a in areas" :key="a" :value="a" />
                            </datalist>
                            <p v-if="form.errors.location" class="text-sm text-red-600">{{ form.errors.location }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="capacity">Kapasitas</Label>
                            <div class="relative">
                                <Input id="capacity" v-model="form.capacity" type="number" min="1" max="99" placeholder="4" class="h-10 pr-16" />
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-slate-500">orang</span>
                            </div>
                            <p v-if="form.errors.capacity" class="text-sm text-red-600">{{ form.errors.capacity }}</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <Label for="notes">Catatan</Label>
                        <Input id="notes" v-model="form.notes" placeholder="Opsional, misal: dekat jendela" class="h-10" />
                        <p v-if="form.errors.notes" class="text-sm text-red-600">{{ form.errors.notes }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showForm = false">Batal</Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Menyimpan…' : editingTable ? 'Simpan perubahan' : 'Tambah meja' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Dialog QR -->
        <Dialog v-model:open="showQrModal">
            <DialogContent v-if="qrModalTable" class="sm:max-w-[400px]">
                <DialogHeader>
                    <DialogTitle>QR {{ qrModalTable.name }}</DialogTitle>
                    <DialogDescription>Cetak dan tempel di meja. Pelanggan scan untuk melihat menu dan memesan.</DialogDescription>
                </DialogHeader>

                <div class="flex flex-col items-center">
                    <div class="rounded-2xl border border-slate-200 bg-white p-3">
                        <img v-if="qrModalTable.qr_code_url" :src="qrSrc(qrModalTable.qr_code_url)!" alt="QR Code" class="h-52 w-52 object-contain" />
                        <div
                            v-else
                            class="flex h-52 w-52 flex-col items-center justify-center gap-2 rounded-xl bg-slate-50 text-center text-xs text-slate-500"
                        >
                            <QrCode class="h-10 w-10 text-slate-300" />
                            Gambar QR belum tersedia.
                        </div>
                    </div>
                    <p v-if="!qrModalTable.is_active" class="mt-3 rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700">
                        Meja ini nonaktif — QR belum bisa dipakai memesan.
                    </p>
                </div>

                <div class="flex items-center gap-2 rounded-xl bg-slate-50 p-2">
                    <code class="flex-1 truncate text-xs text-slate-600">{{ qrModalTable.guest_url }}</code>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 w-8 shrink-0 rounded-lg p-0"
                        title="Salin link"
                        @click="copyGuestUrl(qrModalTable.guest_url)"
                    >
                        <Copy class="h-3.5 w-3.5" />
                    </Button>
                    <a :href="qrModalTable.guest_url" target="_blank" rel="noopener" title="Buka menu meja">
                        <Button variant="outline" size="sm" class="h-8 w-8 shrink-0 rounded-lg p-0"><ExternalLink class="h-3.5 w-3.5" /></Button>
                    </a>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <Button variant="outline" class="gap-1.5 rounded-xl" :disabled="!qrModalTable.qr_code_url" @click="downloadQr(qrModalTable)">
                        <Download class="h-4 w-4" /> Unduh
                    </Button>
                    <Button class="gap-1.5 rounded-xl" :disabled="!qrModalTable.qr_code_url" @click="printQr([qrModalTable])">
                        <Printer class="h-4 w-4" /> Cetak
                    </Button>
                </div>

                <button
                    type="button"
                    class="mx-auto flex items-center gap-1.5 text-xs font-medium text-slate-500 hover:text-primary disabled:opacity-50"
                    :disabled="regenerating"
                    title="Link meja tetap sama, jadi QR yang sudah ditempel tetap berlaku"
                    @click="regenerateQr(qrModalTable)"
                >
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': regenerating }" />
                    {{ qrModalTable.qr_code_url ? 'Buat ulang gambar QR (link tetap sama)' : 'Buat gambar QR' }}
                </button>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
