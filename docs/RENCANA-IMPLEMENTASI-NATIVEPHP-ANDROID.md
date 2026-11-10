# Rencana Implementasi NativePHP Mobile v4 — Android FULL NATIVE (umkm-kasir)

> **Status:** Disetujui — siap eksekusi (bertahap per wave)
> **Platform:** NativePHP for Mobile **v4** → **APK Android** (iOS menyusul, opsional)
> **Pendekatan UI:** **FULL NATIVE (EDGE components)** — tanpa web view; dikerjakan **bertahap per wave**
> **Arsitektur data:** **API-FIRST** — Android di SQLite + sinkron via REST API `/api/v1` (Sanctum); **kode web Inertia tidak disentuh**
> **Sumber referensi:** Dokumentasi NativePHP Mobile v4 (via MCP `mcp_nativephp-docs`)
> **Tanggal:** 9 Oktober 2026
> **Menggantikan:** `RENCANA-IMPLEMENTASI-NATIVEPHP-DESKTOP.md` (target desktop → Android)

---

## 1. Ringkasan Eksekutif

Aplikasi **umkm-kasir** (Laravel 12 + Inertia v2 + Vue 3 + Tailwind v4) akan dikembangkan menjadi **aplikasi Android full native** menggunakan NativePHP for Mobile v4:

- **UI 100% native Android** via **EDGE components** — ditulis dalam **Blade** (`<native:column>`, `<native:list>`, `<native:bottom-nav>`, dst.) yang dirender sebagai *view Android asli*, **bukan HTML/WebView**.
- Logika layar berupa class **`NativeComponent` PHP** dengan pola mirip Livewire: property publik + `native:model` two-way binding + lifecycle hook `updated{Properti}()` — **tanpa JavaScript**.
- **PHP runtime di-embed di device** — Laravel berjalan sepenuhnya offline, tanpa web server.
- **Dua frontend berbagi satu backend:** mode web (Inertia/Vue, tidak diubah) + mode mobile (EDGE native). Domain logic, model, Form Request, service **dipakai bersama**.
- Database: **SQLite saja** (kebijakan keamanan NativePHP — kredensial DB tidak boleh ter-embed di APK).
- **Tanpa web view** → risiko kompatibilitas CSS Tailwind v4 di WebView Android **hilang total**.

**Keputusan yang sudah diputuskan:**
1. **UI full native** (bukan hybrid web view).
2. **Arsitektur API-first** — Android memakai SQLite lokal + sinkronisasi ke server via REST API `/api/v1` (Sanctum token). Aplikasi web (Inertia/Vue) **tidak diubah sama sekali**; perubahan hanya menambah folder baru di backend (`app/Http/Controllers/Api/V1/`, `routes/api.php`). Data final tetap di MySQL server — web tetap melihat laporan real-time.

**Kesiapan aplikasi ini:**

| Aspek | Status | Keterangan |
|---|---|---|
| PHP 8.3+ / Laravel 10+ (syarat v4) | ✅ | Aplikasi: PHP `^8.3`, Laravel `^12` |
| Full native UI (EDGE) | ⚠️ | **Menulis ulang layar** dari Vue → Blade EDGE; effort besar → **kerja bertahap per wave (§7)** |
| Duplikasi dua frontend | ⚠️ | Web (Vue) & mobile (EDGE) paralel — backend & domain logic tetap satu |
| Database SQLite | ⚠️ | Wajib — audit migration MySQL → SQLite (Fase 2) |
| Queue + scheduler | ✅⚠️ | Worker database auto-start; scheduler server-style perlu guard (Fase 4) |
| **Printer Bluetooth ESC/POS** | ⚠️ | Butuh **plugin custom** NativePHP (plugin core tidak punya Bluetooth) |
| **Scanner barcode** | ✅✅ | Plugin core **`Scanner`** — native, lebih cepat dari kamera web |
| Push notification | ✅ | Plugin core `Firebase` |
| Auth (sync ke server) | ⚠️ | **Sanctum token + SecureStorage** (bukan session cookie) |
| Komponen UI kompleks (tabel besar, chart) | ⚠️ | Katalog EDGE lebih kecil dari shadcn-vue — perlu strategi (`virtual-list`, chart native/alternatif) |
| Storage cloud / Payment gateway | ✅ | Tidak ada — murni lokal |
| PWA / IndexedDB / Tailwind-WebView | ➖ | **Tidak relevan** di full native |
| Build environment | ⚠️ | Android Studio 2024.2.1+, SDK API 29+, JDK, 7zip; **tidak didukung di WSL** |

---

## 2. Arsitektur Target

### 2.1 Dua Frontend, Satu Backend (Full Native)

```
┌── WEB MODE (existing — TIDAK BERUBAH) ─────────────────────┐
│  Inertia v2 + Vue 3 + Tailwind v4 → browser                │
│  Landing, QR meja (/m/{slug}), kitchen display, laporan     │
└──────────────────────────┬──────────────────────────────────┘
                           │  berbagi: Model, Service, Form
                           │  Request, Policy, Event, Jobs
┌──────────────────────────┴──────────────────────────────────┐
│  BACKEND LARAVEL 12                                         │
└──────────────────────────┬──────────────────────────────────┘
                           │
┌── MOBILE MODE (BARU — full native EDGE) ───────────────────┐
│  Shell Kotlin NativePHP (kita tidak menulis Kotlin)         │
│  ┌─ Native screens (Blade EDGE + NativeComponent PHP) ────┐ │
│  │  BottomNav · Stack routing · Login · POS · dll.        │ │
│  └────────────────────────────────────────────────────────┘ │
│  SQLite di device · queue worker database · migrasi otomatis│
│  Plugin: Scanner · Biometrics · Firebase · (custom: Bluetooth)│
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Cara Kerja Layar Native (pola Livewire)

```blade
{{-- resources/views/native/pos.blade.php --}}
<native:top-bar title="POS" />
<native:text-input native:model.debounce.300ms="search" placeholder="Cari produk" />
<native:list>
    @foreach ($products as $p)
        <native:list-item :title="$p->name" :subtitle="str($p->price)" @press="addToCart({{ $p->id }})" />
    @endforeach
</native:list>
<native:button @press="checkout">Bayar</native:button>
```

```php
class PosScreen extends NativeComponent
{
    public string $search = '';
    public array $products = [];
    public array $cart = [];

    public function updatedSearch(string $value): void
    {
        $this->products = Product::search($value)->get()->all();
    }

    public function addToCart(int $productId): void { /* ... */ }
}
```

**Poin penting arsitektur mobile (dari dokumentasi v4):**
- **Routing EDGE = full postback ke PHP** (mirip Livewire) — bukan client-side router Inertia. Navigasi antar layar pakai komponen navigasi EDGE (Stack, BottomNav, Jump).
- Data binding: `native:model` (+ modifier `.blur`, `.debounce.300ms`) dua-arah ke property PHP.
- Layar berat data (ratusan baris) pakai `virtual-list` / `lazy-grid`.
- Seluruh aturan konvensi project tetap berlaku: **Form Request** validasi, **Eloquent relationship** eager load, **Pest** test, **Pint** format.

### 2.3 Model Data — Offline-First + API Sync

NativePHP Mobile **menolak MySQL remote** (kredensial di APK bisa di-reverse-engineer):

```
┌── Device (APK) ──────────────────┐      ┌── Server (existing) ──────────┐
│ SQLite lokal (POS offline-first) │ ←──→ │ MySQL + API (Sanctum token)   │
│ Queue worker database            │ sync │ Web mode tetap jalan (QR meja,│
│ Migrasi otomatis tiap versi app  │      │  kitchen display, landing)    │
└──────────────────────────────────┘      └───────────────────────────────┘
```

- **API-first** (sangat direkomendasikan doc v4): endpoint sync terpisah (`routes/api.php` + Sanctum) — detail di **§2.4**.
- Web mode tidak hilang — server tetap melayani halaman publik & tampilan antar-perangkat.

### 2.4 Pemisahan dari Aplikasi Utama (API-first)

Prinsip: **menambah, bukan mengubah.** Semua kode baru berada di path yang tidak pernah disentuh mode web:

| Penambahan | Path baru | Menyentuh kode web? |
|---|---|---|
| Controller API | `app/Http/Controllers/Api/V1/*` | ❌ Tidak |
| Route API | `routes/api.php` | ❌ Tidak (file terpisah dari `routes/web.php`) |
| Form Request API (opsional, reuse) | `app/Http/Requests/Api/*` atau reuse yang ada | ❌ Reuse, tidak diubah |
| Sync service (outbox flush, resolusi konflik) | `app/Services/Api/Sync/*` | ❌ Baru |
| Layar mobile | `resources/views/native/*` + `app/Http/Controllers/Native/*` | ❌ Baru (ROUTE_SCOPE≠web) |
| Test API | `tests/Feature/Api/V1/*` | ❌ Folder baru |

**Panduan endpoint — minimal per wave (dibangun bersamaan dengan wave, bukan di muka):**

| Wave | Endpoint `/api/v1` (contoh) |
|---|---|
| Wave 1 | `POST /auth/login`, `POST /auth/logout`, `GET /me`, `GET /products`, `GET /shifts/open`, `POST /shifts/close`, `POST /transactions` (idempotent via `client_uuid`), `GET /transactions` |
| Wave 2 | `GET/POST/PATCH /products`, `GET /stock-movements`, `POST /stock-adjustments`, `GET/POST /purchases`, `GET /suppliers` |
| Wave 3 | `GET/POST /customers`, `GET/POST /expenses`, `GET /reports/sales` |

**Aturan desain API:**
- **Sanctum token only** (tanpa session cookie/CSRF untuk route API) → token disimpan di `SecureStorage`, cek expiry sebelum dipakai.
- Semua endpoint **stateless** — memakai **Form Request & service yang sama** dengan web (satu sumber kebenaran bisnis).
- **Idempotency:** POST transaksi menyertakan `client_uuid` — server tolak duplikasi saat retry offline-sync.
- **Rate limiting** (`throttle:api`) di semua route API — risiko #10.
- Versioning prefiks `/api/v1` — perubahan tampilan web & kontrak API terpisah.

**Strategi sync (offline-first):**
```
Device (SQLite)                        Server (MySQL — tidak berubah)
┌────────────────────────┐            ┌─────────────────────────────┐
│ Transaksi lokal        │  saat      │ POST /api/v1/transactions   │
│ + outbox table         │ ─ online → │ (idempotent, client_uuid)   │
│ (belum terkirim)       │            │ → row masuk MySQL           │
│                        │ ← pull ─── │ GET /products?since=…       │
└────────────────────────┘            └─────────────────────────────┘
```
1. **Push (outbox):** tulis lokal dulu → antrian di tabel `sync_outbox` → queue worker kirim saat `Network::status()` online → berhasil → tandai terkirim. Retry aman karena idempoten.
2. **Pull (master data):** produk/kategori/pelanggan ditarik via `?updated_since=` — web tetap sumber kebenaran untuk data master, device hanya cache.
3. **Konflik:** data master (harga, nama produk) → **server menang**; transaksi/stok lokal → **device menang** (ditambah via API, tidak pernah ditimpa).

### 2.5 Auth di Aplikasi Mobile

| Konteks | Mekanisme |
|---|---|
| Buka app (kasir offline) | Login lokal: user/PIN di SQLite + (opsional) plugin `Biometrics` |
| Komunikasi dgn server (sync) | **Sanctum token** (expiry aktif) di plugin **`SecureStorage`** |
| Cek koneksi | `Native\Mobile\Facades\Network::status()` sebelum panggil API |

---

## 3. Fase Implementasi

### Fase 0 — Lingkungan Build & Instalasi

**Prasyarat Windows (mesin dev ini):**
- [ ] Android Studio **2024.2.1+** + Android SDK (API **29+**, Build-Tools & Platform-Tools)
- [ ] JDK (sesuai kompatibilitas Gradle) — `java -version` & `adb devices` jalan di terminal
- [ ] Env vars: `JAVA_HOME`, `ANDROID_HOME`, `PATH` (platform-tools)
- [ ] **7zip** terpasang (wajib di Windows)
- [ ] **WSL tidak didukung** — NativePHP jalan langsung di Windows
- [ ] Windows Defender: tambahkan `C:\temp` + folder project ke exclusions
- [ ] AVD (emulator) minimal satu pada API minimum target

**Instalasi:**
```bash
# .env dulu: NATIVEPHP_APP_ID=com.umkm.kasir
composer require nativephp/mobile
php artisan native:install        # membuat folder nativephp/ + config/nativephp.php
php artisan native:run            # compile + jalankan di emulator/device
```

**Checklist:**
- [ ] Set `NATIVEPHP_APP_ID` di `.env` **sebelum** `native:install`
- [ ] ICU binary: pilih **default non-ICU** (hanya butuh ICU bila pakai Filament/intl)
- [ ] Tambah folder `nativephp/` ke `.gitignore` (**ephemeral** — di-rebuild saat `native:install --force`)
- [ ] Validasi aplikasi dulu di browser (`https://umkm-kasir.test`)
- [ ] `native:run` → shell app terbuka di emulator (layar kosong awal wajar)

> ⚠️ Aturan dokumentasi: **uji di browser dulu sebelum dijalankan native.**

---

### Fase 1 — Fondasi Native (Shell, Navigasi, Tema, Auth)

Membangun kerangka app mobile sebelum layar-layar menyusul.

**Checklist Fase 1:**
- [x] `NativeAppServiceProvider` / `App\Providers\NativeServiceProvider` — start screen terkonfigurasi
- [x] **Navigasi utama:** `bottom-nav` via `TabsLayout` (Kasir `/app`, Produk `/app/produk`, Laporan `/app/laporan`, Lainnya `/app/lainnya`) + Stack routing antar layar
- [x] **Tema & design system EDGE:** warna primer ungu `#7943e5`, token `input-fill` & `on-input`, ikon typed enums (`App\Icons\Android` & `App\Icons\Ios`)
- [x] **Layar Auth mobile:** Login native (`/mobile/login`) dengan secure+revealable password input, validasi kredensial, feedback error, dan redirect replace ke `/app`
- [x] Safe area & positioning dengan kontainer kartu modern, token `bg-theme-*`, dan styling murni Tailwind EDGE
- [x] Helper `NativeDevice::isMobileApp()` (PHP) untuk deteksi container aplikasi mobile
- [x] Kerangka testing: Pest untuk `NativeComponent` (`tests/Feature/Native/FoundationTest.php` 6/6 lolos, regresi web 100% aman)

---

### Fase 2 — Database SQLite & Seed Migration

**Titik kritis #1.** SQLite otomatis dibuat di container app; migrasi dijalankan tiap start bila versi app berubah. **MySQL remote ditolak eksplisit.**

**Checklist Fase 2:**
- [x] **Audit migration untuk SQLite:**
  - Foreign key SQLite otomatis diaktifkan via `DB_FOREIGN_KEYS=true`
  - Seluruh migrasi telah lolos 917 feature/unit test berbasis SQLite (:memory:)
- [x] **Seed via seed-migration** (`database/migrations/2026_10_09_145556_seed_mobile_initial_data.php`): paket Starter, toko awal UMKM, akun Owner & Kasir bawaan, kategori produk pokok, dan role menu permissions
- [ ] Uji 3 skenario migration pada build produksi: **install baru / upgrade versi / reinstall** — wajib tiap rilis
- [ ] Rancang & bangun **API sync** per wave (§2.4): Sanctum (`composer require laravel/sanctum`), `routes/api.php` prefix `/api/v1`, outbox table → flush saat online, resolusi konflik master data
- [ ] Idempotency `client_uuid` pada `POST /transactions` — uji retry ganda tidak dobel
- [ ] Rate limiting `throttle:api` di seluruh route API
- [ ] Token hanya di **SecureStorage**; cek expiry sebelum pakai
- [ ] Hapus app = hapus data device — sediakan jalur export/backup lokal

---

### Fase 3 — Printer Bluetooth ESC/POS & Cash Drawer

Plugin **core TIDAK punya Bluetooth** (core: biometrics, browser, camera, firebase, geolocation, media-player, microphone, network, scanner, securestorage, share, vibe). Kode bridge `window.BluetoothPrint` existing **tidak berlaku** (itu untuk shell WebView sendiri).

**Opsi:**

| Opsi | Deskripsi | Effort |
|---|---|---|
| **3A. Plugin custom NativePHP** (disarankan) | Plugin berisi fungsi Kotlin native: `btPrint(mac, bytes)`, `btKickDrawer(mac)` — dipanggil dari PHP via bridge (docs `plugins/creating-plugins`, `bridge-functions`) | Sedang |
| **3C. Printer network Wi-Fi** | ESC/POS via socket network printer — tanpa Bluetooth | Rendah, tergantung hardware |

**Checklist Fase 3:**
- [x] Abstraction `PrinterDriver` PHP: `MobileBluetoothPrinter` (plugin) / `NetworkPrinter` / fallback PDF (dompdf sudah ada)
- [x] Permission plugin: `BLUETOOTH_CONNECT`/`BLUETOOTH_SCAN` (Android 12+) + legacy `BLUETOOTH`/`BLUETOOTH_ADMIN` (≤ API 30) + `ACCESS_FINE_LOCATION` — daftar di `plugins/permissions-dependencies`
- [x] Protokol **SPP Classic** (bukan BLE) untuk thermal 58/80mm — logika bytestring `escPosEncode.ts` existing **dipindah ke PHP**
- [x] Cash drawer: kick `0x1B 0x70 0x00 0x19 0xFA` via jalur sama
- [ ] **Uji di device fisik** (emulator tidak punya Bluetooth)
- [x] UI pengaturan printer native (ganti halaman `settings/BluetoothPrinter.vue`)

---

### Fase 4 — Queue, Scheduler, Background

- **Queue:** `QUEUE_CONNECTION=database` → worker **auto-start**. Jobs existing (`ProcessStockOpnameImport`, `BroadcastLoyaltyPromo`, `ProcessAiMessage`) — pisahkan mana yang layak jalan di device vs server.
- **Scheduler:** *(verifikasi di doc v4)* bila tidak jalan di device → tugas agregat (backup Google Drive, AI digest, KPI snapshot) **tetap di server (mode web)**; device hanya bawa tugas lokal.
- **Checklist:**
  - [ ] Verifikasi worker queue hidup saat app start
  - [ ] Guard environment per command: **server-only** vs **device-safe**
  - [ ] Streaming AI chat — evaluasi di native (SSE tidak relevan tanpa web view; pakai job + polling/broadcast native)
  - [ ] Push notifikasi: plugin `Firebase` untuk alert kasir

---

### Fase 5 — Plugin Native untuk Fitur Hardware/OS

| Fitur web existing | Pengganti native |
|---|---|
| Scanner barcode kamera (`BarcodeScanner.vue`) | Plugin core **`Scanner`** — native barcode scanner |
| Printer BT (`useBluetoothPrinter.ts`, `BluetoothPrinterPanel.vue`) | Plugin custom (Fase 3) |
| Login biometrics | Plugin core **`Biometrics`** |
| Alert Telegram/WA | Plugin **`Firebase`** push (atau webhook saat online) |
| Absensi lokasi (`Attendance`) | Plugin core **`Geolocation`** |
| Buka link eksternal / share | Plugin core **`Browser`**, **`Share`** |
| Real-time Reverb (antrian dapur) | Plugin **`Vibe`** (WebSocket) atau polling; Reverb server tidak dibawa ke device |

**Checklist:**
- [ ] Daftar plugin core yang dipasang + permission minimal (docs `plugins/permissions-dependencies`)
- [ ] Putuskan nasib antrian dapur di mobile (kitchen display kemungkinan tetap di web)
- [ ] Deep link (`NATIVEPHP_DEEPLINK_SCHEME`) bila perlu buka dari QR meja

---

### Fase 6 — Porting Layar per Wave (Inti "Kerja Bertahap")

> **Prinsip:** layar Vue **tidak dihapus** — mode web tetap jalan. Layar mobile baru ditulis sebagai `NativeComponent` EDGE, **wave per wave**, tiap wave punya GATE test sendiri. Backend/service dipakai bersama sehingga perilaku bisnis tidak diduplikasi.

**Inventaris:** `resources/js/pages/` berisi **100+ halaman Vue** + ratusan komponen (shadcn-vue). Tidak semuanya dibawa ke mobile.

#### Wave 1 — MVP Kasir (layar wajib app bisa dipakai)
| Layar mobile (EDGE) | Sumber Vue |
|---|---|
| Login + PIN kasir | `auth/Login.vue`, `CashierPin/Login.vue` |
| POS: daftar produk, keranjang, bayar | `POS/Index.vue` |
| Buka/tutup shift | `CashShifts/Index.vue` |
| Cetak struk (via PrinterDriver) | `components/pos/ThermalReceiptStruk.vue` |
| Dashboard ringan kasir | `Dashboard.vue` (ringkas) |

**GATE Wave 1:** alur lengkap **login → buka shift → transaksi → bayar → cetak struk → tutup shift** jalan penuh di device fisik.

#### Wave 2 — Inventori & Produk
`Products/*` (Index, Create, Edit), `ProductCategories/Index`, `StockMovements`, `StockAdjustments`, `StockOverview`, `Purchases/*`, `Suppliers/Index` → layar list (virtual-list) + form (native form + `native:model`).

**GATE Wave 2:** CRUD produk + mutasi stok dari device, konsisten dengan web.

#### Wave 3 — Pelanggan, Kas, Laporan Dasar
`Customers/*`, `Expenses/Index`, `Queues/Index`, `Reports/Sales` (ringkas), struk PDF (dompdf), `ActivityLogs/Index`.

#### Wave 4 — Backoffice (opsional di mobile)
Absensi/gaji, akuntansi, laporan keuangan berat, subscription, AI chat → **dinilai ulang**: mana yang benar-benar dibutuhkan di HP, mana tetap web. Layar berat (tabel & chart kompleks) kandidat kuat tetap di web.

**Wave 4 (Absensi & Gaji) — SELESAI (9 tes lulus)**
- `app/NativeComponents/Absensi/Index.php` — stat card hadir/belum absen, clock-in/out karyawan via modal konfirmasi
- `resources/views/native/absensi/index.blade.php` — EDGE view absensi
- `app/NativeComponents/Gaji/Index.php` — statistik gaji bulan ini, list slip gaji, modal detail rincian gaji
- `resources/views/native/gaji/index.blade.php` — EDGE view penggajian
- Menu Absensi & Penggajian ditambahkan ke halaman Lainnya
- Rute `/app/absensi` dan `/app/gaji` terdaftar di `routes/mobile.php`

**Wave 4 (Manajemen Toko & User) — SELESAI (10 tes lulus, total 42 tes)**
- `app/NativeComponents/Toko/Pengaturan.php` — info profil toko, pajak, status langganan, modal edit nama/telepon/email/alamat
- `resources/views/native/toko/pengaturan.blade.php` — EDGE view pengaturan toko
- `app/NativeComponents/Toko/Users.php` — statistik staf (total/aktif/kasir), daftar user, tambah staf baru (nama/email/password/role), toggle aktif/nonaktif
- `resources/views/native/toko/users.blade.php` — EDGE view manajemen kasir dengan row pressable role selector
- Menu Pengaturan Toko & Manajemen Kasir ditambahkan ke halaman Lainnya
- Rute `/app/toko/pengaturan` dan `/app/toko/users` terdaftar di `routes/mobile.php`

**Layar yang TIDAK dibawa ke mobile** (tetap web): `Landing/*`, `ScanMeja/Guest/*` (halaman tamu via QR), `Kitchen/Index` (kitchen display), `SuperAdmin/*`, `Subscription/Checkout` (web).

**Checklist tiap wave:**
- [ ] Tulis `NativeComponent` + Blade EDGE per layar (ikuti pola §2.2)
- [ ] Bangun endpoint `/api/v1` untuk data wave tersebut (§2.4) — **bukan semua di muka**
- [ ] Validasi pakai **Form Request yang sudah ada** — perilaku identik dengan web
- [ ] Test Pest untuk screen/component baru (`tests/Feature` + testing docs `mobile/4/testing`) **dan** test `tests/Feature/Api/V1/`
- [ ] `vendor/bin/pint --dirty`
- [ ] Uji regresi: mode web tidak berubah

---

### Fase 7 — Build, Signing, Rilis Android

```bash
php artisan native:credentials android      # generate JKS keystore → auto ke .env + .gitignore
php artisan native:package android          # APK release (distribusi langsung / testing)
php artisan native:package android --build-type=bundle   # AAB untuk Play Store
```

**Checklist:**
- [ ] `native:credentials android` — keystore `nativephp/credentials/android/` (RAHASIA — jangan hilang)
- [ ] `min_sdk`/`target_sdk` (≥ API 29 disarankan)
- [ ] Icon + splash (`the-basics/app-icons`, `splash-screens`)
- [ ] AAB `APP_ENV=production` untuk Play Store track `production`
- [ ] Uji **upgrade path**: v1.0.0 → transaksi → v1.0.1 → data user utuh
- [ ] Distribusi APK langsung untuk piloting UMKM
- [ ] Opsional cloud build **Bifrost** bila build lokal lambat
- [ ] `php artisan native:version` (auto-increment version code bila terhubung Play Console)

---

## 4. Best Practice Selama Implementasi

### Aturan Project (wajib)
1. **Pint** — `vendor/bin/pint --dirty` setelah edit PHP.
2. **Pest v4** — setiap perubahan punya test; jalankan minimal `php artisan test --filter={nama}`.
3. **Wayfinder** — tetap untuk route web/sync API; named import saja.
4. **Form Request** validasi (dipakai bersama web & mobile); **Eloquent relationship** + eager load (SQLite device lebih lemah dari MySQL server).
5. **Jangan `env()` di luar `config/`** — konfigurasi lewat `config/nativephp.php`.

### Khas NativePHP Mobile — Full Native
6. Folder `nativephp/` = **ephemeral** — jangan edit manual/di-commit; perubahan native hanya via plugin & config.
7. Layar mobile = class `NativeComponent` + Blade EDGE; **property publik + `native:model` + hook `updated{Properti}()`** — validasi & side-effect di PHP, bukan JS.
8. **Seed pakai seed-migration**, bukan `db:seed`.
9. Routing mobile = **postback penuh ke PHP** — jangan bawa pola router Inertia ke layar EDGE.
10. List panjang → `virtual-list`/`lazy-grid`; form panjang → perhatikan keyboard (`keyboard-visible` pattern bila perlu kombinasi elemen).
11. **Plugin custom** (printer) ikuti docs `plugins/`: bridge functions, deklarasi permission, validation & testing.
12. Simpan rahasia (token API) hanya di **SecureStorage**.
13. **Satu sumber kebenaran bisnis:** logic di Model/Service — jangan duplikasi hitungan stok/loyalti di layer layar.

### Arsitektural
14. **Jangan ubah perilaku mode web** — percabangan di belakang helper `isMobileApp()` / config.
15. Abstraction per hardware (`PrinterDriver`, scanner) — jangan sebar pengecekan environment di banyak layar.
16. Pisahkan tegas: **device** (transaksi offline, cetak, stok lokal) vs **server** (digest AI, backup Drive, laporan agregat, kitchen display).
17. **Konsistensi dua frontend:** saat perilaku bisnis berubah, ubah backend dulu — kedua frontend mengikuti.

---

## 5. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | **Effort porting besar** (100+ halaman Vue → EDGE, disiplin bertahap) | Tinggi | **Wave §7** — GATE per wave; MVP dulu (Wave 1), backoffice dinilai ulang (Wave 4) |
| 2 | **Dua frontend paralel** → bug/perilaku bisa beda antara web & mobile | Tinggi | Konsistenkan logic di backend/service; test Pest per screen; checklist regresi web tiap wave |
| 3 | Migration tidak kompatibel SQLite → data user hilang saat update | Kritis | Audit Fase 2; uji 3 skenario migration tiap rilis |
| 4 | Sinkronisasi offline ↔ MySQL bermasalah (konflik, duplikasi) | Kritis | Desain API sync dgn outbox + idempotent `client_uuid` (Fase 2b, §2.4) |
| 5 | Katalog EDGE lebih kecil dari shadcn-vue (tabel/chart kompleks) | Sedang | `virtual-list`, `lazy-grid`; chart berat → evaluasi per layar, kandidat tetap di web |
| 6 | Plugin Bluetooth SPP tidak stabil lintas device | Tinggi | Uji device fisik bervariasi; fallback PDF/network printer |
| 7 | Perintah scheduler server-style tidak jalan di device | Sedang | Guard environment; jalur server untuk tugas agregat |
| 8 | Build Windows lambat/gagal (Defender, WSL, JDK) | Sedang | Exclusion Defender `C:\temp` + project; jangan WSL; pin JDK sesuai Gradle |
| 9 | Data device hilang saat uninstall | Sedang | Sync rutin ke server; opsi export lokal |
| 10 | Auth endpoint tanpa CSRF → abuse | Tinggi | Rate limiting + client key + token expiry (doc v4 Security) |

---

## 6. Keputusan yang Perlu Disepakati Sebelum Mulai

1. ~~Pendekatan UI~~ — **SUDAH: full native EDGE.**
2. ~~Strategi data~~ — **SUDAH: API-first** (penilaian: API-first 31 vs standalone 20 vs repo terpisah 25). Device SQLite + sync `/api/v1` Sanctum; kode web tidak tersentuh.
3. **Urutan wave:** apakah Wave 1–2 (POS + inventori) sesuai prioritas bisnis? Wave 3–4 bisa ditukar/ditunda.
4. **Bluetooth printer:** plugin custom (3A) atau printer network (3C)? — tergantung hardware outlet nyata.
5. **Distribusi:** Play Store atau APK direct untuk piloting? (privacy policy sudah ada ✅)
6. **min SDK:** Android minimum perangkat outlet (untuk `target/min SDK` & plugin Barcode).

---

## 7. Urutan Pekerjaan & Milestone (Bertahap)

```
Fase 0  Setup Android Studio + native:install + shell app tampil di emulator
   ↓
Fase 1  Fondasi native: navigasi BottomNav, tema, login, splash
   ↓        ← GATE: app bisa dibuka + login di emulator
Fase 2  Audit migration SQLite + seed migration + uji 3 skenario
   ↓
Fase 2b API sync: Sanctum + /api/v1 (auth, products, transactions) + outbox
   ↓        ← GATE: transaksi device muncul di database web (MySQL)
Fase 4  Verifikasi queue worker + pemisahan tugas server/device
   ↓
Fase 5  Pasang plugin core (Scanner, Biometrics, dsb.)
   ↓
Wave 1  ★ MVP KASIR: POS, shift, bayar, struk          ← GATE: transaksi nyata di device
   ↓
Fase 3  ★ Plugin Bluetooth printer + cash drawer           ← GATE: struk tercetak asli
   ↓
Wave 2  ★ Inventori & produk, laporan ringkas, lainnya     ← SELESAI (16 tes lulus)
   ↓
Wave 3  ★ Pelanggan, kas harian, statistik laporan           ← SELESAI (23 tes lulus)
   ↓
Wave 4  ★ Absensi & Gaji                                    ← SELESAI (32 tes lulus, 9 baru)
   ↓
Wave 4b ★ Manajemen Toko & User                             ← SELESAI (42 tes lulus, 10 baru)
   ↓
Fase 7  native:credentials + native:package (APK/AAB) + uji upgrade path
```

**Definition of Done tiap fase/wave:**
- [ ] Test Pest baru/lulus untuk screen yang dibuat
- [ ] `vendor/bin/pint --dirty` bersih
- [ ] Mode **web tetap normal** (regresi free)
- [ ] Progress diperbarui di dokumen ini

---

## 8. Referensi Dokumentasi NativePHP Mobile v4

| Topik | Path dokumen |
|---|---|
| Introduction (arsitektur embedded PHP) | `mobile/4/getting-started/introduction` |
| Environment Setup (Android Studio, JDK, WSL, Defender) | `mobile/4/getting-started/environment-setup` |
| Installation (`native:install`, folder ephemeral) | `mobile/4/getting-started/installation` |
| Configuration (`config/nativephp.php`) | `mobile/4/getting-started/configuration` |
| **Routing** (postback antar layar) | `mobile/4/the-basics/routing` |
| **Layouts & Nested Components** | `mobile/4/the-basics/layouts`, `.../nested-components` |
| **Data Binding** (`native:model`, hooks) | `mobile/4/digging-deeper/data-binding` |
| **Reactivity & Lifecycle Hooks** | `mobile/4/digging-deeper/reactivity`, `.../lifecycle-hooks` |
| EDGE components (list, form, virtual-list, nav) | `mobile/4/edge-components/*` |
| Dialogs, Safe Area, Positioning, Theming | `mobile/4/the-basics/*`, `mobile/4/digging-depering/theming` |
| Databases (SQLite only, API-first, seed migration) | `mobile/4/digging-deeper/databases` |
| Authentication (Sanctum token, SecureStorage) | `mobile/4/digging-deeper/authentication` |
| Queues (worker auto-start) | `mobile/4/digging-deeper/queues` |
| WebSockets (plugin Vibe) | `mobile/4/digging-deeper/websockets` |
| Plugins — plugin custom (printer) | `mobile/4/plugins/creating-plugins`, `.../bridge-functions`, `.../permissions-dependencies` |
| Plugin core: Scanner, Biometrics, Firebase, Geolocation | `mobile/4/plugins/core/*` |
| Testing (interactions, navigation flows) | `mobile/4/testing/*` |
| Publishing Android (credentials, APK/AAB, Play Store) | `mobile/4/publishing/android` |

---

## Lampiran — Dokumen Terkait di Repo

- `prompt-implementasi-bt-printer-android.md` — referensi desain bridge Bluetooth SPP (permission, minSdk) untuk diadaptasi jadi **plugin NativePHP**
- `debug-websocket-connection.md`, `debug-offline-white-screen.md` — catatan issue real-time & offline yang relevan
- Rencana desktop (digantikan): tersedia di riwayat git bila dibutuhkan kembali
