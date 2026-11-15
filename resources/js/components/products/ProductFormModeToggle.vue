<script setup lang="ts">
defineProps<{ modelValue: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
        <p class="min-w-0 flex-1 text-sm text-slate-600">
            <template v-if="modelValue">Semua kolom ditampilkan, termasuk SKU, barcode, pajak, varian, dan paket.</template>
            <template v-else>Cukup isi nama, harga, dan stok. Kolom lain bisa dilengkapi kapan saja.</template>
        </p>
        <div class="inline-flex shrink-0 rounded-lg border border-slate-200 bg-white p-0.5 text-sm font-medium" role="group" aria-label="Mode form">
            <button
                type="button"
                class="rounded-md px-3 py-1.5 transition-colors"
                :class="!modelValue ? 'bg-primary text-primary-foreground' : 'text-slate-600 hover:bg-slate-100'"
                :aria-pressed="!modelValue"
                @click="emit('update:modelValue', false)"
            >
                Sederhana
            </button>
            <button
                type="button"
                class="rounded-md px-3 py-1.5 transition-colors"
                :class="modelValue ? 'bg-primary text-primary-foreground' : 'text-slate-600 hover:bg-slate-100'"
                :aria-pressed="modelValue"
                @click="emit('update:modelValue', true)"
            >
                Lengkap
            </button>
        </div>
    </div>
</template>
