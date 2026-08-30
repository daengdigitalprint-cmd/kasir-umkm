# PROMPT IMPLEMENTASI: Android WebView + Bluetooth Printer Bridge
## Aplikasi Kasir Web (Laravel + Vue.js) → Thermal Printer via Bluetooth Classic (SPP)

---

## 🎯 KONTEKS PROYEK

Saya membangun aplikasi kasir berbasis web (Laravel backend + Vue.js frontend) yang akan didistribusikan
sebagai **Android APK menggunakan WebView**. Target pengguna menggunakan **tablet dan HP Android**
dengan thermal printer Bluetooth (protokol SPP/Classic, bukan BLE).

**Tujuan:** Membuat jembatan (bridge) antara Vue.js di WebView dan printer Bluetooth Android secara
native, stabil, dan siap produksi.

**Stack:**
- Backend: Laravel (REST API)
- Frontend: Vue.js 3 (Composition API)
- Android: Kotlin (WebView Activity)
- Printer: Thermal 58mm/80mm dengan Bluetooth Classic SPP
- Distribusi: APK direct / Google Play Store

---

## 📋 TAHAPAN IMPLEMENTASI

Implementasikan secara **berurutan, tahap per tahap**. Jangan lanjut ke tahap berikutnya
sebelum tahap sebelumnya selesai dan dikonfirmasi.

---

## TAHAP 1 — Setup Android Project

### Perintah:
> Buat Android project baru dengan Kotlin. Setup WebView Activity sebagai main activity.
> Tambahkan semua dependency dan permission yang dibutuhkan untuk Bluetooth Classic dan WebView.

### Yang harus diimplementasikan:

**`AndroidManifest.xml` — tambahkan permissions:**
```xml
<!-- Bluetooth permissions -->
<uses-permission android:name="android.permission.BLUETOOTH" android:maxSdkVersion="30"/>
<uses-permission android:name="android.permission.BLUETOOTH_ADMIN" android:maxSdkVersion="30"/>
<uses-permission android:name="android.permission.BLUETOOTH_CONNECT"/>
<uses-permission android:name="android.permission.BLUETOOTH_SCAN"/>
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>

<!-- Internet untuk WebView -->
<uses-permission android:name="android.permission.INTERNET"/>
```

**`build.gradle (app)` — pastikan minSdk dan targetSdk:**
```
minSdk = 23
targetSdk = 34
```

### ✅ Checklist Tahap 1:
- [ ] Project terbuat dan bisa build
- [ ] Permissions sudah terdaftar di Manifest
- [ ] Tidak ada error Gradle sync

---

## TAHAP 2 — Setup WebView Activity

### Perintah:
> Buat `MainActivity.kt` dengan WebView yang load URL aplikasi kasir Laravel.
> Enable JavaScript, DOM Storage, dan Mixed Content. Register JavaScript Interface untuk bridge.

### Yang harus diimplementasikan:

```kotlin
// MainActivity.kt
class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var btBridge: BluetoothPrintBridge

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        btBridge = BluetoothPrintBridge(this, webView)

        setupWebView()
        requestBluetoothPermissions()
    }

    private fun setupWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = true
            // Untuk development (HTTP). Production gunakan HTTPS
            mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        }

        // Register bridge — name ini yang dipanggil dari Vue.js
        webView.addJavascriptInterface(btBridge, "BluetoothPrint")

        // Override back button
        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                // Inject status ready ke Vue.js setelah page load
                webView.evaluateJavascript(
                    "window.dispatchEvent(new Event('androidBridgeReady'))", null
                )
            }
        }

        // Load app — ganti dengan URL production atau local IP saat development
        webView.loadUrl("https://kasir.yourdomain.com")
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack()
        else super.onBackPressed()
    }
}
```

### ✅ Checklist Tahap 2:
- [ ] WebView load URL dengan benar
- [ ] JavaScript enabled dan berfungsi
- [ ] Back button behavior sesuai

---

## TAHAP 3 — Bluetooth Permission Handler

### Perintah:
> Buat permission handler untuk Android 12+ (API 31+) yang request BLUETOOTH_CONNECT dan
> BLUETOOTH_SCAN secara runtime. Untuk Android < 12 request ACCESS_FINE_LOCATION.

```kotlin
// PermissionHandler.kt
class PermissionHandler(private val activity: AppCompatActivity) {

    companion object {
        const val REQUEST_CODE_BT = 1001
    }

    fun requestBluetoothPermissions(onGranted: () -> Unit, onDenied: () -> Unit) {
        val permissions = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            arrayOf(
                Manifest.permission.BLUETOOTH_CONNECT,
                Manifest.permission.BLUETOOTH_SCAN
            )
        } else {
            arrayOf(
                Manifest.permission.BLUETOOTH,
                Manifest.permission.BLUETOOTH_ADMIN,
                Manifest.permission.ACCESS_FINE_LOCATION
            )
        }

        val notGranted = permissions.filter {
            ContextCompat.checkSelfPermission(activity, it) != PackageManager.PERMISSION_GRANTED
        }

        if (notGranted.isEmpty()) {
            onGranted()
        } else {
            ActivityCompat.requestPermissions(activity, notGranted.toTypedArray(), REQUEST_CODE_BT)
        }
    }

    fun isBluetoothEnabled(): Boolean {
        val adapter = BluetoothAdapter.getDefaultAdapter()
        return adapter?.isEnabled == true
    }

    fun promptEnableBluetooth(activity: Activity) {
        if (!isBluetoothEnabled()) {
            val enableBtIntent = Intent(BluetoothAdapter.ACTION_REQUEST_ENABLE)
            activity.startActivityForResult(enableBtIntent, 1002)
        }
    }
}
```

### ✅ Checklist Tahap 3:
- [ ] Permission dialog muncul saat pertama buka app
- [ ] Handle Android 11 ke bawah dan Android 12 ke atas
- [ ] Bluetooth enable prompt berfungsi

---

## TAHAP 4 — Bluetooth Bridge Class (Core)

### Perintah:
> Buat class `BluetoothPrintBridge.kt` dengan JavascriptInterface. Implementasikan:
> 1. `scanDevices()` — ambil list paired BT devices, kirim ke Vue.js via callback
> 2. `connect(mac)` — koneksi ke printer, maintain socket
> 3. `print(escposJson)` — kirim data ESC/POS ke printer
> 4. `disconnect()` — tutup koneksi
> Semua operasi BT wajib di background thread. Callback ke Vue.js wajib di UI thread.

```kotlin
// BluetoothPrintBridge.kt
class BluetoothPrintBridge(
    private val activity: Activity,
    private val webView: WebView
) {
    private var btSocket: BluetoothSocket? = null
    private var outputStream: OutputStream? = null
    private val btAdapter: BluetoothAdapter? = BluetoothAdapter.getDefaultAdapter()
    private val executor = Executors.newSingleThreadExecutor()

    // SPP UUID — standar untuk semua thermal printer Bluetooth Classic
    private val SPP_UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    // ─── SCAN PAIRED DEVICES ──────────────────────────────────────────────────

    @JavascriptInterface
    fun scanDevices() {
        try {
            val paired = btAdapter?.bondedDevices ?: emptySet()
            val list = JSONArray()
            paired.forEach { device ->
                JSONObject().apply {
                    put("name", device.name ?: "Unknown")
                    put("mac", device.address)
                    list.put(this)
                }
            }
            invokeJs("window.__btCallback('scan', true, $list, null)")
        } catch (e: Exception) {
            invokeJs("window.__btCallback('scan', false, null, '${e.message}')")
        }
    }

    // ─── CONNECT ──────────────────────────────────────────────────────────────

    @JavascriptInterface
    fun connect(macAddress: String) {
        executor.execute {
            try {
                disconnect() // tutup koneksi sebelumnya jika ada

                val device = btAdapter?.getRemoteDevice(macAddress)
                    ?: throw Exception("Device tidak ditemukan")

                btAdapter.cancelDiscovery()

                btSocket = device.createRfcommSocketToServiceRecord(SPP_UUID)
                btSocket!!.connect()
                outputStream = btSocket!!.outputStream

                invokeJs("window.__btCallback('connect', true, null, null)")
            } catch (e: Exception) {
                btSocket = null
                outputStream = null
                invokeJs("window.__btCallback('connect', false, null, '${e.message}')")
            }
        }
    }

    // ─── PRINT ────────────────────────────────────────────────────────────────

    @JavascriptInterface
    fun print(escposHex: String) {
        executor.execute {
            try {
                if (outputStream == null) {
                    throw Exception("Printer tidak terkoneksi. Hubungkan terlebih dahulu.")
                }

                // escposHex dikirim sebagai hex string dari Vue.js
                val bytes = hexStringToBytes(escposHex)
                outputStream!!.write(bytes)
                outputStream!!.flush()

                invokeJs("window.__btCallback('print', true, null, null)")
            } catch (e: Exception) {
                // Auto-disconnect jika error
                btSocket = null
                outputStream = null
                invokeJs("window.__btCallback('print', false, null, '${e.message}')")
            }
        }
    }

    // ─── DISCONNECT ───────────────────────────────────────────────────────────

    @JavascriptInterface
    fun disconnect() {
        try {
            outputStream?.close()
            btSocket?.close()
        } catch (_: Exception) {} finally {
            outputStream = null
            btSocket = null
        }
    }

    @JavascriptInterface
    fun isConnected(): Boolean = btSocket?.isConnected == true

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private fun invokeJs(script: String) {
        activity.runOnUiThread {
            webView.evaluateJavascript(script, null)
        }
    }

    private fun hexStringToBytes(hex: String): ByteArray {
        val len = hex.length
        val data = ByteArray(len / 2)
        var i = 0
        while (i < len) {
            data[i / 2] = ((Character.digit(hex[i], 16) shl 4) +
                    Character.digit(hex[i + 1], 16)).toByte()
            i += 2
        }
        return data
    }
}
```

### ✅ Checklist Tahap 4:
- [ ] `scanDevices()` return list paired devices
- [ ] `connect()` berhasil establish socket ke printer
- [ ] `print()` kirim bytes ke printer
- [ ] Error handling di semua method
- [ ] Semua BT operation di background thread

---

## TAHAP 5 — Vue.js Composable: usePrinter

### Perintah:
> Buat composable `composables/usePrinter.js` di Vue.js. Implementasikan:
> 1. Bridge wrapper dengan event system menggunakan global callback `window.__btCallback`
> 2. ESC/POS builder untuk struk kasir (header, items, total, footer, cut)
> 3. State management: isConnected, isLoading, selectedPrinter, error
> 4. Data MAC address printer disimpan di localStorage

```javascript
// composables/usePrinter.js
import { ref, onMounted, onUnmounted } from 'vue'

export function usePrinter() {
  const isConnected = ref(false)
  const isLoading = ref(false)
  const error = ref(null)
  const pairedDevices = ref([])
  const selectedPrinter = ref(null)
  const isAndroid = ref(false)

  // ─── BRIDGE DETECTION ────────────────────────────────────────────────────

  const checkBridge = () => {
    isAndroid.value = typeof window.BluetoothPrint !== 'undefined'
    return isAndroid.value
  }

  // ─── GLOBAL CALLBACK (dipanggil dari Android) ────────────────────────────

  onMounted(() => {
    checkBridge()

    // Restore printer dari localStorage
    const saved = localStorage.getItem('printer_device')
    if (saved) selectedPrinter.value = JSON.parse(saved)

    // Bridge callback handler
    window.__btCallback = (action, success, data, errMsg) => {
      isLoading.value = false
      error.value = success ? null : errMsg

      switch (action) {
        case 'scan':
          pairedDevices.value = success ? data : []
          break
        case 'connect':
          isConnected.value = success
          break
        case 'print':
          if (!success) isConnected.value = false
          break
      }
    }

    // Event dari Android saat WebView selesai load
    window.addEventListener('androidBridgeReady', checkBridge)
  })

  onUnmounted(() => {
    window.removeEventListener('androidBridgeReady', checkBridge)
    if (isAndroid.value) window.BluetoothPrint.disconnect()
  })

  // ─── ACTIONS ─────────────────────────────────────────────────────────────

  const scanDevices = () => {
    if (!checkBridge()) return
    isLoading.value = true
    window.BluetoothPrint.scanDevices()
  }

  const connectPrinter = (device) => {
    if (!checkBridge()) return
    isLoading.value = true
    selectedPrinter.value = device
    localStorage.setItem('printer_device', JSON.stringify(device))
    window.BluetoothPrint.connect(device.mac)
  }

  const printReceipt = async (receiptData) => {
    if (!checkBridge()) {
      error.value = 'Fitur print hanya tersedia di aplikasi Android'
      return
    }
    if (!isConnected.value) {
      error.value = 'Printer belum terkoneksi'
      return
    }
    isLoading.value = true
    const hexStr = buildEscPos(receiptData)
    window.BluetoothPrint.print(hexStr)
  }

  const disconnect = () => {
    if (isAndroid.value) window.BluetoothPrint.disconnect()
    isConnected.value = false
  }

  // ─── ESC/POS BUILDER ─────────────────────────────────────────────────────
  // Menghasilkan hex string dari ESC/POS commands

  const buildEscPos = (receipt) => {
    const bytes = []
    const enc = (str) => new TextEncoder().encode(str)

    const push = (...args) => args.forEach(b => bytes.push(b))
    const pushStr = (str) => enc(str).forEach(b => bytes.push(b))

    const ESC = 0x1B
    const GS = 0x1D

    // Init printer
    push(ESC, 0x40)

    // Charset: PC437
    push(ESC, 0x74, 0x00)

    // ── CENTER alignment
    push(ESC, 0x61, 0x01)
    // Bold ON
    push(ESC, 0x45, 0x01)
    pushStr(truncate(receipt.storeName, 32) + '\n')
    // Bold OFF
    push(ESC, 0x45, 0x00)

    if (receipt.storeAddress) pushStr(truncate(receipt.storeAddress, 32) + '\n')
    if (receipt.storePhone) pushStr(receipt.storePhone + '\n')
    pushStr(divider() + '\n')

    // ── LEFT alignment
    push(ESC, 0x61, 0x00)

    // Info transaksi
    pushStr(`No: ${receipt.invoiceNo}\n`)
    pushStr(`Tgl: ${receipt.date}\n`)
    if (receipt.cashierName) pushStr(`Kasir: ${receipt.cashierName}\n`)
    pushStr(divider() + '\n')

    // Items
    receipt.items.forEach(item => {
      pushStr(truncate(item.name, 32) + '\n')
      const qtyPrice = `  ${item.qty} x ${formatRp(item.price)}`
      const subtotal = formatRp(item.qty * item.price)
      pushStr(qtyPrice + subtotal.padStart(32 - qtyPrice.length) + '\n')
    })

    pushStr(divider() + '\n')

    // Totals
    if (receipt.discount) pushStr(padRow('Diskon', `-${formatRp(receipt.discount)}`))
    if (receipt.tax) pushStr(padRow('PPN', formatRp(receipt.tax)))

    push(ESC, 0x45, 0x01) // Bold
    pushStr(padRow('TOTAL', formatRp(receipt.total)))
    push(ESC, 0x45, 0x00)

    pushStr(padRow('Bayar', formatRp(receipt.paid)))
    pushStr(padRow('Kembali', formatRp(receipt.change)))
    pushStr(divider() + '\n')

    // Footer
    push(ESC, 0x61, 0x01) // Center
    pushStr((receipt.footer || 'Terima kasih!\nSampai jumpa kembali') + '\n')
    pushStr('\n\n\n')

    // Cut paper
    push(GS, 0x56, 0x41, 0x00)

    // Convert bytes array ke hex string
    return bytes.map(b => b.toString(16).padStart(2, '0')).join('')
  }

  // ─── HELPERS ─────────────────────────────────────────────────────────────

  const divider = () => '-'.repeat(32)
  const truncate = (str, max) => str.length > max ? str.substring(0, max - 3) + '...' : str
  const formatRp = (num) => new Intl.NumberFormat('id-ID').format(num)
  const padRow = (label, value) => {
    const space = 32 - label.length - value.length
    return label + ' '.repeat(Math.max(1, space)) + value + '\n'
  }

  return {
    isConnected,
    isLoading,
    error,
    pairedDevices,
    selectedPrinter,
    isAndroid,
    scanDevices,
    connectPrinter,
    printReceipt,
    disconnect
  }
}
```

### ✅ Checklist Tahap 5:
- [ ] `scanDevices()` populate `pairedDevices` dengan benar
- [ ] `connectPrinter()` simpan device ke localStorage
- [ ] `buildEscPos()` hasilkan output hex yang valid
- [ ] State `isConnected` terupdate setelah connect/disconnect

---

## TAHAP 6 — Vue.js Component: PrinterSetup

### Perintah:
> Buat komponen `PrinterSetupModal.vue` untuk UI pengaturan printer.
> Tampilkan list paired devices, status koneksi, dan tombol aksi.

```vue
<!-- components/PrinterSetupModal.vue -->
<template>
  <div v-if="show" class="fixed inset-0 bg-black/50 flex items-end z-50">
    <div class="bg-white w-full rounded-t-2xl p-6 max-h-[80vh] overflow-y-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-gray-800">Pengaturan Printer</h2>
        <button @click="$emit('close')" class="text-gray-400 text-2xl">&times;</button>
      </div>

      <!-- Warning: bukan Android -->
      <div v-if="!isAndroid" class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
        <p class="text-amber-700 text-sm">
          ⚠️ Fitur cetak Bluetooth hanya tersedia di aplikasi Android.
          Buka via APK untuk menggunakan fitur ini.
        </p>
      </div>

      <!-- Status Koneksi -->
      <div v-else>
        <div class="flex items-center gap-2 mb-4">
          <div :class="isConnected ? 'bg-green-500' : 'bg-gray-300'"
               class="w-3 h-3 rounded-full"></div>
          <span class="text-sm text-gray-600">
            {{ isConnected
              ? `Terhubung ke ${selectedPrinter?.name}`
              : 'Belum terhubung' }}
          </span>
        </div>

        <!-- Error -->
        <div v-if="error" class="bg-red-50 text-red-600 text-sm p-3 rounded-lg mb-4">
          {{ error }}
        </div>

        <!-- Scan Button -->
        <button
          @click="scanDevices"
          :disabled="isLoading"
          class="w-full border border-blue-500 text-blue-500 rounded-lg py-2 mb-4 text-sm">
          {{ isLoading ? 'Mencari...' : '🔍 Scan Perangkat Bluetooth' }}
        </button>

        <!-- Device List -->
        <div v-if="pairedDevices.length > 0">
          <p class="text-xs text-gray-400 mb-2">PERANGKAT PAIRED</p>
          <div
            v-for="device in pairedDevices"
            :key="device.mac"
            @click="connectPrinter(device)"
            :class="selectedPrinter?.mac === device.mac ? 'border-blue-500 bg-blue-50' : 'border-gray-200'"
            class="border rounded-lg p-3 mb-2 cursor-pointer flex items-center justify-between">
            <div>
              <p class="font-medium text-sm">{{ device.name }}</p>
              <p class="text-xs text-gray-400">{{ device.mac }}</p>
            </div>
            <span v-if="selectedPrinter?.mac === device.mac && isConnected"
                  class="text-green-500 text-xs">✓ Aktif</span>
          </div>
        </div>

        <!-- Hint -->
        <p class="text-xs text-gray-400 mt-4 text-center">
          Pastikan printer sudah di-pair di Settings Bluetooth Android terlebih dahulu
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { usePrinter } from '@/composables/usePrinter'

defineProps({ show: Boolean })
defineEmits(['close'])

const {
  isAndroid, isConnected, isLoading, error,
  pairedDevices, selectedPrinter,
  scanDevices, connectPrinter
} = usePrinter()
</script>
```

### ✅ Checklist Tahap 6:
- [ ] Modal tampil/sembunyikan dengan benar
- [ ] Daftar paired devices muncul setelah scan
- [ ] Highlight device yang sedang aktif
- [ ] Warning muncul jika bukan Android

---

## TAHAP 7 — Integrasi ke Halaman Transaksi

### Perintah:
> Integrasikan `usePrinter` ke halaman checkout/transaksi Vue.js.
> Setelah transaksi berhasil disimpan ke backend Laravel, panggil `printReceipt()`
> dengan data dari response API.

```javascript
// Contoh di halaman Checkout.vue
import { usePrinter } from '@/composables/usePrinter'
import axios from 'axios'

const { printReceipt, isConnected } = usePrinter()

const submitTransaction = async () => {
  try {
    // 1. Simpan ke Laravel API
    const { data } = await axios.post('/api/transactions', cartData.value)

    // 2. Format data untuk struk
    const receiptData = {
      storeName: store.name,
      storeAddress: store.address,
      storePhone: store.phone,
      invoiceNo: data.invoice_no,
      date: new Date().toLocaleString('id-ID'),
      cashierName: authUser.name,
      items: data.items.map(i => ({
        name: i.product_name,
        qty: i.qty,
        price: i.unit_price
      })),
      discount: data.discount,
      tax: data.tax,
      total: data.total,
      paid: paymentAmount.value,
      change: paymentAmount.value - data.total,
      footer: 'Terima kasih telah berbelanja!'
    }

    // 3. Print jika printer terkoneksi
    if (isConnected.value) {
      await printReceipt(receiptData)
    }

  } catch (err) {
    console.error(err)
  }
}
```

### ✅ Checklist Tahap 7:
- [ ] Transaksi tersimpan ke DB sebelum print
- [ ] Data receipt mapping dari API response benar
- [ ] Print tidak blocking jika printer tidak terkoneksi
- [ ] Error print tidak gagalkan transaksi

---

## TAHAP 8 — Testing & Debug

### Perintah:
> Buat test scenario berikut dan pastikan semua pass:

```
TEST CASE 1 — Happy Path
  - Buka app → BT permission granted
  - Buka Printer Setup → Scan → muncul list devices
  - Pilih printer → status "Terhubung"
  - Buat transaksi → Submit → struk tercetak

TEST CASE 2 — Printer Off
  - Printer dimatikan saat sesi aktif
  - Buat transaksi → Submit → error message muncul
  - App tidak crash
  - Bisa reconnect setelah printer dinyalakan kembali

TEST CASE 3 — Permission Denied
  - Deny BT permission → warning muncul di UI
  - Deep link ke Settings BT Android

TEST CASE 4 — First Install
  - Fresh install → permission dialog muncul
  - BT disabled → prompt enable BT
  - Scan → pair printer via Settings → balik ke app → scan lagi → muncul

TEST CASE 5 — Background / Foreground
  - App di-minimize saat print → tidak crash
  - Balik ke foreground → cek koneksi masih aktif
```

### ✅ Checklist Tahap 8:
- [ ] Semua 5 test case pass
- [ ] Tidak ada ANR (App Not Responding)
- [ ] Memory tidak leak setelah beberapa kali print

---

## TAHAP 9 — Build & Release APK

### Perintah:
> Generate signed APK / AAB. Pastikan ProGuard tidak strip class BluetoothPrintBridge.

**`proguard-rules.pro` — wajib tambahkan:**
```
# Jaga Bridge class dari obfuscation
-keep class com.yourpackage.BluetoothPrintBridge { *; }
-keepclassmembers class com.yourpackage.BluetoothPrintBridge {
    @android.webkit.JavascriptInterface <methods>;
}

# WebView JS Interface
-keepattributes JavascriptInterface
```

**Build command:**
```bash
./gradlew assembleRelease
# atau AAB untuk Play Store:
./gradlew bundleRelease
```

### ✅ Checklist Tahap 9:
- [ ] APK bisa diinstall di Android 6.0+
- [ ] Bridge tidak ter-strip oleh ProGuard
- [ ] WebView load dengan benar di APK release

---

## ⚠️ BEST PRACTICES & CATATAN PENTING

1. **Selalu di background thread** — semua operasi Bluetooth wajib di thread terpisah,
   bukan UI thread, untuk mencegah ANR.

2. **Satu socket, banyak print** — jangan open/close socket setiap print.
   Maintain koneksi selama sesi kasir aktif, tutup hanya saat logout.

3. **Retry logic** — jika `print()` gagal, auto-retry 3x sebelum show error ke user.

4. **Hex encoding** — kirim ESC/POS sebagai hex string (bukan raw string) antara
   Vue.js ↔ Android untuk menghindari encoding issue karakter non-UTF8.

5. **Pair dulu di Settings** — arahkan user untuk pair printer di Android Settings BT
   sebelum scan di app. App hanya bisa connect ke paired devices.

6. **Printer yang direkomendasikan** (paling kompatibel):
   - Epson TM-T82 Bluetooth
   - Xprinter XP-58BM / XP-80BM
   - POS-80 / POS-58 generic
   - HPRT TP808

7. **Paper width** — ESC/POS builder di atas default 32 karakter (58mm).
   Ganti ke 48 karakter untuk printer 80mm.

---

## 📁 STRUKTUR FILE AKHIR

```
android-app/
├── app/src/main/
│   ├── AndroidManifest.xml
│   ├── java/com/yourpackage/
│   │   ├── MainActivity.kt
│   │   ├── BluetoothPrintBridge.kt
│   │   └── PermissionHandler.kt
│   └── res/layout/activity_main.xml
└── proguard-rules.pro

vue-app/
└── src/
    ├── composables/
    │   └── usePrinter.js
    └── components/
        └── PrinterSetupModal.vue
```
