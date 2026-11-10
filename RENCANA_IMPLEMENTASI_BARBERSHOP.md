# Rencana Implementasi Dukungan Barbershop

Rancangan agar aplikasi UMKM Kasir dapat dipakai oleh **barbershop / salon kecil** tanpa memfork codebase — mengikuti prinsip *satu codebase, konfigurasi per tipe usaha* dari [RENCANA_PENGEMBANGAN.md](RENCANA_PENGEMBANGAN.md).

**Status:** Implementasi berlangsung
**Terakhir diperbarui:** 24 September 2026

---

## Status Progres

| Tahap | Nama | Status |
|---|---|---|
| 0 | Preset jenis usaha `barbershop` & keputusan arsitektur | Selesai |
| 1 | Fondasi data: migrasi kolom teknisi & durasi layanan | Selesai |
| 2 | POS: penugasan teknisi (barber) per transaksi | Selesai |
| 3 | Katalog layanan non-stok & onboarding otomatis | Selesai |
| 4 | Antrian barbershop (adaptasi modul Antrian) | Selesai |
| 5 | Laporan & KPI per teknisi | Selesai |
| 6 | Komisi teknisi | Selesai |
| 7 | Booking / reservasi online (opsional, paket lanjutan) | Belum |

Setiap tahap ditandai selesai pada tabel di atas dan dicatat rinci di bagian **9. Log Implementasi**.

---

## 1. Tujuan

Menyediakan alur kerja inti barbershop dalam aplikasi yang sudah ada:

```text
Pelanggan datang / reservasi
  -> Ambil antrian (atau bookingjadwal)
  -> Kasir input layanan di POS + pilih teknisi
  -> Transaksi dibayar (tunai/QRIS/kartu/hutang)
  -> Pendapatan, komisi, dan kinerja teknisi tercatat per orang
  -> Laporan harian & loyalitas pelanggan berjalan seperti biasa
```

Layanan inti yang dibutuhkan barbershop:

1. **Katalog layanan jasa** (potong rambut, creambath, shave, dll.) — tanpa stok.
2. **Penentuan teknisi (barber)** per transaksi untuk kinerja & komisi.
3. **Antrian pelanggan** per teknisi (siapa melayani siapa, panggil berikutnya).
4. **Laporan pendapatan per teknisi** + komisi.
5. **Reservasi / booking jadwal** (tahap lanjutan).

---

## 2. Analisis Kondisi Codebase

### 2.1 Fondasi yang sudah tersedia (reused)

| Kebutuhan | Yang sudah ada | Catatan |
|---|---|---|
| Identitas jenis usaha | Kolom `stores.business_type`, `users.business_type`; `BusinessTypePresets::TYPES` | Tinggal menambah entri `barbershop` |
| Produk layanan jasa | `products.track_stock = false`, `inventory_type = 'sellable'` | Layanan jasa = produk tanpa jejak stok |
| Penjualan & pembayaran | POS, `transactions`, `transaction_items`, shift kasir, metode bayar lengkap | Langsung terpakai |
| Kolom komisi | `transactions.commission_percent`, `commission_amount` **sudah ada** | Belum diisi dari UI |
| Pelanggan & loyalitas | `customers`, poin, voucher, diskon | Relevan: pelanggan tetap barbershop |
| Antrian | Modul `queues` + `queue_entries` (waiting → serving → ready → completed) | Perlu adaptasi label & penugasan teknisi |
| Area / kursi | Modul `tables` (`tables.name`, `capacity`, `location`) | Bisa dipakai sebagai daftar kursi/stasiun |
| Layanan berstatus (pola) | `LaundryOrder` (received → processing → ready) | Pola acuan untuk status booking |
| Absensi & payroll | Modul Absensi + Penggajian + `store_user` role pivot | Teknisi = user biasa, langsung tercetak gajinya |
| KPI & Owner Dashboard | `KPIController`, `OwnerDashboardService` | Tinggal ditambah dimensi per teknisi |
| Gerbang paket | `StorePlanLimits`, middleware `plan.advanced` | Dipakai untuk fitur lanjutan (booking) |
| Saklar modul | `ModuleService::MODULES` + `stores.settings` | Untuk menonaktifkan antrian dsb. |
| Hak akses menu per role | `RoleMenuPermissionSeeder` + halaman *Hak Akses Menu* | Role baru bisa didaftarkan & disesuaikan admin |
| Nuansa menu per usaha | Pola `LaundryFeatures::enabled()` dipakai `MainMenu.vue` | Pola sama untuk `BarbershopFeatures` |

### 2.2 Kesenjangan terhadap kebutuhan

1. `BusinessTypePresets` belum memiliki tipe `barbershop`, sehingga onboarding tidak menyiapkan kategori/satuan yang relevan.
2. Tidak ada **identitas teknisi** pada transaksi — laporan hanya tahu *kasir*, bukan *siapa yang melayani*.
3. Tidak ada **durasi layanan**, sehingga antrian & booking tidak bisa memperkirakan waktu.
4. Modul Antrian memakai label F&B (`dine_in`, nomor meja) dan tidak menugaskan teknisi.
5. Kolom komisi pada `transactions` belum pernah diisi maupun ditampilkan.
6. Tidak ada jadwal / reservasi per teknisi.
7. Tabel role (`users.role`, `store_user.role`) belum memiliki peran `barber`.

### 2.3 Risiko integrasi utama

1. Menambah role baru menyentuh enum role, seeder menu, dan middleware `role.*` — harus berhati-hati agar role lama tidak berubah.
2. POS adalah jalur paling kritis; penambahan field `barber_user_id` tidak boleh mengubah perhitungan total, pajak, diskon, maupun struk existing.
3. Antrian dipakai bersama oleh F&B (meja) dan barbershop — penamaan label harus kondisional per `business_type`, bukan mengubah perilaku lama.
4. Komisi berpotensi bentrok dengan modul payroll bila tidak didefinisikan batasnya (komisi = informasi transaksi, bukan pengganti gaji).
5. Toko lama (`business_type` NULL / `general`) wajib tetap berperilaku persis seperti sebelumnya.

---

## 3. Keputusan Arsitektur

### 3.1 Satu codebase, aktivasi lewat `business_type`

Mengikuti prinsip yang sudah dipakai modul Laundry (`LaundryFeatures`): seluruh perilaku khas barbershop disimpan di satu kelas pemungkas `App\Support\BarbershopFeatures` yang membaca `stores.business_type`. Tidak ada tabel maupun migrasi fitur yang menyentuh toko non-barbershop.

```php
class BarbershopFeatures
{
    public static function enabled(?Store $store): bool
    {
        return $store !== null && ($store->business_type ?? '') === 'barbershop';
    }
}
```

### 3.2 Teknisi adalah `User` biasa, bukan tabel baru

Teknisi direpresentasikan sebagai user dengan role **`barber`** (mengikuti pola role `kitchen` yang sudah ada):

- Relasi `transactions.barber_user_id` → `users.id` (nullable).
- Absensi, payroll, dan PIN kasir langsung berfungsi tanpa tabel karyawan tambahan.
- Multi-outlet & organisasi otomatis tercakup lewat pivot `store_user`.

### 3.3 Layanan jasa = produk dengan `track_stock = false`

Tidak membuat tabel `services` baru. Layanan potong rambut dkk. adalah produk biasa yang:

- `inventory_type = 'sellable'` (tetap muncul di POS),
- `track_stock = false` sehingga tidak menulis mutasi stok,
- memiliki durasi baru: kolom `service_duration_minutes` (nullable) — hanya diisi untuk produk jasa.

Keuntungan: laporan, diskon, pajak, struk, loyalitas, dan ekspor produk bekerja tanpa perubahan apa pun.

### 3.4 Antrian = adaptasi modul `queues` yang sudah ada

Barbershop tidak membutuhkan antrian kedai. `queue_entries` diberi kolom `barber_user_id` (nullable):

- **Tanpa teknisi** → antrian umum (urutan tunggu), sama seperti sekarang.
- **Dengan teknisi** → antrian per teknisi; tombol "Panggil berikutnya" menyaring `waiting` untuk teknisi tersebut.

Label F&B (`dine_in`/`table_number`) disembunyikan oleh UI ketika `BarbershopFeatures::enabled()`.

### 3.5 Komisi = data transaksi, bukan modul penggajian

Komisi dihitung saat pembayaran (`commission_percent` × `total_amount`, atau nominal tetap per layanan) dan disimpan di `transactions`. Laporan komisi hanya informatif; **perhitungan gaji tetap berada di modul Payroll** agar tidak ada dua sumber kebenaran.

### 3.6 Gerbang paket

| Fitur | Gerbang |
|---|---|
| Preset, layanan jasa, penugasan teknisi, laporan teknisi | Semua paket (fitur dasar vertikal) |
| Antrian | Mengikuti `queue_module_enabled` (sudah ada) |
| Komisi | Paket GrowUp ke atas (`plan.advanced`) |
| Booking / reservasi | Paket GrowUp ke atas (`plan.advanced`) |

---

## 4. Rancangan Data

### 4.1 Migrasi (satu berkas gabungan)

| Tabel | Kolom baru | Tipe | Keterangan |
|---|---|---|---|
| `products` | `service_duration_minutes` | smallint nullable | Durasi layanan (menit); NULL = bukan jasa / tanpa durasi |
| `transactions` | `barber_user_id` | FK users nullable, on delete set null | Teknisi yang melayani |
| `queue_entries` | `barber_user_id` | FK users nullable, on delete set null | Penugasan antrian ke teknisi |
| `bookings` | *(tabel baru, Tahap 7)* | — | Lihat 4.2 |
| `users.role` / `store_user.role` | enum ditambah `barber` | — | Mengikuti pola migrasi role `kitchen` yang sudah ada |

Indeks: `[store_id, barber_user_id, created_at]` pada `transactions` (laporan per teknisi), `[store_id, barber_user_id, status]` pada `queue_entries`.

### 4.2 Tabel `bookings` (Tahap 7 — opsional)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | |
| `store_id` | FK stores | cascade on delete |
| `customer_id` | FK nullable | bisa walk-in tanpa data pelanggan |
| `barber_user_id` | FK users | teknisi yang dipesan |
| `product_id` | FK nullable | layanan utama yang dipesan |
| `booking_number` | string(40) | unik per toko, contoh `BKG/2026/09/0001` |
| `start_at` / `end_at` | datetime | jadwal (akhiran dihitung dari `service_duration_minutes`) |
| `status` | string(16) | `confirmed`, `checked_in`, `completed`, `cancelled`, `no_show` |
| `source` | string(16) | `walk_in`, `phone`, `online` |
| `notes` | text nullable | |
| `transaction_id` | FK nullable | diisi setelah dibayar di POS |
| `timestamps` | | |

Indeks: `[store_id, start_at]`, `[store_id, barber_user_id, start_at]`, unik `[store_id, booking_number]`.

### 4.3 Preset `barbershop` di `BusinessTypePresets`

```php
'barbershop' => 'Barbershop / Salon',
// catalog():
'categories' => ['Layanan Potong', 'Prewash & Styling', 'Perawatan Rambut', 'Paket', 'Produk Retail', 'Lainnya'],
'units'      => ['jasa', 'paket', 'pcs', 'botol'],
```

### 4.4 Status antrian barbershop (label kondisional)

| Status | Label barbershop | Label F&B (existing) |
|---|---|---|
| `waiting` | Menunggu | Menunggu |
| `serving` | Dilayani (di kursi) | Disajikan |
| `ready` | Selesai | Siap |
| `completed` | Lunas / Selesai | Selesai |
| `cancelled` | Batal | Batal |

Perubahan label murni di sisi tampilan (Vue) berdasarkan `BarbershopFeatures::enabled()`, nilai enum tidak berubah.

---

## 5. Hak Akses

### 5.1 Role baru: `barber`

Menu default untuk role `barber` di `RoleMenuPermissionSeeder`:

```php
'barber' => ['dashboard', 'queues', 'pos_transactions', 'attendance', 'settings'],
```

- **Tanpa `pos` secara bawaan** — kasir/admin yang menyarankan transaksi; admin dapat menambahkan akses POS lewat halaman *Hak Akses Menu* yang sudah ada jika toko kecil memakai teknisi merangkap kasir.
- Antrian (`queues`) menjadi layar kerja harian teknisi.
- Absensi & penggajian otomatis mengikuti role user biasa.

### 5.2 Ringkasan aksi

| Aksi | Peran |
|---|---|
| Input layanan + pilih teknisi di POS | `role.pos` (kasir, admin, manager, owner) |
| Mengelola katalog layanan | `role.inventory` |
| Kelola antrian & panggil berikutnya | `role.pos`, `role.barber` |
| Melihat laporan pendapatan per teknisi | `role.backoffice` |
| Mengatur tarif komisi | `role.store_admin` (gerbang paket GrowUp) |
| Membuat / membatalkan booking | `role.pos` (Tahap 7) |

---

## 6. Tahapan Implementasi

### Tahap 0 — Preset & keputusan arsitektur

Menambahkan `barbershop` ke `BusinessTypePresets::TYPES` + `catalog()`, membuat `App\Support\BarbershopFeatures`, dan mengunci keputusan bagian 3.

Keluaran: 2 berkas (1 diubah, 1 baru).
Kriteria selesai: toko baru dapat memilih "Barbershop / Salon" pada saat pendaftaran & form produk; preset kategori/satuan muncul; toko lain tidak berubah.

### Tahap 1 — Fondasi data

Satu migrasi gabungan sesuai 4.1: kolom `service_duration_minutes`, `barber_user_id` (transactions & queue_entries), enum role `barber`, dan indeks pendukung. Model `Transaction`, `QueueEntry` diperbarui (`$fillable`, relasi `barber()`).

Keluaran: 1 migrasi, 2 model diubah.
Kriteria selesai: migrasi berjalan bersih; kolom nullable (toko lama aman); relasi `transaction->barber` terbaca.

### Tahap 2 — POS: penugasan teknisi

- Dropdown **"Teknisi"** pada panel keranjang/pembayaran di `POS/Index.vue` (hanya tampil bila `BarbershopFeatures::enabled()`), berisi user aktif role `barber` + boleh memilih "—".
- `POSController::processPayment()` menerima & menyimpan `barber_user_id` (divalidasi: user harus anggota toko aktif).
- Struk termal & invoice menampilkan baris *Teknisi: Nama* bila ada.

Keluaran: perubahan `POSController`, `POS/Index.vue`, `receiptPlainText.ts` / `thermalReceipt.ts`.
Kriteria selesai: transaksi tersimpan dengan teknisi; struk menampilkan nama; alur POS non-barbershop tidak menampilkan pilihan sama sekali (tanpa regresi).

### Tahap 3 — Katalog layanan & onboarding

- Field **"Durasi (menit)"** pada form `Products/Create|Edit` (tampil untuk tipe jasa).
- Saat toko `barbershop` dibuat, siapkan kategori preset + opsi **impor layanan contoh** (Potong Rambut Pendek/Panjang, Prewash, Creambath, Shave, dll.) mengikuti pola `StoreDefaultProductCategories::ensureForStore()` — sebagai saran, bukan paksaan.
- `MainMenu.vue`: tampilkan penyebutan "Layanan" pada menu Produk ketika toko barbershop (label kondisional).

Kriteria selesai: produk jasa tersimpan tanpa menulis `stock_movements`; form menolak durasi negatif.

### Tahap 4 — Antrian barbershop

- Pada `Queues/Index.vue` & `Show.vue`: tampilan dua dimensi — **pelanggan menunggu** dan **antrian per teknisi** (tab/kanal).
- Aksi "Panggil berikutnya" per teknisi: ambil `waiting` terlama dengan `barber_user_id` sesuai → status `serving`.
- Form ambil antrian menawarkan pilihan teknisi (atau umum).
- Sembunyikan kolom meja/`dine_in`; gunakan kolom `table_number` (opsional) sebagai *nomor kursi*.
- Sinkronisasi real-time memakai event `KitchenQueueUpdated` / WebSocket yang sudah ada.

Kriteria selesai: dua teknisi dapat melayani paralel tanpa nomor antrian bentrok; antrian umum tetap berfungsi; modul antrian non-barbershop tidak berubah.

### Tahap 5 — Laporan & KPI per teknisi

- Filter tambahan **"Teknisi"** pada `Reports/Sales` + baris agregat per teknisi: jumlah transaksi, pendapatan, rata-rata per kunjungan.
- Ekspor PDF/Excel (`ReportExportController`) menyertakan dimensi teknisi bila filter aktif.
- Kartu KPI: target pendapatan per teknisi (memakai `KpiTarget` bila toko memakai modul KPI).

Kriteria selesai: total per teknisi = total periode yang sama (rekonsiliasi); filter teknisi tidak mengubah hasil laporan lama saat tidak dipilih.

### Tahap 6 — Komisi teknisi

- Pengaturan tarif komisi di `stores.settings['barber_commission']` (pola `StockAdjustmentApproval` — tanpa tabel baru): `mode` (`percent` | `fixed`), `value`, dan tarif per katalog layanan dapat menimpa (`products` dapat memakai kolom `wholesale_price`? **tidak** — gunakan slot `modifier_options['commission']` atau tarif global; keputusan: **tarif global + opsi per produk disimpan di `product.modifier_options['commission']`** agar tanpa migrasi tambahan).
- Saat pembayaran, `commission_percent` & `commission_amount` dihitung dan disimpan di `transactions`.
- Halaman ringkasan komisi per periode per teknisi (berbasis filter yang sama dengan Tahap 5), gerbang `plan.advanced`.
- Nilai komisi **hanya informatif** — payroll tetap memakai modul Penggajian.

Kriteria selesai: komisi terhitung benar (persen vs nominal), tidak dihitung untuk transaksi void, ditolak pada paket Starter.

### Tahap 7 — Booking / reservasi (opsional)

- Tabel `bookings` (4.2), model, generator nomor `BKG`.
- Halaman kalender harian per teknisi: slot kosong dihitung dari `service_duration_minutes`.
- Aksi: buat booking → `checked_in` → otomatis membuat baris antrian `serving`/`waiting` → link `transaction_id` setelah bayar.
- Konflik jadwal dicegah di server (transaction + rentang overlap check), bukan hanya di UI.
- Gerbang `plan.advanced`.

Kriteria selesai: dua booking bertabrakan pada teknisi & waktu sama ditolak; booking batal membebaskan slot; pembatalan tidak menghapus riwayat.

### Tahap 8 — Pengujian & polisan

- Uji regresi alur POS non-barbershop (F&B, retail, laundry).
- Uji akses role `barber`: hanya menu yang ditentukan.
- Uji isolasi data antar toko/organisasi untuk seluruh fitur baru.
- `vendor/bin/pint --dirty` dan `npm run build` sebelum selesai.

---

## 7. Daftar Periksa Teknis

- [ ] Semua kolom baru `nullable` / dengan default aman — migrasi tidak menyentuh data lama.
- [x] `barber_user_id` divalidasi *anggota toko aktif* (mencegah teknisi toko lain masuk).
- [ ] Label kondisional hanya di lapisan tampilan; enum status tidak berubah.
- [x] Perhitungan total/pajak/diskon POS tidak berubah oleh Tahap 2 (bandingkan snapshot struk).
- [ ] Komisi tidak dihitung pada transaksi `is_void = true`.
- [ ] Gerbang paket diperiksa di controller, bukan hanya route/menu.
- [ ] Seluruh query laporan menerapkan scope toko (`store_id`) & anggota organisasi.
- [ ] Menu `barber` terdaftar di `RoleMenuPermissionSeeder` **dan** muncul di daftar menu `MainMenu.vue`.
- [ ] Fitur baru tidak tampil sama sekali untuk `business_type` selain `barbershop` (kecuali preset pendaftaran).

---

## 8. Kriteria Penerimaan Menyeluruh

1. Toko ber-tipe `barbershop` dapat: memilih layanan → memilih teknisi → bayar → melihat laporan per teknisi, dari nol tanpa konfigurasi manual.
2. Toko dengan tipe lain (retail, F&B, bakery, apotek, laundry, umum) tidak melihat perubahan apa pun — UI, route, dan hasil lama identik.
3. Antrian barbershop mendukung beberapa teknisi paralel dengan urutan tunggu yang adil.
4. Komisi tercatat per transaksi dan dapat direkonsiliasi dengan laporan teknisi pada periode yang sama.
5. Role `barber` tidak dapat mengakses menu di luar daftarnya, termasuk lewat URL langsung.
6. Seluruh tahap memiliki uji otomatis Pest (happy path, failure path, isolasi antar toko).

---

## 9. Log Implementasi

Bagian ini diisi setiap kali satu tahap selesai, mencakup berkas yang dibuat/diubah, jumlah pengujian yang lulus, dan keputusan yang berubah di tengah jalan.

### Tahap 0 — Preset & keputusan arsitektur (Selesai)

**Berkas:**

- Diubah: `app/Support/BusinessTypePresets.php` — tipe `barbershop` + `catalog()` (kategori layanan, satuan `jasa`/`paket`).
- Baru: `app/Support/BarbershopFeatures.php` — kelas pemungkas `enabled(?Store)`.
- Baru: `tests/Feature/BarbershopPresetTest.php` — **7 test, 24 assertions, lulus**.

### Tahap 1 — Fondasi data (Selesai)

**Berkas:**

- Baru: `database/migrations/2026_09_24_032935_add_service_duration_minutes_to_products_table.php` — `products.service_duration_minutes` (smallint nullable + indeks).
- Baru: `database/migrations/2026_09_24_032936_add_barber_user_id_to_transactions_and_queue_entries.php` — FK `barber_user_id` nullable + indeks.
- Baru: `database/migrations/2026_09_24_032937_add_barber_role_to_users_and_store_user_tables.php` — enum role `barber` (MySQL ALTER, SQLite recreate `store_user` mengikuti pola migrasi `2026_08_28_110000`).
- Diubah: `app/Models/Transaction.php`, `app/Models/QueueEntry.php` (`$fillable` + relasi `barber()`); `app/Models/Product.php` (`service_duration_minutes`); `app/Models/User.php` (`isBarber()`, `barbersForStore()`).
- Diubah: `app/Modules/CashierPin/CashierPinService.php` (label role → "Teknisi"); `app/Http/Controllers/StoreAccessController.php` (4 titik validasi role + daftar role menu; sekaligus memperbaiki validasi `kitchen` yang hilang); `database/seeders/RoleMenuPermissionSeeder.php` (menu default `barber`); `resources/js/pages/Stores/ManageStaff.vue` (tipe, label, dropdown).
- Baru: `tests/Feature/BarbershopFoundationTest.php` — **8 test, 20 assertions, lulus**.

**Keputusan/penyesuaian:**

- Rencana semula "satu migrasi gabungan" dipecah jadi 3 berkas migrasi (produk / transaksi-antrian / role) agar mudah ditinjau & di-rollback terpisah.
- `users.role` di SQLite tidak perlu diubah lagi (migrasi `2026_09_22_000000` sudah mengganti CHECK constraint dengan varchar); hanya `store_user` yang di-recreate.
- Bug ditemukan & diperbaiki di jalur: `StoreAccessController::updateMenuPermissions` tidak menerima role `kitchen` pada validasi `in:`.
- `wherePivot()` tidak dapat dipakai di dalam closure `whereHas()`; diganti `where('store_user.role', 'barber')`.

### Tahap 2 — POS: penugasan teknisi (Selesai)

**Berkas:**

- Diubah: `app/Http/Controllers/POSController.php` — flag `barbershop_enabled` + daftar `barbers` di `index()`, validasi `barber_user_id` (nullable + `exists` + cek anggota toko aktif → 422), penyimpanan di `Transaction::create` & entri antrian otomatis, `loadMissing('barber:id,name')` + flag di payload struk/invoice/PDF.
- Diubah: `resources/js/pages/POS/Index.vue` — ref `selectedBarberId`, dropdown "Teknisi" kondisional, kirim `barber_user_id` saat pembayaran.
- Diubah: struk — `resources/js/lib/thermalReceipt.ts`, `resources/js/lib/receiptPlainText.ts` (kedua fungsi builder), `resources/views/pdf/invoice.blade.php`: baris "Teknisi" hanya bila ada.
- Baru: `tests/Feature/BarbershopPosTechnicianTest.php` — **5 test, 19 assertions, lulus** (simpan teknisi + payload struk, tolak non-anggota 422, tanpa teknisi → null, warisan ke antrian otomatis, abaikan `barber_user_id` di toko non-barbershop).

**Verifikasi:**

- Regresi: `BarbershopFoundationTest` + `BarbershopPresetTest` + `OnlineSalesModuleTest` + `PosCreditCustomerTest` → **27 test, 79 assertions, lulus**.
- `vendor/bin/pint --dirty` (1 style issue di `POSController` diperbaiki) dan `npm run build` sukses.

**Keputusan/penyesuaian:**

- Validasi keanggotaan teknisi dilakukan di controller (bukan hanya Form Request) karena memerlukan konteks toko aktif.
- Test memakai `stock_quantity => 0` (kolom NOT NULL di SQLite) meski `track_stock => false`.

### Tahap 3 — Katalog layanan & onboarding (Selesai)

**Berkas:**

- Baru: `app/Support/BarbershopSampleServices.php` — daftar 8 layanan contoh (nama, harga, durasi, kategori).
- Diubah: `app/Http/Controllers/ProductController.php` — validasi `service_duration_minutes` (`nullable|integer|min:1|max:1440`) di `store()`/`update()`, payload kondisional `BarbershopFeatures::enabled()`, prop `barbershopEnabled` di `create()`/`edit()`, field `service_duration_minutes` di `$productData` edit, method baru `sampleServices()` (idempotent per nama + `StoreDefaultProductCategories::ensureForStore()`).
- Diubah: `routes/web.php` — `POST products/sample-services` (nama `products.sample-services`) di grup `['store.active', 'role.inventory']` sebelum `Route::resource`.
- Diubah: `app/Http/Middleware/HandleInertiaRequests.php` — prop global `barbershop_enabled`.
- Diubah: `resources/js/components/MainMenu.vue` — baca `barbershop_enabled`; menu `products` berlabel **"Produk & Layanan"** untuk toko barbershop.
- Diubah: `resources/js/pages/Products/Create.vue` & `Edit.vue` — prop `barbershopEnabled`, field `service_duration_minutes`, input **"Durasi Layanan (menit)"** kondisional (min 1, max 1440); Edit menambahkan normalisasi + append FormData pada jalur upload gambar.
- Diubah: `resources/js/pages/Products/Index.vue` — tombol **"Tambah Layanan Contoh"** kondisional (ikon Sparkles, `usePage` → `barbershop_enabled`).
- Baru: `tests/Feature/BarbershopServiceCatalogTest.php` — **5 test, 29 assertions, lulus** (durasi tersimpan tanpa `stock_movements`, tolak durasi 0/negatif 422, abaikan durasi di toko non-barbershop, sample services idempotent, sample services ditolak untuk non-barbershop).

**Verifikasi:**

- Regresi: `BarbershopFoundationTest` + `BarbershopPresetTest` + `BarbershopPosTechnicianTest` + `ProductImportExportTest` + `ProductStockLockTest` + `ProductBatchStatusTest` → **62 test, 255 assertions, lulus**.
- `vendor/bin/pint --dirty` bersih (21 file) dan `npm run build` sukses.

**Keputusan/penyesuaian:**

- Validasi durasi berlaku untuk semua toko (`nullable` sehingga toko lain tidak terpengaruh), tetapi kolom hanya ditulis saat `BarbershopFeatures::enabled()` — konsisten dengan pola `barber_user_id` Tahap 2.
- Layanan contoh dibuat lewat endpoint tombol di halaman daftar produk (bukan otomatis saat onboarding) agar bersifat saran, bukan paksaan — sesuai spesifikasi.
- Idempotensi sample services dijamin `firstOrCreate` kategori + cek keberadaan nama produk per toko.

### Tahap 4 — Antrian barbershop (Selesai)

**Berkas:**

- Baru: `app/Http/Middleware/EnsureQueueAccess.php` (`queue.access`) — mengizinkan `canUsePointOfSale()` OR `canAccessMenu('queues')` OR `canAccessMenu('kitchen_queue')` agar `barber` (hanya menu `queues`), `kitchen` (hanya `kitchen_queue`), dan POS role sama-sama masuk; tolak → redirect dashboard.
- Diubah: `bootstrap/app.php` — alias `queue.access`; `routes/web.php` — seluruh 8 route `queues`/`queue-entries` dipindah ke `['store.active', 'queue.access']`; route hilang `POST /queues/{queue}/entries` (`queue-entries.store`) ditambahkan.
- Diubah: `app/Http/Controllers/QueueController.php` — `barbershopProps()` (`barbershopEnabled` + `barbers` via `User::barbersForStore()`), `serializeEntry()` & eager load `barber:id,name`, `show()` & `index()` menyebar `barbershopProps()`, `storeEntry()` validasi `barber_user_id` (nullable + `exists` + anggota toko barbershop → 422), `lockForUpdate()` pada `Queue` sebelum `max(number)+1`, `$allowedTransitions` diperbaiki `waiting→cancelled`/`serving→completed` (sebelumnya salah), `tempel` `table_number` sebagai nomor kursi & perbaiki `KitchenQueueUpdated::dispatch` → `event()`.
- Diubah: `app/Events/KitchenQueueUpdated.php` — `fromEntry()` muat relasi `barber` + payload `barber_user_id`/`barber_name`.
- Diubah: `resources/js/pages/Queues/Index.vue` — props `barbershopEnabled`/`barbers`, kanal teknisi (Semua/Umum/per-teknisi), filter kanban per kanal, `callNext()` memakai transisi `fetch`→toast sama seperti `advanceStatus()` (race `waiting→serving` → 422 + reload), badge teknisi & Kursi pada kartu, label `serving` → "Sedang Dilayani" pada mode barbershop.
- Diubah: `resources/js/pages/Queues/Show.vue` — props `barbershopEnabled`/`barbers` + interface diperluas, `createForm` tambah `barber_user_id:null`/`table_number:''`, dialog tambah `<select>` Teknisi (Umum/per-teknisi, native style project) + input "Nomor Kursi" hanya saat `barbershopEnabled`, badge teknisi & Kursi pada kartu.
- Baru: `tests/Feature/BarbershopQueueTechnicianTest.php` — **10 test, 68 assertions, lulus** (penomoran `lockForUpdate`, dua teknisi paralel, tolak outsider 422, antrian umum, akses `queue.access` untuk role `barber` via pivot, `POST /queues/{id}/entries`, transisi `serving→completed`/`waiting→cancelled`, teknisi + kursi, prop Inertia papan & detail).

**Verifikasi:**

- Regresi Tahap 0-3 + `KitchenReverbKDSTest` → **31 test, 110 assertions, lulus**; ditambah suite Tahap 4 → **41 test, 178 assertions, lulus**.
- `vendor/bin/pint --dirty` (26 file) dan `npm run build` sukses.
- KDS (`KitchenQueueController::index` `whereNotNull('transaction_id')` + guard FnB) tetap terisolasi — entri barbershop manual tak muncul di dapur.

**Keputusan/penyesuaian:**

- Satu middleware `queue.access` dipakai untuk seluruh route antrian daripada meng-OR-kan `role.pos` & `menu:*` per route.
- `BarbershopQueueTechnicianTest` memakai `Event::fake([KitchenQueueUpdated::class])` di `beforeEach` (Pusher tidak tersedia di test); berkas ini sekaligus mendeklarasi `makeBarbershopPosStore()` guard `function_exists` agar tidak bentrok bila `BarbershopPosTechnicianTest` ikut ter-load.
- `QueueController::updateEntry` diperbaiki dari `KitchenQueueUpdated::dispatch(KitchenQueueUpdated::fromEntry(...))` (salah, menumpuk objek di argumen `__construct(int)`) menjadi `event(KitchenQueueUpdated::fromEntry(...))`, konsisten dengan `storeEntry`/`destroyEntry`.

### Tahap 6 — Komisi teknisi (Selesai)

**Berkas:**

- Baru: `database/migrations/2026_09_24_060000_add_barber_commission_to_products_and_transactions.php` — kolom **terpisah** `transactions.barber_commission_percent` (decimal 8,4) & `barber_commission_amount` (decimal 15,2), plus override per produk `products.barber_commission_mode` (string 10 nullable) & `barber_commission_value` (decimal 12,2 nullable).
- Diubah: `app/Support/BarbershopFeatures.php` — helper `commissionRateFor(?Product)` (urutan: override produk → global toko `stores.settings['barber_commission']`) dan `commissionForLine()`.
- Diubah: `app/Models/Transaction.php` (`$fillable` 2 kolom komisi + cast), `app/Models/Product.php` (2 kolom override).
- Diubah: `app/Http/Controllers/StoreSettingsController.php` — validasi `barber_commission_mode` (`in:percent,fixed`) & `barber_commission_value` (`numeric|min:0`), simpan ke `settings['barber_commission']` hanya saat `business_type = barbershop`; kedua field di-`unset` dari `$validated` agar tidak jadi kolom toko.
- Diubah: `app/Http/Controllers/ProductController.php` — validasi + simpan 2 kolom override di `store()`/`update()` (payload kondisional barbershop).
- Diubah: `app/Http/Controllers/POSController.php` — saat pembayaran dengan `barber_user_id`, hitung komisi per baris (persen → dicatat hanya bila semua baris persen seragam; fixed → percent NULL), amount null bila 0; **void mereset komisi ke null**.
- Diubah: `app/Http/Controllers/ReportController.php` — method baru `barberCommissions()` (guard `abort(404)` non-barbershop, agregasi per teknisi `status = completed`, detail 15 terakhir + ringkasan).
- Diubah: `routes/web.php` — `GET /reports/commissions` (`reports.commissions`) di grup `['store.active', 'role.backoffice']` + `middleware('plan.advanced')`.
- Diubah: `resources/js/pages/Profile.vue` — form pengaturan tarif komisi global (mode persen/nominal, tampil hanya untuk toko barbershop); `resources/js/pages/Products/Create.vue` & `Edit.vue` (override per produk, kondisional `barbershopEnabled`).
- Diubah: `resources/js/components/ReportTabs.vue` — tab **"Komisi Teknisi"** (flag `advanced` + `barbershop`, tampil hanya bila `barbershop_enabled`).
- Baru: `resources/js/pages/Reports/Commissions.vue` — filter periode + outlet, 4 kartu KPI, tabel rekap per teknisi, tabel detail transaksi berpaginasi.
- Baru: `tests/Feature/BarbershopCommissionTest.php` — **7 test, 43 assertions, lulus** (persen global, override fixed per produk, tanpa teknisi → null, simpan tarif via pengaturan toko, laporan + void dikecualikan, 404 non-barbershop, tolak paket Starter).

**Verifikasi:**

- Regresi: `BarbershopPosTechnicianTest` + `BarbershopQueueTechnicianTest` → **15 test, 87 assertions, lulus**.
- `php artisan migrate` sukses; `vendor/bin/pint --dirty` (31 file) dan `npm run build` sukses.

**Keputusan/penyesuaian:**

- **Permintaan user**: komisi barbershop dibuat **khusus/terpisah** dari komisi aggregator F&B — memakai kolom `barber_commission_*` sendiri, bukan `commission_*` yang sudah ada (kolom lama tetap khusus GoFood/GrabFood/ShopeeFood).
- Rencana semula menyimpan override per produk di `modifier_options['commission']` **dibatalkan** — dipakai kolom khusus di `products` agar tidak merusak modifier F&B yang memakai slot yang sama.
- Persentase dicatat hanya bila seluruh baris transaksi memakai tarif persen seragam; selain itu `barber_commission_percent` NULL dan amount tetap dijumlahkan.
- Laporan komisi memfilter `status = 'completed'` sehingga void otomatis tidak dihitung; komisi nilai hanya informatif — payroll tetap modul Penggajian.
- Test pengaturan toko memakai pivot role `admin` (pola `OnlineSalesModuleTest`) karena middleware `role.store_admin` hanya menerima `admin`/superadmin.

### Tahap 5 — Laporan & KPI per teknisi (Selesai)

**Berkas:**

- Diubah: `app/Http/Controllers/ReportController.php` — `sales()` tambah filter `barber_user_id` (hanya bila `BarbershopFeatures::enabled()`, validasi anggota toko → abaikan bila tidak valid), daftar transaksi + `hppSummary` ter-filter teknisi, `barbers` + `barberBreakdown` (agregat `COUNT`/`SUM(barber_commission_amount)` per `barber_user_id`, rata-rata kunjungan) dikirim ke Inertia.
- Diubah: `app/Http/Controllers/ReportExportController.php` — `buildPenjualan()` tambah dimensi teknisi: filter `barber_user_id` pada daftar transaksi & HPP, meta "Teknisi" saat ter-filter, section "Ringkasan per Teknisi" (tidak ikut filter teknisi — rekonsiliasi), kolom dinamis Teknisi pada tabel "Daftar Transaksi".
- Diubah: `resources/js/pages/Reports/Sales.vue` — tambah `barbershopEnabled`/`barbers`/`barberUserId`/`barberBreakdown` props, filter `<select>` teknisi (barbershop saja), `applyFilter()` + `ReportExportButton` meneruskan `barber_user_id`, kartu "Ringkasan per Teknisi" (Transaksi/Omzet/ Komisi/Rata-rata) + kolom Teknisi di tabel transaksi.

**Keputusan/penyesuaian:**

- `KpiTarget` adalah target level **toko**; tidak ada `barber_user_id` per teknisi → kartu "Ringkasan per Teknisi" menampilkan metrik riil (transaksi, omzet, komisi) tanpa target KPI. Target per teknisi ditunda ke iterasi berikutnya bila model diperluas.
- `barberBreakdown` tidak ikut filter `barber_user_id` (kecuali HPP & daftar transaksi) agar dapat dipakai sebagai cross-check: total baris breakdown = total periode.
- Filter teknisi diabaikan sepenuhnya bila `business_type != barbershop` (guard `BarbershopFeatures::enabled()`), sehingga laporan retail/F&B identik dengan sebelum Tahap 5.

**Verifikasi:**

- Regresi barbershop — `BarbershopPosTechnicianTest` + `BarbershopQueueTechnicianTest` + `BarbershopCommissionTest` → **22 test, 130 assertions, lulus**; `vendor/bin/pint --dirty` (32 file) dan `npm run build` sukses.
- `ReportExportController` sebelumnya masih mengembalikan tabel lama hardcode tanpa dimensi teknisi — diperbaiki ke versi dinamis `$txHeadings/$txAligns/$txRows/$txFooter`.
- Konflik deklarasi fungsi `makeBarbershopPosStore()` antara `BarbershopPosTechnicianTest` dan `BarbershopCommissionTest`/`QueueTechnicianTest` saat `php artisan test --filter` diperbaiki dengan guard `function_exists`.
- Build frontend: `Sales-B_*.js` bertambah kartu & kolom Teknisi.
