<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface StoreOption {
    id: number;
    name: string;
}

const props = defineProps<{
    stores: StoreOption[];
    selectedId: number | null;
}>();

// Hanya tampilkan jika ada lebih dari 1 outlet
const show = props.stores.length > 0;

const selected = ref<number | ''>(props.selectedId ?? '');

watch(selected, (val) => {
    const params = new URLSearchParams(window.location.search);
    if (val === '' || val === null) {
        params.delete('store_id');
    } else {
        params.set('store_id', String(val));
    }
    // Preserve current path
    router.get(`${window.location.pathname}?${params.toString()}`, {}, { preserveState: true, replace: true });
});
</script>

<template>
    <div v-if="show" class="flex items-center gap-2">
        <label class="text-xs font-medium text-slate-500 whitespace-nowrap">Outlet:</label>
        <select
            v-model="selected"
            class="h-8 rounded-lg border border-slate-200 bg-white px-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30"
        >
            <option value="">Semua Outlet</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
    </div>
</template>
