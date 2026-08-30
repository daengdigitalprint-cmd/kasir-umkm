# Pembelian Mandiri Tanpa Sistem Gudang

Dokumen pemeliharaan: aturan akses modul pembelian agar toko yang tidak menerapkan
sistem gudang dapat melakukan pembelian secara mandiri, dengan kontrol toggle
"sistem gudang" di Pengaturan.

## Latar Belakang

Sebelumnya, seluruh proses pembelian (daftar, form baru, simpan, dan tambah supplier
cepat) di [PurchaseController](app/Http/Controllers/PurchaseController.php) diblokir
kecuali toko ber-`location_type = central_warehouse`. Akibatnya toko mandiri/standalone
atau grup usaha tanpa Gudang Pusat tidak dapat menjalankan fungsi pembelian sama sekali.

## Aturan Baru

**Kontrol toggle:** pemilik toko menyalakan/nonaktifkan "Aktifkan sistem gudang" di
**Pengaturan → Modul Fitur**. Kewajiban "pembelian hanya dicatat di Gudang Pusat"
berlaku hanya untuk outlet yang toggle-nya aktif **dan** grup usahanya memiliki
Gudang Pusat.

| Skenario toko | Bisa pembelian mandiri? |
|---|---|
| Toko mandiri/standalone (tanpa organisasi) | Ya (toggle tidak relevan) |
| Outlet di grup usaha **tanpa** Gudang Pusat | Ya |
| Outlet di grup usaha ber-Gudang Pusat, toggle **mati** | Ya |
| Outlet di grup usaha ber-Gudang Pusat, toggle **aktif** | Tidak — lewat Gudang Pusat |
| Gudang Pusat | Ya (memang gudangnya, toggle disembunyikan) |

Aktivasi toggle ditolak bila grup usaha belum memiliki Gudang Pusat (validasi di
controller), agar tidak terjadi kondisi outlet "terblokir tapi tidak ada gudang tujuan".

## Perubahan Kode

### 1. `app/Support/StoreLocationType.php`

- `usesWarehouseSystem(Store $store): bool` — toko merupakan Gudang Pusat, atau
  `settings['warehouse_enabled']` aktif dan grup usahanya memiliki Gudang Pusat.
- `mustRoutePurchaseThroughCentralWarehouse(Store $store): bool` — kewajiban lewat
  Gudang Pusat; `true` hanya untuk outlet ber-sistem gudang (toggle aktif).

### 2. `app/Http/Controllers/PurchaseController.php`

Empat guard diganti dari `! StoreLocationType::isCentralWarehouse($store)` menjadi
`StoreLocationType::mustRoutePurchaseThroughCentralWarehouse($store)`:

- `index()` — daftar pembelian
- `create()` — form pembelian baru
- `store()` — simpan pembelian
- `storeSupplier()` — tambah supplier cepat (AJAX)

Pesan "tidak ditemukan di Gudang Pusat ini" (di `store()`) diganti netral
"tidak ditemukan di toko ini".

### 3. `app/Http/Controllers/StoreSettingsController.php`

- Validasi `warehouse_enabled` (boolean).
- Simpan ke `settings['warehouse_enabled']`.
- Validasi bisnis: aktivasi ditolak jika toko bukan Gudang Pusat dan grup usahanya
  tidak memiliki Gudang Pusat (error field `warehouse_enabled`).

### 4. `app/Http/Controllers/Settings/ProfileController.php`

Payload pengaturan menambah `warehouse_enabled` dan `location_type`.

### 5. `resources/js/pages/settings/Profile.vue`

Toggle "Aktifkan sistem gudang" di bagian Modul Fitur, disembunyikan untuk Gudang
Pusat, dengan teks penjelas dan tampilan error validasi.

### 6. `tests/Feature/PurchaseNoWarehouseTest.php`

14 test: aturan guard 5 skenario, akses HTTP menu pembelian 5 skenario, simpan
toggle di pengaturan 3 skenario, dan satu test bisnis pembelian + stok pada toko
tanpa sistem gudang.

## Database

**Tidak ada perubahan skema.** Toggle disimpan di kolom JSON `stores.settings`.
Struktur yang dipakai sudah ada: `stores.location_type`, `stores.organization_id`.

## Verifikasi

```bash
php artisan test tests/Feature/PurchaseWarehouseTest.php tests/Feature/StoreLocationTypeTest.php tests/Feature/PurchaseNoWarehouseTest.php
```

Semua 34 test lulus. Suite penuh: kegagalan yang tersisa (Auth, Settings, Import,
dsb.) adalah kegagalan 419 CSRF yang sudah terdokumentasi pre-existing dan tidak
bersentuhan dengan kode pembelian/gudang.

## Catatan Pemeliharaan

- Fungsi pembelian lain (retur pembelian, supplier, pre-order, laporan pembelian)
  tidak memblokir toko non-gudang; tidak diubah.
- Toggle bersifat per-toko (`stores.settings`), bukan per-grup. Jika ingin model
  per-grup, pindahkan key ke tabel `organizations` lalu sesuaikan
  `usesWarehouseSystem()` — seluruh guard di PurchaseController mengikutinya.
- Daftar produk di form pembelian (`PurchaseController::create()`) tidak lagi
  membatasi `inventory_type` ke `raw_material`/`packaging`; semua produk aktif
  yang melacak stok (termasuk `sellable` dan `production_output`) muncul dan bisa
  dicari, konsisten dengan halaman Produk dan scan barcode.
