<script setup lang="ts">
/**
 * Satu pilihan cara stok, menggantikan tiga centang yang tumpang-tindih
 * (lacak stok / tanpa batas / izinkan stok habis).
 */
export type StockMode = 'none' | 'track' | 'unlimited';

defineProps<{
    modelValue: StockMode;
    /** Mis. produk bervarian wajib menghitung stok. */
    lockedReason?: string | null;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: StockMode] }>();

const OPTIONS: Array<{ key: StockMode; label: string; desc: string }> = [
    { key: 'none', label: 'Tidak dihitung', desc: 'Menu dibuat saat dipesan, jasa' },
    { key: 'track', label: 'Hitung stok', desc: 'Stok berkurang tiap terjual' },
    { key: 'unlimited', label: 'Tanpa batas', desc: 'Selalu tersedia' },
];
</script>

<template>
    <div>
        <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Cara stok">
            <button
                v-for="o in OPTIONS"
                :key="o.key"
                type="button"
                role="radio"
                :aria-checked="modelValue === o.key"
                :disabled="Boolean(lockedReason) && o.key !== 'track'"
                class="rounded-xl border px-3 py-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                :class="modelValue === o.key ? 'border-primary bg-primary/5' : 'border-slate-200 hover:border-slate-300'"
                @click="emit('update:modelValue', o.key)"
            >
                <span class="block text-sm font-semibold" :class="modelValue === o.key ? 'text-primary' : 'text-slate-800'">{{ o.label }}</span>
                <span class="block text-xs text-slate-500">{{ o.desc }}</span>
            </button>
        </div>
        <p v-if="lockedReason" class="mt-1.5 text-xs text-slate-500">{{ lockedReason }}</p>
    </div>
</template>
