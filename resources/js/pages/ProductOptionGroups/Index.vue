<script setup lang="ts">
import OptionGroupDialog from '@/components/products/OptionGroupDialog.vue';
import type { OptionGroup } from '@/types/optionGroups';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { confirmDelete, toastSuccess } from '@/lib/swal';
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, ListChecks, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    groups: OptionGroup[];
    store: { id: number; name: string };
}>();

const dialogOpen = ref(false);
const editingGroup = ref<OptionGroup | null>(null);

function openCreate() {
    editingGroup.value = null;
    dialogOpen.value = true;
}

function openEdit(group: OptionGroup) {
    editingGroup.value = group;
    dialogOpen.value = true;
}

function onSaved() {
    void toastSuccess('Grup pilihan tersimpan.');
    router.reload({ only: ['groups'] });
}

async function destroyGroup(group: OptionGroup) {
    const used = group.products_count ?? 0;
    const text =
        used > 0
            ? `Grup "${group.name}" dipakai ${used} produk. Pilihan ini tidak akan muncul lagi di kasir untuk produk tersebut.`
            : `Grup "${group.name}" akan dihapus.`;
    if (!(await confirmDelete('Hapus grup pilihan?', text))) return;
    router.delete(`/product-option-groups/${group.id}`, { preserveScroll: true });
}

function priceLabel(price: number): string {
    return Number(price) > 0 ? `+Rp ${Number(price).toLocaleString('id-ID')}` : 'Gratis';
}
</script>

<template>
    <Head title="Pilihan Tambahan" />

    <AdminLayout title="Pilihan Tambahan">
        <template #header-actions>
            <Button as="a" href="/products" variant="outline" size="sm" class="inline-flex items-center gap-2">
                <ArrowLeft class="h-4 w-4" />
                Produk
            </Button>
        </template>

        <div class="mx-auto space-y-6 pb-8" style="max-width: 90rem">
            <Card class="rounded-2xl border-slate-200 shadow-sm">
                <CardHeader>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                                <ListChecks class="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <CardTitle>Grup pilihan tambahan</CardTitle>
                                <CardDescription>
                                    Mis. level gula, level es, topping. Buat sekali di sini, lalu centang di form produk yang
                                    memakainya.
                                </CardDescription>
                            </div>
                        </div>
                        <Button type="button" class="shrink-0" @click="openCreate">
                            <Plus class="mr-1 h-4 w-4" />
                            Buat grup
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <p v-if="groups.length === 0" class="py-8 text-center text-sm text-slate-500">
                        Belum ada grup pilihan. Klik "Buat grup" — tersedia contoh siap pakai.
                    </p>
                    <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="g in groups" :key="g.id" class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-slate-900">{{ g.name }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                            {{ g.selection === 'single' ? 'Pilih satu' : 'Boleh banyak' }}
                                        </span>
                                        <span
                                            v-if="g.is_required"
                                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800"
                                        >
                                            Wajib
                                        </span>
                                        <span class="rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-medium text-teal-800">
                                            {{ g.products_count ?? 0 }} produk
                                        </span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 w-8 p-0"
                                        :aria-label="`Ubah grup ${g.name}`"
                                        @click="openEdit(g)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 w-8 p-0 text-red-600 hover:text-red-700"
                                        :aria-label="`Hapus grup ${g.name}`"
                                        @click="destroyGroup(g)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="o in g.options" :key="o.key" class="flex justify-between gap-2 text-slate-700">
                                    <span class="truncate">{{ o.label }}</span>
                                    <span class="shrink-0 text-xs text-slate-500">{{ priceLabel(o.price) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <OptionGroupDialog v-model:open="dialogOpen" :group="editingGroup" @saved="onSaved" />
    </AdminLayout>
</template>
