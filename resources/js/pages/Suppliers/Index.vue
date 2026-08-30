<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Building2, Plus, Pencil, Trash2, Phone, Mail, MapPin } from 'lucide-vue-next';
import { ref } from 'vue';
import { confirmDelete, toastSuccess } from '@/lib/swal';

interface SupplierRow {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
    purchases_count: number;
}

const props = defineProps<{
    suppliers: SupplierRow[];
    store: { id: number; name: string; currency: string };
}>();

const showDialog = ref(false);
const editingSupplier = ref<SupplierRow | null>(null);

const form = useForm({
    name: '',
    phone: '',
    email: '',
    address: '',
    notes: '',
});

function openCreateDialog() {
    editingSupplier.value = null;
    form.reset();
    showDialog.value = true;
}

function openEditDialog(supplier: SupplierRow) {
    editingSupplier.value = supplier;
    form.name = supplier.name;
    form.phone = supplier.phone || '';
    form.email = supplier.email || '';
    form.address = supplier.address || '';
    form.notes = supplier.notes || '';
    showDialog.value = true;
}

function submitForm() {
    if (editingSupplier.value) {
        form.put(`/suppliers/${editingSupplier.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                void toastSuccess('Supplier berhasil diperbarui');
            },
        });
    } else {
        form.post('/suppliers', {
            preserveScroll: true,
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
                void toastSuccess('Supplier berhasil ditambahkan');
            },
        });
    }
}

async function deleteSupplier(supplier: SupplierRow) {
    const confirmed = await confirmDelete(
        'Hapus supplier?',
        `Supplier "${supplier.name}" akan dihapus${supplier.purchases_count > 0 ? '. Supplier ini memiliki ' + supplier.purchases_count + ' riwayat pembelian dan tidak dapat dihapus' : ''}.`,
    );
    if (!confirmed) return;

    form.delete(`/suppliers/${supplier.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            void toastSuccess('Supplier berhasil dihapus');
        },
    });
}
</script>

<template>
    <Head title="Kelola Supplier" />

    <AdminLayout title="Kelola Supplier">
        <div class="max-w-5xl mx-auto space-y-6 pb-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Kelola Supplier</h1>
                    <p class="text-sm text-slate-600 mt-1">
                        Daftar supplier untuk pembelian barang dan stok masuk.
                    </p>
                </div>
                <Button @click="openCreateDialog" class="rounded-xl">
                    <Plus class="h-4 w-4 mr-2" />
                    Tambah Supplier
                </Button>
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10">
                            <Building2 class="h-5 w-5 text-blue-700" />
                        </div>
                        <div>
                            <CardTitle>Daftar Supplier</CardTitle>
                            <CardDescription>{{ suppliers.length }} supplier terdaftar</CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="suppliers.length === 0" class="text-center py-12 text-slate-500">
                        <p>Belum ada supplier.</p>
                        <Button @click="openCreateDialog" variant="outline" class="mt-4 rounded-xl">
                            Tambah supplier pertama
                        </Button>
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="supplier in suppliers"
                            :key="supplier.id"
                            class="p-4 border border-slate-200 rounded-xl hover:bg-slate-50/80 transition-colors"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <h3 class="font-bold text-slate-900 text-lg">{{ supplier.name }}</h3>
                                    <div class="mt-2 space-y-1 text-sm text-slate-600">
                                        <div v-if="supplier.phone" class="flex items-center gap-2">
                                            <Phone class="h-4 w-4" />
                                            <span>{{ supplier.phone }}</span>
                                        </div>
                                        <div v-if="supplier.email" class="flex items-center gap-2">
                                            <Mail class="h-4 w-4" />
                                            <span>{{ supplier.email }}</span>
                                        </div>
                                        <div v-if="supplier.address" class="flex items-center gap-2">
                                            <MapPin class="h-4 w-4" />
                                            <span>{{ supplier.address }}</span>
                                        </div>
                                        <div v-if="supplier.notes" class="mt-2 text-slate-500 italic">
                                            {{ supplier.notes }}
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-slate-500">
                                        {{ supplier.purchases_count }} pembelian
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <Button
                                        @click="openEditDialog(supplier)"
                                        variant="outline"
                                        size="sm"
                                        class="rounded-lg"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        @click="deleteSupplier(supplier)"
                                        variant="destructive"
                                        size="sm"
                                        class="rounded-lg"
                                        :disabled="supplier.purchases_count > 0"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Dialog Tambah/Edit -->
        <Dialog v-model:open="showDialog">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editingSupplier ? 'Edit Supplier' : 'Tambah Supplier' }}</DialogTitle>
                    <DialogDescription>
                        {{ editingSupplier ? 'Perbarui informasi supplier' : 'Isi data supplier baru' }}
                    </DialogDescription>
                </DialogHeader>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <Label for="name">Nama Supplier <span class="text-red-500">*</span></Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="PT. Supplier Indonesia"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label for="phone">Telepon</Label>
                        <Input
                            id="phone"
                            v-model="form.phone"
                            type="text"
                            placeholder="08123456789"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="supplier@example.com"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label for="address">Alamat</Label>
                        <Textarea
                            id="address"
                            v-model="form.address"
                            placeholder="Jl. Contoh No. 123"
                            class="mt-1"
                            rows="2"
                        />
                    </div>
                    <div>
                        <Label for="notes">Catatan</Label>
                        <Textarea
                            id="notes"
                            v-model="form.notes"
                            placeholder="Catatan tambahan tentang supplier"
                            class="mt-1"
                            rows="2"
                        />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showDialog = false">Batal</Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
