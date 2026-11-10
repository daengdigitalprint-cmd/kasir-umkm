# Rencana Implementasi NativePHP Desktop (v2) — umkm-kasir

> **Status:** Draft / Menunggu Persetujuan
> **Sumber referensi:** Dokumentasi NativePHP Desktop v2 (via MCP `mcp_nativephp-docs`)
> **Tanggal:** 9 Oktober 2026

---

## 1. Ringkasan Eksekutif

Aplikasi **umkm-kasir** saat ini adalah aplikasi POS/keuangan UMKM multi-outlet berbasis **Laravel 12 + Inertia v2 + Vue 3 + Tailwind v4**, dijalankan sebagai aplikasi web melalui Laravel Herd (`https://umkm-kasir.test`). Tujuan rencana ini adalah menambahkan **varian desktop offline-first** agar kasir tetap bisa bertransaksi tanpa koneksi internet, dengan tetap mempertahankan versi web yang ada.

NativePHP Desktop v2 mengemas aplikasi Laravel menjadi aplikasi Electron: PHP runtime dibundel, halaman Inertia dirender di WebView, database per-user berada di folder `appdata`, queue worker + scheduler berjalan otomatis.

**Kesimpulan kesiapan:**

| Aspek | Status | Keterangan |
|---|---|---|
| PHP 8.3+ / Laravel 11+ | ✅ | Aplikasi: PHP `^8.3` (runtime 8.4.24), Laravel `^12` |
| Auth session-based (`web` guard) | ✅ | Breeze-style manual, tanpa Sanctum — kompatibel WebView |
| Queue (`QUEUE_CONNECTION=database`) | ✅ | Worker `default` auto-start oleh NativePHP |
| Scheduler (10 perintah di `bootstrap/app.php`) | ✅ | Berjalan otomatis tiap menit di NativePHP |
| Storage disk `local` (tanpa S3) | ✅ | Tidak ada dependensi cloud |
| Payment gateway | ✅ | Belum ada integrasi eksternal (manual) |
| **Database (MySQL → SQLite)** | ⚠️ | NativePHP **hanya mendukung SQLite out-of-the-box** |
| **Print struk (`window.print()`/popup)** | ⚠️ | Perlu validasi di WebView + jalur `System::printers()` |
| **Printer Bluetooth ESC/POS + cash drawer** | ❌ | Web Bluetooth/`window.BluetoothPrint` (Android) **tidak tersedia di desktop** |
| **Broadcasting Reverb (antrian dapur)** | ⚠️ | Server WebSocket terpisah — tidak jalan otomatis di desktop |
| **PWA / IndexedDB offline sync** | ⚠️ | Service worker tidak relevan; IndexedDB perlu diverifikasi di WebView |
| Scanner barcode USB (keyboard wedge) | ✅ | Jalan normal di WebView |

---

## 2. Arsitektur Target

### 2.1 Model Hybrid (Disarankan)

Kedua mode berbagi **kode basis yang sama** dan dibedakan oleh environment config:

```
┌── Mode WEB (existing, tidak berubah) ────────────────────┐
│  Server Herd/VPS → MySQL + Reverb + browser tamu         │
│  Landing, QR meja (/m/{slug}), kitchen display, laporan  │
└──────────────────────────────────────────────────────────┘

┌── Mode DESKTOP (baru, NativePHP v2) ─────────────────────┐
│  Electron window → WebView (Inertia/Vue)                 │
│  + PHP runtime bundled                                   │
│  + SQLite di {appdata}/database/database.sqlite          │
│  + Queue worker default (auto) + Scheduler (auto)        │
│  + Printer OS via System::printers() / native ESC/POS    │
│  Modul: POS, shift kasir, inventori, laporan dasar       │
└──────────────────────────────────────────────────────────┘
```

**Prinsip:**
- Halaman publik/guest (landing, QR meja, invoice guest) **tidak** dibundel — dibuka via `Shell::openExternal()` ke URL web.
- Satu perangkat kasir = satu instance desktop dengan data lokal (SQLite), disinkronkan ke server bila online.

### 2.2 Layout Env Per-Mode

```
.env              → mode web (MySQL, Reverb) — TIDAK DIUBAH
.env.native       → mode desktop (sqlite, queue=database, BROADCAST_CONNECTION=log/nativephp)
```

Best practice: NativePHP otomatis memaksa SQLite saat berjalan di dalam Electron, sehingga `.env` web tidak perlu diubah. `.env.native` hanya untuk override eksplisit saat development (`APP_URL`, `BROADCAST_CONNECTION`, dsb.).

---

## 3. Fase Implementasi

### Fase 0 — Instalasi & Setup Dasar

**Estimasi dampak:** `composer.json`, `config/nativephp.php`, `app/Providers/NativeAppServiceProvider.php`

```bash
composer require nativephp/desktop
php artisan native:install          # publish provider + config + deps Electron
```

Yang dilakukan installer (per dokumentasi v2):
1. Publish service provider (`App\Providers\NativeAppServiceProvider`)
2. Publish `config/nativephp.php`
3. Tambah script `native:dev` ke `composer.json`
4. Daftarkan `native:install` sebagai `post-update-cmd`
5. Install dependency Electron (butuh **Node 22+**)

**Checklist Fase 0:**
- [ ] Pastikan Node 22+ terpasang (Herd menyediakan PHP, bukan Node)
- [ ] `composer require nativephp/desktop`
- [ ] `php artisan native:install`
- [ ] Isi `config/nativephp.php`:
  ```php
  'app_id'  => 'com.umkm.kasir',
  'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'), // WAJIB dinaikkan tiap rilis → pemicu migrasi DB user
  'provider' => \App\Providers\NativeAppServiceProvider::class,
  'prebuild' => ['npm run build'],   // build aset Vite sebelum packaging
  ```
- [ ] `NativeAppServiceProvider::boot()` → `Window::open(url: '/pos')` (langsung ke halaman kasir)
- [ ] `NativeAppServiceProvider::phpIni()` → `memory_limit: 512M`, `max_execution_time: 0`
- [ ] **Validasi dulu di browser** (`php artisan native:dev` hanya setelah aplikasi jalan normal di web tanpa error)
- [ ] Jalankan `php artisan native:run` — jendela desktop terbuka, login berfungsi

> ⚠️ **Aturan dokumentasi:** uji aplikasi di browser dulu sebelum dijalankan secara native — exception lebih sulit terdeteksi di WebView.

---

### Fase 1 — Database (MySQL → SQLite)

**Titik kritis #1.** NativePHP v2 hanya mendukung SQLite:
- **Dev:** `{build-dir}/nativephp.sqlite` (dipaksa saat berjalan di Electron, agar tidak menimpa DB web Anda)
- **Production:** `{appdata}/database/database.sqlite` — dibuat otomatis, migrasi dijalankan otomatis tiap app start bila versi `NATIVEPHP_APP_VERSION` berubah

**Checklist Fase 1:**
- [ ] **Audit semua migration** untuk kompatibilitas SQLite:
  - Kolom `enum()` → ganti `string` + validation di aplikasi (SQLite tidak punya ENUM native; Laravel menanganinya tapi lebih aman eksplisit)
  - `->change()` pada kolom — pastikan tidak ada atribute yang ter-drop (aturan Laravel 12: migration modifikasi kolom harus menyertakan seluruh atribut lama)
  - Komponen DB-specific (fulltext index, `DB::raw` dengan sintaks MySQL, stored procedure, trigger)
  - Foreign key constraints: aktifkan dukungan SQLite FK bila bergantung padanya
- [ ] Uji seluruh migration di SQLite lokal: `php artisan native:migrate` / `native:migrate:fresh`
- [ ] Uji `native:seed` untuk data awal (kasir, role, sample produk)
- [ ] **WAJIB uji migrasi upgrade** (v1.0.0 → v1.0.1) pada build produksi — kesalahan di sini bisa menghapus data pengguna
- [ ] Session/cache/queue sudah `database` driver → tabel `sessions`, `cache`, `jobs` otomatis ikut ter-migrasi di SQLite (bawaan NativePHP sudah menyediakan `jobs` table)
- [ ] Putuskan strategi **sinkronisasi multi-device** (SQLite per-device vs server MySQL) — di luar scope fase ini, didokumentasikan di §6

**Konfigurasi yang tidak perlu diubah:** `config/database.php` — NativePHP menyetel `DB_DATABASE` ke file SQLite saat runtime Electron.

---

### Fase 2 — Print Struk & Hardware

**Titik kritis #2.** Modul print saat ini:

| Jalur | File | Status Desktop |
|---|---|---|
| Thermal via `window.print()` popup | `resources/js/lib/thermalReceipt.ts`, `kitchenTicketPrint.ts`, `cupLabelPrint.ts`, `pages/Invoice.vue` | ⚠️ Perlu uji di WebView Electron |
| Web Bluetooth / `window.BluetoothPrint` (Android) | `resources/js/lib/bluetoothThermalPrint.ts`, `composables/useBluetoothPrinter.ts`, `usePrinter.ts` | ❌ **Tidak tersedia di WebView desktop** |
| Cash drawer kick (`window.BluetoothPrint.kickDrawer()`) | `usePrinter.ts` | ❌ Sama — butuh pengganti native |
| PDF server-side dompdf | `barryvdh/laravel-dompdf` | ✅ Aman, dipakai apa adanya |
| Barcode scanner USB (keyboard wedge) | `components/BarcodeScanner.vue` | ✅ Jalan |
| Barcode scanner kamera (`getUserMedia`) | idem | ⚠️ Minta izin WebView |

**Strategi (bertahap):**

1. **Fase 2a — Printer sistem (USB/network yang terdaftar di OS):**
   - Pakai facade `Native\Desktop\Facades\System::printers()` untuk list printer
   - Render struk → print via jalur native Electron (bukan `window.print()` browser)
   - Cocok untuk printer ESC/POS yang sudah ter-install sebagai printer Windows
2. **Fase 2b — Cash drawer:** kick drawer via perintah ESC/POS yang sama (`0x1B 0x70 0x00 0x19 0xFA`) ke printer yang dipakai — tanpa Web Bluetooth
3. **Fase 2c (opsional) — Printer Bluetooth murni:** child process Node/PHP yang menulis langsung ke socket/COM port printer. Hanya bila 2a/2b tidak memadai.

**Checklist Fase 2:**
- [ ] Buat abstraction `PrinterDriver` dengan implementasi: `BrowserPrinter` (web), `NativeSystemPrinter` (desktop), `BluetoothPrinter` (Android existing)
- [ ] Deteksi mode runtime: `Native\Desktop\Facades\App` / environment check → pilih driver
- [ ] Uji print struk thermal 58mm & 80mm di WebView
- [ ] Uji cash drawer kick
- [ ] Uji barcode scanner USB (keyboard wedge) — tidak perlu perubahan
- [ ] Uji izin kamera WebView untuk scanner kamera (opsional)

---

### Fase 3 — Real-time / Broadcasting (Antrian Dapar)

**Titik kritis #3.** Saat ini: `BROADCAST_CONNECTION=reverb`, channel `kitchen-store.{storeId}` (`routes/channels.php`), Laravel Echo + pusher-js di frontend. Reverb adalah **server WebSocket terpisah** yang tidak otomatis jalan di desktop.

**Opsi (pilih satu):**

| Opsi | Effort | Cocok untuk |
|---|---|---|
| **A. Polling sederhana** (disarankan untuk MVP desktop) | Rendah | Kasir 1 perangkat, antrian dapur ditampilkan di perangkat yang sama |
| **B. Event bawaan NativePHP** — `ShouldBroadcastNow` di channel `nativephp` + `window.Native.on()` | Sedang | Update real-time dalam 1 window (progress job, notifikasi) |
| **C. Jalankan Reverb sebagai ChildProcess** | Tinggi | Bila kitchen display tetap butuh WebSocket antar-perangkat |

**Checklist Fase 3:**
- [ ] Putuskan opsi A/B/C untuk antrian dapur di mode desktop
- [ ] Bila opsi A: buat fallback polling `Kitchen/Index` tiap N detik hanya saat mode desktop
- [ ] Bila opsi B: implementasikan event class dengan `broadcastOn()` → `new Channel('nativephp')`
- [ ] Streaming AI chat (`/ai/chat/stream`, SSE) — verifikasi berjalan di WebView
- [ ] Notifikasi Telegram/WA (webhook `ALERT_PUSH_*`) — tetap jalan asal ada internet

---

### Fase 4 — PWA / Offline Sync

**Titik kritis #4.** Aplikasi punya `PwaInstallBanner.vue`, `useOfflineSync.ts`, `offlineDb.ts` (IndexedDB).

- [ ] Nonaktifkan `PwaInstallBanner` di mode desktop (service worker/PWA tidak relevan)
- [ ] Verifikasi IndexedDB berfungsi di WebView Electron (basis sync offline tetap terpakai)
- [ ] Tentukan strategi sinkronisasi: SQLite lokal ↔ MySQL server saat online (conflict resolution untuk transaksi POS)
- [ ] Uji skenario offline penuh: buka shift → transaksi → tutup shift → reconnect → sync

---

### Fase 5 — Queue, Scheduler, dan Background Jobs

**Status: hampir otomatis ✅**

- **Queue:** NativePHP auto-start worker `default` (queue `database`). Konfigurasi `queue_workers` di `config/nativephp.php` bila perlu worker tambahan. Jobs existing (`ProcessStockOpnameImport`, `BroadcastLoyaltyPromo`, `ProcessAiMessage`) jalan tanpa perubahan.
- **Scheduler:** 10 perintah di `bootstrap/app.php` `->withSchedule()` (backup 02:00, loyalty, KPI, absensi, dll.) berjalan otomatis tiap menit.
- **Backup lokal:** `stores:backup` akan menulis ke `storage/app` di dalam appdata — verifikasi path & ukuran.

**Checklist Fase 5:**
- [ ] Verifikasi queue worker hidup saat app start (`QueueWorker` facade / log)
- [ ] Verifikasi scheduler mengeksekusi perintah per-menit saat app berjalan
- [ ] Sesuaikan perintah backup yang mengasumsikan path server (mis. `backup:drive` ke Google Drive — pastikan credential tersedia atau di-skip di desktop)
- [ ] `MAIL_MAILER=log` — pastikan alur verifikasi email/reset password tidak menggantung di mode desktop (pertimbangkan auto-verify lokal)

---

### Fase 6 — Build, Publish, dan Update

- [ ] `php artisan native:build` — target Windows (NSIS installer)
- [ ] `prebuild: ['npm run build']` sudah terdaftar → aset Vite ikut ter-bundle
- [ ] Pastikan `php artisan wayfinder:generate`/Vite plugin menghasilkan manifest sebelum build (hindari `ViteException: Unable to locate file in Vite manifest`)
- [ ] Setup auto-updater: provider `github` (release) / `s3`/`spaces` — isi env terkait
- [ ] Tentukan `cleanup_env_keys` & `cleanup_exclude_files` (bawaan sudah aman)
- [ ] Code signing Windows (opsional, untuk hindari SmartScreen warning)
- [ ] Uji **upgrade path**: install v1.0.0 → transaksi → update ke v1.0.1 → data user utuh

---

## 4. Best Practice Selama Implementasi

### Kode & Konvensi (mengikuti aturan project)
1. **Pint** — jalankan `vendor/bin/pint --dirty` setiap selesai edit file PHP.
2. **Pest v4** — setiap perubahan WAJIB punya test:
   - `php artisan make:test --pest {Name}` → `tests/Feature/...`
   - Uji happy path + failure path; jalankan minimal: `php artisan test --filter={nama}`
   - Browser testing (`tests/Browser/`) cocok untuk uji alur POS di WebView.
3. **Wayfinder** — setelah ubah route, andalkan Vite plugin (atau `php artisan wayfinder:generate`); pakai named import untuk tree-shaking.
4. **Inertia v2** — pakai `<Form>` component / `useForm`; deferred props + empty state skeleton untuk data berat (laporan).
5. **Tailwind v4** — CSS-first `@theme`, tanpa `tailwind.config.js`; dukung `dark:` bila halaman terkait sudah mendukungnya.
6. **Form Request** untuk validasi — jangan inline di controller.
7. **Eloquent relationships** dengan return type; eager load untuk hindari N+1 (apalagi di SQLite lokal yang lebih lambat untuk query kompleks).
8. **Jangan `env()` di luar `config/`** — semua konfigurasi NativePHP lewat `config/nativephp.php`.

### Struktur Perubahan
- **Jangan mengubah perilaku mode web.** Semua penyesuaian desktop di belakang pengecekan runtime atau config branch.
- **Abstraksi hardware** (`PrinterDriver`) — jangan sebar `if (isNative())` di banyak file Vue.
- **Satu tempat** untuk deteksi mode desktop (helper `isNativeApp()` di `resources/js/lib/` + config PHP).
- Dokumentasi progress per fase di `docs/` (lanjutkan dari file ini).

### Keamanan
- Ikuti panduan NativePHP Security: **unikkan key per instalasi** (APP_KEY dibuat per-appdata, jangan dibagikan).
- `cleanup_env_keys` membuang `*_SECRET` dari bundel production — pastikan tidak ada secret yang dibutuhkan aplikasi ikut terhapus.
- Halaman guest (`/m/{slug}`, invoice publik) tetap disajikan server web — jangan di-expose lewat WebView.

---

## 5. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | Migration tidak kompatibel SQLite → data user korup saat update | Kritis | Audit migration di Fase 1; **uji upgrade path di setiap rilis**; increment `version` dengan benar |
| 2 | Print thermal gagal di WebView | Tinggi | Abstraction `PrinterDriver`; fallback cetak PDF (dompdf) selalu tersedia |
| 3 | Antrian dapur real-time mati di desktop | Sedang | Opsi polling (Fase 3A) untuk MVP |
| 4 | Data terpecah (SQLite lokal vs MySQL server) tanpa sync | Tinggi | Definisikan scope: mode desktop standalone per-outlet dulu, sync menyusul |
| 5 | IndexedDB/sync offline tidak stabil di WebView | Sedang | Uji dini di Fase 4; fallback ke server bila online |
| 6 | Scheduler menjalankan job "server" (Google Drive backup, AI digest) yang tidak relevan | Rendah | Guard perintah dengan environment check / config |
| 7 | `window.print()` popup diblokir WebView | Sedang | Ganti ke jalur `System::printers()` native (Fase 2A) |

---

## 6. Keputusan yang Perlu Disepakati Sebelum Mulai

1. **Scope mode desktop:** semua modul atau hanya modul POS + inventori + laporan dasar? *(Disarankan: POS-centric dulu)*
2. **Strategi data:** desktop standalone (SQLite per kasir, tanpa sync) atau hybrid dengan sync ke MySQL server? — menentukan kompleksitas Fase 1 & 4 secara drastis.
3. **Antrian dapur real-time** di desktop: opsi polling (A), event native (B), atau Reverb child process (C)?
4. **Target platform build pertama:** Windows saja atau Windows + macOS?
5. **Auto-updater:** aktif (GitHub Releases) atau distribusi manual?

---

## 7. Urutan Pengerjaan & Milestone

```
Fase 0  Instalasi NativePHP + window pertama jalan
   ↓
Fase 1  Audit migration SQLite + native:migrate sukses        ← GATE: semua test lulus
   ↓
Fase 5  Verifikasi queue worker + scheduler jalan di desktop
   ↓
Fase 3  Antrian dapur (opsi A polling untuk MVP)
   ↓
Fase 2  PrinterDriver abstraction + print native + cash drawer ← GATE: struk tercetak asli
   ↓
Fase 4  Nonaktifkan PWA banner + verifikasi IndexedDB + sync
   ↓
Fase 6  native:build Windows + uji upgrade path + auto-updater
```

**Definisi Selesai (DoD) tiap fase:**
- [ ] Test Pest baru/lulus untuk perubahan yang dibuat
- [ ] `vendor/bin/pint --dirty` bersih
- [ ] Mode **web tetap berfungsi normal** (regresi free)
- [ ] Catatan progress diperbarui di dokumen ini

---

## 8. Referensi Dokumentasi NativePHP v2

| Topik | Path dokumen |
|---|---|
| Installation | `desktop/2/getting-started/installation` |
| Configuration (`config/nativephp.php`) | `desktop/2/getting-started/configuration` |
| Development & Debugging | `desktop/2/getting-started/development`, `.../debugging` |
| Command Reference (`native:run`, `native:migrate`, dll.) | `desktop/2/getting-started/commands` |
| Databases (SQLite) | `desktop/2/digging-deeper/databases` |
| Queues (`queue_workers`) | `desktop/2/digging-deeper/queues` |
| Child Processes (scheduler, Reverb) | `desktop/2/digging-deeper/child-processes` |
| Broadcasting (event `nativephp`) | `desktop/2/digging-deeper/broadcasting` |
| Printing (`System::printers()`) | `desktop/2/the-basics/system` |
| Building & First Run (appdata, migrasi) | `desktop/2/publishing/building` |
| Security (APP_KEY per instalasi) | `desktop/2/digging-deeper/security` |
| Testing | `desktop/2/testing/basics` |
