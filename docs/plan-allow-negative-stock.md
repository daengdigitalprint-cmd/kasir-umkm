# Rencana Implementasi: Izinkan Penjualan Stok Habis (Allow Negative Stock)

> **Status Umum**: ✅ Selesai
> **Terakhir Diperbarui**: 2026-08-31

---

## Ringkasan

Fitur ini memungkinkan produk tertentu untuk tetap bisa dijual meskipun stok sudah habis (mencapai nol). Ketika diaktifkan, stok produk boleh menjadi negatif sesuai jumlah penjualan yang melebihi stok tersedia. Fitur ini diaktifkan/dinonaktifkan **per produk** secara individual.

---

## Daftar Isi

1. [Database & Migration](#1-database--migration)
2. [Backend — Model](#2-backend--model)
3. [Backend — Controller (CRUD Produk)](#3-backend--controller-crud-produk)
4. [Backend — POS (Validasi & Potong Stok)](#4-backend--pos-validasi--potong-stok)
5. [Frontend — Form Edit Produk](#5-frontend--form-edit-produk)
6. [Frontend — Laporan (Visual Negatif)](#6-frontend--laporan-visual-negatif)
7. [Pengujian (Testing)](#7-pengujian-testing)
8. [Dampak Laporan](#8-dampak-laporan)
9. [Best Practice & Rekomendasi](#9-best-practice--rekomendasi)

---

## 1. Database & Migration

### [x] Task 1.1 — Buat Migration

**File**: `database/migrations/2026_08_31_033955_add_allow_negative_stock_to_products_table.php`

```php
// Kolom yang ditambahkan ke tabel `products`:
$table->boolean('allow_negative_stock')->default(false)->after('unlimited_stock');
```

```php
// Kolom yang ditambahkan ke tabel `product_variants`:
$table->boolean('allow_negative_stock')->default(false)->after('min_stock_level');
```

| Checklist | Item |
|-----------|------|
| [x] | Buat file migration |
| [x] | Jalankan migration di local |
| [x] | Verifikasi kolom ada di kedua tabel |
| [x] | Pastikan default `false` untuk produk lama |

---

## 2. Backend — Model

### [x] Task 2.1 — Update Model Product

**File**: `app/Models/Product.php`

| Checklist | Item |
|-----------|------|
| [x] | Tambah `allow_negative_stock` ke `$fillable` |
| [x] | Pastikan tidak perlu `$casts` (boolean sudah otomatis) |

### [x] Task 2.2 — Update Model ProductVariant

**File**: `app/Models/ProductVariant.php`

| Checklist | Item |
|-----------|------|
| [x] | Tambah `allow_negative_stock` ke `$fillable` |

---

## 3. Backend — Controller (CRUD Produk)

### [x] Task 3.1 — Update ProductController::edit()

**File**: `app/Http/Controllers/ProductController.php` (method `edit()`)

| Checklist | Item |
|-----------|------|
| [x] | Pastikan `allow_negative_stock` terkirim ke view (sudah otomatis dari `$fillable` + `toArray()`) |

### [x] Task 3.2 — Update ProductController::update()

**File**: `app/Http/Controllers/ProductController.php` (method `update()`)

| Checklist | Item |
|-----------|------|
| [x] | Tambah validasi: `'allow_negative_stock' => 'nullable\|boolean'` |
| [x] | Pastikan field tersimpan ke database |

### [x] Task 3.3 — Update ProductController::store() (Opsional)

**File**: `app/Http/Controllers/ProductController.php` (method `store()`)

| Checklist | Item |
|-----------|------|
| [x] | Tambah field `allow_negative_stock` saat buat produk baru (opsional, default false) |

---

## 4. Backend — POS (Validasi & Potong Stok)

### [x] Task 4.1 — Update POSController::addItem()

**File**: `app/Http/Controllers/POSController.php` (method `addItem()`)

**Perubahan**: Tambah kondisi `allow_negative_stock` pada pengecekan stok.

```
Sebelum:
  if ($product->track_stock && ! $product->unlimited_stock && stok kurang) → tolak

Sesudah:
  if ($product->track_stock && ! $product->unlimited_stock && ! $product->allow_negative_stock && stok kurang) → tolak
```

| Checklist | Item |
|-----------|------|
| [x] | Update kondisi validasi untuk produk biasa |
| [x] | Update kondisi validasi untuk produk varian |

### [x] Task 4.2 — Update POSController::processPayment()

**File**: `app/Http/Controllers/POSController.php` (method `processPayment()`)

**3 lokasi pengecekan yang perlu diubah:**

#### 4.2a — Validasi stok produk utama

```
Sebelum:
  if ($product->track_stock && ! $product->unlimited_stock) { cek stok }

Sesudah:
  if ($product->track_stock && ! $product->unlimited_stock && ! $product->allow_negative_stock) { cek stok }
```

#### 4.2b — Validasi stok komponen bundle

```
Sebelum:
  if ($component->track_stock && ! $component->unlimited_stock) { cek stok komponen }

Sesudah:
  if ($component->track_stock && ! $component->unlimited_stock && ! $component->allow_negative_stock) { cek stok komponen }
```

#### 4.2c — Validasi stok sale consumption (bahan baku F&B)

```
Sebelum:
  if ($product->track_stock && ! $product->unlimited_stock) { cek stok konsumsi }

Sesudah:
  if ($product->track_stock && ! $product->unlimited_stock && ! $product->allow_negative_stock) { cek stok konsumsi }
```

| Checklist | Item |
|-----------|------|
| [x] | Update validasi produk utama di processPayment() |
| [x] | Update validasi komponen bundle di processPayment() |
| [x] | Update validasi sale consumption di processPayment() |

### [x] Task 4.3 — Update POSController::applySaleStockDeduction()

**File**: `app/Http/Controllers/POSController.php` (method `applySaleStockDeduction()`)

**Perubahan**: Ketika `allow_negative_stock = true` dan batch habis, **jangan lempar exception**. Biarkan stok menjadi negatif.

```
Sebelum (batch tracking):
  Jika total batch < qty → lempar Exception("Batch tidak mencukupi")

Sesudah:
  Jika total batch < qty && !allow_negative_stock → lempar Exception
  Jika total batch < qty && allow_negative_stock → biarkan, catat sisa negatif di batch terakhir atau buat catatan
```

| Checklist | Item |
|-----------|------|
| [x] | Update logika batch tracking untuk allow_negative_stock |
| [x] | Pastikan StockMovement tetap tercatat dengan benar |
| [x] | Pastikan `balance_after` di StockMovement akurat |

### [x] Task 4.4 — Pastikan SaleConsumptionResolver::validateStock()

**File**: `app/Support/SaleConsumptionResolver.php`

| Checklist | Item |
|-----------|------|
| [x] | Cek apakah validasi konsumsi bahan juga perlu update |
| [x] | Tambah kondisi `allow_negative_stock` jika diperlukan |

### [x] Task 4.5 — Pastikan Transfer Stok (Opsional)

**File**: `app/Http/Controllers/StockTransferController.php`

| Checklist | Item |
|-----------|------|
| [x] | Pertimbangkan: transfer keluar untuk produk dengan allow_negative_stock — apakah boleh? |

---

## 5. Frontend — Form Edit Produk

### [x] Task 5.1 — Tambah Toggle di Edit.vue

**File**: `resources/js/pages/Products/Edit.vue`

**Lokasi**: Di sekitar area `track_stock` dan `unlimited_stock`, tambahkan toggle baru:

| Field | V-model | Label |
|-------|---------|-------|
| Checkbox/Switch | `form.allow_negative_stock` | "Izinkan Penjualan Stok Habis" |

**Kondisi tampilan:**
- Toggle hanya muncul/m aktif jika `track_stock = true` (karena jika stok tidak dilacak, fitur ini tidak relevan)
- Toggle harus **DISABLED** jika `unlimited_stock = true` (karena unlimited sudah skip semua validasi)
- Deskripsi helper text: "Produk ini tetap bisa dijual meskipun stok habis. Stok akan menjadi negatif dan harus direstok segera."

| Checklist | Item |
|-----------|------|
| [x] | Tambah field `allow_negative_stock` ke form data di script section |
| [x] | Tambah toggle UI di template section |
| [x] | Tambah kondisi show/disabled yang benar |
| [x] | Verifikasi field terkirim saat submit form |

---

## 6. Frontend — Laporan (Visual Negatif)

### [x] Task 6.1 — Laporan Inventaris

**File**: `resources/js/pages/Reports/Inventory.vue` atau sejenisnya

| Checklist | Item |
|-----------|------|
| [x] | Tampilkan stok negatif dengan warna merah atau badge peringatan |
| [x] | Tambahkan kolom indikator "Allow Negative" untuk produk yang mengaktifkan fitur |

### [x] Task 6.2 — Stock Overview

**File**: Halaman ringkasan stok

| Checklist | Item |
|-----------|------|
| [x] | Tandai produk dengan stok negatif secara prominent |
| [x] | Pertimbangkan filter khusus "Produk Stok Negatif" |

### [x] Task 6.3 — Dashboard Widget (Opsional)

| Checklist | Item |
|-----------|------|
| [x] | Tambah widget/alert di dashboard untuk jumlah produk dengan stok negatif |

---

## 7. Pengujian (Testing)

### [x] Task 7.1 — Unit Test Migration

| Checklist | Item |
|-----------|------|
| [x] | Test kolom `allow_negative_stock` ada dan default false |
| [x] | Test untuk produk dan varian |

### [x] Task 7.2 — Feature Test POS

| Checklist | Item |
|-----------|------|
| [x] | Test: produk dengan `allow_negative_stock = false` ditolak saat stok habis |
| [x] | Test: produk dengan `allow_negative_stock = true` berhasil saat stok habis |
| [x] | Test: stok menjadi negatif setelah penjualan melebihi stok |
| [x] | Test: `unlimited_stock` tetap override `allow_negative_stock` |
| [x] | Test: produk tanpa `track_stock` tetap skip validasi |

### [x] Task 7.3 — Feature Test Restok

| Checklist | Item |
|-----------|------|
| [x] | Test: restok produk dengan stok negatif — stok bertambah dari negatif |
| [x] | Test: restok produk dengan stok positif — stok bertambah normal |
| [x] | Contoh: stok -5 + restok 10 = stok akhir 5 |

### [x] Task 7.4 — Feature Test CRUD

| Checklist | Item |
|-----------|------|
| [x] | Test: simpan produk dengan `allow_negative_stock = true` |
| [x] | Test: update produk mengubah flag `allow_negative_stock` |
| [x] | Test: toggle hanya bisa diakses oleh user yang berwenang |

### [x] Task 7.5 — Run Full Test Suite

| Checklist | Item |
|-----------|------|
| [x] | Jalankan seluruh test suite untuk memastikan tidak ada regressi |

---

## 8. Dampak Laporan

### Laporan Penjualan
- **Tidak terdampak** — data dari `Transaction` + `TransactionItem`, bukan dari stok.

### Laporan Stok/Inventaris
- **Terlihat** — stok negatif akan muncul. Perlu visual peringatan.

### Kartu Stok
- **Tidak terdampak** — `StockMovement` mencatat `quantity_change` dan `balance_after` secara natural.

### Laporan Laba Rugi
- **Tidak terdampak** — HPP dari snapshot saat transaksi dibuat.

### Laporan Arus Kas
- **Tidak terdampak** — mencatat aliran uang, bukan stok.

### Laporan Selisih Stok
- **Berpotensi selisih** — stok fisik tidak mungkin negatif, tapi stok sistem negatif. Ini benar dan diharapkan.

### Neraca
- **Perhatian** — nilai persediaan negatif perlu perhatian khusus di akuntansi.

---

## 9. Best Practice & Rekomendasi

### Siapa yang Bisa Mengaktifkan
- **Hanya Owner atau Super Admin**
- Kasir dan Manager tidak boleh mengakses toggle ini

### Notifikasi Peringatan
- Dashboard admin menampilkan jumlah produk dengan stok negatif
- Log peringatan saat stok mencapai negatif pertama kali

### Rekonsiliasi Stok
- Jalankan stock opname rutin (minimal mingguan) untuk produk yang mengaktifkan fitur ini
- Dashboard perlu filter khusus untuk produk stok negatif

### Batas Stok Negatif
- **Versi sederhana** (implementasi awal): tanpa batas, stok boleh negatif sebanyak yang dijual
- **Versi lanjutan** (opsional ke depan): tambahkan field `min_negative_limit` untuk membatasi

### Audit Log
- Catat di `activity_log` saat toggle diaktifkan/dinonaktifkan
- Siapa yang mengubah, kapan, dan produk apa

---

## Progres Keseluruhan

| Fase | Status |
|------|--------|
| 1. Database & Migration | ✅ Selesai |
| 2. Backend — Model | ✅ Selesai |
| 3. Backend — Controller CRUD | ✅ Selesai |
| 4. Backend — POS Validasi | ✅ Selesai |
| 5. Frontend — Form Edit | ✅ Selesai |
| 6. Frontend — Laporan | ✅ Selesai |
| 7. Pengujian (Testing) | ✅ Selesai (9 test pass) |
| 8. Format Code (Pint) | ✅ Selesai |
| 9. Deploy ke VPS | ⬜ Belum (manual) |

---

## File yang Diubah

| # | File | Tipe | Keterangan |
|---|------|------|------------|
| 1 | `database/migrations/2026_08_31_033955_add_allow_negative_stock_to_products_table.php` | BARU | Migration tambah kolom |
| 2 | `app/Models/Product.php` | UBAH | Tambah $fillable + $casts |
| 3 | `app/Models/ProductVariant.php` | UBAH | Tambah $fillable + $casts |
| 4 | `app/Http/Controllers/ProductController.php` | UBAH | Validasi & simpan field |
| 5 | `app/Http/Controllers/POSController.php` | UBAH | Update validasi stok di 4 lokasi |
| 6 | `app/Support/SaleConsumptionResolver.php` | UBAH | Update validasi konsumsi |
| 7 | `resources/js/pages/Products/Edit.vue` | UBAH | Tambah toggle UI |
| 8 | `resources/js/pages/Reports/Inventory.vue` | UBAH | Visual stok negatif |
| 9 | `resources/js/pages/Reports/StockCard.vue` | UBAH | Visual stok negatif |
| 10 | `tests/Feature/AllowNegativeStockTest.php` | BARU | 9 test case |
| 11 | `tests/Feature/SaleConsumptionResolverTest.php` | UBAH | +3 test allow_negative_stock |
