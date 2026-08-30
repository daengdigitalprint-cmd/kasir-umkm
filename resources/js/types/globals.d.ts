import { AppPageProps } from '@/types/index';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}

declare global {
    /** Web Bluetooth (Chrome/Edge); lib DOM bawaan TS belum selalu memuat ini. */
    interface BluetoothThermalRequestDeviceOptions {
        filters?: Array<{ services?: Array<number | string> }>;
        optionalServices?: Array<number | string>;
        acceptAllDevices?: boolean;
    }

    interface BluetoothThermalDevice extends EventTarget {
        readonly id: string;
        readonly name?: string | null;
        readonly gatt?: BluetoothThermalRemoteGATTServer | null;
    }

    interface BluetoothThermalRemoteGATTServer {
        readonly connected: boolean;
        connect(): Promise<BluetoothThermalRemoteGATTServer>;
        disconnect(): void;
        getPrimaryService(service: string | number): Promise<BluetoothThermalRemoteGATTService>;
    }

    interface BluetoothThermalRemoteGATTService {
        getCharacteristic(characteristic: string | number): Promise<BluetoothThermalRemoteGATTCharacteristic>;
    }

    interface BluetoothThermalCharacteristicProperties {
        readonly broadcast: boolean;
        readonly read: boolean;
        readonly writeWithoutResponse: boolean;
        readonly write: boolean;
        readonly notify: boolean;
        readonly authenticatedSignedWrites: boolean;
        readonly reliableWrite: boolean;
        readonly writableAuxiliaries: boolean;
    }

    interface BluetoothThermalRemoteGATTCharacteristic extends EventTarget {
        readonly properties: BluetoothThermalCharacteristicProperties;
        readValue(): Promise<DataView>;
        writeValue(value: BufferSource): Promise<void>;
        writeValueWithoutResponse(value: BufferSource): Promise<void>;
    }

    interface BluetoothThermalAPI {
        requestDevice(options: BluetoothThermalRequestDeviceOptions): Promise<BluetoothThermalDevice>;
        /** Perangkat yang sudah pernah diizinkan untuk origin ini (Chrome/Edge). */
        getDevices?: () => Promise<BluetoothThermalDevice[]>;
        getAvailability?: () => Promise<boolean>;
    }

    interface Navigator {
        bluetooth?: BluetoothThermalAPI;
    }

    interface AndroidBluetoothPrinterDevice {
        name: string;
        mac: string;
    }

    interface AndroidBluetoothPrintBridge {
        scanDevices(): void;
        connect(macAddress: string, role?: 'cashier' | 'kitchen'): void;
        print(escposHex: string, role?: 'cashier' | 'kitchen'): void;
        disconnect(role?: 'cashier' | 'kitchen'): void;
        isConnected(role?: 'cashier' | 'kitchen'): boolean;
        kickDrawer?(): void;
    }

    interface Window {
        BluetoothPrint?: AndroidBluetoothPrintBridge;
        __btCallback?: (
            action: 'scan' | 'connect' | 'print',
            success: boolean,
            data: AndroidBluetoothPrinterDevice[] | string | null,
            errMsg: string | null,
        ) => void;
    }
}

export {};
