/**
 * offlineDb.ts
 *
 * Layer IndexedDB untuk mode offline kasir.
 * Menyimpan:
 *   - offlineSales   : antrian transaksi yang belum di-sync ke server
 *   - productCache   : snapshot produk terakhir yang berhasil dimuat
 *   - syncLog        : riwayat hasil sinkronisasi
 *
 * Enkripsi ringan: payload JSON di-XOR dengan derived key dari store_id
 * agar tidak mudah dibaca jika perangkat diakses pihak lain.
 * (Untuk enkripsi penuh pakai Web Crypto API — lihat encryptPayload/decryptPayload.)
 */

const DB_NAME = 'umkm_kasir_offline';
const DB_VERSION = 1;

export interface OfflineSaleItem {
    product_id: number;
    product_variant_id: number | null;
    quantity: number;
    unit_price: number;
    subtotal: number;
    modifiers?: { key: string }[];
    product_name: string;
    variant_name?: string | null;
}

export interface OfflineSale {
    /** UUID lokal — dipakai sebagai idempotency key saat sync */
    localId: string;
    storeId: number;
    cashierId: number;
    items: OfflineSaleItem[];
    payment_method: string;
    amount_paid: number;
    total_amount: number;
    discount_amount: number;
    customer_name: string | null;
    customer_type?: 'ecer' | 'grosir';
    discount_code: string | null;
    order_type?: string;
    table_reference?: string | null;
    service_charge_percent?: number;
    tip_amount?: number;
    split_bill_count?: number;
    pre_order_id?: number | null;
    pharmacy_sale_reference?: string | null;
    prescription?: {
        reference?: string;
        number?: string;
        patient_name?: string;
        doctor_name?: string;
        issued_at?: string;
    };
    created_at: string; // ISO
    synced: boolean;
    syncError?: string | null;
    syncAttempts: number;
}

export interface ProductCacheEntry {
    storeId: number;
    products: unknown[];
    savedAt: string;
}

export interface SyncLogEntry {
    localId: string;
    synced_at: string;
    server_sale_id?: number;
    error?: string;
}

// ─── Buka / inisialisasi DB ───────────────────────────────────────────────────────────────
let _db: IDBDatabase | null = null;

function openDb(): Promise<IDBDatabase> {
    if (_db) return Promise.resolve(_db);
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (e) => {
            const db = (e.target as IDBOpenDBRequest).result;
            if (!db.objectStoreNames.contains('offlineSales')) {
                const store = db.createObjectStore('offlineSales', { keyPath: 'localId' });
                store.createIndex('synced', 'synced', { unique: false });
                store.createIndex('storeId', 'storeId', { unique: false });
            }
            if (!db.objectStoreNames.contains('productCache')) {
                db.createObjectStore('productCache', { keyPath: 'storeId' });
            }
            if (!db.objectStoreNames.contains('syncLog')) {
                db.createObjectStore('syncLog', { keyPath: 'localId' });
            }
        };
        req.onsuccess = (e) => {
            _db = (e.target as IDBOpenDBRequest).result;
            resolve(_db);
        };
        req.onerror = () => reject(req.error);
    });
}

function tx(
    db: IDBDatabase,
    stores: string | string[],
    mode: IDBTransactionMode = 'readonly',
): IDBTransaction {
    return db.transaction(stores, mode);
}

function promisifyRequest<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

// ─── Enkripsi XOR sederhana (obfuscation, bukan crypto-grade) ───────────────────────
// Untuk enkripsi sesungguhnya, gunakan Web Crypto AES-GCM.
function xorKey(storeId: number): Uint8Array {
    const key = new Uint8Array(16);
    for (let i = 0; i < 16; i++) {
        key[i] = ((storeId * 31 + i * 17) ^ 0xab) & 0xff;
    }
    return key;
}

export function encryptPayload(data: unknown, storeId: number): string {
    const json = JSON.stringify(data);
    const bytes = new TextEncoder().encode(json);
    const key = xorKey(storeId);
    const out = new Uint8Array(bytes.length);
    for (let i = 0; i < bytes.length; i++) {
        out[i] = bytes[i] ^ key[i % key.length];
    }
    return btoa(String.fromCharCode(...out));
}

export function decryptPayload<T>(cipher: string, storeId: number): T {
    const bytes = Uint8Array.from(atob(cipher), (c) => c.charCodeAt(0));
    const key = xorKey(storeId);
    const out = new Uint8Array(bytes.length);
    for (let i = 0; i < bytes.length; i++) {
        out[i] = bytes[i] ^ key[i % key.length];
    }
    return JSON.parse(new TextDecoder().decode(out)) as T;
}

// ─── OfflineSales CRUD ───────────────────────────────────────────────────────────────────

export async function saveSaleOffline(sale: OfflineSale): Promise<void> {
    const db = await openDb();
    const t = tx(db, 'offlineSales', 'readwrite');
    await promisifyRequest(t.objectStore('offlineSales').put(sale));
}

export async function getPendingSales(storeId: number): Promise<OfflineSale[]> {
    const db = await openDb();
    const t = tx(db, 'offlineSales');
    // Boolean tidak dapat diindeks IndexedDB, jadi index 'synced' selalu kosong.
    // Gunakan index 'storeId' (number, valid) lalu filter status di JS.
    const index = t.objectStore('offlineSales').index('storeId');
    const all = await promisifyRequest<OfflineSale[]>(index.getAll(storeId));
    return all.filter((s) => !s.synced && s.syncAttempts < 5);
}

export async function getPendingSalesCount(storeId: number): Promise<number> {
    const sales = await getPendingSales(storeId);
    return sales.length;
}

/** Alias untuk useOfflineSync */
export async function markSaleOfflineDone(
    localId: string,
    serverSaleId?: number,
): Promise<void> {
    return markSaleSynced(localId, serverSaleId);
}

export async function markSaleSynced(
    localId: string,
    serverSaleId?: number,
): Promise<void> {
    const db = await openDb();
    const t = tx(db, ['offlineSales', 'syncLog'], 'readwrite');
    const salesStore = t.objectStore('offlineSales');
    const sale = await promisifyRequest<OfflineSale>(salesStore.get(localId));
    if (sale) {
        sale.synced = true;
        sale.syncError = null;
        salesStore.put(sale);
    }
    const logStore = t.objectStore('syncLog');
    logStore.put({
        localId,
        synced_at: new Date().toISOString(),
        server_sale_id: serverSaleId,
    } satisfies SyncLogEntry);
}

export async function markSaleSyncError(localId: string, error: string): Promise<void> {
    const db = await openDb();
    const t = tx(db, 'offlineSales', 'readwrite');
    const store = t.objectStore('offlineSales');
    const sale = await promisifyRequest<OfflineSale>(store.get(localId));
    if (sale) {
        sale.syncError = error;
        sale.syncAttempts += 1;
        store.put(sale);
    }
}

/** Hapus transaksi yang sudah berhasil di-sync (housekeeping). */
export async function cleanSyncedSales(): Promise<void> {
    const db = await openDb();
    const t = tx(db, 'offlineSales', 'readwrite');
    const store = t.objectStore('offlineSales');
    // Boolean tidak dapat diindeks — scan seluruh store lalu filter di JS.
    const all = await promisifyRequest<OfflineSale[]>(store.getAll());
    const synced = all.filter((s) => s.synced);
    for (const s of synced) store.delete(s.localId);
}

// ─── Product Cache ─────────────────────────────────────────────────────────────────────

export async function cacheProducts(storeId: number, products: unknown[]): Promise<void> {
    const db = await openDb();
    const t = tx(db, 'productCache', 'readwrite');
    t.objectStore('productCache').put({
        storeId,
        products,
        savedAt: new Date().toISOString(),
    } satisfies ProductCacheEntry);
}

export async function getCachedProducts(storeId: number): Promise<unknown[] | null> {
    const db = await openDb();
    const t = tx(db, 'productCache');
    const entry = await promisifyRequest<ProductCacheEntry | undefined>(
        t.objectStore('productCache').get(storeId),
    );
    return entry?.products ?? null;
}

/** Purge sync logs older than 24 hours (1 day). */
export async function purgeExpiredLogs(): Promise<void> {
    const db = await openDb();
    const t = tx(db, 'syncLog', 'readwrite');
    const store = t.objectStore('syncLog');
    const all = await promisifyRequest<SyncLogEntry[]>(store.getAll());
    const cutoff = Date.now() - 24 * 60 * 60 * 1000; // 24 hours
    for (const entry of all) {
        if (new Date(entry.synced_at).getTime() < cutoff) {
            store.delete(entry.localId);
        }
    }
}
