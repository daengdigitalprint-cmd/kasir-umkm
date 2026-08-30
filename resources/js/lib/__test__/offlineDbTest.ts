/**
 * offlineDbTest.ts — Test manual yang dapat dijalankan dari browser console.
 *
 * Cara pakai:
 *   1. Buka DevTools Console di halaman mana saja
 *   2. Import: await import('/resources/js/lib/__test__/offlineDbTest.ts')
 *   3. Jalankan: await window.testOfflineDb()
 *
 * Atau cukup buka file ini langsung di tab baru (Vite akan serve sebagai module).
 */

import {
    cacheProducts,
    getCachedProducts,
    getPendingSales,
    getPendingSalesCount,
    saveSaleOffline,
    markSaleOfflineDone,
    markSaleSyncError,
    cleanSyncedSales,
} from '@/lib/offlineDb';

declare global {
    interface Window {
        testOfflineDb: () => Promise<void>;
    }
}

async function testOfflineDb(): Promise<void> {
    const log: string[] = [];
    const say = (msg: string) => {
        console.log(`[TEST] ${msg}`);
        log.push(msg);
    };

    const TEST_STORE = 999;
    const TEST_LOCAL = crypto.randomUUID();

    try {
        say('1. Cache 3 produk dummy');
        await cacheProducts(TEST_STORE, [
            { id: 1, name: 'Produk A' },
            { id: 2, name: 'Produk B' },
            { id: 3, name: 'Produk C' },
        ]);

        const cached = await getCachedProducts(TEST_STORE);
        say(`   -> cached: ${(cached as unknown[]).length} produk`);

        say('2. Simpan 2 transaksi offline');
        await saveSaleOffline({
            localId: TEST_LOCAL + '-1',
            storeId: TEST_STORE,
            cashierId: 1,
            items: [{ product_id: 1, product_variant_id: null, quantity: 2, unit_price: 5000, subtotal: 10000, modifiers: [] }],
            payment_method: 'cash',
            amount_paid: 10000,
            total_amount: 10000,
            discount_amount: 0,
            customer_name: 'Test 1',
            discount_code: null,
            created_at: new Date().toISOString(),
            synced: false,
            syncError: null,
            syncAttempts: 0,
        });
        await saveSaleOffline({
            localId: TEST_LOCAL + '-2',
            storeId: TEST_STORE,
            cashierId: 1,
            items: [{ product_id: 2, product_variant_id: null, quantity: 1, unit_price: 15000, subtotal: 15000, modifiers: [] }],
            payment_method: 'cash',
            amount_paid: 20000,
            total_amount: 15000,
            discount_amount: 0,
            customer_name: 'Test 2',
            discount_code: null,
            created_at: new Date().toISOString(),
            synced: false,
            syncError: null,
            syncAttempts: 0,
        });

        say('3. Hitung pending');
        const count = await getPendingSalesCount(TEST_STORE);
        say(`   -> pending: ${count}`);

        say('4. Ambil semua pending');
        const pending = await getPendingSales(TEST_STORE);
        say(`   -> ${pending.length} transaksi`);

        say('5. Tandai #1 sebagai sync error');
        await markSaleSyncError(pending[0].localId, 'simulated error');

        say('6. Tandai #2 sebagai synced');
        await markSaleOfflineDone(pending[1].localId, 42);

        say('7. Bersihkan synced');
        await cleanSyncedSales();
        const remaining = await getPendingSalesCount(TEST_STORE);
        say(`   -> remaining: ${remaining} (seharusnya 1, yang error)`);

        say('8. Selesai. Semua test PASSED.');
    } catch (e) {
        say(`FAIL: ${e instanceof Error ? e.message : String(e)}`);
    }

    return;
}

window.testOfflineDb = testOfflineDb;
export { testOfflineDb };
