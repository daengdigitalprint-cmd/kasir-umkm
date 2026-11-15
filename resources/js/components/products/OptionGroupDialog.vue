<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2, Plus, Trash2 } from 'lucide-vue-next';
import type { OptionGroup, OptionGroupOption } from '@/types/optionGroups';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    /** Grup yang diedit; null = buat baru. */
    group: OptionGroup | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    saved: [group: OptionGroup, created: boolean];
}>();

interface Preset {
    title: string;
    name: string;
    selection: 'single' | 'multiple';
    is_required: boolean;
    options: Array<{ label: string; price: number }>;
}

/** Contoh siap pakai supaya pemilik toko tidak mulai dari form kosong. */
const presets: Preset[] = [
    {
        title: 'Level gula',
        name: 'Gula',
        selection: 'single',
        is_required: true,
        options: [
            { label: 'Normal', price: 0 },
            { label: 'Sedikit', price: 0 },
            { label: 'Tanpa gula', price: 0 },
        ],
    },
    {
        title: 'Level es',
        name: 'Es',
        selection: 'single',
        is_required: true,
        options: [
            { label: 'Normal', price: 0 },
            { label: 'Sedikit', price: 0 },
            { label: 'Tanpa es', price: 0 },
        ],
    },
    {
        title: 'Level pedas',
        name: 'Pedas',
        selection: 'single',
        is_required: true,
        options: [
            { label: 'Tidak pedas', price: 0 },
            { label: 'Sedang', price: 0 },
            { label: 'Pedas', price: 0 },
            { label: 'Extra pedas', price: 0 },
        ],
    },
    {
        title: 'Topping',
        name: 'Topping',
        selection: 'multiple',
        is_required: false,
        options: [
            { label: 'Boba', price: 3000 },
            { label: 'Keju', price: 3000 },
            { label: 'Jelly', price: 2000 },
        ],
    },
];

const name = ref('');
const selection = ref<'single' | 'multiple'>('single');
const isRequired = ref(false);
const rows = ref<OptionGroupOption[]>([]);
const saving = ref(false);
const error = ref('');

function blankRow(): OptionGroupOption {
    return { key: '', label: '', price: 0 };
}

function resetFrom(group: OptionGroup | null) {
    error.value = '';
    name.value = group?.name ?? '';
    selection.value = group?.selection ?? 'single';
    isRequired.value = group?.is_required ?? false;
    rows.value = group?.options?.length
        ? group.options.map((o) => ({ key: o.key, label: o.label, price: Number(o.price) || 0 }))
        : [blankRow(), blankRow()];
}

watch(
    () => props.open,
    (open) => {
        if (open) resetFrom(props.group);
    },
    { immediate: true },
);

function applyPreset(p: Preset) {
    name.value = p.name;
    selection.value = p.selection;
    isRequired.value = p.is_required;
    rows.value = p.options.map((o) => ({ key: '', label: o.label, price: o.price }));
}

function addRow() {
    rows.value.push(blankRow());
}

function removeRow(index: number) {
    rows.value.splice(index, 1);
    if (rows.value.length === 0) rows.value.push(blankRow());
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function save() {
    const cleanName = name.value.trim();
    const options = rows.value
        .filter((r) => r.label.trim() !== '')
        .map((r) => ({ key: r.key, label: r.label.trim(), price: Math.max(0, Number(r.price) || 0) }));

    if (!cleanName) {
        error.value = 'Nama grup wajib diisi.';
        return;
    }
    if (options.length === 0) {
        error.value = 'Isi minimal satu pilihan.';
        return;
    }

    error.value = '';
    saving.value = true;
    try {
        const editing = props.group !== null;
        const res = await fetch(editing ? `/product-option-groups/${props.group!.id}` : '/product-option-groups', {
            method: editing ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                name: cleanName,
                selection: selection.value,
                is_required: isRequired.value,
                options,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const firstError = data?.errors ? (Object.values(data.errors)[0] as string[] | undefined)?.[0] : undefined;
            error.value = firstError ?? data?.message ?? 'Gagal menyimpan grup pilihan.';
            return;
        }
        emit('saved', data.group as OptionGroup, !editing);
        emit('update:open', false);
    } catch {
        error.value = 'Terjadi kesalahan jaringan. Coba lagi.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ group ? 'Ubah grup pilihan' : 'Buat grup pilihan' }}</DialogTitle>
                <DialogDescription>
                    Dibuat sekali, bisa dipakai di banyak produk. Kasir akan ditanya pilihan ini saat produk ditambahkan.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div v-if="!group" class="space-y-1.5">
                    <p class="text-xs font-medium text-slate-600">Mulai dari contoh:</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="p in presets"
                            :key="p.title"
                            type="button"
                            class="rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-medium text-teal-800 hover:bg-teal-100"
                            @click="applyPreset(p)"
                        >
                            {{ p.title }}
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="option-group-name">Nama grup *</Label>
                    <Input id="option-group-name" v-model="name" maxlength="100" placeholder="Contoh: Gula, Topping, Level pedas" />
                </div>

                <div class="space-y-2">
                    <Label>Cara memilih</Label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                            :class="selection === 'single' ? 'border-teal-500 bg-teal-50/60' : 'border-slate-200'"
                        >
                            <input v-model="selection" type="radio" value="single" class="mt-0.5" />
                            <span>
                                <span class="font-medium">Pilih satu</span>
                                <span class="block text-xs text-slate-500">Mis. gula: normal / sedikit / tanpa</span>
                            </span>
                        </label>
                        <label
                            class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                            :class="selection === 'multiple' ? 'border-teal-500 bg-teal-50/60' : 'border-slate-200'"
                        >
                            <input v-model="selection" type="radio" value="multiple" class="mt-0.5" />
                            <span>
                                <span class="font-medium">Boleh lebih dari satu</span>
                                <span class="block text-xs text-slate-500">Mis. topping: boba + keju</span>
                            </span>
                        </label>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input v-model="isRequired" type="checkbox" class="h-4 w-4 rounded border-gray-300" />
                        Wajib dipilih kasir
                    </label>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label>Pilihan</Label>
                        <Button type="button" variant="outline" size="sm" @click="addRow">
                            <Plus class="mr-1 h-4 w-4" />
                            Tambah
                        </Button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(row, idx) in rows" :key="idx" class="flex items-center gap-2">
                            <Input v-model="row.label" maxlength="120" placeholder="Nama pilihan" class="h-9 flex-1" />
                            <div class="relative w-32 shrink-0">
                                <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-slate-400">+Rp</span>
                                <Input v-model.number="row.price" type="number" min="0" step="500" class="h-9 pl-9" />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-9 w-9 shrink-0 p-0 text-destructive"
                                aria-label="Hapus pilihan"
                                @click="removeRow(idx)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Isi 0 bila pilihan tidak menambah harga.</p>
                </div>

                <div v-if="error" class="text-sm text-red-600">{{ error }}</div>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="saving" @click="emit('update:open', false)">Batal</Button>
                <Button type="button" :disabled="saving" @click="save">
                    <Loader2 v-if="saving" class="mr-2 h-4 w-4 animate-spin" />
                    Simpan
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
