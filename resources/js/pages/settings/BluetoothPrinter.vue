<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BluetoothPrinterPanel from '@/components/BluetoothPrinterPanel.vue';
import Heading from '@/components/Heading.vue';
</script>

<template>
    <Head title="Pengaturan Printer Bluetooth" />

    <div class="container mx-auto max-w-4xl px-4 py-6">
        <Heading title="Pengaturan Printer Bluetooth" />
        <p class="mb-6 mt-2 text-slate-600">
            Kelola koneksi printer Bluetooth untuk pencetakan struk langsung dari aplikasi kasir
        </p>

        <BluetoothPrinterPanel :show-title="false" />
    </div>
</template>
