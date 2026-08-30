<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ArrowLeft, EyeOff } from 'lucide-vue-next';

const props = defineProps<{
    categories: Array<{ id: number; name: string }>;
    countableTotal: number;
}>();

const form = useForm({
    notes: '',
    blind_count: true,
    category_ids: [] as number[],
});

function toggleCategory(id: number) {
    const i = form.category_ids.indexOf(id);
    if (i === -1) form.category_ids.push(id);
    else form.category_ids.splice(i, 1);
}

function submit() {
    form.post('/stock-opnames');
}
</script>

<template>
    <Head title="Mulai stock opname" />

    <AdminLayout title="Mulai stock opname">
        <div class="mx-auto space-y-6 pb-8" style="max-width: 60rem">
            <div class="flex items-center gap-3">
                <Button as-child variant="ghost" size="icon" class="rounded-xl">
                    <Link href="/stock-opnames"><ArrowLeft class="h-4 w-4" /></Link>
                </Button>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">Mulai stock opname</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Stok sistem dibekukan saat sesi dibuka. Penjualan yang terjadi setelahnya tetap terhitung benar.
                    </p>
                </div>
            </div>

            <div v-if="form.errors.category_ids" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                {{ form.errors.category_ids }}
            </div>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Cakupan hitung</CardTitle>
                    <CardDescription>
                        Kosongkan pilihan kategori untuk menghitung seluruh produk ({{ props.countableTotal }} produk).
                        Menghitung sebagian kategori lebih mudah diselesaikan.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="props.categories.length === 0" class="text-sm text-slate-500">
                        Belum ada kategori. Seluruh produk akan dihitung.
                    </div>
                    <div v-else class="flex flex-wrap gap-2">
                        <button
                            v-for="c in props.categories"
                            :key="c.id"
                            type="button"
                            class="rounded-full border px-4 py-1.5 text-sm font-semibold transition"
                            :class="
                                form.category_ids.includes(c.id)
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                            "
                            @click="toggleCategory(c.id)"
                        >
                            {{ c.name }}
                        </button>
                    </div>
                    <p class="text-xs text-slate-500">
                        {{ form.category_ids.length === 0 ? 'Seluruh produk akan dihitung.' : `${form.category_ids.length} kategori dipilih.` }}
                    </p>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <CardTitle class="text-base font-bold">Cara menghitung</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-slate-300">
                        <input v-model="form.blind_count" type="checkbox" class="mt-1 h-4 w-4 rounded" />
                        <span>
                            <span class="flex items-center gap-2 font-semibold text-slate-900">
                                <EyeOff class="h-4 w-4" />
                                Sembunyikan stok sistem saat menghitung
                            </span>
                            <span class="mt-1 block text-sm text-slate-600">
                                Disarankan. Bila petugas melihat angka sistem, ia cenderung menyalin angka itu alih-alih menghitung
                                sungguhan, sehingga selisih yang sebenarnya tidak pernah ketahuan.
                            </span>
                        </span>
                    </label>

                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700">Catatan sesi</label>
                        <input
                            v-model="form.notes"
                            type="text"
                            placeholder="Contoh: opname bulanan Agustus, rak depan"
                            class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-slate-900 focus:outline-none"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="outline" class="rounded-xl">
                    <Link href="/stock-opnames">Batal</Link>
                </Button>
                <Button class="rounded-xl" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Membuka sesi…' : 'Buka sesi opname' }}
                </Button>
            </div>
        </div>
    </AdminLayout>
</template>
