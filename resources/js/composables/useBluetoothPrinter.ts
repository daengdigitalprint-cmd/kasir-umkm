/**
 * @deprecated Gunakan `usePrinter` dari `@/composables/usePrinter`.
 * File ini dipertahankan agar import lama tidak rusak; semua logika sudah di usePrinter.
 */
import { computed } from 'vue';
import { usePrinter } from '@/composables/usePrinter';

export type { BluetoothPickMode } from '@/lib/bluetoothThermalPrint';

export interface BluetoothPrinterInfo {
    id: string;
    name: string;
    connected: boolean;
    lastConnected?: string;
}

export function useBluetoothPrinter() {
    const printer = usePrinter();

    const printerInfo = computed((): BluetoothPrinterInfo | null => {
        const p = printer.selectedPrinter.value;
        if (!p) return null;
        return {
            id: p.id,
            name: p.name,
            connected: printer.isConnected.value,
        };
    });

    const connectionStatus = computed(() => {
        if (printer.isLoading.value) return 'connecting' as const;
        if (printer.isConnected.value) return 'connected' as const;
        if (printer.error.value) return 'error' as const;
        return 'disconnected' as const;
    });

    return {
        printerInfo,
        isConnecting: printer.isLoading,
        isPrinting: printer.isPrinting,
        connectionStatus,
        lastError: printer.error,
        isSupported: printer.isAvailable,
        isConnected: printer.isConnected,
        scanAndConnect: printer.scanAndConnect,
        disconnect: printer.disconnect,
        forgetPrinter: printer.forgetPrinter,
        testPrint: printer.testPrint,
        printReceipt: async (bytes: Uint8Array, forcePicker = false) => {
            const result = await printer.printEscPosBytes(bytes, { forcePicker });
            return result;
        },
        checkBluetoothAvailability: printer.checkAvailability,
        loadPrinterInfo: printer.initialize,
    };
}
