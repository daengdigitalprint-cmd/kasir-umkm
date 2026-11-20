<script setup lang="ts">
/**
 * Daftar langkah setelah outlet/gudang baru dibuat di grup usaha.
 *
 * Server mengirim flash `new_store_checklist` sekali saat toko dibuat; kartu disimpan
 * di localStorage per toko supaya tetap tampil selama pemilik mengerjakan langkahnya
 * (pindah halaman lalu kembali ke dashboard), sampai semua dicentang atau ditutup.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Circle, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ChecklistFlash {
    store_id: number;
    name: string;
    location_type: string;
    has_products: boolean;
}

interface Step {
    key: string;
    label: string;
    hint: string;
    href: string;
}

const props = defineProps<{ storeId: number | null | undefined }>();
const page = usePage();

const STORAGE_PREFIX = 'kasir:new-store-checklist:';

function readSaved(storeId: number): (ChecklistFlash & { done: string[] }) | null {
    try {
        const raw = localStorage.getItem(STORAGE_PREFIX + storeId);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeSaved(storeId: number, value: (ChecklistFlash & { done: string[] }) | null) {
    try {
        if (value === null) localStorage.removeItem(STORAGE_PREFIX + storeId);
        else localStorage.setItem(STORAGE_PREFIX + storeId, JSON.stringify(value));
    } catch {
        // localStorage tidak tersedia (mode privat): kartu tetap tampil sekali dari flash.
    }
}

const state = ref<(ChecklistFlash & { done: string[] }) | null>(null);

function load() {
    const flash = (page.props as { flash?: { new_store_checklist?: ChecklistFlash | null } }).flash?.new_store_checklist ?? null;
    if (flash) {
        const fresh = { ...flash, done: [] as string[] };
        writeSaved(flash.store_id, fresh);
        state.value = flash.store_id === props.storeId ? fresh : null;
        return;
    }
    state.value = props.storeId ? readSaved(props.storeId) : null;
}
load();
watch(() => props.storeId, load);

const isWarehouse = computed(() => state.value?.location_type === 'central_warehouse');

const steps = computed<Step[]>(() => {
    if (!state.value) return [];
    const list: Step[] = [];
    if (!state.value.has_products) {
        list.push({
            key: 'products',
            label: 'Salin produk dari lokasi lain',
            hint: isWarehouse.value ? 'Bahan baku & kemasan dari outlet yang sudah ada.' : 'Menu & bahan dari Gudang Pusat atau outlet lain.',
            href: '/manage-products',
        });
    }
    list.push({
        key: 'staff',
        label: isWarehouse.value ? 'Tambah karyawan gudang' : 'Tambah karyawan',
        hint: 'Kasir bisa masuk dengan PIN, tanpa email.',
        href: '/manage-staff',
    });
    if (isWarehouse.value) {
        list.push({ key: 'purchase', label: 'Catat pembelian pertama', hint: 'Stok bahan masuk dari supplier.', href: '/purchases/create' });
    } else {
        list.push({ key: 'receipt', label: 'Atur struk & printer', hint: 'Nama, alamat, dan logo di struk.', href: '/settings/store/struk' });
    }
    return list;
});

const doneCount = computed(() => steps.value.filter((s) => state.value?.done.includes(s.key)).length);

function markDone(key: string) {
    if (!state.value) return;
    if (!state.value.done.includes(key)) state.value.done.push(key);
    if (doneCount.value >= steps.value.length) {
        dismiss();
        return;
    }
    writeSaved(state.value.store_id, state.value);
}

function dismiss() {
    if (state.value) writeSaved(state.value.store_id, null);
    state.value = null;
}
</script>

<template>
    <div v-if="state && steps.length" class="mb-6 rounded-2xl border border-primary/20 bg-white p-4 shadow-sm sm:p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-bold tracking-wide text-primary uppercase">Langkah berikutnya · {{ doneCount }}/{{ steps.length }}</p>
                <p class="mt-0.5 text-base font-bold text-slate-900">{{ state.name }} siap dipakai</p>
            </div>
            <button type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup" @click="dismiss">
                <X class="h-4 w-4" />
            </button>
        </div>
        <ul class="mt-3 space-y-2">
            <li v-for="step in steps" :key="step.key">
                <Link
                    :href="step.href"
                    class="flex items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors"
                    :class="state.done.includes(step.key) ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'"
                    @click="markDone(step.key)"
                >
                    <CheckCircle2 v-if="state.done.includes(step.key)" class="h-5 w-5 shrink-0 text-emerald-600" />
                    <Circle v-else class="h-5 w-5 shrink-0 text-slate-300" />
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-900">{{ step.label }}</span>
                        <span class="block text-xs text-slate-500">{{ step.hint }}</span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
