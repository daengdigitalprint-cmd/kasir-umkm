<script setup lang="ts">
import { onMounted } from 'vue';
import { usePrinter } from '@/composables/usePrinter';

defineProps<{ show: boolean }>();
defineEmits<{ close: [] }>();

const {
    isAndroid,
    isAvailable,
    isConnected,
    isLoading,
    error,
    pairedDevices,
    selectedPrinter,
    initialize,
    scanDevices,
    connectPrinter,
} = usePrinter();

onMounted(() => {
    void initialize();
});
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-end bg-black/50">
        <div class="max-h-[80vh] w-full overflow-y-auto rounded-t-2xl bg-white p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-800">Pengaturan Printer</h2>
                <button type="button" class="text-2xl text-gray-400" @click="$emit('close')">&times;</button>
            </div>

            <div v-if="!isAvailable" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm text-amber-700">
                    Fitur cetak Bluetooth hanya tersedia di aplikasi Android (APK) atau browser Chrome dengan
                    printer BLE.
                </p>
            </div>

            <div v-else-if="isAndroid">
                <div class="mb-4 flex items-center gap-2">
                    <div class="h-3 w-3 rounded-full" :class="isConnected ? 'bg-green-500' : 'bg-gray-300'" />
                    <span class="text-sm text-gray-600">
                        {{ isConnected ? `Terhubung ke ${selectedPrinter?.name}` : 'Belum terhubung' }}
                    </span>
                </div>

                <div v-if="error" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{{ error }}</div>

                <button
                    type="button"
                    class="mb-4 w-full rounded-lg border border-blue-500 py-2 text-sm text-blue-500 disabled:opacity-50"
                    :disabled="isLoading"
                    @click="scanDevices"
                >
                    {{ isLoading ? 'Mencari...' : 'Scan Perangkat Bluetooth' }}
                </button>

                <div v-if="pairedDevices.length > 0">
                    <p class="mb-2 text-xs text-gray-400">PERANGKAT PAIRED</p>
                    <div
                        v-for="device in pairedDevices"
                        :key="device.id"
                        class="mb-2 cursor-pointer rounded-lg border p-3"
                        :class="selectedPrinter?.id === device.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200'"
                        @click="connectPrinter(device)"
                    >
                        <p class="text-sm font-medium">{{ device.name }}</p>
                        <p class="text-xs text-gray-400">{{ device.mac ?? device.id }}</p>
                    </div>
                </div>

                <p class="mt-4 text-center text-xs text-gray-400">
                    Pastikan printer sudah di-pair di Settings Bluetooth Android terlebih dahulu
                </p>
            </div>
        </div>
    </div>
</template>
