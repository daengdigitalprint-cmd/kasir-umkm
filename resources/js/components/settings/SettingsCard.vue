<script setup lang="ts">
/** Kartu pengaturan polos: judul, keterangan, isi. Tanpa bayangan. */
defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white">
        <header v-if="title || $slots.action" class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div class="min-w-0">
                <h2 v-if="title" class="text-base font-bold text-slate-900">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
            </div>
            <slot name="action" />
        </header>
        <div class="px-5 py-4">
            <slot />
        </div>
    </section>
</template>
