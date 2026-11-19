<script setup lang="ts">
import { Crown, Loader2 } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * Satu baris saklar pengaturan: ikon, judul, keterangan, dan tombol geser di kanan.
 * Isi slot bawaan tampil di bawahnya saat saklar menyala (mis. pengaturan turunan).
 */
const props = defineProps<{
    modelValue: boolean;
    title: string;
    description?: string;
    icon?: Component;
    disabled?: boolean;
    loading?: boolean;
    /** Catatan kecil (mis. kenapa saklar dikunci). */
    note?: string;
    /** Fitur paket berbayar yang belum bisa dipakai. */
    locked?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

function toggle() {
    if (props.disabled || props.loading || props.locked) return;
    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <div class="py-3.5 first:pt-1 last:pb-1">
        <div class="flex items-start gap-3">
            <div
                v-if="icon"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                :class="modelValue ? 'bg-primary/10 text-primary' : 'bg-slate-100 text-slate-400'"
            >
                <component :is="icon" class="h-[18px] w-[18px]" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-800">
                    {{ title }}
                    <span
                        v-if="locked"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800"
                    >
                        <Crown class="h-3 w-3" /> Paket berbayar
                    </span>
                </p>
                <p v-if="description" class="mt-0.5 text-xs leading-relaxed text-slate-500">{{ description }}</p>
                <p v-if="note" class="mt-1 text-xs font-medium text-slate-600">{{ note }}</p>
                <slot name="extra" />
            </div>
            <slot name="control">
                <button
                    type="button"
                    role="switch"
                    :aria-checked="modelValue"
                    :aria-label="title"
                    :disabled="disabled || loading || locked"
                    class="relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    :class="modelValue ? 'bg-primary' : 'bg-slate-300'"
                    @click="toggle"
                >
                    <span
                        class="inline-flex h-5 w-5 transform items-center justify-center rounded-full bg-white transition-transform"
                        :class="modelValue ? 'translate-x-[22px]' : 'translate-x-0.5'"
                    >
                        <Loader2 v-if="loading" class="h-3 w-3 animate-spin text-slate-400" />
                    </span>
                </button>
            </slot>
        </div>
        <div v-if="modelValue && $slots.default" class="mt-3 sm:pl-12">
            <slot />
        </div>
    </div>
</template>
