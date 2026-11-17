# Rencana Implementasi — Fitur Lanjutan (Stok, Multi-outlet, Penjualan F&B)

Disusun 17 November 2026. Daftar fitur dan alasannya ada di [docs/rencana-fitur-lanjutan.md](docs/rencana-fitur-lanjutan.md). Dokumen ini berisi **cara mengerjakannya**: perubahan data, berkas yang disentuh, modul, tes, dan kriteria selesai, supaya bisa dikerjakan satu per satu kapan saja.

Asal-usul: permintaan calon klien F&B multi-outlet dengan Central Kitchen. Yang diambil hanya bagian yang berguna untuk semua pengguna; integrasi GoFood/GrabFood dan HPP FIFO sengaja ditunda (lihat bagian akhir).

### Konteks klien perintis (diperbarui 17 November 2026)

| Hal | Isi |
|---|---|
| Skala | 2 outlet + 1 Central Kitchen (dibuat sebagai lokasi Gudang Pusat) |
| Prioritas mereka | Fitur Inventory beserta alur permintaan stok → **F1–F7** |
| HPP | Klien menyebut "perhitungan HPP" sebagai kebutuhan; **metode belum dikonfirmasi**. Sudah ditanyakan apakah rata-rata tertimbang (yang dipakai sekarang) cukup atau wajib FIFO. Jika FIFO wajib → proyek terpisah (lihat "Ditunda") |
| Penawaran yang dikirim | Harga normal Rp397.000/bulan (2 × Rp99.000 + Modul Central Kitchen Rp199.000); **harga perintis Rp299.000/bulan** tetap 12 bulan atau Rp2.990.000/tahun; implementasi Rp2.500.000 (DP 50%); kontrak minimal 12 bulan |
| Estimasi yang disampaikan | 6–8 minggu, diserahkan bertahap |
| Status | Menunggu jawaban soal FIFO dan jadwal demo |

Klien ini memakai fitur yang tetap milik produk: semua yang dibangun di sini harus berlaku untuk pengguna lain juga, bukan versi khusus satu klien.

---

## 0. Aturan umum (berlaku untuk semua fitur)

1. **Modul mati secara bawaan.** Fitur baru yang punya menu/route sendiri didaftarkan di `App\Support\PlanModules::MODULES` (key, `setting`, `menus`, `routes`). Route dijaga middleware modul yang sama dengan modul lain, menu ikut `MenuCatalog` + `MainMenu.vue`.
2. **Tambah, jangan ubah.** Tabel/kolom baru boleh; kolom lama tidak diubah artinya. Alur Transfer Stok, Pembelian, dan HPP rata-rata tertimbang (`App\Support\InventoryCosting`) tetap jalan seperti sekarang.
3. **Kolom baru selalu nullable atau punya default** yang membuat data lama berperilaku persis sama.
4. **Tes.** Setiap fitur punya berkas tes Pest sendiri di `tests/Feature/`. Seluruh tes lama (`php artisan test`) harus lulus sebelum deploy.
5. **Istilah layar** mengikuti yang sudah dipakai: Stok Minimum (`min_stock_level`), Stok Rendah, Gudang Pusat (`StoreLocationType::CENTRAL_WAREHOUSE`), Transfer Stok, Pembelian.
6. **Deploy** mengikuti prosedur biasa (bandingkan hash, backup, unggah, `migrate --force`, `optimize`). Fitur yang menambah migrasi dideploy terpisah dari fitur lain supaya mudah dibatalkan.

---

## Tahap 0 — Revisi harga & Modul Central Kitchen (keputusan bisnis + teknis)

Harga yang dimaksud pemilik: **Rp99.000 per outlet per bulan**. Kondisi produksi saat ini (dicek 17 November 2026) belum sesuai:

| Yang berjalan sekarang | Nilai |
|---|---|
| GrowUp bulanan (`bisnis`) | Rp99.000, `max_stores` = **3** |
| GrowUp tahunan (`bisnis-tahunan`) | Rp1.188.000, `max_stores` = **3** |
| Outlet tambahan di atas batas | **Rp85.000**/outlet/bulan, tertulis tetap di `App\Services\OutletAddOnService::PRICE_PER_OUTLET_MONTHLY` |
| Gudang Pusat | Tidak dihitung dalam batas outlet (`StorePlanLimits` hanya menghitung scope `outlet()`) dan tidak ditagih |
| Halaman harga & landing `/mulai` | "Hingga 3 outlet & transfer stok" (dari `max_stores`) |

### R1. Harga per outlet

**Keputusan yang harus ditetapkan dulu:**
1. Pelanggan lama GrowUp yang sudah memakai 2–3 outlet: tetap harga lama sampai masa langganan habis, atau langsung harga baru?
2. Harga tahunan per outlet (usulan: 10 × bulanan = Rp990.000/outlet/tahun).

**Pekerjaan:**
- Super Admin → Paket: `max_stores` GrowUp (bulanan & tahunan) menjadi 1.
- Pindahkan harga outlet tambahan dari konstanta ke pengaturan Super Admin (pola sama dengan `AttendancePayrollPricing`), lalu setel Rp99.000. `OutletAddOnService::priceFor()` membaca dari pengaturan, dengan nilai cadangan bila belum diatur.
- Teks harga: `LandingPricing.vue`, `App\Support\LandingPricing`, landing iklan `resources/views/ads/landing.blade.php` (fitur GrowUp), FAQ di `LandingFaq.vue` dan `App\Support\LandingSeo::faqItems()` → "Rp99 rb per outlet, tambah outlet kapan saja".
- Tes: `LandingPricingTest` disesuaikan; tes baru untuk harga outlet tambahan dari pengaturan.

**Perkiraan:** 2–3 hari (setelah keputusan di atas).

### R2. Modul Central Kitchen (add-on berbayar)

Satu modul berbayar per grup usaha yang membuka fitur multi-lokasi F4–F7 (surat jalan, terima sebagian, permintaan stok, pantau stok semua lokasi).

| Hal | Usulan |
|---|---|
| Harga normal | Rp199.000/bulan per grup usaha (rentang wajar Rp149.000–Rp249.000) |
| Harga klien perintis | Bundel 2 outlet + Modul CK Rp299.000/bulan, tetap 12 bulan |
| Kebijakan Gudang Pusat | Tidak dihitung sebagai outlet; biayanya sudah tercakup dalam Modul CK |

**Pekerjaan:**
- Key `central_kitchen` di `PlanModules` (setting `central_kitchen_enabled`, menu `stock_requests` & `stock_monitor`, routes `stock-requests.*`, `stock-monitor.*`, `stock-transfers.delivery-note`). F6 dan F7 menumpang modul ini, tidak membuat key sendiri.
- Pembayaran add-on lewat alur `SubscriptionPayment` yang sudah dipakai add-on outlet & absensi (tipe baru `central_kitchen`), dengan masa aktif seperti `attendance_payroll_until` (kolom baru `central_kitchen_until` di `organizations`).
- Halaman Fitur Tambahan menampilkan modul ini beserta harganya.

**Perkiraan:** 3–4 hari (di luar F4–F7).

---

## Tahap 1 — Stok untuk semua pengguna

### F1. Penanda stok menipis di kasir

**Tujuan:** kasir melihat label kuning "Sisa N" saat stok ≤ Stok Minimum, bukan hanya "Stok: Habis".

**Data:** tidak ada perubahan. Pakai `products.stock_quantity`, `min_stock_level`, `track_stock` yang sudah ada.

**Berkas:**
- `resources/js/pages/POS/Index.vue` — fungsi label stok (sekitar baris 200–245, yang menghasilkan `Stok: …` dan `Stok: Habis`): tambah kondisi `track_stock && min_stock_level > 0 && stock_quantity <= min_stock_level` → kelas kuning + teks `Sisa N`.
- Pastikan data produk POS sudah membawa `min_stock_level` (cek payload katalog POS; tambahkan bila belum).

**Modul:** tidak perlu, berlaku untuk semua toko yang mengisi Stok Minimum.

**Tes:** tes payload katalog POS memuat `min_stock_level`.

**Selesai bila:** produk dengan stok 3 dan Stok Minimum 5 tampil "Sisa 3" berwarna kuning di kasir; produk tanpa Stok Minimum tidak berubah tampilan.

**Perkiraan:** 1 hari.

---

### F2. Daftar Belanja otomatis

**Tujuan:** satu halaman berisi barang di bawah Stok Minimum + saran jumlah beli, lalu bisa langsung dijadikan Pembelian.

**Data:**
- Kolom baru opsional `products.reorder_target` (decimal 18,4, nullable) = stok yang ingin dicapai setelah belanja. Bila kosong, saran = `min_stock_level × 2 − stock_quantity` (dibulatkan ke atas).

**Backend:**
- `App\Support\ShoppingList` — query produk `track_stock = true`, `min_stock_level > 0`, `stock_quantity <= min_stock_level`, per toko aktif; hitung `suggested_qty`.
- `ShoppingListController@index` (halaman), `@toPurchase` (mengarahkan ke `purchases.create` dengan baris terisi lewat query/session).
- Route `shopping-list.*`, dijaga hak akses yang sama dengan Pembelian.

**Frontend:**
- `resources/js/pages/ShoppingList/Index.vue` — tabel: produk, stok, Stok Minimum, saran beli (bisa diubah), centang; tombol "Jadikan Pembelian".
- `Purchases/Create.vue` — terima baris awal dari daftar belanja.
- Menu baru "Daftar Belanja" di grup "Pembelian & Distribusi" (`MenuCatalog`).

**Modul:** ikut modul Pembelian (paket GrowUp), tanpa saklar baru.

**Tes:** produk di bawah minimum muncul, di atas minimum tidak; saran jumlah benar dengan/tanpa `reorder_target`; tombol membawa baris ke form Pembelian.

**Perkiraan:** 3–4 hari.

---

### F3. Satuan beli & isi (konversi lintas dimensi)

**Tujuan:** supplier menjual per karton/pcs/kg, stok tetap dicatat di satuan dasar. Contoh: 1 karton = 24 pcs; 1 pcs bawang = 250 gram.

**Yang sudah ada:** `purchase_lines` sudah punya `input_unit`, `quantity_input`, `base_unit`, `qty_base`, `unit_cost_input`, `unit_cost_base`. `App\Support\BaseUnit` sudah mengonversi dalam dimensi yang sama (kg↔gram, liter↔ml).

**Data:**
- Tabel baru `product_units`: `id`, `product_id`, `name` (mis. "karton"), `factor` (decimal 18,6 = isi dalam satuan dasar), `is_purchase_default` (bool), timestamps. Unik `(product_id, name)`.
- Produk lama **tidak perlu baris** di tabel ini; tanpa baris berarti perilaku sekarang (satuan dasar + konversi dimensi yang sama).

**Backend:**
- `App\Models\ProductUnit`; relasi `Product::units()`.
- `BaseUnit` / helper baru `PurchaseUnitResolver::toBase(product, inputUnit, qtyInput)` → cek `product_units` dulu, baru jatuh ke konversi dimensi yang sama.
- `PurchaseController@store` — hitung `qty_base` dan `unit_cost_base` lewat resolver. Isi `input_unit`/`quantity_input` apa adanya.
- (Opsional, tahap berikutnya) dipakai juga di Transfer Stok dan Permintaan Stok.

**Frontend:**
- `Products/Create.vue`, `Edit.vue` — bagian "Satuan beli" (di pengaturan lanjutan): tambah baris nama + isi.
- `Purchases/Create.vue` — dropdown satuan per baris menampilkan satuan dasar + satuan beli produk; tampilkan hasil konversi ("2 karton = 48 pcs").

**Modul:** tanpa saklar; bagian form hanya tampil di pengaturan lanjutan produk.

**Tes:** beli 2 karton (isi 24) → stok +48 pcs dan `unit_cost_base` = harga karton ÷ 24; produk tanpa satuan beli tetap seperti sekarang (regresi pembelian 0,5 kg / 1,5 liter harus tetap lulus).

**Risiko:** sedang (menyentuh Pembelian). Mitigasi: jalur lama tidak berubah bila produk tidak punya satuan beli.

**Perkiraan:** 5–8 hari.

---

## Tahap 2 — Multi-outlet & Gudang Pusat (paket GrowUp)

### F4. Surat jalan Transfer Stok

**Tujuan:** dokumen fisik untuk setiap pengiriman antar lokasi.

**Data:** tidak ada perubahan.

**Backend:** `StockTransferController@deliveryNote` → `Pdf::loadView('pdf.delivery-note', …)` (pola sama dengan `pdf/invoice.blade.php`). Route `stock-transfers.delivery-note`.

**Frontend:** tombol "Cetak surat jalan" di `StockTransfers/Show.vue` untuk status Dikirim/Diterima.

**Isi PDF:** nomor transfer, lokasi asal → tujuan, tanggal kirim, pengirim, daftar barang + jumlah + satuan, kolom tanda tangan pengirim/penerima.

**Tes:** PDF bisa diunduh oleh anggota toko asal/tujuan; ditolak untuk toko lain.

**Perkiraan:** 1–2 hari.

---

### F5. Terima sebagian & selisih

**Tujuan:** jumlah yang benar-benar tiba bisa berbeda dari yang dikirim, dan selisihnya tercatat.

**Data (migrasi baru, semua nullable):**
- `stock_transfer_lines.quantity_received` (decimal 18,4, nullable) — null = sama dengan `quantity` (perilaku lama).
- `stock_transfer_lines.discrepancy_reason` (string 255, nullable).

**Backend:**
- `StockTransferController@receive` — terima input opsional per baris; stok tujuan bertambah sebesar `quantity_received ?? quantity`. Selisih **tidak** dikembalikan otomatis ke asal (barang dianggap rusak/hilang), dicatat sebagai pergerakan stok "Selisih transfer" di lokasi tujuan untuk jejak audit.
- Validasi: `0 ≤ quantity_received ≤ quantity`; alasan wajib bila berbeda.

**Frontend:** `StockTransfers/Show.vue` — saat "Terima", kolom jumlah diterima (default = jumlah kirim) + alasan bila berbeda.

**Laporan:** daftar transfer dengan selisih (filter di `StockTransfers/Index.vue`).

**Tes:** terima penuh tanpa isian = perilaku lama; terima 8 dari 10 → stok tujuan +8, selisih 2 tercatat dengan alasan; validasi menolak > jumlah kirim.

**Risiko:** sedang (menyentuh alur terima). Mitigasi: tanpa isian = jalur lama.

**Perkiraan:** 3–4 hari.

---

### F6. Permintaan Stok antar lokasi

**Tujuan:** outlet meminta stok ke Gudang Pusat (atau outlet lain); penerima merevisi jumlah dan menyetujui; hasilnya otomatis menjadi Transfer Stok yang sudah ada. Tidak ada input ulang.

**Alur status:** `requested` (Diminta) → `approved` (Disetujui, jumlah boleh direvisi) → transfer dibuat & dikirim → selesai saat transfer Diterima. Bisa juga `rejected` (Ditolak) atau `cancelled` (Dibatalkan peminta).

**Data (tabel baru):**
- `stock_requests`: `id`, `request_number` (unik), `organization_id`, `from_store_id` (peminta), `to_store_id` (pemasok, biasanya Gudang Pusat), `status`, `notes`, `requested_by`, `approved_by`, `approved_at`, `rejected_reason`, `stock_transfer_id` (nullable, FK ke transfer hasil), timestamps.
- `stock_request_lines`: `id`, `stock_request_id`, `requester_product_id` (produk di toko peminta), `supplier_product_id` (produk padanan di toko pemasok, nullable sampai dicocokkan), `quantity_requested`, `quantity_approved` (nullable), `stock_at_request` (snapshot stok peminta), `min_stock_at_request`, timestamps.

**Backend:**
- `App\Models\StockRequest`, `StockRequestLine`.
- `App\Actions\StockRequestWorkflow` — `approve()` membuat `StockTransfer` + baris (memakai pencocokan produk yang sama dengan `StockTransferController@match`) lalu memanggil alur kirim yang sudah ada (`App\Actions\StockTransferWorkflow`). Transfer yang dihasilkan tetap bisa dicetak surat jalannya (F4) dan diterima sebagian (F5).
- `StockRequestController` — `index` (masuk & keluar), `create` (bisa diisi dari Daftar Belanja F2), `store`, `show`, `approve` (dengan revisi jumlah), `reject`, `cancel`.
- Hak akses: peminta = anggota toko asal; penyetuju = admin/manager/staf gudang toko pemasok (sama seperti hak kirim transfer sekarang).
- Realtime: event `StockRequestUpdated` di channel `store.{storeId}` (pola sama dengan `KitchenQueueUpdated` + `routes/channels.php`), supaya permintaan baru langsung muncul di layar gudang.

**Frontend:**
- `resources/js/pages/StockRequests/Index.vue` (tab Masuk / Keluar, lencana jumlah permintaan baru), `Create.vue`, `Show.vue` (revisi jumlah per baris, tampilkan stok peminta & Stok Minimum saat diminta).
- Daftar Belanja (F2): tombol "Minta ke Gudang Pusat" selain "Jadikan Pembelian".

**Modul:** bagian dari Modul Central Kitchen (`central_kitchen`, lihat R2). Hanya bermakna untuk usaha yang punya lebih dari satu lokasi.

**Tes:**
- Outlet membuat permintaan → gudang melihatnya; outlet lain tidak.
- Gudang merevisi 10 → 8 dan menyetujui → transfer terbit dengan 8, stok gudang −8.
- Outlet menerima → stok outlet +8; status permintaan selesai.
- Tolak & batal tidak mengubah stok.
- Toko tanpa modul tidak bisa membuka route.

**Perkiraan:** 10–15 hari.

---

### F7. Pantau Stok Semua Lokasi

**Tujuan:** owner melihat stok semua outlet dan Gudang Pusat dalam satu tabel.

**Data:** tidak ada perubahan.

**Backend:**
- `App\Services\MultiLocationStockService` — ambil produk dari semua toko milik owner (pakai cara yang sama dengan `ReportController::resolveStoreIds` untuk owner), kelompokkan per nama/SKU produk, kolom per lokasi.
- `StockMonitorController@index` (+ ekspor Excel memakai `maatwebsite/excel` yang sudah terpasang).

**Frontend:** `resources/js/pages/StockMonitor/Index.vue` — tabel produk × lokasi, sel kuning (≤ Stok Minimum) dan merah (habis), filter "hanya menipis/habis", cari produk.

**Modul:** owner saja; bagian dari Modul Central Kitchen (`central_kitchen`, lihat R2).

**Tes:** owner melihat semua lokasinya; kasir/manager ditolak; pencocokan produk antar lokasi berdasarkan SKU lalu nama.

**Perkiraan:** 4–6 hari.

---

## Tahap 3 — Penjualan F&B

### F8. Saran tambahan menu (upsell)

**Data:** `upsell_rules`: `id`, `store_id`, `trigger_product_id`, `suggested_product_id`, `sort`, `is_active`, timestamps.

**Backend:** CRUD aturan; endpoint saran untuk keranjang (aturan manual dulu; tambahan "sering dibeli bersama" dihitung dari `transaction_items` 30 hari terakhir, di-cache per toko).

**Frontend:** pengaturan pasangan menu; di POS dan Scan Meja muncul baris kecil "Tambah Es Teh?" setelah produk pemicu masuk keranjang (satu ketukan untuk menambah).

**Modul:** key `upsell`.

**Perkiraan:** 5–7 hari.

### F9. Segmentasi pelanggan

**Data:** tidak ada tabel baru; dihitung dari transaksi + pelanggan.

**Aturan awal:** Setia (≥ 4 kunjungan / 30 hari), Baru (kunjungan pertama ≤ 30 hari), Lama tidak datang (pernah ≥ 3 kunjungan, tidak datang ≥ 30 hari). Ambang bisa diatur.

**Frontend:** tab segmen di halaman Pelanggan + tombol WhatsApp (tautan `wa.me`, tanpa biaya API) dengan pesan bawaan yang bisa diubah.

**Perkiraan:** 4–6 hari.

### F10. Pesanan Antar mandiri

**Data:** `delivery_orders`: `id`, `transaction_id`, `customer_name`, `phone`, `address`, `delivery_fee`, `courier_user_id` (karyawan), `status` (`preparing`, `on_the_way`, `delivered`, `cancelled`), `dispatched_at`, `delivered_at`, `notes`, timestamps.

**Backend:** dibuat dari POS saat tipe pesanan Antar (`SalesChannel::Delivery`); ongkir masuk total transaksi sebagai komponen terpisah (dicatat juga di laporan).

**Frontend:** isian alamat & ongkir di checkout POS; halaman "Pengantaran" (daftar + ubah status); kurir melihat pesanan miliknya; ongkir tercetak di struk.

**Modul:** key `delivery`.

**Perkiraan:** 8–12 hari.

### F11. QRIS dinamis

**Prasyarat:** pilih payment gateway dan buat akun merchant (biaya per transaksi ditanggung toko).

**Data:** `payment_intents`: `id`, `store_id`, `transaction_id` (nullable sampai lunas), `gateway`, `external_id`, `amount`, `status`, `qr_string`, `expires_at`, `paid_at`, `payload`, timestamps. Kredensial gateway disimpan terenkripsi per toko.

**Backend:** `App\Services\Payments\QrisGateway` (antarmuka) + satu implementasi gateway; endpoint buat QR, webhook pelunasan (verifikasi tanda tangan), transaksi otomatis lunas saat webhook diterima; batas waktu & pembatalan.

**Frontend:** di POS metode "QRIS (otomatis)" menampilkan QR + status menunggu; di Scan Meja pelanggan bisa bayar langsung.

**Modul:** key `qris_gateway`.

**Risiko:** sedang (pembayaran). Uji di mode sandbox gateway sebelum produksi.

**Perkiraan:** 7–10 hari.

---

## Ditunda (dan syarat untuk memulainya)

| Fitur | Alasan ditunda | Syarat mulai |
|---|---|---|
| Integrasi otomatis GoFood/GrabFood/ShopeeFood | API hanya untuk mitra/integrator resmi | Ada akses API resmi atau integrator pihak ketiga; dibuat sebagai layanan terpisah yang mengirim pesanan ke POS |
| HPP metode FIFO | `InventoryCosting` dipakai di ±52 titik dalam 12 berkas (POS, Pembelian, Produksi, Transfer, Opname, Penyesuaian, Resep); salah hitung berdampak ke laba rugi semua toko | Ada kebutuhan nyata; dibuat sebagai **pilihan metode per toko** (`inventory_costing_method`) dengan lapisan biaya per penerimaan, toko lain tetap rata-rata tertimbang |
| Order otomatis ke Gudang Pusat | Bergantung F2 & F6 | Setelah F2 & F6 jalan: tugas terjadwal membuat Permintaan Stok dari Daftar Belanja untuk toko yang menyalakannya |

---

## Urutan & perkiraan total

Urutan di bawah sudah menyesuaikan prioritas klien perintis (Inventory + permintaan stok). R1 bisa dikerjakan kapan saja setelah keputusan bisnisnya ditetapkan; R2 dikerjakan bersama F6 supaya modulnya langsung bisa dijual.

| Urutan | Fitur | Perkiraan | Migrasi? |
|---|---|---|---|
| — | R1 Harga per outlet | 2–3 hari | Ya (pengaturan harga) |
| — | R2 Modul Central Kitchen (pembayaran add-on) | 3–4 hari | Ya (1 kolom) |
| 1 | F1 Penanda stok menipis | 1 hari | Tidak |
| 2 | F2 Daftar Belanja | 3–4 hari | Ya (1 kolom) |
| 3 | F3 Satuan beli & isi | 5–8 hari | Ya (1 tabel) |
| 4 | F4 Surat jalan | 1–2 hari | Tidak |
| 5 | F5 Terima sebagian | 3–4 hari | Ya (2 kolom) |
| 6 | F6 Permintaan Stok | 10–15 hari | Ya (2 tabel) |
| 7 | F7 Pantau Stok Semua Lokasi | 4–6 hari | Tidak |
| 8–11 | F8–F11 (sesuai kebutuhan pasar) | 24–35 hari | Ya |

**Tahap 0: ±5–7 hari kerja. Tahap 1–2: ±27–40 hari kerja. Tahap 3: ±24–35 hari kerja.** Untuk klien perintis, yang dijanjikan adalah Tahap 1–2 (6–8 minggu); Tahap 0 dikerjakan di sela-selanya. Perkiraan dari pembacaan kode per 17 November 2026; pastikan ulang saat mulai mengerjakan tiap fitur.

## Status

| Fitur | Status |
|---|---|
| R1 Harga per outlet | Menunggu keputusan pelanggan lama & harga tahunan |
| R2 Modul Central Kitchen | ✅ Selesai 17 Nov 2026 (`CentralKitchenAddOnTest`). Tab "Central Kitchen" di Langganan → checkout (transfer/QRIS) → Super Admin konfirmasi di daftar pembayaran → `organizations.central_kitchen_until` diperpanjang untuk seluruh grup. Harga diatur Super Admin (bawaan Rp199.000/bln, Rp1.990.000/thn). Toko tanpa modul diarahkan ke tab ini. Aktivasi manual tetap ada: `php artisan central-kitchen:activate`. Harga khusus klien perintis (bundel Rp299.000/bln) ditagih manual di luar aplikasi, lalu modul diaktifkan dengan perintah aktivasi |
| F1 Penanda stok menipis di kasir | ✅ Selesai 17 Nov 2026 (branch `feat/stok-dan-central-kitchen`, `PosLowStockMarkerTest`) |
| F2 Daftar Belanja | ✅ Selesai 17 Nov 2026 (`ShoppingListTest`). `reorder_target` belum dibuat — saran memakai 2 × Stok Minimum. Outlet yang dialihkan ke Gudang Pusat hanya bisa "Salin daftar"; tombol "Minta ke Gudang Pusat" menyusul di F6 |
| F3 Satuan beli & isi | ✅ Selesai 17 Nov 2026 (`PurchaseUnitTest`). Diatur di Edit Produk → pengaturan lanjutan (disimpan terpisah dari form produk). Dipakai di Pembelian & tool AI pembelian; belum di Transfer Stok. Satuan beli terhapus otomatis bila satuan dasar diganti |
| F4 Surat jalan | ✅ Selesai 17 Nov 2026 (`StockTransferDeliveryNoteTest`). Tombol "Cetak surat jalan" di detail transfer (status Dikirim/Diterima). Belum digating modul `central_kitchen` — diputuskan saat R2 dibuat |
| Keamanan Transfer Stok | ✅ Diperbaiki 17 Nov 2026 (`StockTransferAccessTest`). Sebelumnya admin/manajer toko mana pun bisa melihat, mengirim, menerima, dan membatalkan transfer grup lain lewat ID. Kini akses lintas toko hanya untuk grup usaha yang sama; terima transfer juga dikunci dari dobel-terima |
| F5 Terima sebagian & selisih | ✅ Selesai 17 Nov 2026 (`StockTransferPartialReceiptTest`). Selisih dicatat di baris transfer + catatan pergerakan "transfer masuk" (tanpa jenis pergerakan baru). Filter "Ada selisih" di daftar transfer. Transfer apotek dengan batch/ED wajib diterima penuh |
| F6 Permintaan Stok | ✅ Selesai 17 Nov 2026 (`StockRequestTest`). Outlet meminta → pemasok merevisi jumlah & "Setujui & kirim" → Transfer Stok langsung Dikirim → diterima outlet = Selesai. Transfer hasil permintaan yang dibatalkan ikut membatalkan permintaan. Realtime lewat channel privat `stock-requests.{storeId}` (cadangan: muat ulang 30 detik). Badge jumlah permintaan masuk di menu. Tombol "Minta ke Gudang Pusat" di Daftar Belanja. Varian belum didukung (sama seperti Transfer Stok) |
| Kunci transfer | ✅ Kirim & batal transfer kini mengunci baris sungguhan (sebelumnya klik ganda bisa memotong/mengembalikan stok dua kali) |
| F7 Pantau Stok Semua Lokasi | ✅ Selesai 17 Nov 2026 (`StockMonitorTest`). Menu owner "Pantau stok semua lokasi" (`/owner/stock-monitor`): tabel barang × lokasi (Gudang Pusat di kolom pertama), merah = habis, kuning = ≤ Stok Minimum, filter menipis/habis, cari, ekspor Excel berwarna. Dicocokkan lewat SKU lalu nama. Hanya lokasi grup aktif yang owner miliki. Barang bervarian belum ikut |
| F8–F11 | Belum dikerjakan |
| HPP FIFO | Menunggu jawaban klien (wajib atau tidak) |
