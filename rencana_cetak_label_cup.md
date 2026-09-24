# Rencana Implementasi Modul Cetak Label Cup (Cup Sticker Printing)

Dokumen ini berisi arsitektur dan langkah implementasi fitur **Cetak Label Cup / Stiker Pesanan** untuk warkop, kafe, dan F&B pada aplikasi kasir UMKM.

---

## 📌 Status Fase & Progress

- [x] **Fase 1: Investigasi & Perancangan Arsitektur**
  - Analisis alur pengaturan toko (`StoreSettingsController`, `ProfileController`, `Profile.vue`).
  - Analisis alur POS dan cetak thermal (`POSController.php`, `POS/Index.vue`, `usePrinter.ts`, `escPosEncode.ts`, `kitchenTicketPrint.ts`).
  - Penentuan struktur konfigurasi setting `cup_label_enabled`, ukuran label cup (misal: 40x30mm / 50x30mm atau lebar standar stiker), informasi kustomisasi (nama menu, varian, modifier/topping, catatan, nomor transaksi/antrean, nama pemesan, dan nomor cup misal `Cup 1/3`).

- [x] **Fase 2: Backend & Pengaturan Toko**
  - [x] Tambahkan konfigurasi `cup_label_enabled` di validasi `StoreSettingsController.php` & default nilai.
  - [x] Tambahkan parsing `cup_label_enabled` pada `Settings\ProfileController.php` dan `POSController.php`.

- [x] **Fase 3: Frontend Pengaturan Toko (Settings UI)**
  - [x] Tambahkan toggle sakelar "Stiker Cup / Label Pesanan" di tab Toko pada `resources/js/pages/settings/Profile.vue`.
  - [x] Dukung keterangan informatif untuk jenis usaha F&B / Warkop / Kafe.

- [x] **Fase 4: Modul Cetak Label Cup di Kasir (POS Integration & Print Engine)**
  - [x] Buat utility helper `resources/js/lib/cupLabelPrint.ts` untuk memformat stiker per item & cetak (ukuran compact label thermal).
  - [x] Tambahkan tombol "Cetak Stiker Cup" di popup sukses pembayaran POS (`POS/Index.vue`).

- [x] **Fase 5: Pengujian & Pembersihan Kode**
  - [x] Buat feature test Pest `tests/Feature/CupLabelSettingsTest.php` untuk memverifikasi toggle pengaturan cup label tersimpan dan dikirimkan ke POS props.
  - [x] Jalankan Pint (`vendor/bin/pint --dirty`) untuk formatting PHP (Semua file lulus).

---

## 📋 Detail Spesifikasi Label Cup
Setiap stiker cup berisi:
1. **Header Toko & Nomor Pesanan / Nomor Antrean** (misal: `#TRX-001` atau nomor antrean).
2. **Nomor Urut Cup** (misal: `Cup 1 dari 3` jika memesan 3 pcs kopi).
3. **Nama Produk & Varian** (misal: `Es Kopi Susu Aren - Large`).
4. **Modifikasi / Topping** (misal: `• Less Sugar`, `• Extra Shot`).
5. **Catatan Khusus** (misal: `Tanpa sedotan`).
6. **Nama Pelanggan & Waktu Pesanan**.
