<script setup lang="ts">
/**
 * F3 — Satuan beli & isi. Supplier menjual per karton/dus/pack, stok tetap
 * dicatat di satuan dasar. Disimpan lewat tombol sendiri (tidak ikut form produk).
 */
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { toastSuccess } from '@/lib/swal';

export interface PurchaseUnitRow {
    name: string;
    factor: number | string;
    is_purchase_default: boolean;
}

const props = defineProps<{
    productId: number;
    baseUnit: string;
    initialUnits: PurchaseUnitRow[];
    /** Satuan dasar di form sudah diubah tapi belum disimpan. */
    baseUnitDirty?: boolean;
}>();

const rows = ref<PurchaseUnitRow[]>(props.initialUnits.map((u) => ({ ...u })));
const errors = ref<Record<string, string>>({});
const saving = ref(false);

function addRow() {
    rows.value.push({ name: '', factor: '', is_purchase_default: rows.value.length === 0 });
}

function removeRow(index: number) {
    rows.value.splice(index, 1);
}

function setDefault(index: number) {
    rows.value.forEach((r, i) => (r.is_purchase_default = i === index ? !r.is_purchase_default : false));
}

function save() {
    saving.value = true;
    errors.value = {};
    router.put(
        `/products/${props.productId}/purchase-units`,
        { units: rows.value.map((r) => ({ name: r.name, factor: r.factor, is_purchase_default: r.is_purchase_default })) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toastSuccess('Satuan beli disimpan.'),
            onError: (e) => (errors.value = e as Record<string, string>),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <div>
            <p class="text-sm font-semibold text-slate-900">Satuan beli</p>
            <p class="text-xs text-muted-foreground">
                Untuk barang yang dibeli per karton, dus, atau pack. Contoh: 1 karton = 24 {{ baseUnit }}. Satuan ini muncul di form Pembelian;
                stok tetap dicatat dalam {{ baseUnit }}.
            </p>
        </div>

        <p v-if="baseUnitDirty" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Simpan produk dulu setelah mengubah satuan dasar. Satuan beli lama akan dihapus karena isinya tidak berlaku lagi.
        </p>

        <template v-else>
            <div v-for="(row, i) in rows" :key="i" class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-slate-600">1</span>
                <input
                    v-model="row.name"
                    type="text"
                    maxlength="20"
                    placeholder="karton"
                    class="h-9 w-28 rounded-md border border-input bg-background px-2 text-sm"
                    :aria-label="`Nama satuan beli ${i + 1}`"
                />
                <span class="text-sm text-slate-600">=</span>
                <input
                    v-model="row.factor"
                    type="number"
                    min="0"
                    step="any"
                    placeholder="24"
                    class="h-9 w-24 rounded-md border border-input bg-background px-2 text-right text-sm"
                    :aria-label="`Isi satuan beli ${i + 1}`"
                />
                <span class="text-sm text-slate-600">{{ baseUnit }}</span>
                <label class="ml-2 inline-flex items-center gap-1.5 text-xs text-slate-600">
                    <input type="checkbox" class="h-4 w-4 rounded" :checked="row.is_purchase_default" @change="setDefault(i)" />
                    Pilihan awal di Pembelian
                </label>
                <button type="button" class="ml-auto rounded-md p-1.5 text-red-600 hover:bg-red-50" @click="removeRow(i)">
                    <Trash2 class="h-4 w-4" />
                    <span class="sr-only">Hapus satuan {{ row.name }}</span>
                </button>
                <p v-if="errors[`units.${i}.name`] || errors[`units.${i}.factor`]" class="w-full text-xs text-red-600">
                    {{ errors[`units.${i}.name`] || errors[`units.${i}.factor`] }}
                </p>
            </div>
            <p v-if="errors.units" class="text-xs text-red-600">{{ errors.units }}</p>

            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" class="rounded-lg" @click="addRow">
                    <Plus class="mr-1 h-4 w-4" /> Tambah satuan beli
                </Button>
                <Button type="button" size="sm" class="rounded-lg" :disabled="saving" @click="save">
                    {{ saving ? 'Menyimpan…' : 'Simpan satuan beli' }}
                </Button>
            </div>
        </template>
    </div>
</template>
