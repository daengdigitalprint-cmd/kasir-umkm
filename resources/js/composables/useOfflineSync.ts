/**
 * useOfflineSync.ts
 *
 * Composable untuk:
 * 1. Mendeteksi status koneksi (online/offline) via VueUse useOnline
 * 2. Menyimpan transaksi ke IndexedDB saat offline
 * 3. Sinkronisasi otomatis ke server saat koneksi pulih
 * 4. Menyediakan jumlah transaksi pending untuk indikator UI
 */

import {
    cleanSyncedSales,
    getPendingSales,
    getPendingSalesCount,
    markSaleOfflineDone,
    markSaleSyncError,
    saveSaleOffline,
    purgeExpiredLogs,
} from '@/lib/offlineDb';
import type { OfflineSale } from '@/lib/offlineDb';
import { useOnline } from '@vueuse/core';
import { computed, onMounted, onUnmounted, readonly, ref, watch } from 'vue';

// Singleton agar composable dapat dipakai di beberapa komponen
// tanpa membuat listener ganda.
let _storeId = 0;
let _cashierId = 0;

/** Baca XSRF-TOKEN dari cookie — auto-refresh oleh Laravel, selalu sinkron dengan session. */
function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

const _pendingCount = ref(0);
const _isSyncing = ref(false);
const _lastSyncAt = ref<string | null>(null);
const _syncErrors = ref<string[]>([]);

const isOnline = useOnline();

// ─── Debug logger (nonaktif) ──────────────────────────────────────────────────
// biome-ignore lint/correctness/noUnusedVariables: kept for re-enabling during debug sessions
function dbg(..._args: unknown[]) {}

/** Perbarui jumlah pending dari IndexedDB. */
async function refreshPendingCount() {
    if (_storeId) {
        _pendingCount.value = await getPendingSalesCount(_storeId);
        dbg('refreshPendingCount →', _pendingCount.value);
    }
}

/**
 * Sinkronisasi semua transaksi pending ke server.
 * Menggunakan localId sebagai idempotency key di header
 * agar server menolak duplikat.
 */
async function syncPending() {
    dbg('syncPending called', { isSyncing: _isSyncing.value, isOnline: isOnline.value, storeId: _storeId });

    if (_isSyncing.value) {
        dbg('skip — already syncing');
        return;
    }
    if (!isOnline.value) {
        dbg('skip — offline');
        return;
    }
    if (!_storeId) {
        dbg('skip — storeId not set');
        return;
    }

    const pending = await getPendingSales(_storeId);
    dbg('pending count:', pending.length);
    if (pending.length === 0) return;

    _isSyncing.value = true;
    _syncErrors.value = [];

    for (const sale of pending) {
        try {
            const { localId, synced: _s, syncError: _e, syncAttempts: _a, storeId: _stId, cashierId: _cId, created_at: _cat, ...rest } = sale;

            dbg('syncing sale:', localId, 'attempt:', sale.syncAttempts + 1);

            // Bersihkan payload: hanya kirim field yang diharapkan server
            const payload = {
                items: (rest.items as unknown as Array<Record<string, unknown>>).map((item) => ({
                    product_id: item.product_id,
                    product_variant_id: item.product_variant_id ?? null,
                    quantity: item.quantity,
                    // Server mengabaikan diskon ini bila modul Diskon per Produk nonaktif.
                    line_discount: Number(item.line_discount) || 0,
                    modifiers: (item.modifiers as Array<{ key: string }> | undefined) ?? [],
                })),
                payment_method: rest.payment_method,
                amount_paid: rest.amount_paid,
                ...(rest.payments?.length ? { payments: rest.payments } : {}),
                customer_name: rest.customer_name ?? null,
                ...(rest.customer_type !== undefined ? { customer_type: rest.customer_type } : {}),
                discount_code: rest.discount_code ?? null,
                ...(rest.loyalty_voucher_code ? { loyalty_voucher_code: rest.loyalty_voucher_code } : {}),
                ...(rest.order_type !== undefined ? { order_type: rest.order_type } : {}),
                ...(rest.table_reference !== undefined ? { table_reference: rest.table_reference } : {}),
                ...(rest.service_charge_percent !== undefined ? { service_charge_percent: rest.service_charge_percent } : {}),
                ...(rest.tip_amount !== undefined ? { tip_amount: rest.tip_amount } : {}),
                ...(rest.split_bill_count !== undefined ? { split_bill_count: rest.split_bill_count } : {}),
                ...(rest.pre_order_id ? { pre_order_id: rest.pre_order_id } : {}),
                ...(rest.pharmacy_sale_reference ? { pharmacy_sale_reference: rest.pharmacy_sale_reference } : {}),
                ...(rest.prescription ? { prescription: rest.prescription } : {}),
            };

            dbg('POST /pos/process-payment', { localId, payload });

            const res = await fetch('/pos/process-payment', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Offline-Sale-Id': localId,
                },
                body: JSON.stringify(payload),
            });

            const data = (await res.json()) as {
                success: boolean;
                message?: string;
                errors?: Record<string, string[]>;
                transaction?: { id: number };
                duplicate?: boolean;
            };

            dbg('response:', res.status, data);

            if (data.success || data.duplicate) {
                dbg('SUCCESS', localId, '→ server sale', data.transaction?.id);
                await markSaleOfflineDone(localId, data.transaction?.id);
            } else {
                const errMsg = data.errors
                    ? Object.values(data.errors).flat().join(', ')
                    : (data.message ?? 'Server menolak transaksi');
                dbg('REJECTED', localId, errMsg);
                await markSaleSyncError(
                    localId,
                    errMsg,
                );
                _syncErrors.value.push(`[${localId.slice(0, 8)}] ${errMsg}`);
            }
        } catch (e) {
            const msg = e instanceof Error ? e.message : 'Network error';
            dbg('NETWORK ERROR', sale.localId, msg);
            await markSaleSyncError(sale.localId, msg);
            _syncErrors.value.push(`[${sale.localId.slice(0, 8)}] ${msg}`);
        }
    }

    // Bersihkan entri yang sudah berhasil di-sync
    await cleanSyncedSales();
    await refreshPendingCount();

    _isSyncing.value = false;
    _lastSyncAt.value = new Date().toISOString();
    dbg('sync done. errors:', _syncErrors.value.length, 'remaining:', _pendingCount.value);
}

// Auto-sync saat koneksi pulih
watch(isOnline, async (online) => {
    dbg('isOnline changed:', online);
    if (online) {
        // Daftarkan Background Sync ke SW (jika browser mendukung)
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            try {
                const reg = await navigator.serviceWorker.ready;
                await (reg as ServiceWorkerRegistration & { sync: { register(tag: string): Promise<void> } }).sync.register('background-sync');
                dbg('Background Sync registered');
            } catch (e) {
                dbg('Background Sync not supported, falling back to direct sync:', e);
            }
        }
        await syncPending();
    }
});

// Dengarkan pesan SW_BACKGROUND_SYNC dari service worker (Background Sync API)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event: MessageEvent) => {
        if (event.data?.type === 'SW_BACKGROUND_SYNC') {
            dbg('Received SW_BACKGROUND_SYNC message from service worker');
            void syncPending();
        }
    });
}

// Polling setiap 30 detik saat online (untuk menangani edge case)
let _pollTimer: ReturnType<typeof setInterval> | null = null;

function startPolling() {
    if (_pollTimer) return;
    _pollTimer = setInterval(() => {
        if (isOnline.value) void syncPending().catch((e) => dbg('Polling sync error:', e));
    }, 30_000);
}

function stopPolling() {
    if (_pollTimer) {
        clearInterval(_pollTimer);
        _pollTimer = null;
    }
}

/**
 * Simpan transaksi ke IndexedDB (untuk dipakai saat offline).
 * Mengembalikan localId yang digunakan sebagai referensi.
 */
export async function queueOfflineSale(
    sale: Omit<OfflineSale, 'localId' | 'synced' | 'syncAttempts' | 'syncError'>,
): Promise<string> {
    const localId = crypto.randomUUID();
    dbg('queueOfflineSale →', localId, {
        items: sale.items.length,
        payment_method: sale.payment_method,
        total_amount: sale.total_amount,
    });
    await saveSaleOffline({
        ...sale,
        localId,
        synced: false,
        syncAttempts: 0,
        syncError: null,
    });
    await refreshPendingCount();
    dbg('saved to IDB, pending now:', _pendingCount.value);
    return localId;
}

export function useOfflineSync(options?: {
    storeId?: number;
    cashierId?: number;
}) {
    if (options?.storeId) _storeId = options.storeId;
    if (options?.cashierId) _cashierId = options.cashierId;

    onMounted(async () => {
        await refreshPendingCount();
        try {
            await purgeExpiredLogs();
        } catch {
            // purge expired logs gagal, tidak kritis
        }
        startPolling();
        // Sync langsung saat mount bila sudah online & ada pending —
        // menangani kasus halaman di-reload dalam kondisi online (tidak ada event perubahan status).
        if (isOnline.value) {
            void syncPending();
        }
    });

    onUnmounted(() => {
        stopPolling();
    });

    return {
        isOnline: readonly(isOnline),
        pendingCount: readonly(_pendingCount),
        isSyncing: readonly(_isSyncing),
        lastSyncAt: readonly(_lastSyncAt),
        syncErrors: readonly(_syncErrors),
        /** Trigger manual sync */
        syncNow: syncPending,
        /** Cek jumlah pending terbaru */
        refreshPendingCount,
    };
}

/** Update cashierId aktif untuk transaksi offline (mis. setelah PIN login kasir bergantian). */
export function setOfflineCashierId(cashierId: number) {
    _cashierId = cashierId;
}
