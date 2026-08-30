import { cacheProducts, getCachedProducts } from '@/lib/offlineDb';
import { useOnline } from '@vueuse/core';
import { ref } from 'vue';

const isSyncing = ref(false);
const lastSync = ref<string | null>(null);

export function useProductCache() {
    const isOnline = useOnline();
    const CATALOG_STALE_MS = 60 * 60 * 1000; // 1 hour

    async function syncCatalog(storeId: number, force = false) {
        if (isSyncing.value) return { skipped: true };
        if (!isOnline.value) return { skipped: true };
        if (!storeId) return { skipped: true };

        const lastSyncTimeKey = `catalog_last_sync_${storeId}`;
        const lastSyncTime = Number(localStorage.getItem(lastSyncTimeKey)) || 0;
        const isStale = Date.now() - lastSyncTime > CATALOG_STALE_MS;

        if (!force && !isStale) {
            return { skipped: true };
        }

        isSyncing.value = true;
        try {
            const params: Record<string, string> = {};
            if (lastSyncTime) {
                params.updated_after = String(lastSyncTime);
            }
            const queryParams = new URLSearchParams(params).toString();
            const res = await fetch(`/products/catalog?${queryParams}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!res.ok) {
                throw new Error(`Server returned status ${res.status}`);
            }

            const data = (await res.json()) as {
                products: any[];
                total: number;
                synced_at: number;
            };

            let cached = (await getCachedProducts(storeId)) || [];

            if (!lastSyncTime) {
                // Full sync
                cached = data.products;
            } else {
                // Incremental sync merge
                const incoming = data.products;
                for (const p of incoming) {
                    if (p.deleted_at || !p.is_active) {
                        // Remove from cache
                        cached = cached.filter((item: any) => item.id !== p.id);
                    } else {
                        // Insert or update
                        const index = cached.findIndex((item: any) => item.id === p.id);
                        if (index !== -1) {
                            cached[index] = p;
                        } else {
                            cached.push(p);
                        }
                    }
                }
            }

            await cacheProducts(storeId, cached);

            localStorage.setItem(lastSyncTimeKey, String(data.synced_at));
            lastSync.value = new Date(data.synced_at).toISOString();

            return { synced: data.products.length };
        } catch (e) {
            console.error('Catalog sync error:', e);
            throw e;
        } finally {
            isSyncing.value = false;
        }
    }

    async function searchProducts(storeId: number, query: string) {
        if (!query || query.length < 2) return [];
        const cached = (await getCachedProducts(storeId)) || [];
        const q = query.toLowerCase().trim();
        return (cached as any[])
            .filter((p: any) => {
                const nameMatch = p.name?.toLowerCase().includes(q);
                const skuMatch = p.sku?.toLowerCase().includes(q);
                const barcodeMatch = p.barcode?.includes(q);
                const categoryMatch = p.category?.toLowerCase().includes(q);
                return nameMatch || skuMatch || barcodeMatch || categoryMatch;
            })
            .slice(0, 50);
    }

    async function getProductByBarcode(storeId: number, barcode: string) {
        if (!barcode) return null;
        const cached = (await getCachedProducts(storeId)) || [];
        return (cached as any[]).find((p: any) => p.barcode === barcode) || null;
    }

    async function getProductById(storeId: number, id: number) {
        const cached = (await getCachedProducts(storeId)) || [];
        return (cached as any[]).find((p: any) => p.id === id) || null;
    }

    return {
        isSyncing,
        lastSync,
        syncCatalog,
        searchProducts,
        getProductByBarcode,
        getProductById,
    };
}
