# Rencana Implementasi Fitur Koreksi Stok

Modul Penyesuaian Stok (*Stock Adjustment*) dan Stock Opname (*Stock Count*) untuk paket GrowUp ke atas.

## Status Progres

| Tahap | Nama | Status |
|---|---|---|
| 0 | Finalisasi aturan bisnis dan gerbang paket | Selesai |
| 1 | Fondasi data: migrasi, model, relasi | Selesai |
| 2 | Penyesuaian Stok dasar (non-varian, non-batch) | Selesai |
| 3 | Penguncian jalur lama di form Edit Produk | Selesai |
| 4 | Stock Opname: sesi, draft, lembar hitung | Selesai |
| 5 | Dukungan varian dan batch FIFO | Selesai |
| 6 | Approval dan pemisahan wewenang | Selesai |
| 7 | Laporan selisih, kartu stok, dan jurnal | Selesai |

Setiap tahap ditandai selesai pada tabel di atas dan dicatat rinci di bagian **9. Log Implementasi**.

---

## 1. Tujuan

Menyediakan mekanisme koreksi stok yang dapat diaudit, menggantikan praktik mengetik ulang angka stok di form Edit Produk.

Target alur bisnis:

```text
Selisih fisik ditemukan
  -> Dokumen koreksi dibuat (bernomor, beralasan)
  -> Ditinjau dan disetujui
  -> Posting: mutasi stok + StockMovement + nilai rupiah
  -> Terlihat di kartu stok dan laporan selisih
```

---

## 2. Analisis Kondisi Codebase

### 2.1 Fondasi yang sudah tersedia

- Ledger `stock_movements` lengkap dengan `type`, `reference_type/id`, `balance_after`, dan `user_id`.
- Konstanta `StockMovement::TYPE_ADJUSTMENT` dan `TYPE_DAMAGE` sudah terdefinisi.
- Halaman riwayat mutasi dengan filter produk, tipe, dan rentang tanggal sudah tersedia.
- Laporan ringkasan stok beserta nilai persediaan sudah tersedia.
- Costing weighted average terpusat di `InventoryCosting`.
- Pola dokumen berstatus dengan mutasi pembalik sudah matang di modul transfer stok.
- Batch FIFO, varian, dan bundel sudah didukung di POS dan transfer.
- Gerbang paket `StorePlanLimits::allowsAdvancedRetailFeatures()` sudah dipakai modul transfer.
- Middleware peran `role.inventory`, `role.store_admin`, dan `role.stock_view` sudah terdaftar.

### 2.2 Kesenjangan terhadap kebutuhan

- Satu-satunya cara koreksi stok adalah mengetik ulang angka di form Edit Produk.
- Koreksi tidak menyimpan alasan terstruktur; catatan hanya berupa teks generik.
- Koreksi tidak menghitung nilai rupiah, sehingga kerugian persediaan tidak terukur.
- Tidak ada dokumen bernomor, sehingga tidak dapat dicetak sebagai berita acara maupun ditelusuri.
- Tidak ada persetujuan, sehingga selisih kas berpotensi ditutupi lewat perubahan stok.
- Produk batch dan varian dikoreksi lewat angka agregat tanpa kejelasan batch atau varian mana yang berubah.
- Belum ada mekanisme hitung fisik berkala beserta lembar hitungnya.

### 2.3 Risiko integrasi utama

1. Toko tetap berjualan selama opname berlangsung. Koreksi yang menimpa nilai stok secara absolut akan menghapus efek penjualan di sela-sela proses.
2. Produk batch menyimpan stok di dua tempat, yaitu `products.stock_quantity` dan `product_batches.quantity_remaining`. Keduanya harus tetap sinkron setelah posting.
3. Produk varian menyimpan stok di `product_variants`, sedangkan induk hanya menampung hasil penjumlahan. Koreksi harus menyasar varian.
4. Perubahan `average_cost` yang keliru saat koreksi akan merusak laporan laba seluruh periode.
5. Penguncian baris tanpa urutan yang konsisten berisiko menimbulkan *deadlock* saat posting dokumen dengan banyak baris.
6. Mengunci field stok di form Edit Produk berpotensi memutus alur pengguna paket Starter yang tidak mendapat fitur ini.

---

## 3. Keputusan Arsitektur

### 3.1 Dua fitur terpisah dalam satu fondasi data

Penyesuaian Stok dan Stock Opname memakai pasangan tabel yang sama, dibedakan oleh kolom `type`.

| Aspek | Penyesuaian Stok | Stock Opname |
|---|---|---|
| Tujuan | Kejadian insidental | Hitung fisik berkala |
| Frekuensi | Harian | Mingguan atau bulanan |
| Jumlah baris | Sedikit | Banyak |
| Alasan | Dipilih per dokumen | Selalu `opname` |
| Alur | Ringkas | Bertahap dengan draft |

Alasan penggabungan tabel: keduanya menghasilkan mutasi yang identik secara akuntansi, sehingga laporan selisih cukup membaca satu sumber.

### 3.2 Posting memakai selisih, bukan nilai absolut

Nilai stok sistem dibekukan sebagai snapshot saat baris dibuat. Saat posting:

```text
variance    = counted_quantity - system_quantity (snapshot)
stok_akhir  = stok_saat_ini + variance
```

Pendekatan ini menjaga transaksi yang terjadi selama proses hitung tetap terhitung benar. Konsisten dengan pola mutasi delta yang sudah dipakai modul transfer stok.

### 3.3 Perlakuan terhadap HPP

- **Selisih minus**: `average_cost` tidak berubah, kuantitas berkurang. Nilai hilang dihitung `qty x average_cost` dan dicatat sebagai kerugian persediaan.
- **Selisih plus**: memakai `average_cost` yang berlaku saat posting. Pengguna tidak diminta memasukkan harga, karena data yang diisi asal akan merusak nilai persediaan.

`InventoryCosting::weightedAverage()` sudah menangani kasus ini dengan benar tanpa perlu diubah.

### 3.4 Aturan per jenis produk

| Jenis produk | Perlakuan |
|---|---|
| Produk biasa | Koreksi langsung pada `products.stock_quantity` |
| Produk varian | Koreksi per `product_variant_id`, induk disinkronkan dari penjumlahan varian |
| Produk batch | Koreksi per batch; minus tanpa batch spesifik dipotong FIFO, plus membuat batch baru berkode `ADJ <nomor dokumen>` |
| Produk bundel | Ditolak; yang dikoreksi adalah komponennya |
| `track_stock` mati atau `unlimited_stock` aktif | Dikecualikan dari daftar |

### 3.5 Dokumen bersifat final setelah posting

Dokumen berstatus `posted` tidak dapat diubah maupun dihapus. Pembatalan dilakukan melalui mutasi pembalik, mengikuti pola pembatalan transfer stok.

### 3.6 Gerbang paket

Seluruh modul ini tersedia mulai paket GrowUp (slug `bisnis` dan `bisnis-tahunan`). Paket Starter dan Starter Tahunan tidak mendapat akses.

Implementasi memakai `StorePlanLimits::allowsAdvancedRetailFeatures()` yang sudah ada, tanpa menambah helper baru. Penegakan dilakukan berlapis:

1. **Route**: middleware `plan.advanced` agar halaman tidak dapat dibuka sama sekali.
2. **Controller**: `StorePlanLimits::assertAdvancedRetail()` pada seluruh aksi tulis, sebagai pengaman bila route ditembus.
3. **Menu**: item sidebar disembunyikan bagi toko Starter.
4. **Form Edit Produk**: field stok tetap dapat diisi pada paket Starter, karena mereka tidak punya jalur pengganti.

Butir keempat penting. Mengunci field stok tanpa menyediakan fitur pengganti akan membuat pengguna Starter kehilangan kemampuan mengoreksi stok sepenuhnya.

---

## 4. Rancangan Data

### 4.1 Tabel `stock_adjustments`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | |
| `store_id` | FK stores | cascade on delete |
| `adjustment_number` | string(40) | unik per toko, contoh `ADJ/2026/08/0001` |
| `type` | string(16) | `adjustment` atau `opname` |
| `reason_code` | string(24) | lihat 4.3 |
| `status` | string(24) | `draft`, `pending_approval`, `posted`, `cancelled` |
| `notes` | text nullable | |
| `counted_at` | datetime nullable | waktu hitung fisik |
| `created_by` | FK users | |
| `approved_by`, `approved_at` | nullable | |
| `posted_by`, `posted_at` | nullable | |
| `cancelled_by`, `cancelled_at`, `cancellation_reason` | nullable | |
| `total_variance_value` | decimal(18,2) | nilai rupiah selisih, boleh negatif |
| `timestamps` | | |

Indeks: `[store_id, status, created_at]`, `[store_id, type, created_at]`, unik `[store_id, adjustment_number]`.

### 4.2 Tabel `stock_adjustment_lines`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | |
| `stock_adjustment_id` | FK | cascade on delete |
| `product_id` | FK products | |
| `product_variant_id` | FK nullable | |
| `product_batch_id` | FK nullable | |
| `system_quantity` | decimal(18,4) | snapshot stok saat baris dibuat |
| `counted_quantity` | decimal(18,4) | hasil hitung fisik |
| `variance_quantity` | decimal(18,4) | `counted - system`, disimpan |
| `unit_cost` | decimal(18,6) | `average_cost` saat posting |
| `variance_value` | decimal(18,2) | |
| `line_notes` | string nullable | |

Indeks: `[stock_adjustment_id]`, `[product_id]`.

Alasan `system_quantity` disimpan: antara pembukaan sesi dan posting, toko masih bertransaksi. Snapshot memungkinkan sistem menjelaskan asal angka kepada pengguna, misalnya stok sistem saat hitung 50, hasil hitung 48, terjual 3 di sela proses, sehingga stok akhir menjadi 45.

### 4.3 Daftar `reason_code`

| Kode | Label | Arah lazim |
|---|---|---|
| `damage` | Rusak atau pecah | Minus |
| `expired` | Kedaluwarsa | Minus |
| `lost` | Hilang atau selisih | Minus |
| `found` | Ditemukan | Plus |
| `internal_use` | Pemakaian internal | Minus |
| `sample` | Sampel atau hadiah | Minus |
| `correction` | Koreksi salah input | Dua arah |
| `opname` | Hasil stock opname | Dua arah |

---

## 5. Hak Akses

| Aksi | Peran |
|---|---|
| Membuat dan mengisi dokumen | `role.inventory` |
| Menyetujui dan posting | admin, manager, `role.store_admin` |
| Melihat riwayat dan laporan | `role.stock_view` |

Aturan pemisahan wewenang: pembuat dokumen tidak boleh menyetujui dokumennya sendiri. Aturan ini diberlakukan pada Tahap 6 dan dapat dilonggarkan lewat pengaturan toko bila hanya ada satu pengguna.

---

## 6. Tahapan Implementasi

### Tahap 0 — Finalisasi aturan bisnis dan gerbang paket

Mengunci keputusan pada bagian 3, mendaftarkan middleware `plan.advanced`, dan menambahkan konstanta yang diperlukan pada `StockMovement`.

Keluaran: middleware terdaftar di `bootstrap/app.php`, keputusan tercatat di dokumen ini.

Kriteria selesai: middleware menolak toko Starter dan meneruskan toko GrowUp.

### Tahap 1 — Fondasi data

Migrasi dua tabel sesuai bagian 4, model `StockAdjustment` dan `StockAdjustmentLine` beserta relasi, konstanta status dan alasan, serta generator nomor dokumen per toko.

Keluaran: dua migrasi, dua model, satu helper penomoran.

Kriteria selesai: migrasi berjalan bersih, nomor dokumen unik per toko dan tidak bentrok saat pembuatan bersamaan.

### Tahap 2 — Penyesuaian Stok dasar

Cakupan terbatas pada produk biasa tanpa varian dan tanpa batch.

- Controller dengan aksi `index`, `create`, `store`, `show`, `post`, dan `cancel`.
- Posting dalam `DB::transaction()` dengan `lockForUpdate()` terurut `orderBy('id')`.
- Menulis satu `StockMovement` per baris selisih dengan `reference_type` mengarah ke baris dokumen.
- Larangan stok akhir negatif.
- Halaman daftar dan form ringkas.

Keluaran: controller, request validasi, dua halaman Vue, entri route dan menu.

Kriteria selesai: koreksi plus dan minus mengubah stok sesuai selisih, tercatat di riwayat mutasi, dokumen `posted` tidak dapat di-post ulang, dan toko Starter ditolak.

### Tahap 3 — Penguncian jalur lama

Field stok pada form Edit Produk dijadikan hanya-baca bagi toko GrowUp, disertai tautan menuju form penyesuaian. Toko Starter tidak terpengaruh.

Penguncian ditegakkan juga di sisi server, bukan sekadar atribut `readonly` pada input.

Keluaran: perubahan pada `ProductController::update()` dan `Products/Edit.vue`.

Kriteria selesai: perubahan `stock_quantity` dari form edit ditolak bagi toko GrowUp, sementara toko Starter tetap berjalan seperti sebelumnya.

### Tahap 4 — Stock Opname

- Sesi opname dengan filter kategori agar hitung parsial dapat dilakukan.
- Lembar hitung dengan kolom produk, satuan, stok sistem, qty fisik, selisih, dan nilai selisih.
- Mode *blind count*: kolom stok sistem dapat disembunyikan agar petugas tidak sekadar menyalin angka.
- Input melalui pemindaian barcode yang mengarahkan fokus ke baris terkait.
- Simpan draft agar sesi dapat dilanjutkan pada waktu berbeda.
- Ringkasan total item selisih dan nilai rugi atau laba.

Keluaran: perluasan controller, halaman lembar hitung, penyimpanan draft.

Kriteria selesai: sesi 100 baris dapat disimpan sebagai draft, dilanjutkan, lalu diposting dengan selisih yang benar meski terjadi penjualan di sela proses.

### Tahap 5 — Varian dan batch FIFO

- Baris dokumen menyasar `product_variant_id`; induk disinkronkan dari penjumlahan varian.
- Toko dengan `BatchTracking::enabled()` menghitung per batch.
- Selisih minus dipotong FIFO mengikuti pola `TransferStockOperations::deductAtSource()`.
- Selisih plus membentuk batch baru berkode `ADJ <nomor dokumen>`.
- Sinkronisasi `products.stock_quantity` dari penjumlahan batch setelah posting.
- Produk bundel ditolak dengan pesan yang jelas.

Keluaran: kelas `AdjustmentStockOperations` sejajar dengan `TransferStockOperations`.

Kriteria selesai: koreksi pada produk batch menjaga kesamaan antara penjumlahan batch dan stok produk; koreksi varian menjaga kesamaan antara penjumlahan varian dan stok induk.

### Tahap 6 — Approval dan pemisahan wewenang

Status `pending_approval` diaktifkan, ambang nilai yang memerlukan persetujuan dapat diatur, dan pembuat dokumen dilarang menyetujui dokumennya sendiri.

Keluaran: aksi `approve` dan `reject`, pengaturan ambang pada toko.

Kriteria selesai: dokumen di atas ambang tidak dapat diposting sebelum disetujui pengguna lain.

### Tahap 7 — Laporan dan jurnal

- Laporan selisih stok per periode, alasan, dan produk, lengkap dengan nilai rupiah.
- Kartu stok per produk dengan saldo berjalan.
- Metrik akurasi inventori: `(1 - |total selisih| / total stok sistem) x 100%`.
- Jurnal akuntansi: debit beban kerugian persediaan, kredit persediaan, memanfaatkan `ChartOfAccount`, `JournalEntry`, dan `JournalLine` yang sudah ada.

Keluaran: dua halaman laporan dan integrasi jurnal.

Kriteria selesai: laporan dapat direkonsiliasi dengan riwayat mutasi pada periode yang sama.

---

## 7. Daftar Periksa Teknis Saat Posting

Berlaku untuk seluruh tahap yang melakukan mutasi stok.

- [ ] Seluruh proses dibungkus `DB::transaction()`.
- [ ] `lockForUpdate()` diterapkan pada produk dengan urutan `orderBy('id')` untuk mencegah *deadlock*.
- [ ] Status diperiksa agar dokumen `posted` tidak diposting ulang.
- [ ] Satu baris `stock_movements` ditulis untuk setiap baris selisih.
- [ ] `balance_after` diisi dengan stok setelah mutasi.
- [ ] Stok akhir negatif ditolak.
- [ ] `unit_cost` dan `variance_value` dibekukan pada saat posting.
- [ ] Gerbang paket diperiksa di controller, bukan hanya di route.
- [ ] Kuantitas diproses dengan `ProductQuantity` dan bcmath, bukan aritmetika float.

---

## 8. Kriteria Penerimaan Menyeluruh

- Setiap perubahan stok di luar penjualan, pembelian, transfer, dan produksi memiliki dokumen bernomor, beralasan, dan berpengguna.
- Nilai rupiah selisih dapat direkonsiliasi dengan laporan nilai persediaan.
- Data antar toko dan antar organisasi tetap terisolasi.
- Produk batch dan varian tetap sinkron setelah koreksi.
- Toko paket Starter tidak dapat mengakses modul ini melalui route, API, maupun menu.
- Alur retail biasa, POS, dan transfer stok tidak mengalami regresi.

---

## 9. Log Implementasi

Bagian ini diisi setiap kali satu tahap selesai, mencakup berkas yang dibuat atau diubah, jumlah pengujian yang lulus, dan keputusan yang berubah di tengah jalan.

### Tahap 0 — Finalisasi aturan bisnis dan gerbang paket

Status: **Selesai**

Berkas baru: `app/Http/Middleware/EnsureAdvancedPlan.php`.

Berkas diubah: `bootstrap/app.php` (alias `plan.advanced`), `app/Models/StockMovement.php` (konstanta `TYPE_OPNAME` dan `TYPE_ADJUSTMENT_REVERSAL`), `app/Http/Controllers/StockMovementController.php` (label kedua tipe baru).

Keputusan yang dikunci:

1. Modul memakai `StorePlanLimits::allowsAdvancedRetailFeatures()` yang sudah ada, tanpa helper baru. Toko dengan slug `starter` dan `starter-tahunan` ditolak.
2. Middleware mengembalikan redirect ke dashboard dengan pesan ajakan menaikkan paket, bukan `abort(403)`, agar konsisten dengan `EnsureStockViewRole` dan `EnsureStoreIsActive`.
3. Field stok pada form Edit Produk tetap terbuka bagi paket Starter. Penguncian pada Tahap 3 hanya berlaku bagi toko GrowUp yang sudah memiliki jalur pengganti.
4. Pembatalan dokumen koreksi memakai tipe mutasi tersendiri (`adjustment_reversal`), bukan menulis ulang `adjustment` bernilai negatif, agar laporan selisih dapat memisahkan koreksi asli dari pembatalannya.

Verifikasi: `php artisan about` berjalan tanpa galat, menandakan alias middleware terdaftar sah.

### Tahap 1 — Fondasi data

Status: **Selesai** (9 test lulus, 25 assertion)

Berkas baru:

- `database/migrations/2026_08_04_100000_create_stock_adjustments_tables.php`
- `app/Models/StockAdjustment.php`
- `app/Models/StockAdjustmentLine.php`
- `tests/Feature/StockAdjustmentFoundationTest.php`

Rincian yang dibangun:

1. Dua tabel sesuai rancangan bagian 4, termasuk indeks unik `[store_id, adjustment_number]` dan indeks komposit untuk penyaringan daftar.
2. Konstanta tipe, status, dan delapan `reason_code` beserta label bahasa Indonesia.
3. Mesin status `allowedTransitions()` dan `assertCanTransitionTo()` mengikuti pola `StockTransfer`, sehingga dokumen `posted` tidak dapat diposting ulang.
4. Generator nomor `StockAdjustment::nextNumber()` menghasilkan urutan per toko per bulan dengan awalan `ADJ` untuk penyesuaian dan `OPN` untuk opname.
5. Relasi lengkap ke toko, produk, varian, batch, dan empat jejak pengguna (pembuat, penyetuju, pemosting, pembatal).

Catatan teknis: nomor dokumen dibuat pada hook `creating`. Penguncian baris untuk mencegah nomor kembar saat pembuatan bersamaan akan ditambahkan pada Tahap 2, di dalam transaksi posting, karena di tahap ini dokumen belum dibuat lewat controller.

Verifikasi: migrasi berjalan bersih pada basis data pengembangan; nomor berurutan `0001` lalu `0002`, terpisah antar toko, dan tidak tercampur antara `ADJ` dan `OPN`.

### Tahap 2 — Penyesuaian Stok dasar

Status: **Selesai** (22 test kumulatif lulus, 56 assertion)

Berkas baru:

- `app/Support/AdjustmentStockOperations.php`
- `app/Http/Controllers/StockAdjustmentController.php`
- `resources/js/pages/StockAdjustments/Index.vue`
- `resources/js/pages/StockAdjustments/Create.vue`
- `resources/js/pages/StockAdjustments/Show.vue`
- `tests/Feature/StockAdjustmentPostingTest.php`

Berkas diubah: `routes/web.php` (lima route bergerbang `plan.advanced`), `resources/js/components/MainMenu.vue` (menu «Penyesuaian stok» dengan flag `advancedRetailOnly`).

Rincian yang dibangun:

1. `AdjustmentStockOperations` menampung seluruh mutasi stok, sejajar dengan `TransferStockOperations`. Berisi `assertAdjustable()`, `applyLine()`, dan `reverseLine()`.
2. Posting memakai selisih terhadap snapshot, sesuai keputusan 3.2. Stok akhir dihitung `stok_berjalan + selisih`, bukan menimpa dengan hasil hitung.
3. Penguncian `lockForUpdate()` diurutkan `orderBy('id')` untuk mencegah *deadlock*.
4. Stok akhir negatif ditolak; nilai stok tidak berubah saat penolakan terjadi.
5. `average_cost` dibekukan saat posting dan tidak digeser oleh koreksi, sesuai keputusan 3.3.
6. Baris tanpa selisih dilewati tanpa menulis mutasi, agar riwayat stok tidak dipenuhi baris kosong.
7. Pembatalan menulis mutasi pembalik bertipe `adjustment_reversal`, bukan menghapus dokumen.
8. Halaman `Create.vue` menghitung selisih dan nilai rupiah secara langsung di layar sebelum dokumen dikirim, sehingga pengguna melihat dampaknya lebih dulu.

Penegakan paket dilakukan berlapis: middleware `plan.advanced` pada route, `StorePlanLimits::assertAdvancedRetail()` pada aksi `store` dan `cancel`, serta flag `advancedRetailOnly` pada menu.

Verifikasi:

- 13 test posting lulus, mencakup koreksi plus dan minus, penolakan stok negatif, penolakan produk paket/varian/tanpa-track/tak-terbatas, isolasi antar toko, kuantitas desimal, dan pembatalan.
- Skenario kritis «penjualan di sela proses» terbukti benar: stok sistem 50, hasil hitung 48, terjual 3 sebelum posting, stok akhir menjadi 45 — bukan 48.
- `npm run build` sukses; `vue-tsc` tidak melaporkan galat pada berkas baru.
- Suite penuh: 225 lulus, 25 gagal. Seluruh kegagalan bersifat *pre-existing* (CSRF 419 pada test POST di modul Auth, Settings, Loyalty, dan Import) dan tidak menyentuh modul koreksi stok.

Catatan cakupan: Tahap 2 sengaja menolak produk bervarian dan produk batch lewat `assertAdjustable()`. Keduanya dibuka pada Tahap 5.

### Tahap 3 — Penguncian jalur lama

Status: **Selesai** (5 test lulus, 27 assertion)

Berkas baru: `tests/Feature/ProductStockLockTest.php`.

Berkas diubah: `app/Http/Controllers/ProductController.php` (metode `edit` dan `update`), `resources/js/pages/Products/Edit.vue`.

Rincian yang dibangun:

1. Pada `update()`, nilai `stock_quantity` yang dikirim form diabaikan bagi toko GrowUp; stok lama dipertahankan. Penguncian berada di sisi server, sehingga tidak dapat ditembus lewat permintaan langsung.
2. Pada `edit()`, prop `stockLockedToAdjustment` dikirim ke halaman.
3. Input stok dinonaktifkan dan diberi keterangan beserta tautan menuju form penyesuaian, sehingga pengguna tahu ke mana harus pergi.
4. Toko Starter tidak terpengaruh sama sekali; kondisi lama tetap berlaku.

Catatan konsistensi: penguncian ini disusun sebagai kondisi tambahan pada rantai `disabled` yang sudah ada, bersama batch, varian, dan paket. Dengan begitu tidak ada perubahan perilaku pada tiga kondisi sebelumnya.

Verifikasi:

- Dua test HTTP nyata memastikan prop `stockLockedToAdjustment` bernilai `true` bagi toko GrowUp dan `false` bagi toko Starter.
- Tiga test memastikan nilai stok kiriman diabaikan pada GrowUp namun tetap diterima pada Starter, termasuk `starter-tahunan`.
- `npm run build` sukses.

### Tahap 4 — Stock Opname

Status: **Selesai** (11 test lulus, 26 assertion)

Berkas baru:

- `app/Http/Controllers/StockOpnameController.php`
- `resources/js/pages/StockOpnames/Index.vue`
- `resources/js/pages/StockOpnames/Create.vue`
- `resources/js/pages/StockOpnames/Show.vue`
- `tests/Feature/StockOpnameTest.php`

Berkas diubah: `routes/web.php` (tujuh route bergerbang `plan.advanced`), `resources/js/components/MainMenu.vue` (menu «Stock opname»).

Rincian yang dibangun:

1. Sesi opname membekukan stok sistem sebagai snapshot pada setiap baris ketika dibuka, sesuai keputusan 3.2.
2. Hitungan awal tiap baris disamakan dengan stok sistem, sehingga produk yang tidak sempat dihitung tidak menimbulkan selisih palsu saat diposting.
3. Cakupan hitung dapat dipersempit per kategori, sehingga hitung parsial dapat diselesaikan dalam satu waktu. Ini penting agar fitur benar-benar dipakai UMKM.
4. Simpan draft dapat dilakukan berkali-kali; sesi dapat dilanjutkan pada hari berbeda tanpa kehilangan data.
5. Mode *blind count* aktif secara bawaan selama sesi berjalan. Kolom stok sistem, selisih, dan nilai disembunyikan agar petugas tidak menyalin angka catatan.
6. Nilai selisih memakai HPP yang dibekukan saat sesi dibuka, bukan HPP terbaru, agar nilai kerugian tidak berubah karena pembelian yang terjadi di sela proses.
7. Halaman menampilkan metrik akurasi inventori secara langsung sebagai umpan balik bagi pemilik toko.
8. Posting dibatasi bagi admin dan manajer, sedangkan pengisian lembar hitung terbuka bagi `role.inventory`.
9. Pembatalan sesi draft hanya mengubah status, sedangkan pembatalan sesi terposting menulis mutasi pembalik.

Verifikasi:

- Sesi draft terbukti tidak mengubah stok maupun menulis mutasi.
- Draft bertahap terbukti mempertahankan hasil hitung sebelumnya.
- Skenario penjualan di sela proses terbukti benar: sistem 50, hitung 48, terjual 3, hasil akhir 45.
- Posting ganda ditolak, dan stok tidak berubah akibat penolakan.
- Produk yang cocok dengan sistem tidak menghasilkan baris mutasi.
- Tujuh route terdaftar; `npm run build` sukses.

### Tahap 5 — Varian dan batch FIFO

Status: **Selesai** (15 test baru; 72 test kumulatif modul stok lulus, 198 assertion)

Berkas baru: `tests/Feature/StockAdjustmentVariantBatchTest.php`.

Berkas diubah: `app/Support/AdjustmentStockOperations.php`, `app/Http/Controllers/StockAdjustmentController.php`, `app/Http/Controllers/StockOpnameController.php`, `resources/js/pages/StockAdjustments/Create.vue`, `resources/js/pages/StockAdjustments/Show.vue`, `resources/js/pages/StockOpnames/Show.vue`.

Rincian yang dibangun:

1. `AdjustmentStockOperations` dirombak menjadi satu jalur masuk `mutate()` yang memilih salah satu dari tiga sasaran: varian, batch, atau produk biasa. Dengan begitu `applyLine()` dan `reverseLine()` tidak menduplikasi logika.
2. Koreksi varian menulis ke `product_variants`, lalu menyinkronkan stok induk dari penjumlahan seluruh varian. Mutasi mencatat `product_variant_id`.
3. Koreksi batch memotong FIFO berdasarkan kedaluwarsa terdekat, memakai `TransferStockOperations::ensureBatchAligned()` yang sudah ada agar produk berstok tanpa batch tetap tertangani.
4. Selisih plus pada mode batch membentuk batch baru berkode `ADJ <nomor dokumen>`, sehingga asal penambahan dapat ditelusuri.
5. Stok produk selalu disinkronkan dari penjumlahan batch setelah mutasi, menjaga kedua sumber tetap sama.
6. Opname memecah produk bervarian menjadi satu baris per varian saat sesi dibuka.
7. Pemeriksaan duplikat pada penyesuaian memakai pasangan produk dan varian, sehingga dua varian dari produk yang sama tetap boleh berada dalam satu dokumen.
8. Pemilih produk di halaman penyesuaian memecah produk bervarian menjadi opsi per varian, lengkap dengan stok masing-masing.

Verifikasi:

- Varian: koreksi menyasar varian yang benar, induk tersinkron, `product_variant_id` tercatat, stok negatif ditolak, pembatalan mengembalikan nilai semula.
- Varian: penolakan berlaku untuk varian tidak dipilih, varian milik produk lain, dan varian pada produk tanpa varian.
- Batch: FIFO terbukti menghabiskan batch ber-ED terdekat lebih dulu (10 dan 20 dipotong 12 menjadi 0 dan 18).
- Batch: penjumlahan batch selalu sama dengan stok produk setelah koreksi.
- Batch: koreksi melebihi stok ditolak; produk berstok tanpa batch memperoleh batch awal otomatis.
- Toko tanpa mode batch tetap memakai jalur produk biasa tanpa membuat baris batch.
- Produk paket tetap ditolak.
- `vue-tsc` bersih pada seluruh berkas modul; `npm run build` sukses.

### Tahap 6 — Approval dan pemisahan wewenang

Status: **Selesai** (8 test baru; 80 test kumulatif modul stok lulus, 210 assertion)

Berkas baru: `app/Support/StockAdjustmentApproval.php`, `tests/Feature/StockAdjustmentApprovalTest.php`.

Berkas diubah: `app/Http/Controllers/StockAdjustmentController.php`, `app/Http/Controllers/StockOpnameController.php`, `routes/web.php`, `resources/js/pages/StockAdjustments/Show.vue`, `resources/js/pages/StockOpnames/Show.vue`.

Rincian yang dibangun:

1. Pengaturan approval disimpan di `stores.settings['stock_adjustment']`, mengikuti pola `LoyaltySettings` sehingga tidak perlu tabel maupun migrasi baru.
2. Tiga saklar tersedia: `approval_enabled`, `approval_threshold`, dan `segregation_of_duties`.
3. Ambang nol berarti seluruh dokumen wajib disetujui; ambang di atas nol hanya menahan dokumen yang nilai mutlak selisihnya melampaui ambang.
4. Dokumen yang perlu persetujuan berhenti pada status `pending_approval` **tanpa memutasi stok**. Baris dokumen tetap tersimpan lengkap dengan nilai selisihnya, sehingga penyetuju dapat menilai dampaknya sebelum memutuskan.
5. Aksi `approve` menerapkan seluruh selisih lalu mengisi `approved_by`, `approved_at`, `posted_by`, dan `posted_at` dalam satu transaksi.
6. Pemisahan wewenang menolak pembuat dokumen menyetujui dokumennya sendiri, dan hanya admin atau manajer yang boleh menyetujui.
7. Penolakan dokumen `pending_approval` cukup mengubah status karena stok belum berubah; pembatalan dokumen `posted` tetap memakai mutasi pembalik.
8. Halaman detail menampilkan panel peringatan berisi alasan penahanan, dan menyembunyikan tombol setujui bila pemblokiran wewenang berlaku.

Catatan perilaku bawaan: `approval_enabled` bernilai `false`, sehingga toko yang sudah memakai fitur ini pada Tahap 2 sampai 5 tidak berubah perilakunya. Approval bersifat opt-in.

Catatan teknis: logika penerapan baris di opname diekstrak menjadi `applyAllLines()` agar `post()` dan `approve()` tidak menduplikasi kode penguncian dan iterasi.

Verifikasi:

- Ambang nol menahan seluruh dokumen; ambang 500.000 meloloskan 400.000 dan menahan 600.000.
- Pembuat dokumen ditolak saat mencoba menyetujui dokumennya sendiri, dan diizinkan setelah pemisahan wewenang dinonaktifkan.
- Kasir ditolak meski dokumen bukan miliknya.
- Nilai pengaturan tersimpan dan terbaca kembali dengan benar.
- Refaktor tidak menimbulkan regresi: 80 test modul stok lulus.

### Tahap 7 — Laporan dan jurnal

Status: **Selesai** (9 test baru; 89 test kumulatif modul stok lulus, 249 assertion)

Berkas baru: `tests/Feature/StockAdjustmentJournalTest.php`.

Berkas diubah: `app/Support/Accounting/ChartOfAccountsBootstrap.php`, `app/Support/Accounting/JournalPoster.php`, `app/Http/Controllers/StockAdjustmentController.php`, `app/Http/Controllers/StockOpnameController.php`, `resources/js/components/ReportTabs.vue`, `resources/js/pages/Reports/StockVariance.vue`, `resources/js/pages/Reports/StockCard.vue`.

Rincian yang dibangun:

1. Akun `Beban kerugian persediaan` (`6.1.02`) ditambahkan di `ChartOfAccountsBootstrap` untuk menampung nilai kerugian stok pada laporan laba-rugi.
2. `JournalPoster::postStockAdjustment()` menjurnal selisih koreksi stok: selisih minus mendebit beban kerugian dan mengkredit persediaan; selisih plus membalik arahnya. Selisih bersih nol tidak dijurnal.
3. `JournalPoster::reverseStockAdjustment()` menghapus entri jurnal dokumen yang dibatalkan.
4. Jurnal dipanggil dari `StockAdjustmentController` (store, approve, cancel) dan `StockOpnameController` (post, approve, cancel). Panggilan berada di luar transaksi inti agar tidak menggulung transaksi utama.
5. Tab «Selisih stok» dan «Kartu stok» ditambahkan di `ReportTabs.vue` dengan flag `advanced`, sehingga hanya terlihat bagi toko paket GrowUp ke atas. Tab «Shift kasir» juga dikonsolidasi menggunakan flag yang sama.
6. Halaman `StockVariance.vue` dan `StockCard.vue` menyertakan `ReportTabs` dengan `activeTab` yang sesuai.
7. Laporan selisih stok dan kartu stok diakses melalui middleware `plan.advanced` yang sudah terdaftar di Tahap 0.
8. Jurnal koreksi stok seimbang (total debit = total kredit) dan bersifat idempoten (panggilan ulang tidak membuat entri kembar).
9. Laporan laba-rugi dan neraca secara otomatis mengakomodasi akun baru karena menghitung seluruh akun bertipe `TYPE_EXPENSE`.

Verifikasi:
- 9 test lulus: jurnal minus, jurnal plus, jurnal nol, keseimbangan debit/kredit, idempotensi, pembatalan, laporan selisih, kartu stok, penolakan paket starter.
- `npm run build` sukses tanpa galat.
- Refaktor tidak menimbulkan regresi pada test Tahap 7.

### Fitur tambahan — direct_stock_edit

Status: **Selesai** (13 test baru; 102 test kumulatif modul stok lulus, 288 assertion)

Berkas baru: `tests/Feature/DirectStockEditTest.php`.

Berkas diubah: `app/Support/StockAdjustmentApproval.php`, `app/Http/Controllers/ProductController.php`, `app/Http/Controllers/StockAdjustmentController.php`, `app/Http/Controllers/StockOpnameController.php`, `app/Http/Controllers/StoreSettingsController.php`, `app/Http/Controllers/Settings/ProfileController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/components/MainMenu.vue`, `resources/js/pages/settings/Profile.vue`.

Rincian yang dibangun:

1. Key `direct_stock_edit` ditambahkan di `StockAdjustmentApproval::DEFAULTS` dengan nilai default `false`. Method `directStockEdit()` mengembalikan nilai key ini dari settings toko.
2. `ProductController::edit()` menghitung `stockLockedToAdjustment` sebagai kombinasi `allowsAdvancedRetailFeatures` DAN `!direct_stockEdit`. Field stok dikunci hanya jika paket GrowUp DAN direct_stock_edit aktif (false). Paket Starter selalu bisa edit stok langsung.
3. `StockAdjustmentController::create()` dan `StockOpnameController::create()` redirect ke daftar produk jika `direct_stock_edit = true` dengan pesan info.
4. `HandleInertiaRequests` mengirim `direct_stock_edit` sebagai shared prop global, tersedia di seluruh halaman Inertia.
5. `MainMenu.vue` menyembunyikan menu «Penyesuaian stok» dan «Stock opname» jika `direct_stock_edit = true`. Menu «Produk» tetap tampil.
6. `StoreSettingsController::update()` memproses field `direct_stock_edit` dari request — hanya untuk toko paket GrowUp. Nilai disimpan di `stores.settings.stock_adjustment.direct_stock_edit`.
7. `ProfileController::edit()` mengirim `direct_stock_edit` ke halaman Settings/Profile.
8. `Profile.vue` menampilkan section «Koreksi Stok» dengan toggle checkbox (hanya terlihat bagi paket GrowUp via `v-if="$page.props.plan_limits?.allows_advanced_retail"`).

Perilaku:

| `direct_stock_edit` | Field stok Edit Produk | Menu Koreksi Stok |
|---|---|---|
| `false` (default) | Dikunci untuk GrowUp, buka untuk Starter | Tampil & wajib dipakai |
| `true` | Bisa diedit langsung | Disembunyikan; akses langsung di-redirect |

Verifikasi:
- 13 test lulus: default, aktifkan/nonaktifkan, isolasi toko, form edit (growup/starter), redirect controller, akses langsung.
- `npm run build` sukses tanpa galat.
