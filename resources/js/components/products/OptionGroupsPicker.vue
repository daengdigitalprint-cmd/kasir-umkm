<script setup lang="ts">
import OptionGroupDialog from '@/components/products/OptionGroupDialog.vue';
import type { OptionGroup } from '@/types/optionGroups';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    /** Grup pilihan milik toko. */
    groups: OptionGroup[];
    /** ID grup yang ditempel ke produk ini. */
    modelValue: number[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>();

// Salinan lokal agar grup yang baru dibuat lewat popup langsung muncul tanpa reload.
const localGroups = ref<OptionGroup[]>([...props.groups]);
const dialogOpen = ref(false);
const editingGroup = ref<OptionGroup | null>(null);

function toggle(id: number) {
    emit(
        'update:modelValue',
        props.modelValue.includes(id) ? props.modelValue.filter((x) => x !== id) : [...props.modelValue, id],
    );
}

function openCreate() {
    editingGroup.value = null;
    dialogOpen.value = true;
}

function openEdit(group: OptionGroup) {
    editingGroup.value = group;
    dialogOpen.value = true;
}

function onSaved(group: OptionGroup, created: boolean) {
    const idx = localGroups.value.findIndex((g) => g.id === group.id);
    if (idx >= 0) {
        localGroups.value[idx] = group;
    } else {
        localGroups.value.push(group);
    }
    // Grup baru dibuat dari form produk ini → langsung dipakai.
    if (created && !props.modelValue.includes(group.id)) {
        emit('update:modelValue', [...props.modelValue, group.id]);
    }
}

function summary(group: OptionGroup): string {
    return group.options
        .map((o) => (Number(o.price) > 0 ? `${o.label} (+${Number(o.price).toLocaleString('id-ID')})` : o.label))
        .join(', ');
}
</script>

<template>
    <Card>
        <CardHeader>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <CardTitle>Pilihan tambahan</CardTitle>
                    <CardDescription>
                        Mis. sedikit gula, tanpa es, pakai topping. Centang grup yang berlaku untuk produk ini — kasir akan
                        ditanya saat produk ditambahkan.
                    </CardDescription>
                </div>
                <Button type="button" variant="outline" size="sm" class="shrink-0" @click="openCreate">
                    <Plus class="mr-1 h-4 w-4" />
                    Buat grup
                </Button>
            </div>
        </CardHeader>
        <CardContent class="space-y-3">
            <p v-if="localGroups.length === 0" class="text-sm text-muted-foreground">
                Belum ada grup pilihan. Klik "Buat grup" — tersedia contoh siap pakai seperti level gula dan topping.
            </p>
            <div v-else class="grid gap-2 md:grid-cols-2">
                <div
                    v-for="g in localGroups"
                    :key="g.id"
                    class="flex items-start gap-3 rounded-lg border p-3"
                    :class="modelValue.includes(g.id) ? 'border-teal-500 bg-teal-50/50' : 'border-slate-200'"
                >
                    <input
                        :id="`option-group-${g.id}`"
                        type="checkbox"
                        class="mt-1 h-4 w-4 rounded border-gray-300"
                        :checked="modelValue.includes(g.id)"
                        @change="toggle(g.id)"
                    />
                    <label :for="`option-group-${g.id}`" class="min-w-0 flex-1 cursor-pointer">
                        <span class="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-slate-900">
                            {{ g.name }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                {{ g.selection === 'single' ? 'Pilih satu' : 'Boleh banyak' }}
                            </span>
                            <span
                                v-if="g.is_required"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800"
                            >
                                Wajib
                            </span>
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ summary(g) }}</span>
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 shrink-0 p-0"
                        :aria-label="`Ubah grup ${g.name}`"
                        @click="openEdit(g)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Mengubah grup berlaku untuk semua produk yang memakainya.
                <Link href="/product-option-groups" class="text-primary underline-offset-2 hover:underline">Kelola semua grup</Link>
            </p>
        </CardContent>

        <OptionGroupDialog v-model:open="dialogOpen" :group="editingGroup" @saved="onSaved" />
    </Card>
</template>
