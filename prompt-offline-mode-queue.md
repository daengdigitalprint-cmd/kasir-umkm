# PROMPT IMPLEMENTASI: Offline Mode + Queue
## Aplikasi Kasir Android (Laravel + Vue.js + IndexedDB)

---

## 🎯 SPESIFIKASI

- **Sync method:** Batch sekaligus ke Laravel
- **Retensi data offline:** Maksimal 1 hari (auto-purge setelah 24 jam)
- **Produk saat offline:** Cache dari IndexedDB + sync katalog dari server
- **Target:** Android WebView (tablet & HP)

---

## 🏗️ ARSITEKTUR KESELURUHAN

```
ONLINE:
Laravel API ←──────────────────── Vue.js (normal flow)
     ↓ catalog sync                    ↑ batch sync saat online
IndexedDB ──── produk cache      queue manager

OFFLINE:
IndexedDB ──── produk cache ───→ Vue.js kasir tetap jalan
                   ↓
            transaksi pending ──→ antri di IndexedDB
                   ↓
        saat online: batch sync ke Laravel
```

### IndexedDB Stores:
| Store | Isi |
|---|---|
| `transactions` | Transaksi pending / synced / error |
| `products` | Katalog produk cache |
| `sync_meta` | Timestamp last sync katalog & transaksi |

### File yang dibuat:
```
Android:
└── NetworkBridge.kt               ← deteksi koneksi

Vue.js:
├── composables/
│   ├── useOfflineDB.js            ← IndexedDB wrapper
│   ├── useProductCache.js         ← katalog produk offline
│   └── useTransactionQueue.js     ← queue + batch sync
└── components/
    └── OfflineStatusBar.vue       ← UI indicator

Laravel:
├── routes/api.php
├── Http/Controllers/
│   ├── TransactionSyncController.php
│   └── ProductCatalogController.php
└── Http/Requests/
    └── BatchSyncRequest.php
```

---

## TAHAP 1 — Android: Network Bridge

### Perintah:
> Buat `NetworkBridge.kt` dengan JavascriptInterface untuk cek status koneksi.
> Daftarkan ConnectivityManager callback yang broadcast event ke Vue.js
> saat koneksi berubah (online/offline).

```kotlin
// NetworkBridge.kt
class NetworkBridge(
    private val context: Context,
    private val activity: Activity,
    private val webView: WebView
) {
    private val connectivityManager =
        context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

    // ─── CEK KONEKSI SAAT INI ────────────────────────────────────────────────

    @JavascriptInterface
    fun isOnline(): Boolean {
        val network = connectivityManager.activeNetwork ?: return false
        val caps = connectivityManager.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
               caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    // ─── REGISTER NETWORK CALLBACK ───────────────────────────────────────────

    fun registerNetworkCallback() {
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()

        connectivityManager.registerNetworkCallback(request, object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                invokeJs("window.dispatchEvent(new CustomEvent('networkChange', { detail: { online: true } }))")
            }
            override fun onLost(network: Network) {
                invokeJs("window.dispatchEvent(new CustomEvent('networkChange', { detail: { online: false } }))")
            }
        })
    }

    private fun invokeJs(script: String) {
        activity.runOnUiThread { webView.evaluateJavascript(script, null) }
    }
}
```

**Register di `MainActivity.kt`:**
```kotlin
val netBridge = NetworkBridge(this, this, webView)
webView.addJavascriptInterface(netBridge, "NetworkStatus")

// Panggil setelah WebView ready
netBridge.registerNetworkCallback()
```

### ✅ Checklist Tahap 1:
- [ ] `window.NetworkStatus.isOnline()` return true/false dari Vue.js
- [ ] Event `networkChange` ter-dispatch saat koneksi berubah
- [ ] Test: airplane mode ON/OFF → event terpicu

---

## TAHAP 2 — Vue.js: IndexedDB Wrapper

### Perintah:
> Buat `composables/useOfflineDB.js` sebagai wrapper IndexedDB.
> Buat database `kasir_offline` dengan 3 object store:
> `transactions`, `products`, `sync_meta`.
> Expose fungsi CRUD yang menggunakan Promise (async/await friendly).

```javascript
// composables/useOfflineDB.js

const DB_NAME = 'kasir_offline'
const DB_VERSION = 1

let _db = null

const openDB = () => new Promise((resolve, reject) => {
    if (_db) return resolve(_db)

    const req = indexedDB.open(DB_NAME, DB_VERSION)

    req.onupgradeneeded = (e) => {
        const db = e.target.result

        // ── TRANSACTIONS store
        if (!db.objectStoreNames.contains('transactions')) {
            const txStore = db.createObjectStore('transactions', { keyPath: 'local_id' })
            txStore.createIndex('status', 'status')           // pending | synced | error
            txStore.createIndex('created_at', 'created_at')   // untuk auto-purge
        }

        // ── PRODUCTS store
        if (!db.objectStoreNames.contains('products')) {
            const prodStore = db.createObjectStore('products', { keyPath: 'id' })
            prodStore.createIndex('barcode', 'barcode', { unique: false })
            prodStore.createIndex('name', 'name')
            prodStore.createIndex('category_id', 'category_id')
        }

        // ── SYNC META store
        if (!db.objectStoreNames.contains('sync_meta')) {
            db.createObjectStore('sync_meta', { keyPath: 'key' })
        }
    }

    req.onsuccess = (e) => { _db = e.target.result; resolve(_db) }
    req.onerror = () => reject(req.error)
})

// ─── GENERIC HELPERS ─────────────────────────────────────────────────────────

const dbGet = async (store, key) => {
    const db = await openDB()
    return new Promise((resolve, reject) => {
        const req = db.transaction(store, 'readonly').objectStore(store).get(key)
        req.onsuccess = () => resolve(req.result)
        req.onerror = () => reject(req.error)
    })
}

const dbPut = async (store, value) => {
    const db = await openDB()
    return new Promise((resolve, reject) => {
        const req = db.transaction(store, 'readwrite').objectStore(store).put(value)
        req.onsuccess = () => resolve(req.result)
        req.onerror = () => reject(req.error)
    })
}

const dbGetAll = async (store, indexName = null, query = null) => {
    const db = await openDB()
    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, 'readonly')
        const s = tx.objectStore(store)
        const req = indexName ? s.index(indexName).getAll(query) : s.getAll()
        req.onsuccess = () => resolve(req.result)
        req.onerror = () => reject(req.error)
    })
}

const dbDelete = async (store, key) => {
    const db = await openDB()
    return new Promise((resolve, reject) => {
        const req = db.transaction(store, 'readwrite').objectStore(store).delete(key)
        req.onsuccess = () => resolve()
        req.onerror = () => reject(req.error)
    })
}

const dbClear = async (store) => {
    const db = await openDB()
    return new Promise((resolve, reject) => {
        const req = db.transaction(store, 'readwrite').objectStore(store).clear()
        req.onsuccess = () => resolve()
        req.onerror = () => reject(req.error)
    })
}

export const useOfflineDB = () => ({
    openDB, dbGet, dbPut, dbGetAll, dbDelete, dbClear
})
```

### ✅ Checklist Tahap 2:
- [ ] Database terbuat di browser (cek DevTools → Application → IndexedDB)
- [ ] Ketiga store ada: `transactions`, `products`, `sync_meta`
- [ ] Index terbuat dengan benar

---

## TAHAP 3 — Vue.js: Product Cache

### Perintah:
> Buat `composables/useProductCache.js`.
> Implementasikan:
> 1. `syncCatalog()` — fetch produk dari Laravel, simpan ke IndexedDB, catat timestamp
> 2. `searchProducts(query)` — cari produk dari IndexedDB (by name atau barcode)
> 3. `getProductByBarcode(barcode)` — lookup saat scan barcode
> 4. Auto-sync inkremental: kirim `updated_after` timestamp ke Laravel agar hanya dapat delta
> 5. Sinkronisasi otomatis saat app online dan katalog > 1 jam belum diupdate

```javascript
// composables/useProductCache.js
import { ref } from 'vue'
import { useOfflineDB } from './useOfflineDB'
import axios from 'axios'

export function useProductCache() {
    const { dbPut, dbGetAll, dbGet, dbClear } = useOfflineDB()
    const isSyncing = ref(false)
    const lastSync = ref(null)

    const CATALOG_STALE_MS = 60 * 60 * 1000  // 1 jam

    // ─── SYNC KATALOG DARI SERVER ────────────────────────────────────────────

    const syncCatalog = async (force = false) => {
        // Cek apakah perlu sync
        const meta = await dbGet('sync_meta', 'catalog_last_sync')
        const lastSyncTime = meta?.value || 0
        const isStale = Date.now() - lastSyncTime > CATALOG_STALE_MS

        if (!force && !isStale) return { skipped: true }

        isSyncing.value = true
        try {
            // Inkremental: kirim timestamp → server return produk yang berubah saja
            const params = lastSyncTime ? { updated_after: lastSyncTime } : {}
            const { data } = await axios.get('/api/products/catalog', { params })

            // Jika full sync (pertama kali), clear dulu
            if (!lastSyncTime) await dbClear('products')

            // Simpan semua produk ke IndexedDB
            for (const product of data.products) {
                if (product.deleted_at) {
                    // Hapus produk yang sudah didelete
                    await dbDelete('products', product.id)
                } else {
                    await dbPut('products', product)
                }
            }

            // Update timestamp
            await dbPut('sync_meta', {
                key: 'catalog_last_sync',
                value: Date.now(),
                count: data.products.length
            })

            lastSync.value = new Date()
            return { synced: data.products.length }

        } finally {
            isSyncing.value = false
        }
    }

    // ─── SEARCH PRODUK OFFLINE ────────────────────────────────────────────────

    const searchProducts = async (query) => {
        if (!query || query.length < 2) return []
        const all = await dbGetAll('products')
        const q = query.toLowerCase()
        return all.filter(p =>
            p.name?.toLowerCase().includes(q) ||
            p.sku?.toLowerCase().includes(q) ||
            p.barcode?.includes(q)
        ).slice(0, 20) // limit 20 hasil
    }

    const getProductByBarcode = async (barcode) => {
        const all = await dbGetAll('products', 'barcode', barcode)
        return all[0] || null
    }

    const getProductById = async (id) => {
        return dbGet('products', id)
    }

    return {
        isSyncing, lastSync,
        syncCatalog, searchProducts,
        getProductByBarcode, getProductById
    }
}
```

### ✅ Checklist Tahap 3:
- [ ] Pertama buka app (online) → `syncCatalog()` fetch semua produk
- [ ] Produk tersimpan di IndexedDB `products` store
- [ ] `searchProducts('beras')` return hasil dari cache tanpa hit server
- [ ] Sync kedua (inkremental) hanya kirim timestamp, dapat delta saja

---

## TAHAP 4 — Vue.js: Transaction Queue Manager

### Perintah:
> Buat `composables/useTransactionQueue.js`.
> Implementasikan:
> 1. `saveTransaction(data)` — simpan ke IndexedDB dengan status 'pending', generate local_id unik
> 2. `batchSync()` — ambil semua 'pending', kirim sekaligus ke `/api/transactions/sync`
> 3. `purgeExpired()` — hapus transaksi 'synced' yang lebih dari 24 jam
> 4. Auto-trigger sync saat event 'networkChange' online
> 5. Status: pendingCount, isSyncing, lastSyncResult

```javascript
// composables/useTransactionQueue.js
import { ref, onMounted, onUnmounted } from 'vue'
import { useOfflineDB } from './useOfflineDB'
import axios from 'axios'

export function useTransactionQueue() {
    const { dbPut, dbGetAll, dbDelete } = useOfflineDB()

    const pendingCount = ref(0)
    const isSyncing = ref(false)
    const lastSyncResult = ref(null) // { success: 3, failed: 0, at: Date }

    const MAX_AGE_MS = 24 * 60 * 60 * 1000  // 24 jam

    // ─── SIMPAN TRANSAKSI BARU ────────────────────────────────────────────────

    const saveTransaction = async (transactionData) => {
        const local_id = `local_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`
        const record = {
            ...transactionData,
            local_id,
            status: 'pending',
            created_at: Date.now(),
            sync_attempts: 0,
            sync_error: null
        }
        await dbPut('transactions', record)
        await refreshPendingCount()
        return local_id
    }

    // ─── BATCH SYNC KE LARAVEL ────────────────────────────────────────────────

    const batchSync = async () => {
        if (isSyncing.value) return

        const pending = await dbGetAll('transactions', 'status', 'pending')
        if (pending.length === 0) return

        isSyncing.value = true
        let successCount = 0
        let failedCount = 0

        try {
            const { data } = await axios.post('/api/transactions/sync', {
                transactions: pending.map(t => ({
                    local_id: t.local_id,
                    ...t.payload      // payload = data transaksi yang dikirim ke server
                }))
            })

            // Proses hasil per transaksi dari server
            for (const result of data.results) {
                const record = pending.find(p => p.local_id === result.local_id)
                if (!record) continue

                if (result.status === 'success' || result.status === 'duplicate') {
                    // Update status jadi synced
                    await dbPut('transactions', {
                        ...record,
                        status: 'synced',
                        server_id: result.transaction_id,
                        synced_at: Date.now()
                    })
                    successCount++
                } else {
                    // Tandai error, increment attempt
                    await dbPut('transactions', {
                        ...record,
                        status: 'error',
                        sync_attempts: (record.sync_attempts || 0) + 1,
                        sync_error: result.message
                    })
                    failedCount++
                }
            }

            lastSyncResult.value = {
                success: successCount,
                failed: failedCount,
                at: new Date()
            }

        } catch (e) {
            // Network error — tandai semua sebagai error sementara
            for (const t of pending) {
                await dbPut('transactions', {
                    ...t,
                    sync_attempts: (t.sync_attempts || 0) + 1,
                    sync_error: e.message
                })
            }
            failedCount = pending.length
        } finally {
            isSyncing.value = false
            await refreshPendingCount()
        }

        return { successCount, failedCount }
    }

    // ─── AUTO-PURGE 24 JAM ────────────────────────────────────────────────────

    const purgeExpired = async () => {
        const synced = await dbGetAll('transactions', 'status', 'synced')
        const cutoff = Date.now() - MAX_AGE_MS

        for (const t of synced) {
            if (t.synced_at && t.synced_at < cutoff) {
                await dbDelete('transactions', t.local_id)
            }
        }
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    const refreshPendingCount = async () => {
        const pending = await dbGetAll('transactions', 'status', 'pending')
        pendingCount.value = pending.length
    }

    const getPendingTransactions = () => dbGetAll('transactions', 'status', 'pending')
    const getErrorTransactions = () => dbGetAll('transactions', 'status', 'error')

    // ─── NETWORK EVENT LISTENER ───────────────────────────────────────────────

    const onNetworkChange = async (e) => {
        if (e.detail.online) {
            await batchSync()  // Auto-sync saat online
        }
    }

    onMounted(async () => {
        await refreshPendingCount()
        await purgeExpired()                        // Bersihkan yang expired tiap buka app
        window.addEventListener('networkChange', onNetworkChange)
    })

    onUnmounted(() => {
        window.removeEventListener('networkChange', onNetworkChange)
    })

    return {
        pendingCount, isSyncing, lastSyncResult,
        saveTransaction, batchSync,
        getPendingTransactions, getErrorTransactions,
        purgeExpired, refreshPendingCount
    }
}
```

### ✅ Checklist Tahap 4:
- [ ] `saveTransaction()` simpan ke IndexedDB dengan `local_id` unik
- [ ] `pendingCount` terupdate setelah save
- [ ] `batchSync()` kirim semua pending sekaligus ke Laravel
- [ ] Hasil sync per-item diproses (success/duplicate/error)
- [ ] `purgeExpired()` bersihkan data > 24 jam
- [ ] Auto-sync terpicu saat event `networkChange` online

---

## TAHAP 5 — Vue.js: Offline Status Bar Component

### Perintah:
> Buat `components/OfflineStatusBar.vue` yang tampil di atas halaman kasir.
> Tampilkan status: Online/Offline, badge pending count, tombol manual sync,
> dan hasil sync terakhir.

```vue
<!-- components/OfflineStatusBar.vue -->
<template>
  <!-- Hanya tampil jika offline atau ada pending -->
  <Transition name="slide-down">
    <div v-if="!isOnline || pendingCount > 0" :class="barClass">

      <!-- OFFLINE -->
      <div v-if="!isOnline" class="flex items-center gap-2">
        <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
        <span class="text-sm font-medium">Mode Offline</span>
        <span v-if="pendingCount > 0" class="ml-2 bg-white/20 rounded-full px-2 py-0.5 text-xs">
          {{ pendingCount }} transaksi menunggu sync
        </span>
      </div>

      <!-- ONLINE TAPI ADA PENDING -->
      <div v-else class="flex items-center justify-between w-full">
        <div class="flex items-center gap-2">
          <span class="text-sm">
            {{ isSyncing ? '⏳ Menyinkronkan...' : `📤 ${pendingCount} transaksi belum tersync` }}
          </span>
        </div>
        <button
          v-if="!isSyncing"
          @click="batchSync"
          class="text-xs bg-white/20 hover:bg-white/30 rounded px-3 py-1 transition">
          Sync Sekarang
        </button>
      </div>

      <!-- Hasil sync terakhir -->
      <div v-if="lastSyncResult && isOnline && pendingCount === 0"
           class="text-xs opacity-75 mt-0.5">
        ✓ Sync berhasil {{ lastSyncResult.success }} transaksi
        · {{ formatTime(lastSyncResult.at) }}
      </div>

    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useTransactionQueue } from '@/composables/useTransactionQueue'

const isOnline = ref(true)
const { pendingCount, isSyncing, lastSyncResult, batchSync } = useTransactionQueue()

const barClass = computed(() => [
    'px-4 py-2 text-white text-sm transition-all duration-300',
    !isOnline.value ? 'bg-red-500' : 'bg-amber-500'
])

const formatTime = (date) => date?.toLocaleTimeString('id-ID', {
    hour: '2-digit', minute: '2-digit'
})

const onNetworkChange = (e) => { isOnline.value = e.detail.online }

onMounted(() => {
    // Cek kondisi awal via Android bridge
    if (typeof window.NetworkStatus !== 'undefined') {
        isOnline.value = window.NetworkStatus.isOnline()
    }
    window.addEventListener('networkChange', onNetworkChange)
})
onUnmounted(() => window.removeEventListener('networkChange', onNetworkChange))
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s ease; }
.slide-down-enter-from, .slide-down-leave-to { transform: translateY(-100%); opacity: 0; }
</style>
```

### ✅ Checklist Tahap 5:
- [ ] Bar merah muncul saat offline
- [ ] Bar kuning muncul saat online tapi ada pending
- [ ] Badge angka pending akurat
- [ ] Tombol "Sync Sekarang" berfungsi
- [ ] Hilang otomatis saat semua ter-sync

---

## TAHAP 6 — Integrasi ke Checkout Vue.js

### Perintah:
> Modifikasi halaman Checkout.vue.
> Cek status online sebelum submit. Jika offline, simpan ke queue.
> Jika online, coba kirim ke server. Jika gagal, fallback ke queue.
> Print struk tetap jalan di kedua kondisi.

```javascript
// Di Checkout.vue
import { useTransactionQueue } from '@/composables/useTransactionQueue'
import { usePrinter } from '@/composables/usePrinter'

const { saveTransaction, batchSync } = useTransactionQueue()
const { printReceipt, kickDrawer, isConnected } = usePrinter()

const submitTransaction = async () => {
    isSubmitting.value = true

    // Data transaksi
    const transactionPayload = {
        invoice_no: generateInvoiceNo(),  // generate di client: INV-{timestamp}
        cashier_id: authUser.id,
        items: cart.value,
        subtotal: subtotal.value,
        discount: discount.value,
        tax: tax.value,
        total: total.value,
        paid: paymentAmount.value,
        change: paymentAmount.value - total.value,
        payment_method: paymentMethod.value
    }

    const isOnline = typeof window.NetworkStatus !== 'undefined'
        ? window.NetworkStatus.isOnline()
        : navigator.onLine

    let savedToServer = false

    if (isOnline) {
        try {
            // Coba submit langsung ke server
            await axios.post('/api/transactions', transactionPayload)
            savedToServer = true
        } catch (e) {
            // Gagal → fallback ke queue
            console.warn('Server error, saving offline:', e.message)
        }
    }

    if (!savedToServer) {
        // Simpan ke IndexedDB queue
        await saveTransaction({ payload: transactionPayload })
    }

    // ── Cetak struk (online maupun offline)
    const receiptData = buildReceiptData(transactionPayload)
    if (isConnected.value) {
        await printReceipt(receiptData)
        kickDrawer()
    }

    // ── Feedback ke kasir
    showSuccessModal({
        message: savedToServer
            ? 'Transaksi berhasil disimpan'
            : 'Transaksi tersimpan offline, akan sync otomatis saat online',
        offline: !savedToServer
    })

    resetCart()
    isSubmitting.value = false
}

const generateInvoiceNo = () => {
    const now = new Date()
    const pad = (n) => n.toString().padStart(2, '0')
    return `INV-${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${Date.now()}`
}
```

### ✅ Checklist Tahap 6:
- [ ] Offline → transaksi masuk queue, struk tetap tercetak
- [ ] Online → transaksi langsung ke server, queue bypass
- [ ] Invoice number unik dari client (format: INV-YYYYMMDD-timestamp)
- [ ] Kasir dapat feedback yang benar di kedua kondisi

---

## TAHAP 7 — Laravel: Catalog Endpoint

### Perintah:
> Buat `ProductCatalogController.php` dengan endpoint `GET /api/products/catalog`.
> Support parameter `updated_after` (unix timestamp) untuk inkremental sync.
> Return juga produk yang sudah deleted (tandai `deleted_at`) agar client bisa hapus dari cache.

```php
// Http/Controllers/ProductCatalogController.php

class ProductCatalogController extends Controller
{
    public function index(Request $request)
    {
        $updatedAfter = $request->query('updated_after');

        $query = Product::withTrashed()  // include soft-deleted
            ->select([
                'id', 'name', 'sku', 'barcode',
                'price', 'category_id', 'unit',
                'stock', 'image_url',
                'updated_at', 'deleted_at'
            ]);

        if ($updatedAfter) {
            // Inkremental: hanya produk yang berubah setelah timestamp
            $query->where('updated_at', '>=',
                Carbon::createFromTimestampMs($updatedAfter)
            );
        }

        $products = $query->get()->map(function ($p) {
            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'sku'         => $p->sku,
                'barcode'     => $p->barcode,
                'price'       => (int) $p->price,
                'category_id' => $p->category_id,
                'unit'        => $p->unit,
                'stock'       => $p->stock,
                'image_url'   => $p->image_url,
                'deleted_at'  => $p->deleted_at?->timestamp
            ];
        });

        return response()->json([
            'products'    => $products,
            'total'       => $products->count(),
            'synced_at'   => now()->timestampMs(),
        ]);
    }
}
```

**Route:**
```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products/catalog', [ProductCatalogController::class, 'index']);
});
```

### ✅ Checklist Tahap 7:
- [ ] `/api/products/catalog` tanpa param → return semua produk
- [ ] `/api/products/catalog?updated_after=1234567890000` → return delta saja
- [ ] Produk soft-deleted ikut di response (untuk client-side delete)
- [ ] Response time < 2 detik untuk 1000 produk

---

## TAHAP 8 — Laravel: Batch Sync Endpoint

### Perintah:
> Buat `TransactionSyncController.php` dengan endpoint `POST /api/transactions/sync`.
> Terima array `transactions`. Proses setiap item dengan idempotency check via `invoice_no`.
> Return status per item: success / duplicate / error.
> Wrap semua dalam database transaction.

```php
// Http/Controllers/TransactionSyncController.php

class TransactionSyncController extends Controller
{
    public function batchSync(BatchSyncRequest $request)
    {
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($request->transactions as $txData) {
                $results[] = $this->processSingle($txData);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Sync gagal: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'results' => $results,
            'synced_at' => now()->toISOString()
        ]);
    }

    private function processSingle(array $data): array
    {
        // ── IDEMPOTENCY CHECK ──────────────────────────────────────────────
        $existing = Transaction::where('invoice_no', $data['invoice_no'])->first();

        if ($existing) {
            return [
                'local_id'       => $data['local_id'],
                'status'         => 'duplicate',
                'transaction_id' => $existing->id,
                'message'        => 'Invoice sudah ada di server'
            ];
        }

        // ── SIMPAN TRANSAKSI BARU ──────────────────────────────────────────
        $transaction = Transaction::create([
            'invoice_no'     => $data['invoice_no'],
            'cashier_id'     => $data['cashier_id'],
            'subtotal'       => $data['subtotal'],
            'discount'       => $data['discount'] ?? 0,
            'tax'            => $data['tax'] ?? 0,
            'total'          => $data['total'],
            'paid'           => $data['paid'],
            'change'         => $data['change'],
            'payment_method' => $data['payment_method'],
            'created_at'     => $data['created_at'] ?? now(),  // pakai waktu client
        ]);

        // ── SIMPAN ITEMS ───────────────────────────────────────────────────
        foreach ($data['items'] as $item) {
            $transaction->items()->create([
                'product_id'   => $item['product_id'],
                'product_name' => $item['name'],
                'qty'          => $item['qty'],
                'unit_price'   => $item['price'],
                'subtotal'     => $item['qty'] * $item['price'],
            ]);

            // Update stok
            Product::where('id', $item['product_id'])
                ->decrement('stock', $item['qty']);
        }

        return [
            'local_id'       => $data['local_id'],
            'status'         => 'success',
            'transaction_id' => $transaction->id,
            'message'        => 'OK'
        ];
    }
}
```

**Form Request:**
```php
// Http/Requests/BatchSyncRequest.php
class BatchSyncRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'transactions'                  => 'required|array|max:100',
            'transactions.*.local_id'       => 'required|string',
            'transactions.*.invoice_no'     => 'required|string',
            'transactions.*.cashier_id'     => 'required|integer',
            'transactions.*.items'          => 'required|array|min:1',
            'transactions.*.items.*.product_id' => 'required|integer',
            'transactions.*.items.*.qty'    => 'required|integer|min:1',
            'transactions.*.items.*.price'  => 'required|numeric|min:0',
            'transactions.*.total'          => 'required|numeric|min:0',
            'transactions.*.paid'           => 'required|numeric',
        ];
    }
}
```

**Route:**
```php
Route::post('/transactions/sync', [TransactionSyncController::class, 'batchSync']);
```

### ✅ Checklist Tahap 8:
- [ ] Kirim 3 transaksi → 3 sukses masuk DB
- [ ] Kirim ulang transaksi yang sama → status 'duplicate', tidak double insert
- [ ] Satu item error → tidak rollback item lain (proses per-item, bukan all-or-nothing)
- [ ] Stok produk terpotong saat sync
- [ ] Validasi request bekerja

---

## TAHAP 9 — Testing Skenario Lengkap

### Perintah:
> Jalankan semua skenario berikut secara manual di device Android:

```
SKENARIO 1 — Full Offline
1. Airplane mode ON
2. Buka app → status bar merah "Mode Offline" muncul
3. Search produk → hasil muncul dari IndexedDB cache
4. Buat 3 transaksi → semua cetak struk, masuk queue
5. Status bar: "3 transaksi menunggu sync"
6. Airplane mode OFF
7. Auto-sync terpicu → status bar hilang
8. Cek Laravel DB → 3 transaksi masuk

SKENARIO 2 — Koneksi Putus Saat Submit
1. Internet normal
2. Saat tap Submit → putus internet (simulate dengan airplane mode cepat)
3. Transaksi masuk queue (fallback)
4. Struk tetap tercetak
5. Internet nyala → auto-sync

SKENARIO 3 — Idempotency
1. Sync 2 transaksi → sukses
2. Matikan app → nyalakan lagi (sebelum status di IndexedDB diupdate)
3. Sync ulang transaksi yang sama
4. Server return 'duplicate' → tidak double insert di DB

SKENARIO 4 — Purge 24 Jam
1. Set waktu device maju 25 jam (atau mock MAX_AGE_MS = 5000ms)
2. Buka app → `purgeExpired()` jalan
3. Transaksi 'synced' yang lama terhapus dari IndexedDB
4. Transaksi 'pending' TIDAK terhapus

SKENARIO 5 — Catalog Sync
1. Tambah produk baru di Laravel admin
2. Buka app → sync katalog (inkremental)
3. Produk baru muncul saat search offline
```

### ✅ Checklist Tahap 9:
- [ ] Semua 5 skenario pass
- [ ] Tidak ada data hilang di skenario manapun
- [ ] Stok terpotong akurat setelah sync

---

## ⚠️ BEST PRACTICES

1. **Invoice No dari client** — generate di Vue.js saat transaksi dibuat (INV-YYYYMMDD-{timestamp}),
   bukan dari server. Ini yang jadi idempotency key.

2. **created_at dari client** — kirim waktu transaksi dari device, bukan server time,
   agar laporan harian akurat meski sync delay.

3. **Jangan sync saat sync** — guard `if (isSyncing.value) return` mencegah double sync.

4. **Batch max 100** — validasi di Laravel `max:100` per batch untuk cegah timeout.

5. **Stok offline tidak terpotong** — stok di cache tidak dikurangi saat offline,
   biar tidak salah. Stok baru diupdate setelah sync ke server.

6. **Error transactions** — tampilkan di menu "Riwayat Offline" agar kasir bisa
   manual retry atau lapor ke admin.
