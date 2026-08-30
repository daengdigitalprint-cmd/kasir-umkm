<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toastSuccess, toastError } from '@/lib/swal';
import { Plus, QrCode, Pencil, Trash2, Power, PowerOff, Copy, Users, MapPin, ExternalLink, Download } from 'lucide-vue-next';
import { ref, computed } from 'vue';

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

const showForm = ref(false);
const editingTable = ref<TableItem | null>(null);
const showQrModal = ref(false);
const qrModalTable = ref<TableItem | null>(null);

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
    showForm.value = true;
};

const openEdit = (table: TableItem) => {
    editingTable.value = table;
    form.name = table.name;
    form.code = table.code || '';
    form.capacity = table.capacity || '';
    form.location = table.location || '';
    form.notes = table.notes || '';
    showForm.value = true;
};

const closeForm = () => {
    showForm.value = false;
    editingTable.value = null;
};

const submitForm = () => {
    if (editingTable.value) {
        form.patch(`/tables/${editingTable.value.id}`, {
            onSuccess: () => {
                closeForm();
                toastSuccess('Meja berhasil diperbarui!');
            },
            onError: () => toastError('Gagal memperbarui meja.'),
        });
    } else {
        form.post('/tables', {
            onSuccess: () => {
                closeForm();
                toastSuccess('Meja berhasil ditambahkan!');
            },
            onError: () => toastError('Gagal menambahkan meja.'),
        });
    }
};

const toggleActive = (table: TableItem) => {
    router.patch(`/tables/${table.id}/toggle`, {}, {
        onSuccess: () => toastSuccess(table.is_active ? 'Meja dinonaktifkan.' : 'Meja diaktifkan.'),
        onError: () => toastError('Gagal mengubah status meja.'),
    });
};

const deleteTable = (table: TableItem) => {
    if (confirm(`Hapus meja "${table.name}"? Data tidak dapat dikembalikan.`)) {
        router.delete(`/tables/${table.id}`, {
            onSuccess: () => toastSuccess('Meja berhasil dihapus.'),
            onError: () => toastError('Gagal menghapus meja.'),
        });
    }
};

const regenerateQr = (table: TableItem) => {
    router.post(`/tables/${table.id}/qr`, {}, {
        onSuccess: () => toastSuccess('QR Code berhasil diperbarui!'),
        onError: () => toastError('Gagal generate QR Code.'),
    });
};

const openQrModal = (table: TableItem) => {
    qrModalTable.value = table;
    showQrModal.value = true;
};

const copyGuestUrl = (url: string) => {
    navigator.clipboard.writeText(url).then(() => {
        toastSuccess('URL berhasil disalin!');
    }).catch(() => toastError('Gagal menyalin URL.'));
};

const downloadQr = async (table: TableItem) => {
    if (!table.qr_code_url) return;
    const fileName = `qr-meja-${table.name.replace(/\s+/g, '-').toLowerCase()}.png`;
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
        // Fallback: buka di tab baru jika fetch gagal (mis. kebijakan CORS)
        const link = document.createElement('a');
        link.href = table.qr_code_url!;
        link.download = fileName;
        link.target = '_blank';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
};

const activeCount = computed(() => props.tables.filter((t) => t.is_active).length);
const inactiveCount = computed(() => props.tables.filter((t) => !t.is_active).length);
</script>

<template>
    <AdminLayout title="Manajemen Meja">
        <Head title="Manajemen Meja" />

        <div class="p-4 md:p-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-xl font-black text-slate-900">Manajemen Meja</h1>
                    <p class="text-sm text-slate-500 mt-0.5">
                        Kelola meja dan QR Code untuk menu digital pelanggan
                    </p>
                </div>
                <Button @click="openCreate" class="gap-1.5 rounded-xl">
                    <Plus class="h-4 w-4" />
                    Tambah Meja
                </Button>
            </div>

            <!-- Stat Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
                <div class="rounded-2xl border border-slate-100 bg-white p-4">
                    <p class="text-[11px] font-bold text-slate-500">Total Meja</p>
                    <p class="text-2xl font-black text-slate-900">{{ props.tables.length }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-4">
                    <p class="text-[11px] font-bold text-emerald-600">Aktif</p>
                    <p class="text-2xl font-black text-emerald-700">{{ activeCount }}</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                    <p class="text-[11px] font-bold text-slate-500">Nonaktif</p>
                    <p class="text-2xl font-black text-slate-400">{{ inactiveCount }}</p>
                </div>
            </div>

            <!-- Table List -->
            <div v-if="props.tables.length === 0" class="text-center py-16">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                    <QrCode class="h-8 w-8 text-violet-500" />
                </div>
                <p class="text-sm font-bold text-slate-500">Belum ada meja</p>
                <p class="text-xs text-slate-400 mt-1">Klik "Tambah Meja" untuk mulai</p>
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="table in props.tables"
                    :key="table.id"
                    :class="[
                        'rounded-2xl border bg-white p-4 transition-shadow hover:shadow-md',
                        table.is_active ? 'border-slate-100' : 'border-slate-200 opacity-60',
                    ]"
                >
                    <!-- Card Header -->
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-black text-slate-900 truncate">{{ table.name }}</h3>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-xs text-slate-400 font-mono">{{ table.code || '-' }}</span>
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold',
                                        table.is_active
                                            ? 'bg-emerald-50 text-emerald-600'
                                            : 'bg-slate-100 text-slate-500',
                                    ]"
                                >
                                    <span :class="['h-1.5 w-1.5 rounded-full', table.is_active ? 'bg-emerald-500' : 'bg-slate-400']" />
                                    {{ table.is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </div>
                        </div>

                        <!-- QR Code Thumbnail -->
                        <button
                            v-if="table.qr_code_url"
                            @click="openQrModal(table)"
                            class="shrink-0 ml-2 rounded-xl border border-slate-100 overflow-hidden hover:ring-2 hover:ring-violet-200 transition"
                        >
                            <img :src="table.qr_code_url" alt="QR Code" class="h-14 w-14 object-cover" />
                        </button>
                        <div
                            v-else
                            class="shrink-0 ml-2 flex h-14 w-14 items-center justify-center rounded-xl bg-slate-50"
                        >
                            <QrCode class="h-6 w-6 text-slate-300" />
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex flex-wrap gap-2 mb-3">
                        <span v-if="table.capacity" class="inline-flex items-center gap-1 text-xs text-slate-500">
                            <Users class="h-3 w-3" /> {{ table.capacity }} orang
                        </span>
                        <span v-if="table.location" class="inline-flex items-center gap-1 text-xs text-slate-500">
                            <MapPin class="h-3 w-3" /> {{ table.location }}
                        </span>
                    </div>

                    <!-- Actions -->
                    <div class="grid grid-cols-2 gap-2 border-t border-slate-50 pt-3 sm:grid-cols-4">
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-w-0 w-full gap-1 rounded-lg text-xs h-8"
                            @click="openEdit(table)"
                        >
                            <Pencil class="h-3 w-3" />
                            Edit
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-w-0 w-full gap-1 rounded-lg text-xs h-8"
                            @click="regenerateQr(table)"
                        >
                            <QrCode class="h-3 w-3" />
                            QR
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-w-0 w-full gap-1 rounded-lg text-xs h-8"
                            @click="copyGuestUrl(table.guest_url)"
                        >
                            <Copy class="h-3 w-3" />
                            URL
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-w-0 w-full gap-1 rounded-lg text-xs h-8"
                            @click="toggleActive(table)"
                        >
                            <component :is="table.is_active ? PowerOff : Power" class="h-3 w-3" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-w-0 w-full gap-1 rounded-lg text-xs h-8 text-red-500 hover:bg-red-50 sm:col-span-4"
                            @click="deleteTable(table)"
                        >
                            <Trash2 class="h-3 w-3" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add/Edit Form Modal -->
        <div
            v-if="showForm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="closeForm"
        >
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-black text-slate-900 mb-4">
                    {{ editingTable ? 'Edit Meja' : 'Tambah Meja Baru' }}
                </h2>

                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <Label for="name" class="text-xs font-bold text-slate-600">Nama Meja *</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            placeholder="Meja 1, VIP A, Outdoor B"
                            class="mt-1 rounded-xl"
                            required
                        />
                    </div>
                    <div>
                        <Label for="code" class="text-xs font-bold text-slate-600">Kode (opsional)</Label>
                        <Input
                            id="code"
                            v-model="form.code"
                            placeholder="T01, VIP-A"
                            class="mt-1 rounded-xl"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label for="capacity" class="text-xs font-bold text-slate-600">Kapasitas</Label>
                            <Input
                                id="capacity"
                                v-model="form.capacity"
                                type="number"
                                min="1"
                                max="99"
                                placeholder="4"
                                class="mt-1 rounded-xl"
                            />
                        </div>
                        <div>
                            <Label for="location" class="text-xs font-bold text-slate-600">Area</Label>
                            <Input
                                id="location"
                                v-model="form.location"
                                placeholder="Indoor, Outdoor"
                                class="mt-1 rounded-xl"
                            />
                        </div>
                    </div>
                    <div>
                        <Label for="notes" class="text-xs font-bold text-slate-600">Catatan</Label>
                        <Input
                            id="notes"
                            v-model="form.notes"
                            placeholder="Dekat jendela, dekat AC"
                            class="mt-1 rounded-xl"
                        />
                    </div>

                    <div class="flex gap-2 pt-2">
                        <Button type="button" variant="outline" class="flex-1 rounded-xl" @click="closeForm">
                            Batal
                        </Button>
                        <Button type="submit" class="flex-1 rounded-xl" :disabled="form.processing">
                            {{ editingTable ? 'Simpan' : 'Tambah' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- QR Code Modal -->
        <div
            v-if="showQrModal && qrModalTable"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="showQrModal = false"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl text-center">
                <h2 class="text-lg font-black text-slate-900 mb-1">{{ qrModalTable.name }}</h2>
                <p class="text-xs text-slate-500 mb-4">Scan QR untuk lihat menu digital</p>

                <div class="bg-white rounded-xl border border-slate-100 p-4 inline-block mb-4">
                    <img
                        v-if="qrModalTable.qr_code_url"
                        :src="qrModalTable.qr_code_url"
                        alt="QR Code"
                        class="h-48 w-48 object-contain"
                    />
                    <div v-else class="h-48 w-48 flex items-center justify-center bg-slate-50 rounded-xl">
                        <QrCode class="h-12 w-12 text-slate-300" />
                    </div>
                    <Button
                        v-if="qrModalTable.qr_code_url"
                        variant="outline"
                        size="sm"
                        class="mt-3 w-full rounded-lg gap-1"
                        @click="downloadQr(qrModalTable)"
                    >
                        <Download class="h-3.5 w-3.5" />
                        Download QR
                    </Button>
                </div>

                <div class="flex items-center gap-2 bg-slate-50 rounded-xl p-2 mb-3">
                    <code class="flex-1 text-xs text-slate-600 truncate">{{ qrModalTable.guest_url }}</code>
                    <Button variant="outline" size="sm" class="rounded-lg shrink-0" @click="copyGuestUrl(qrModalTable.guest_url)">
                        <Copy class="h-3 w-3" />
                    </Button>
                </div>

                <div class="flex gap-2">
                    <Button variant="outline" class="flex-1 rounded-xl" @click="showQrModal = false">
                        Tutup
                    </Button>
                    <Button variant="outline" class="flex-1 rounded-xl gap-1" @click="regenerateQr(qrModalTable); showQrModal = false;">
                        <QrCode class="h-3.5 w-3.5" />
                        Regenerate
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>