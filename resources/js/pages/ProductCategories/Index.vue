<script setup lang="ts">
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ArrowLeft, Layers, Trash2 } from 'lucide-vue-next';
import { confirmDelete } from '@/lib/swal';
import { ref, watch } from 'vue';

interface ProductCategoryRow {
    id: number;
    name: string;
    sort_order: number;
    products_count: number;
}

const props = defineProps<{
    categories: ProductCategoryRow[];
    store: { id: number; name: string };
}>();

const addForm = useForm({
    name: '',
    sort_order: 0,
});

const submitAdd = () => {
    addForm.post('/product-categories', {
        preserveScroll: true,
        onSuccess: () => addForm.reset('name', 'sort_order'),
    });
};

type RowEdit = { name: string; sort_order: number };
const rowEdits = ref<Record<number, RowEdit>>({});

watch(
    () => props.categories,
    (list) => {
        const next: Record<number, RowEdit> = {};
        for (const c of list) {
            next[c.id] = { name: c.name, sort_order: c.sort_order };
        }
        rowEdits.value = next;
    },
    { immediate: true },
);

const saveRow = (id: number) => {
    const r = rowEdits.value[id];
    if (!r) return;
    router.put(
        `/product-categories/${id}`,
        { name: r.name.trim(), sort_order: Number(r.sort_order) || 0 },
        { preserveScroll: true },
    );
};

const destroyRow = async (c: ProductCategoryRow) => {
    if (
        !(await confirmDelete(
            'Hapus kategori?',
            c.products_count > 0
                ? `${c.products_count} produk akan kehilangan kategori (bisa dipilih lagi nanti).`
                : `Kategori "${c.name}" akan dihapus.`,
        ))
    ) {
        return;
    }
    router.delete(`/product-categories/${c.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Kategori Produk" />

    <AdminLayout title="Kategori Produk">
        <template #header-actions>
            <div class="flex flex-wrap items-center gap-2">
                <Button as="a" href="/products" variant="outline" size="sm" class="inline-flex items-center gap-2">
                    <ArrowLeft class="h-4 w-4" />
                    Produk
                </Button>
            </div>
        </template>

        <div class="mx-auto space-y-8 pb-8" style="max-width: 90rem">
            <p class="text-sm text-slate-600 -mt-1">
                Toko: <span class="font-semibold text-slate-900">{{ store.name }}</span>
            </p>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                            <Layers class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle>Tambah kategori</CardTitle>
                            <CardDescription>
                                Kategori dipakai di form produk. Nama disimpan juga di kolom kategori produk untuk laporan &
                                POS.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <form class="flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="submitAdd">
                        <div class="flex-1 space-y-2">
                            <Label for="new_name">Nama kategori *</Label>
                            <Input
                                id="new_name"
                                v-model="addForm.name"
                                required
                                maxlength="100"
                                placeholder="Contoh: Sembako, Snack"
                                class="h-10"
                            />
                            <p v-if="addForm.errors.name" class="text-sm text-red-600">{{ addForm.errors.name }}</p>
                        </div>
                        <div class="w-full space-y-2 sm:w-28">
                            <Label for="new_sort">Urutan</Label>
                            <Input
                                id="new_sort"
                                v-model.number="addForm.sort_order"
                                type="number"
                                min="0"
                                class="h-10"
                            />
                            <p v-if="addForm.errors.sort_order" class="text-sm text-red-600">
                                {{ addForm.errors.sort_order }}
                            </p>
                        </div>
                        <Button type="submit" class="h-10 shrink-0" :disabled="addForm.processing">Simpan</Button>
                    </form>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle>Daftar kategori</CardTitle>
                    <CardDescription>
                        Ubah nama atau urutan tampil, lalu klik Simpan per baris. Lihat juga
                        <Link href="/products/create" class="text-primary underline-offset-2 hover:underline">tambah produk</Link>.
                    </CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="pb-3 pr-4 font-medium">Nama</th>
                                <th class="pb-3 pr-4 font-medium w-28">Urutan</th>
                                <th class="pb-3 pr-4 font-medium w-24">Produk</th>
                                <th class="pb-3 font-medium w-40 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in categories" :key="c.id" class="border-b border-slate-100 align-top">
                                <td class="py-3 pr-4">
                                    <Input
                                        v-if="rowEdits[c.id]"
                                        v-model="rowEdits[c.id].name"
                                        maxlength="100"
                                        class="h-9"
                                    />
                                </td>
                                <td class="py-3 pr-4">
                                    <Input
                                        v-if="rowEdits[c.id]"
                                        v-model.number="rowEdits[c.id].sort_order"
                                        type="number"
                                        min="0"
                                        class="h-9 w-24"
                                    />
                                </td>
                                <td class="py-3 pr-4 text-slate-600">{{ c.products_count }}</td>
                                <td class="py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button type="button" variant="secondary" size="sm" class="h-8" @click="saveRow(c.id)">
                                            Simpan
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-8 text-red-600 hover:text-red-700"
                                            @click="destroyRow(c)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="categories.length === 0">
                                <td colspan="4" class="py-8 text-center text-slate-500">
                                    Belum ada kategori. Tambahkan di atas — lalu pilih saat membuat produk.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
