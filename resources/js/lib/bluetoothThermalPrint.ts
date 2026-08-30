/**
 * Kirim data ESC/POS ke printer thermal BLE lewat Web Bluetooth API.
 * Https atau localhost; umumnya Chrome / Edge desktop & Android Chrome.
 *
 * Setelah sekali memilih printer, ID disimpan di localStorage dan
 * `getDevices()` dipakai agar tidak perlu dialog pemilih tiap cetak.
 *
 * Printer harus mendukung BLE dengan layanan serial (bukan Bluetooth Classic SPP saja).
 */

const STORAGE_KEY = 'umkmkasir_thermal_bt_device_id';

function addDebugLog(msg: string) {
    console.log(`[BT] ${msg}`);
}

let cachedDevice: BluetoothThermalDevice | null = null;
// GATT server yang tetap aktif antar-cetak agar tidak perlu dialog ulang
let persistentGatt: BluetoothThermalRemoteGATTServer | null = null;
let lastGattServer: BluetoothThermalRemoteGATTServer | null = null;

const WRITE_CANDIDATES: { service: string; characteristic: string }[] = [
    // HM-10 / banyak printer thermal "BLE serial"
    { service: '0000ffe0-0000-1000-8000-00805f9b34fb', characteristic: '0000ffe1-0000-1000-8000-00805f9b34fb' },
    // RPP02N Primary Service (discovered via nRF Connect)
    { service: '0000ff00-0000-1000-8000-00805f9b34fb', characteristic: '0000ff02-0000-1000-8000-00805f9b34fb' },
    // RPP02N Secondary Service
    { service: '000018f0-0000-1000-8000-00805f9b34fb', characteristic: '00002af1-0000-1000-8000-00805f9b34fb' },
    // RPP02N Custom Service
    { service: 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', characteristic: 'bef8d6c9-9c21-4c9e-b632-bd58c1009f9f' },
    // Nordic UART–style (beberapa modul)
    { service: '6e400001-b5a3-f393-e0a9-e50e24dcca9e', characteristic: '6e400002-b5a3-f393-e0a9-e50e24dcca9e' },
    // RFCOMM-like UUID vendor (MTP-II, some Xprinter BLE)
    { service: '49535343-fe7d-4ef8-8fa9-9fafd205e455', characteristic: '49535343-8841-43f4-a8d4-ecbe34729bb3' },
];

function mergeUniqueServices(): string[] {
    const s = new Set<string>();
    for (const c of WRITE_CANDIDATES) {
        s.add(c.service);
    }
    return [...s];
}

function getStoredPrinterDeviceId(): string | null {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function setStoredPrinterDeviceId(id: string): void {
    try {
        localStorage.setItem(STORAGE_KEY, id);
    } catch {
        /* private mode / quota */
    }
}

export function clearBluetoothThermalPrinterMemory(): void {
    addDebugLog('[CONN] clearBluetoothThermalPrinterMemory: reset cachedDevice + persistentGatt');
    // Putuskan koneksi GATT persisten jika masih aktif
    if (persistentGatt?.connected) {
        try { persistentGatt.disconnect(); } catch { /* ignore */ }
    }
    cachedDevice = null;
    persistentGatt = null;
    lastGattServer = null;
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* ignore */
    }
}

export function setCachedBluetoothDevice(device: BluetoothThermalDevice): void {
    addDebugLog(`[CONN] setCachedBluetoothDevice: ${device.name ?? device.id}`);
    cachedDevice = device;
    setStoredPrinterDeviceId(device.id);
}

export function hasRememberedBluetoothPrinter(): boolean {
    return !!getStoredPrinterDeviceId();
}

/**
 * Kembalikan GATT persisten yang aktif jika ada.
 * Dipanggil oleh usePrinter sebelum print agar tahu apakah perlu reconnect.
 */
export function getActivePersistentGatt(): BluetoothThermalRemoteGATTServer | null {
    if (persistentGatt?.connected) return persistentGatt;
    return null;
}

/**
 * Simpan GATT server yang sudah terkoneksi agar bisa dipakai ulang lintas halaman.
 */
export function setPersistentGatt(gatt: BluetoothThermalRemoteGATTServer | null): void {
    persistentGatt = gatt;
    lastGattServer = gatt;
    addDebugLog(`[CONN] setPersistentGatt: ${gatt?.connected ? 'connected' : 'null/disconnected'}`);
}

export async function connectGatt(device: BluetoothThermalDevice): Promise<BluetoothThermalRemoteGATTServer> {
    addDebugLog('[CONN] connectGatt called');
    // Pakai persistentGatt jika masih hidup
    if (persistentGatt?.connected) {
        addDebugLog('[CONN] Reusing persistentGatt');
        lastGattServer = persistentGatt;
        return persistentGatt;
    }
    if (device.gatt?.connected && lastGattServer) {
        addDebugLog('[CONN] GATT already connected (lastGattServer)');
        persistentGatt = lastGattServer;
        return lastGattServer;
    }

    if (!device.gatt) throw new Error('Device GATT is null');
    addDebugLog('[CONN] Connecting GATT...');
    lastGattServer = await device.gatt.connect();
    persistentGatt = lastGattServer;
    addDebugLog('[CONN] GATT connected successfully');
    return lastGattServer;
}

/**
 * Coba restore cachedDevice dari getDevices() tanpa membuka dialog.
 * Dipanggil saat halaman baru dimuat (misalnya POS) setelah koneksi dibuat di Pengaturan.
 */
export async function tryRestoreCachedDevice(): Promise<BluetoothThermalDevice | null> {
    if (cachedDevice) {
        addDebugLog(`[CONN] tryRestoreCachedDevice: cachedDevice sudah ada (${cachedDevice.name ?? cachedDevice.id})`);
        return cachedDevice;
    }
    const bt = navigator.bluetooth;
    if (!bt || typeof bt.getDevices !== 'function') return null;
    const sid = getStoredPrinterDeviceId();
    if (!sid) return null;
    try {
        const granted = await bt.getDevices();
        const hit = granted.find((d) => d.id === sid) ?? (granted.length === 1 ? granted[0] : null);
        if (hit) {
            addDebugLog(`[CONN] tryRestoreCachedDevice: restored ${hit.name ?? hit.id}`);
            cachedDevice = hit;
            return hit;
        }
    } catch (e) {
        addDebugLog(`[CONN] tryRestoreCachedDevice error: ${e instanceof Error ? e.message : String(e)}`);
    }
    return null;
}

export function isBluetoothThermalSupported(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    // Cek navigator.bluetooth langsung — beberapa WebView/Android mungkin exposed
    // meskipun isSecureContext false atau user agent mengandung "wv"
    if (typeof navigator !== 'undefined' && 'bluetooth' in navigator && !!navigator.bluetooth) {
        return true;
    }
    return false;
}

/**
 * Chunk kecil agar aman untuk writeValueWithoutResponse (banyak printer ~20 byte; kami pakai 96).
 */
const CHUNK = 96;

async function writeAll(characteristic: BluetoothThermalRemoteGATTCharacteristic, data: Uint8Array): Promise<void> {
    const writeNoResp = characteristic.properties.writeWithoutResponse;
    const useWrite = characteristic.properties.write;

    for (let i = 0; i < data.length; i += CHUNK) {
        const slice = data.subarray(i, i + CHUNK) as unknown as BufferSource;
        try {
            if (writeNoResp) {
                await characteristic.writeValueWithoutResponse(slice);
            } else if (useWrite) {
                await characteristic.writeValue(slice);
            } else {
                throw new Error('Karakteristik printer tidak mendukung write.');
            }
        } catch (e) {
            addDebugLog(`Write error: ${e instanceof Error ? e.message : String(e)}`);
            throw new Error('Koneksi printer dibatalkan atau tidak tersedia');
        }
        await new Promise((r) => setTimeout(r, 12));
    }
}

export async function findWritableCharacteristic(
    server: BluetoothThermalRemoteGATTServer,
): Promise<BluetoothThermalRemoteGATTCharacteristic | null> {
    for (const { service: serviceUuid, characteristic: charUuid } of WRITE_CANDIDATES) {
        try {
            const svc = await server.getPrimaryService(serviceUuid);
            const ch = await svc.getCharacteristic(charUuid);
            if (ch.properties.write || ch.properties.writeWithoutResponse) {
                return ch;
            }
        } catch {
            continue;
        }
    }
    return null;
}

/**
 * Pilih perangkat: utamakan yang sudah diizinkan / tersimpan tanpa dialog.
 * Mode 'known' tidak akan membuka dialog browser — langsung throw jika printer belum dipasangkan.
 * Mode 'any' selalu membuka dialog untuk memilih/mengganti printer.
 */
async function resolveDevice(
    pickMode: BluetoothPickMode,
    forcePicker: boolean,
): Promise<BluetoothThermalDevice> {
    addDebugLog(`resolveDevice called with mode: ${pickMode}, forcePicker: ${forcePicker}`);
    const bt = navigator.bluetooth!;
    const optionalServices = mergeUniqueServices();

    // cachedDevice adalah referensi live BluetoothDevice — selalu pakai jika ada dan tidak forcePicker
    // Ini mengatasi bug getDevices() selalu kosong di Android Chrome
    if (cachedDevice && !forcePicker) {
        addDebugLog(`Using cachedDevice: ${cachedDevice.name ?? cachedDevice.id}`);
        return cachedDevice;
    }

    // Coba ambil device yang sudah pernah diizinkan tanpa membuka dialog
    if (typeof bt.getDevices === 'function') {
        try {
            const granted = await bt.getDevices();
            const sid = getStoredPrinterDeviceId();
            if (sid) {
                const hit = granted.find((d) => d.id === sid);
                if (hit) {
                    addDebugLog('Found granted device from stored ID');
                    return hit;
                }
            }
            if (granted.length === 1) {
                setStoredPrinterDeviceId(granted[0].id);
                return granted[0];
            }
        } catch (e) {
            addDebugLog(`getDevices threw error: ${e instanceof Error ? e.message : String(e)}`);
        }
    }

    // Jika mode 'known', tidak ketemu di getDevices, dan forcePicker false:
    // Coba fallback ke picker JIKA kita punya ID tersimpan (mengatasi bug getDevices kosong di Android Chrome)
    if (pickMode === 'known' && !forcePicker) {
        if (getStoredPrinterDeviceId()) {
            addDebugLog('Fallback ke picker karena ada ID tersimpan tapi getDevices kosong');
            // Lanjut ke picker di bawah
        } else {
            throw new Error(
                'Printer Bluetooth belum dipasangkan. Buka Pengaturan → Bluetooth, lalu klik "Cari & Hubungkan Printer".',
            );
        }
    }

    // Mode 'any' atau forcePicker atau fallback: buka dialog pilih perangkat
    const device =
        pickMode === 'any'
            ? await bt.requestDevice({
                  acceptAllDevices: true,
                  optionalServices,
              })
            : await bt.requestDevice({
                  filters: optionalServices.map((s) => ({ services: [s] })),
                  optionalServices,
              });
    addDebugLog(`New device selected: ${device.name || 'Unknown'}`);
    setStoredPrinterDeviceId(device.id);
    cachedDevice = device;
    return device;
}

export type BluetoothPickMode = 'known' | 'any';

export type SendBluetoothOptions = {
    /**
     * true = selalu buka dialog pilih perangkat (untuk ganti printer atau pindah ke "semua BLE").
     * false = pakai perangkat yang sudah diizinkan + ID tersimpan bila ada.
     */
    forcePicker?: boolean;
};

/** Callback global saat printer terputus (dipakai usePrinter untuk update state). */
let onDisconnectedCallback: ((device: BluetoothThermalDevice) => void) | null = null;

export function setBluetoothDisconnectListener(cb: ((device: BluetoothThermalDevice) => void) | null): void {
    onDisconnectedCallback = cb;
}

/** Guard agar listener tidak terpasang duplikat pada device yang sama. */
const listenerAttached = new WeakSet<object>();

function attachDisconnectListener(device: BluetoothThermalDevice): void {
    if (listenerAttached.has(device)) return;
    const dev = device as unknown as EventTarget;
    if (typeof dev.addEventListener !== 'function') return;
    try {
        listenerAttached.add(device);
        device.addEventListener('gattserverdisconnected', () => {
            addDebugLog(`[CONN] gattserverdisconnected: ${device.name ?? device.id}`);
            persistentGatt = null;
            lastGattServer = null;
            onDisconnectedCallback?.(device);
        });
    } catch {
        /* beberapa implementasi tidak mendukung addEventListener */
        listenerAttached.delete(device);
    }
}

/**
 * Putuskan koneksi GATT persisten secara aktif (mode browser).
 */
export function disconnectBluetoothGatt(): void {
    if (persistentGatt?.connected) {
        try { persistentGatt.disconnect(); } catch { /* ignore */ }
    }
    persistentGatt = null;
    lastGattServer = null;
}

/**
 * Connect ke printer BLE tanpa mengirim data apapun (connect-only).
 * - Buka picker jika perlu (forcePicker), atau pakai device tersimpan.
 * - Timeout 10 detik agar tidak hanging saat printer mati/out-of-range.
 * - Pasang listener gattserverdisconnected untuk deteksi putus otomatis.
 */
export async function connectOnlyViaBluetooth(
    forcePicker: boolean = true,
    timeoutMs: number = 10000,
): Promise<BluetoothThermalDevice> {
    if (!isBluetoothThermalSupported()) {
        throw new Error('Browser/WebView tidak mendukung Web Bluetooth.');
    }

    // Pakai device yang sudah dikenal jika tidak forcePicker
    const device = await resolveDevice(forcePicker ? 'any' : 'known', forcePicker);

    // Sudah terhubung? langsung kembalikan
    if (persistentGatt?.connected) {
        addDebugLog('[CONN] connectOnlyViaBluetooth: sudah terhubung (persistentGatt)');
        return device;
    }

    // Connect dengan timeout
    if (!device.gatt) throw new Error('GATT tidak tersedia pada perangkat ini.');

    let timeoutId: ReturnType<typeof setTimeout> | undefined;
    const timeoutPromise = new Promise<never>((_, reject) => {
        timeoutId = setTimeout(
            () => reject(new Error(`Timeout: gagal menghubungkan dalam ${timeoutMs / 1000} detik. Pastikan printer menyala dan dalam jangkauan.`)),
            timeoutMs,
        );
    });

    try {
        const gatt = await Promise.race([device.gatt.connect(), timeoutPromise]);
        if (timeoutId !== undefined) clearTimeout(timeoutId);
        persistentGatt = gatt;
        lastGattServer = gatt;
        cachedDevice = device;
        setStoredPrinterDeviceId(device.id);
        attachDisconnectListener(device);
        addDebugLog(`[CONN] connectOnlyViaBluetooth sukses: ${device.name ?? device.id}`);
        return device;
    } catch (e) {
        if (timeoutId !== undefined) clearTimeout(timeoutId);
        throw e instanceof Error ? e : new Error(String(e));
    }
}

/**
 * Kirim `payload` ESC/POS ke printer BLE.
 * Tanpa `forcePicker`, percobaan pertama memakai `getDevices()` sehingga biasanya tidak ada dialog.
 */
export async function sendEscPosViaBluetooth(
    payload: Uint8Array,
    pickMode: BluetoothPickMode = 'known',
    options: SendBluetoothOptions = {},
): Promise<void> {
    addDebugLog('sendEscPosViaBluetooth started');
    if (!isBluetoothThermalSupported()) {
        throw new Error(
            'Browser tidak mendukung Web Bluetooth, atau halaman tidak diakses lewat HTTPS / localhost. Gunakan Chrome atau Edge (desktop/Android).',
        );
    }

    const { forcePicker = false } = options;
    const device = await resolveDevice(pickMode, forcePicker);
    addDebugLog(`[CONN] sendEscPosViaBluetooth: device=${device.name ?? device.id}`);

    // Gunakan persistentGatt yang masih aktif jika ada, hindari reconnect
    let gatt: BluetoothThermalRemoteGATTServer | null = persistentGatt?.connected ? persistentGatt : null;

    if (gatt) {
        addDebugLog('[CONN] Reusing persistentGatt for print');
    } else {
        addDebugLog('[CONN] No active GATT, connecting...');
        let connected = false;
        let retries = 3;

        while (retries > 0 && !connected) {
            try {
                addDebugLog(`[CONN] device.gatt.connect()... (retries left: ${retries})`);
                gatt = await device.gatt?.connect() || null;
                if (gatt) {
                    addDebugLog('[CONN] GATT connect success!');
                    connected = true;
                    // Simpan sebagai persistentGatt agar cetak berikutnya tidak reconnect
                    persistentGatt = gatt;
                    lastGattServer = gatt;
                    attachDisconnectListener(device);
                }
            } catch (e) {
                addDebugLog(`[CONN] GATT connect error: ${e instanceof Error ? e.message : String(e)}`);
                retries--;
                if (retries === 0) throw e;
                await new Promise((resolve) => setTimeout(resolve, 1500));
            }
        }
    }

    if (!gatt) {
        throw new Error('GATT tidak tersedia pada perangkat ini.');
    }

    try {
        const characteristic = await findWritableCharacteristic(gatt);
        if (!characteristic) {
            throw new Error(
                'Layanan cetak Bluetooth tidak dikenali. Pastikan printer thermal BLE (bukan hanya Bluetooth klasik) atau coba mode "Semua perangkat BLE".',
            );
        }
        await writeAll(characteristic, payload);
        addDebugLog('[CONN] Print data written successfully, keeping GATT alive');
        // Sengaja TIDAK disconnect — koneksi dipertahankan untuk cetak berikutnya
    } catch (e) {
        // Jika write gagal, anggap koneksi terputus dan reset
        addDebugLog(`[CONN] Write failed, resetting persistentGatt: ${e instanceof Error ? e.message : String(e)}`);
        persistentGatt = null;
        lastGattServer = null;
        try { if (gatt.connected) gatt.disconnect(); } catch { /* ignore */ }
        throw e;
    }
}
