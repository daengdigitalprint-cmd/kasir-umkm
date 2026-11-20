<script setup lang="ts">
import { Box, FlaskConical, Package, ShoppingCart } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/**
 * Pertanyaan pertama di form produk: "Produk ini apa?".
 * Jenis menentukan isian berikutnya (harga jual, foto, cara stok).
 */
const props = defineProps<{
    modelValue: string;
    /** Jenis yang tersedia dari server (key => label). */
    types: Record<string, string>;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const META: Record<string, { label: string; desc: string; icon: Component }> = {
    sellable: { label: 'Dijual di kasir', desc: 'Menu atau barang jual', icon: ShoppingCart },
    raw_material: { label: 'Bahan baku', desc: 'Dipakai untuk resep', icon: Box },
    packaging: { label: 'Kemasan', desc: 'Cup, box, plastik', icon: Package },
    production_output: { label: 'Hasil produksi', desc: 'Dibuat dari resep produksi', icon: FlaskConical },
};

const options = computed(() =>
    Object.keys(props.types).map((key) => ({ key, ...(META[key] ?? { label: props.types[key], desc: '', icon: Package }) })),
);
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-base font-bold text-slate-900">Produk ini apa?</h2>
        <p class="mt-0.5 text-sm text-slate-500">Isian di bawah menyesuaikan pilihan ini.</p>
        <div class="mt-4 grid grid-cols-2 gap-2.5 md:grid-cols-4" role="radiogroup" aria-label="Jenis produk">
            <button
                v-for="o in options"
                :key="o.key"
                type="button"
                role="radio"
                :aria-checked="modelValue === o.key"
                class="flex items-start gap-2.5 rounded-xl border p-3 text-left transition"
                :class="modelValue === o.key ? 'border-primary bg-primary/5' : 'border-slate-200 hover:border-slate-300'"
                @click="emit('update:modelValue', o.key)"
            >
                <component :is="o.icon" class="mt-0.5 h-5 w-5 shrink-0" :class="modelValue === o.key ? 'text-primary' : 'text-slate-400'" />
                <span class="min-w-0">
                    <span class="block text-sm font-semibold" :class="modelValue === o.key ? 'text-primary' : 'text-slate-800'">{{ o.label }}</span>
                    <span class="block text-xs text-slate-500">{{ o.desc }}</span>
                </span>
            </button>
        </div>
    </section>
</template>
