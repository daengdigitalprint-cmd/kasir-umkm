<script setup lang="ts">
/**
 * Isian nominal rupiah: yang tampil berpemisah ribuan ("10.000"), yang disimpan angka polos ("10000").
 *
 * Memakai <input> asli, bukan komponen Input: komponen itu mengikat nilainya sendiri lewat v-model,
 * sehingga `:value` dari luar tertimpa (kolom tampil kosong) dan ketikan tidak sampai ke formulir.
 */
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

const model = defineModel<string | number | null>({ default: '' });
const props = defineProps<{ class?: HTMLAttributes['class'] }>();

const digitsOf = (value: unknown) => String(value ?? '').replace(/\D/g, '');
const format = (digits: string) => (digits ? new Intl.NumberFormat('id-ID').format(Number(digits)) : '');

/**
 * Angka polos dari nilai model. Nilai dari server bisa berdesimal ("10000.00" atau 10000.5):
 * dibulatkan dulu, supaya angka di belakang koma tidak ikut terbaca sebagai rupiah.
 */
const digitsOfModel = (value: unknown): string => {
    if (typeof value === 'number') return Number.isFinite(value) ? String(Math.max(0, Math.round(value))) : '';
    const text = String(value ?? '').trim();
    if (/^\d+\.\d+$/.test(text)) return String(Math.round(Number(text)));
    return digitsOf(text);
};

const shown = computed(() => format(digitsOfModel(model.value)));

const onInput = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const digits = digitsOf(input.value);
    model.value = digits;
    // Huruf atau tanda yang ikut terketik langsung dibuang dari tampilan.
    input.value = format(digits);
};
</script>

<template>
    <input
        type="text"
        inputmode="numeric"
        autocomplete="off"
        data-slot="input"
        :value="shown"
        :class="
            cn(
                'border-input placeholder:text-muted-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                props.class,
            )
        "
        @input="onInput"
    />
</template>
