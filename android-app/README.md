# UMKM Kasir — Android APK (WebView + Bluetooth Classic)

Aplikasi Android yang membungkus kasir web Laravel/Vue lewat WebView, dengan bridge native untuk printer thermal Bluetooth Classic (SPP).

## Persyaratan

- Android Studio Hedgehog atau lebih baru
- JDK 17
- Perangkat Android 6.0+ (API 23)

## Konfigurasi URL

Edit `app/src/main/res/values/strings.xml`:

```xml
<string name="kasir_url">https://kasir.domain-anda.com</string>
```

Development dengan [Laravel Herd](https://herd.laravel.com): default `http://umkm-kasir.test` (cleartext diizinkan).

Emulator Android: gunakan `http://10.0.2.2` untuk mengakses localhost PC.

## Build

Buka folder `android-app` di Android Studio → **Sync Gradle** → **Run**.

Release APK / AAB:

```bash
cd android-app
./gradlew assembleRelease
# atau untuk Play Store:
./gradlew bundleRelease
```

APK release: `app/build/outputs/apk/release/app-release.apk`

## Bridge JavaScript

Dari Vue.js, interface tersedia sebagai `window.BluetoothPrint`:

| Method | Keterangan |
|--------|------------|
| `scanDevices()` | Daftar perangkat paired |
| `connect(mac)` | Koneksi SPP ke printer |
| `print(hex)` | Kirim data ESC/POS (hex string) |
| `disconnect()` | Tutup socket |
| `isConnected()` | Status koneksi |

Callback: `window.__btCallback(action, success, data, errorMsg)`

Event saat WebView siap: `androidBridgeReady`

## Vue.js

- Composable: `resources/js/composables/usePrinter.ts`
- Modal setup: `resources/js/components/PrinterSetupModal.vue`
- Integrasi POS: auto-cetak setelah transaksi jika printer terhubung

Browser desktop tetap memakai Web Bluetooth (BLE) via `useBluetoothPrinter`.

## Pairing printer

1. Pair printer di **Settings → Bluetooth** Android (wajib sebelum scan di app)
2. Buka **Pengaturan → Bluetooth** di kasir web
3. Scan → pilih printer → status **Terhubung**
4. Transaksi di POS akan auto-cetak struk

## Skenario uji (Tahap 8)

### TEST 1 — Happy path
- Izin BT granted → Scan → pilih printer → transaksi → struk tercetak

### TEST 2 — Printer mati
- Submit transaksi → error di UI, app tidak crash → nyalakan printer → reconnect

### TEST 3 — Permission denied
- Deny izin → peringatan di UI → buka Settings Android untuk izin

### TEST 4 — Fresh install
- Dialog izin → prompt aktifkan BT → pair di Settings → scan lagi

### TEST 5 — Background / foreground
- Minimize saat print → tidak ANR → foreground → cek `isConnected`

## ProGuard

Class bridge dilindungi di `app/proguard-rules.pro` agar `@JavascriptInterface` tidak ter-strip saat release build.
