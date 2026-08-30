<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Armchair } from 'lucide-vue-next';
</script>

<template>
    <Head title="Meja Tidak Aktif" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50 p-4">
        <div class="text-center max-w-xs">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 mb-4">
                <Armchair class="h-8 w-8 text-slate-400" />
            </div>
            <h1 class="font-black text-slate-900 text-lg">Meja Tidak Aktif</h1>
            <p class="text-sm text-slate-500 mt-2">
                Meja ini sedang tidak tersedia. Silakan hubungi staf untuk bantuan.
            </p>
        </div>
    </div>
</template>