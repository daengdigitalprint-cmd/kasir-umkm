<script setup lang="ts">
import BluetoothPrinterPanel from '@/components/BluetoothPrinterPanel.vue';
import SettingsShell from '@/components/settings/SettingsShell.vue';
</script>

<template>
    <SettingsShell active="printer" title="Printer Bluetooth" description="Hubungkan printer struk ke perangkat ini untuk cetak langsung dari kasir.">
        <BluetoothPrinterPanel embedded :show-title="false" />
    </SettingsShell>
</template>
