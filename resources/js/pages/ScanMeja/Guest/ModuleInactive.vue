<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Smartphone } from 'lucide-vue-next';

/** `delivery`: halaman dibuka dari tautan pesan antar, bukan QR meja. */
defineProps<{ delivery?: boolean }>();
</script>

<template>
    <Head title="Modul Tidak Aktif" />
    <div class="min-h-screen flex items-center justify-center bg-slate-50 p-4">
        <div class="text-center max-w-xs">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 mb-4">
                <Smartphone class="h-8 w-8 text-slate-400" />
            </div>
            <h1 class="font-black text-slate-900 text-lg">{{ delivery ? 'Pesan Antar Belum Tersedia' : 'Menu Digital Belum Tersedia' }}</h1>
            <p class="text-sm text-slate-500 mt-2">
                {{
                    delivery
                        ? 'Toko ini sedang tidak menerima pesanan antar lewat tautan. Silakan hubungi toko langsung.'
                        : 'Fitur menu digital belum diaktifkan untuk toko ini. Silakan hubungi staf.'
                }}
            </p>
        </div>
    </div>
</template>