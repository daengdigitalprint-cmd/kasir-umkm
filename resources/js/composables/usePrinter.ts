/**
 * Composable terpusat untuk printer thermal Bluetooth.
 * Mendukung dual-printer: 'cashier' (struk) dan 'kitchen' (pesanan dapur).
 */

import { ref, computed, onBeforeUnmount, getCurrentInstance, reactive } from 'vue';
import {
    isBluetoothThermalSupported,
    sendEscPosViaBluetooth,
    clearBluetoothThermalPrinterMemory,
    connectOnlyViaBluetooth,
    setBluetoothDisconnectListener,
    disconnectBluetoothGatt,
    findWritableCharacteristic,
    setCachedBluetoothDevice,
    setPersistentGatt,
    getActivePersistentGatt,
    tryRestoreCachedDevice,
    type BluetoothPickMode,
} from '@/lib/bluetoothThermalPrint';
import { receiptLinesToEscPos } from '@/lib/escPosEncode';

export type PrinterRole = 'cashier' | 'kitchen';
export type PrinterMode = 'android' | 'browser' | 'none';

export interface PrinterDevice {
    name: string;
    id: string;
    mac?: string;
}

export interface PrintResult {
    success: boolean;
    error?: string;
}

const STORAGE_KEYS = {
    cashier: 'printer_device_cashier',
    kitchen: 'printer_device_kitchen',
};

// Global states keyed by role
const mode = ref<PrinterMode>('none');
const printerStates = reactive<Record<PrinterRole, {
    isConnected: boolean;
    isLoading: boolean;
    isPrinting: boolean;
    error: string | null;
    selectedPrinter: PrinterDevice | null;
}>>({
    cashier: { isConnected: false, isLoading: false, isPrinting: false, error: null, selectedPrinter: null },
    kitchen: { isConnected: false, isLoading: false, isPrinting: false, error: null, selectedPrinter: null },
});

const pairedDevices = ref<PrinterDevice[]>([]);
const browserAvailable = ref<boolean | null>(null);

let callbackInstalled = false;
let listenersInstalled = false;
let activeBridgeReadyRef: (() => void) | null = null;
let activeBridgeReadyInterval: ReturnType<typeof setInterval> | null = null;

function detectMode(): PrinterMode {
    if (typeof window.BluetoothPrint !== 'undefined') return 'android';
    if (isBluetoothThermalSupported()) return 'browser';
    return 'none';
}

function refreshMode(): PrinterMode {
    mode.value = detectMode();
    return mode.value;
}

function loadSavedPrinters() {
    refreshMode();
    (['cashier', 'kitchen'] as PrinterRole[]).forEach(role => {
        const key = STORAGE_KEYS[role];
        const saved = localStorage.getItem(key);
        if (saved) {
            try {
                const parsed = JSON.parse(saved);
                printerStates[role].selectedPrinter = {
                    name: parsed.name,
                    id: parsed.mac || parsed.id,
                    mac: parsed.mac
                };
            } catch (e) {
                localStorage.removeItem(key);
            }
        }

        if (mode.value === 'android' && typeof window.BluetoothPrint?.isConnected === 'function') {
            printerStates[role].isConnected = window.BluetoothPrint.isConnected(role);
        }
    });
}

function savePrinter(role: PrinterRole, device: PrinterDevice) {
    localStorage.setItem(STORAGE_KEYS[role], JSON.stringify({
        name: device.name,
        mac: device.mac ?? device.id,
        id: device.id
    }));
}

function clearSavedPrinter(role: PrinterRole) {
    localStorage.removeItem(STORAGE_KEYS[role]);
    printerStates[role].selectedPrinter = null;
    printerStates[role].isConnected = false;
}

let androidPrintResolvers: Record<string, ((res: PrintResult) => void) | null> = {
    cashier: null,
    kitchen: null
};

function installAndroidCallback() {
    if (callbackInstalled) return;
    callbackInstalled = true;

    window.__btCallback = (action, success, data, errMsg) => {
        const roleStr = typeof data === 'string' ? data : 'cashier';
        const role = (roleStr === 'kitchen' ? 'kitchen' : 'cashier') as PrinterRole;

        if (action === 'print') {
            printerStates[role].isPrinting = false;
        } else {
            printerStates[role].isLoading = false;
        }

        if (!success) printerStates[role].error = errMsg || 'Terjadi kesalahan';
        else printerStates[role].error = null;

        switch (action) {
            case 'scan':
                pairedDevices.value = success && Array.isArray(data)
                    ? data.map((d) => ({ name: d.name, id: d.mac, mac: d.mac }))
                    : [];
                break;
            case 'connect':
                printerStates[role].isConnected = success;
                break;
            case 'print':
                if (!success) printerStates[role].isConnected = false;
                const resolver = androidPrintResolvers[role];
                if (resolver) {
                    resolver(success ? { success: true } : { success: false, error: errMsg ?? 'Gagal mencetak' });
                    androidPrintResolvers[role] = null;
                }
                break;
            case 'drawer':
                if (!success) {
                    printerStates.cashier.error = errMsg || 'Gagal membuka laci';
                }
                break;
        }
    };
}

function ensureListeners() {
    if (listenersInstalled) return;
    listenersInstalled = true;
    installAndroidCallback();

    // Listener disconnect untuk mode browser/WebView
    setBluetoothDisconnectListener((device) => {
        (['cashier', 'kitchen'] as PrinterRole[]).forEach(role => {
            const sel = printerStates[role].selectedPrinter;
            if (sel && (sel.id === device.id || sel.mac === device.id)) {
                printerStates[role].isConnected = false;
            }
        });
    });

    const checkBridgeReady = () => {
        if (typeof window.BluetoothPrint !== 'undefined') {
            mode.value = 'android';
            loadSavedPrinters();
            // Auto connect if saved
            (['cashier', 'kitchen'] as PrinterRole[]).forEach(role => {
                const dev = printerStates[role].selectedPrinter;
                if (dev && !printerStates[role].isConnected && !printerStates[role].isLoading) {
                    printerStates[role].isLoading = true;
                    window.BluetoothPrint?.connect(dev.mac ?? dev.id, role);
                }
            });
        }
    };

    activeBridgeReadyRef = checkBridgeReady;
    window.addEventListener('androidBridgeReady', checkBridgeReady);

    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (typeof window.BluetoothPrint !== 'undefined') {
            checkBridgeReady();
            clearInterval(interval);
        } else if (attempts >= 10) {
            clearInterval(interval);
        }
    }, 500);
    activeBridgeReadyInterval = interval;
}

function bytesToHex(bytes: Uint8Array): string {
    return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

export function usePrinter() {
    ensureListeners();
    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            if (activeBridgeReadyRef) {
                window.removeEventListener('androidBridgeReady', activeBridgeReadyRef);
                activeBridgeReadyRef = null;
            }
            if (activeBridgeReadyInterval) {
                clearInterval(activeBridgeReadyInterval);
                activeBridgeReadyInterval = null;
            }
            listenersInstalled = false;
        });
    }

    const initialize = async () => {
        refreshMode();
        loadSavedPrinters();

        if (mode.value === 'browser' && browserAvailable.value === null) {
            try {
                browserAvailable.value = (await navigator.bluetooth?.getAvailability?.()) ?? true;
            } catch {
                browserAvailable.value = false;
            }
        }
    };

    const scanDevices = () => {
        if (mode.value !== 'android' || !window.BluetoothPrint) return;
        printerStates.cashier.isLoading = true;
        window.BluetoothPrint.scanDevices();
    };

    const connectPrinter = (device: PrinterDevice, role: PrinterRole = 'cashier') => {
        if (mode.value !== 'android' || !window.BluetoothPrint) return;
        printerStates[role].isLoading = true;
        printerStates[role].selectedPrinter = device;
        savePrinter(role, device);
        window.BluetoothPrint.connect(device.mac ?? device.id, role);
    };

    const disconnect = (role: PrinterRole = 'cashier') => {
        if (mode.value === 'android') {
            window.BluetoothPrint?.disconnect(role);
        } else if (mode.value === 'browser') {
            disconnectBluetoothGatt();
        }
        printerStates[role].isConnected = false;
    };

    const forgetPrinter = (role: PrinterRole = 'cashier') => {
        disconnect(role);
        clearSavedPrinter(role);
    };

    const printEscPosBytes = async (
        bytes: Uint8Array,
        options: { role?: PrinterRole; forcePicker?: boolean } | PrinterRole = {}
    ): Promise<PrintResult> => {
        const role = (typeof options === 'string' ? options : options.role || 'cashier') as PrinterRole;
        const forcePicker = typeof options === 'object' ? !!options.forcePicker : false;

        if (mode.value === 'android') {
            return new Promise((resolve) => {
                if (!window.BluetoothPrint) return resolve({ success: false, error: 'Bridge tidak tersedia' });
                if (!printerStates[role].isConnected) return resolve({ success: false, error: `Printer ${role} belum terhubung` });
                if (androidPrintResolvers[role]) return resolve({ success: false, error: 'Sedang mencetak...' });

                printerStates[role].isPrinting = true;
                androidPrintResolvers[role] = resolve;
                window.BluetoothPrint.print(bytesToHex(bytes), role);
            });
        }

        if (mode.value === 'browser') {
            printerStates[role].isPrinting = true;
            try {
                await sendEscPosViaBluetooth(bytes, 'known', { forcePicker });
                printerStates[role].isConnected = true;
                return { success: true };
            } catch (e) {
                printerStates[role].isConnected = false;
                return { success: false, error: e instanceof Error ? e.message : 'Gagal mencetak' };
            } finally {
                printerStates[role].isPrinting = false;
            }
        }

        return { success: false, error: 'Printer tidak tersedia' };
    };

    const kickDrawer = () => {
        if (mode.value === 'android' && window.BluetoothPrint?.kickDrawer) {
            window.BluetoothPrint.kickDrawer();
        }
    };

    /**
     * Mode browser/WebView: buka dialog Web Bluetooth untuk memilih printer.
     * Connect-only (tidak mengirim data), dengan timeout & deteksi disconnect.
     */
    const connectBrowserDevice = async (role: PrinterRole = 'cashier'): Promise<PrintResult> => {
        if (mode.value !== 'browser') {
            return { success: false, error: 'Web Bluetooth tidak tersedia' };
        }
        printerStates[role].isLoading = true;
        printerStates[role].error = null;
        try {
            const device = await connectOnlyViaBluetooth(true, 10000);
            printerStates[role].isConnected = true;
            printerStates[role].selectedPrinter = {
                name: device.name || 'Printer Bluetooth',
                id: device.id,
            };
            savePrinter(role, printerStates[role].selectedPrinter);
            return { success: true };
        } catch (e) {
            printerStates[role].isConnected = false;
            printerStates[role].error = e instanceof Error ? e.message : 'Gagal menghubungkan printer';
            return { success: false, error: printerStates[role].error ?? undefined };
        } finally {
            printerStates[role].isLoading = false;
        }
    };

    return {
        mode: computed(() => mode.value),
        isAndroid: computed(() => mode.value === 'android'),
        isBrowser: computed(() => mode.value === 'browser'),
        isAvailable: computed(() => mode.value === 'android' || mode.value === 'browser'),
        isConnected: computed(() => printerStates.cashier.isConnected),
        isKitchenConnected: computed(() => printerStates.kitchen.isConnected),
        isPrinting: computed(() => printerStates.cashier.isPrinting || printerStates.kitchen.isPrinting),
        isLoading: computed(() => printerStates.cashier.isLoading || printerStates.kitchen.isLoading),
        error: computed(() => printerStates.cashier.error || printerStates.kitchen.error),
        selectedPrinter: computed(() => printerStates.cashier.selectedPrinter),
        selectedKitchenPrinter: computed(() => printerStates.kitchen.selectedPrinter),
        pairedDevices,
        initialize,
        scanDevices,
        connectPrinter,
        disconnect,
        forgetPrinter,
        printEscPosBytes,
        kickDrawer,
        connectBrowserDevice,
        // Helper for specific role state
        getRoleState: (role: PrinterRole) => printerStates[role],
    };
}
