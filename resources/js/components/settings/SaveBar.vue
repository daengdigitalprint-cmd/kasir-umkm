<script setup lang="ts">
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { Loader2, Save } from 'lucide-vue-next';

/**
 * Bilah simpan yang melayang di bawah layar selama ada perubahan belum disimpan.
 * Sekaligus mencegah perubahan hilang: konfirmasi saat pindah halaman atau menutup tab.
 */
const props = defineProps<{
    dirty: boolean;
    processing?: boolean;
    label?: string;
}>();

const emit = defineEmits<{ save: []; discard: [] }>();

useUnsavedGuard(
    () => props.dirty,
    () => Boolean(props.processing),
);
</script>

<template>
    <!-- Ruang kosong agar bilah melayang tidak menutupi isi paling bawah. -->
    <div v-if="dirty" class="h-24" aria-hidden="true" />
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="translate-y-3 opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="translate-y-3 opacity-0"
    >
        <div
            v-if="dirty"
            role="status"
            class="fixed right-4 bottom-[5.5rem] left-4 z-40 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/40 bg-white px-4 py-3 lg:bottom-4 lg:left-auto lg:w-[34rem]"
        >
            <p class="text-sm font-semibold text-slate-900">Ada perubahan yang belum disimpan</p>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-50"
                    :disabled="processing"
                    @click="emit('discard')"
                >
                    Batalkan
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 disabled:opacity-50"
                    :disabled="processing"
                    @click="emit('save')"
                >
                    <Loader2 v-if="processing" class="h-4 w-4 animate-spin" />
                    <Save v-else class="h-4 w-4" />
                    {{ processing ? 'Menyimpan…' : (label ?? 'Simpan') }}
                </button>
            </div>
        </div>
    </Transition>
</template>
