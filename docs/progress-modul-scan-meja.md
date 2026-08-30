# Progress Implementasi: Modul Manajemen Modul (Scan Meja)

> **Tanggal Mulai**: 22 Agustus 2026
> **Proyek**: UMKM Kasir

---

## Status Progress

| # | Tahap | File/Component | Status | Keterangan |
|---|---|---|---|---|
| 1 | Progress tracking | `docs/progress-modul-scan-meja.md` | ✅ Selesai | File ini |
| 2 | Migrasi | `database/migrations/2026_08_22_000000_create_module_activity_logs_table.php` | ✅ Selesai | Migrasi berhasil dijalankan |
| 3 | Model + Service | `app/Models/ModuleActivityLog.php`<br>`app/Services/ModuleService.php`<br>`app/Support/ScanMejaFeatures.php` | ✅ Selesai | - |
| 4 | Middleware | `app/Http/Middleware/EnsureScanMejaEnabled.php` | ✅ Selesai | Alias `scan_meja.enabled` |
| 5 | StoreSettingsController | `app/Http/Controllers/StoreSettingsController.php` | ✅ Selesai | Validasi + handler + audit log |
| 6 | ProfileController | `app/Http/Controllers/Settings/ProfileController.php` | ✅ Selesai | Kirim `scan_meja_enabled` ke frontend |
| 7 | UI Toggle | `resources/js/pages/settings/Profile.vue` | ✅ Selesai | Checkbox di Modul Fitur |
| 8 | HandleInertiaRequests | `app/Http/Middleware/HandleInertiaRequests.php` | ✅ Selesai | Shared data global |
| 9 | Registrasi Middleware | `bootstrap/app.php` | ✅ Selesai | Alias `scan_meja.enabled` |
| 10 | Final Review | - | ✅ Selesai | Signatures terverifikasi |

---

## Ringkasan Implementasi

### 1. Struktur Database (Migrasi)
Tabel `module_activity_logs` dibuat untuk menyimpan riwayat perubahan status modul:
- `store_id` — relasi ke toko
- `user_id` — identitas pengguna yang mengubah (nullable, nullOnDelete)
- `module_name` — nama modul (ex: `scan_meja_enabled`)
- `action` — `enabled` / `disabled`
- `status_sebelum` / `status_sesudah` — status boolean lama & baru
- `ip_address`, `user_agent` — untuk audit
- `keterangan` — catatan deskriptif
- `timestamps` — timestamp perubahan (created_at)

### 2. Model & Service
- **`ModuleActivityLog`** — model Eloquent dengan scope `module()`, `forStore()`, `recent()`
- **`ModuleService`** — service terpusat berisi:
  - Konstanta daftar modul (`MODULES`)
  - `toggle()` — ubah status + catat log
  - `isEnabled()` — cek status modul
  - `getHistory()` — riwayat perubahan
  - `getAllModulesStatus()` — daftar semua modul + status
- **`ScanMejaFeatures`** — helper khusus: `enabled()` dan `availableFor()` (hanya F&B/Bakery)

### 3. Endpoint & Validasi Akses
- Status disimpan melalui `StoreSettingsController::update()` yang sudah dilindungi middleware `role.store_admin` (hanya admin toko)
- Validasi akses tambahan dijamin oleh `canManageStoreSettings()` pada User model

### 4. UI Toggle
- Checkbox toggle ditambahkan di section "Modul Fitur" halaman `settings/Profile.vue`
- Label: "Aktifkan Modul Scan Meja (Menu Digital)"
- State di-inisialisasi dari `props.store.scan_meja_enabled`

### 5. Pengecekan Status Modul di Seluruh Sistem
- Middleware `EnsureScanMejaEnabled` (alias `scan_meja.enabled`) — redirect ke dashboard jika modul nonaktif
- Shared data global `scan_meja_enabled` via `HandleInertiaRequests` — tersedia di semua halaman Inertia
- Helper `ScanMejaFeatures::enabled()` untuk pengecekan programatik

### 6. Logging (Audit Trail)
Perubahan status modul dicatat di **dua tempat**:
1. **`module_activity_logs`** — tabel khusus untuk audit modul (timestamp + user + status)
2. **`activity_logs`** via `ActivityLogger::log()` — log umum (action `module.enabled` / `module.disabled`)

---

## Catatan Penting

### Validasi Akses
- Perubahan status modul hanya bisa dilakukan oleh user dengan role admin toko (`role.store_admin` middleware + `canManageStoreSettings()`)
- Logging mencatat `user_id` aktor + `ip_address` + `user_agent`

### Kepatuhan Kriteria Keberhasilan
| Kriteria | Status | Bukti |
|---|---|---|
| Status tersimpan permanen | ✅ | Disimpan di `stores.settings` JSON (persisten di DB) |
| Tidak ganggu fungsionalitas lain | ✅ | Hanya mengubah 1 key JSON, tidak mengubah struktur lain |
| Perubahan < 2 detik | ✅ | Operasi DB sederhana (1 update + 1 insert) |
| Hanya admin yang bisa ubah | ✅ | Middleware `role.store_admin` + `canManageStoreSettings()` |
| Semua perubahan tercatat | ✅ | `module_activity_logs` + `activity_logs` dual logging |

---

## File yang Dimodifikasi
- `app/Http/Controllers/StoreSettingsController.php` — validasi + handler + audit log
- `app/Http/Controllers/Settings/ProfileController.php` — `scan_meja_enabled` di array store
- `app/Http/Middleware/HandleInertiaRequests.php` — shared data global
- `resources/js/pages/settings/Profile.vue` — toggle checkbox + type + state init
- `bootstrap/app.php` — import + alias middleware

## File Baru
- `database/migrations/2026_08_22_000000_create_module_activity_logs_table.php`
- `app/Models/ModuleActivityLog.php`
- `app/Services/ModuleService.php`
- `app/Support/ScanMejaFeatures.php`
- `app/Http/Middleware/EnsureScanMejaEnabled.php`

---

## Log Perubahan

| Waktu | Aksi | Detail |
|---|---|---|
| 22 Aug 2026 | Inisialisasi | File progress dibuat, eksplorasi codebase selesai |
| 22 Aug 2026 | Migrasi | Tabel `module_activity_logs` dibuat & dijalankan |
| 22 Aug 2026 | Backend | Model, Service, helper, middleware dibuat |
| 22 Aug 2026 | Integrasi | Controller + shared data + UI toggle + registrasi middleware |
| 22 Aug 2026 | Review | Verifikasi signature `ActivityLogger::log` & struktur UI |

---

---
## Fase 2: Fitur Aktual Scan Meja

| # | Tahap | File/Component | Status | Keterangan |
|---|---|---|---|---|
| 11 | Migrasi | `database/migrations/2026_08_22_100000_create_tables_table.php` | ✅ Selesai | Tabel `tables` (name, code, slug, qr_code_url, is_active, capacity, location, notes) |
| 12 | Model | `app/Models/Table.php` | ✅ Selesai | auto slug, relasi store, guestUrl() |
| 13 | Relasi Store | `app/Models/Store.php` | ✅ Selesai | `tables(): HasMany` |
| 14 | QR Code Service | `app/Services/QrCodeService.php` | ✅ Selesai | API QR eksternal + fallback, simpan ke storage/public |
| 15 | TableController | `app/Http/Controllers/TableController.php` | ✅ Selesai | CRUD + toggleActive + regenerateQr, guard `scan_meja_enabled` |
| 16 | GuestMenuController | `app/Http/Controllers/GuestMenuController.php` | ✅ Selesai | Tampilkan menu berdasarkan slug meja |
| 17 | GuestOrderController | `app/Http/Controllers/GuestOrderController.php` | ✅ Selesai | Buat pesanan `dine_in` + `TransactionItem` dalam DB::transaction |
| 18 | Routes | `routes/web.php` | ✅ Selesai | Admin: `/tables` CRUD + toggle + QR; Guest: `/m/{slug}`, `/api/guest/order`, `/api/guest/order/{transaction}/status` |
| 19 | Halaman Admin | `resources/js/pages/ScanMeja/Tables.vue` | ✅ Selesai | Grid kartu meja, modal form, modal QR, CRUD, toggle aktif |
| 20 | Halaman Menu Publik | `resources/js/pages/ScanMeja/Guest/Menu.vue` | ✅ Selesai | Mobile-first, grid produk, cart drawer, submit via fetch() |
| 21 | Halaman Fallback | `ScanMeja/Guest/StoreInactive.vue`<br>`ScanMeja/Guest/ModuleInactive.vue`<br>`ScanMeja/Guest/TableInactive.vue` | ✅ Selesai | Tampilan jika toko/modul/meja tidak aktif |
| 22 | Navigasi Sidebar | `resources/js/components/AppSidebar.vue` | ✅ Selesai | Item "Manajemen Meja" dengan ikon `QrCode` |

---

## Ringkasan Fase 2

### 1. Struktur Database
Tabel `tables` menyimpan data meja:
- `store_id`, `name`, `code` (nomor/kode meja)
- `slug` (unique, auto-generated 8 karakter)
- `qr_code_url` (path ke gambar QR di storage)
- `is_active`, `capacity`, `location`, `notes`

### 2. Alur QR Code
- QR code dibuat saat meja dibuat via `QrCodeService::generateForTable()`
- Menggunakan API `api.qrserver.com` (fallback Google Charts)
- Gambar disimpan di `storage/app/public/qr-codes/{store_id}/table-{id}.png`
- URL QR di-generate ke `{APP_URL}/m/{slug}` (guest menu)

### 3. Alur Pesanan Tamu
1. Tamu scan QR → akses `/m/{slug}` → halaman menu publik
2. Pilih produk, tambah ke keranjang (client-side state)
3. Submit order via `POST /api/guest/order` (fetch, bukan Inertia)
4. Backend buat `Transaction` (status `pending`, payment_method `unpaid`, order_type `dine_in`) + `TransactionItem`
5. Return JSON berisi `transaction_id` untuk tracking status

### 4. Guard & Keamanan
- Semua route admin dilindungi `store.active` + `role.pos`
- Guest route tanpa auth, tapi validasi internal: table active + modul enabled
- Halaman fallback mencegah akses ke toko/modul/meja yang tidak aktif

### 5. UI/UX
- **Admin**: Halaman `Tables.vue` — grid meja, modal form (tambah/edit), modal QR code, tombol aksi (edit, QR, URL, toggle, hapus)
- **Guest**: Halaman `Menu.vue` — header sticky, grid produk 2 kolom, bottom sheet detail, cart bar, cart drawer, submit order
- **Fallback**: 3 halaman terpisah untuk error state (toko tidak aktif, modul tidak aktif, meja tidak aktif)

---

## File yang Dimodifikasi / Baru (Fase 2)

### Baru
- `database/migrations/2026_08_22_100000_create_tables_table.php`
- `app/Models/Table.php`
- `app/Services/QrCodeService.php`
- `app/Http/Controllers/TableController.php`
- `app/Http/Controllers/GuestMenuController.php`
- `app/Http/Controllers/GuestOrderController.php`
- `resources/js/pages/ScanMeja/Tables.vue`
- `resources/js/pages/ScanMeja/Guest/Menu.vue`
- `resources/js/pages/ScanMeja/Guest/StoreInactive.vue`
- `resources/js/pages/ScanMeja/Guest/ModuleInactive.vue`
- `resources/js/pages/ScanMeja/Guest/TableInactive.vue`

### Dimodifikasi
- `app/Models/Store.php` — tambah relasi `tables()`
- `routes/web.php` — tambah group admin tables + guest routes
- `resources/js/components/AppSidebar.vue` — tambah item "Manajemen Meja"

---

## Log Perubahan

| Waktu | Aksi | Detail |
|---|---|---|
| 22 Aug 2026 | Inisialisasi | File progress dibuat, eksplorasi codebase selesai |
| 22 Aug 2026 | Migrasi (Fase 1) | Tabel `module_activity_logs` dibuat & dijalankan |
| 22 Aug 2026 | Backend (Fase 1) | Model, Service, helper, middleware dibuat |
| 22 Aug 2026 | Integrasi (Fase 1) | Controller + shared data + UI toggle + registrasi middleware |
| 22 Aug 2026 | Review (Fase 1) | Verifikasi signature `ActivityLogger::log` & struktur UI |
| 22 Aug 2026 | Fase 2 - Migrasi | Tabel `tables` dibuat & dijalankan |
| 22 Aug 2026 | Fase 2 - Backend | Model Table, QrCodeService, TableController, GuestMenuController, GuestOrderController |
| 22 Aug 2026 | Fase 2 - Routes | Admin tables + guest menu/order routes |
| 22 Aug 2026 | Fase 2 - Frontend | Tables.vue, Menu.vue, 3 halaman fallback, sidebar nav |
| 22 Aug 2026 | Fase 3 - Backend | payment_method + customer_name di GuestOrderController, auto-create QueueEntry, GuestMenuController::status(), route status |
| 22 Aug 2026 | Fase 3 - Frontend | OrderStatus.vue (polling), payment method + customer name di Menu.vue |

---

## Fase 3: Checkout Pembayaran + Status Pesanan + Notifikasi Dapur

| # | Tahap | File/Component | Status | Keterangan |
|---|---|---|---|---|
| 23 | Costumer Name + Payment | `app/Http/Controllers/GuestOrderController.php` | ✅ Selesai | `customer_name`, `payment_method` (cash/qris/ewallet/transfer) |
| 24 | Notifikasi Dapur | `app/Http/Controllers/GuestOrderController.php` | ✅ Selesai | Auto-create `QueueEntry` (status `waiting`) jika `queue_module_enabled` |
| 25 | Status Page Controller | `app/Http/Controllers/GuestMenuController.php` | ✅ Selesai | `status()` method, render `OrderStatus.vue` |
| 26 | Route Status | `routes/web.php` | ✅ Selesai | `GET /m/{slug}/status/{transaction}` |
| 27 | Halaman Status Pesanan | `resources/js/pages/ScanMeja/Guest/OrderStatus.vue` | ✅ Selesai | Polling 3 detik, status badge, detail item, total, metode bayar |
| 28 | Checkout Enhancement | `resources/js/pages/ScanMeja/Guest/Menu.vue` | ✅ Selesai | Input nama, pilihan metode bayar, redirect ke status page |

---

### Ringkasan Fase 3

**1. Checkout Pembayaran**
- Tamu memilih metode pembayaran: Tunai, QRIS, E-Wallet, atau Transfer di keranjang
- Nama pemesan (opsional) dicatat di `payment_details` transaksi
- Setelah submit, tamu otomatis diarahkan ke halaman status pesanan

**2. Halaman Status Pesanan (OrderStatus.vue)**
- Menampilkan nomor pesanan, status (dengan ikon sesuai status)
- Daftar item pesanan + total
- Metode pembayaran yang dipilih
- Auto-polling setiap 3 detik via `GET /api/guest/order/{id}/status`
- Berhenti polling jika status sudah `completed` atau `cancelled`

**3. Notifikasi Dapur (Auto QueueEntry)**
- Saat guest order dibuat, sistem otomatis membuat `QueueEntry` (status `waiting`)
- QueueEntry hanya dibuat jika `queue_module_enabled` di store settings
- QueueEntry muncul di halaman kelola antrian (`/queue`) dan antrian dapur (`/kitchen-queue`)
- Pattern diambil dari `POSController` (find-or-create Queue, auto-number, items snapshot)

---

## File yang Dimodifikasi / Baru (Fase 3)

### Baru
- `resources/js/pages/ScanMeja/Guest/OrderStatus.vue`

### Dimodifikasi
- `app/Http/Controllers/GuestOrderController.php` — `customer_name`, `payment_method`, auto QueueEntry
- `app/Http/Controllers/GuestMenuController.php` — `status()` method + import `Transaction`
- `routes/web.php` — route `GET /m/{slug}/status/{transaction}`
- `resources/js/pages/ScanMeja/Guest/Menu.vue` — input nama + metode bayar + redirect

---

*Implementasi modul Scan Meja selesai penuh — mencakup mekanisme enable/disable (Fase 1), fitur menu digital + pemesanan tamu (Fase 2), checkout pembayaran + status pesanan + notifikasi dapur (Fase 3).*