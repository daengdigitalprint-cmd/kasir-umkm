<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string }>();

const info = computed(
    () =>
        ({
            draft: { label: 'Draft', hint: 'Masih bisa dihitung ulang', class: 'bg-amber-50 text-amber-700 ring-amber-200' },
            approved: { label: 'Disetujui', hint: 'Menunggu dibayar', class: 'bg-blue-50 text-blue-700 ring-blue-200' },
            paid: { label: 'Dibayar', hint: 'Final', class: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
        })[props.status] ?? { label: props.status, hint: '', class: 'bg-slate-100 text-slate-600 ring-slate-200' },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
        :class="info.class"
        :title="info.hint"
    >
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
        {{ info.label }}
    </span>
</template>
