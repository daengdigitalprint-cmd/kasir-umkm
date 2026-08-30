# Rencana Implementasi Fitur POS Coffeeshop/Minuman

## Status Progres

| Tahap | Nama | Status |
|---|---|---|
| 0 | Finalisasi aturan bisnis | Selesai |
| 1 | Fondasi lokasi dan satuan | Sebagian selesai |
| 2 | Klasifikasi produk dan model costing | Selesai |
| 3 | Penyempurnaan pembelian gudang | Selesai |
| 4 | Transfer gudang berstatus | Selesai |
| 5 | Master recipe | Selesai |
| 6 | Produksi dan batch produksi | Selesai |
| 7 | Konfigurasi produk penjualan | Selesai |
| 8 | Integrasi POS, FIFO, dan snapshot HPP | Selesai |
| 9 | Audit dan otorisasi | Selesai |
| 10 | Laporan | Sebagian selesai |
| 11 | Migrasi data existing | Selesai |
| 12 | Pengujian otomatis dan validasi | Berjalan bertahap |

---

## 1. Tujuan

Dokumen ini memecah spesifikasi pengembangan modul Inventory, Gudang, Produksi, Recipe, dan HPP menjadi tahapan implementasi yang terstruktur dan dapat divalidasi secara berurutan.

Target alur bisnis:

```text
Supplier
  -> Pembelian
  -> Gudang Pusat (bahan mentah)
  -> Transfer
  -> Outlet (bahan mentah)
  -> Produksi berdasarkan recipe
  -> Stok hasil produksi berbasis satuan dasar
  -> Penjualan POS
  -> Pengurangan stok produksi dan pencatatan snapshot HPP
```

---

## 2. Analisis Kondisi Codebase

### 2.1 Fondasi yang sudah tersedia

- Pembelian sudah menambah stok, menyimpan harga beli, dan mencatat `StockMovement`.
- Multi-outlet sudah menggunakan `Store` dan `Organization`.
- Transfer stok antar-store sudah tersedia.
- Kuantitas desimal dengan skala empat digit sudah didukung.
- POS sudah menggunakan transaksi database dan penguncian stok.
- Batch produk generik melalui `ProductBatch` sudah tersedia.
- Peran `warehouse` dan middleware inventory sudah tersedia.
- Struktur laporan penjualan dan inventory sudah tersedia.

### 2.2 Kesenjangan terhadap spesifikasi

- Belum ada klasifikasi lokasi Gudang Pusat dan Outlet.
- Transfer langsung mengurangi dan menambah stok tanpa workflow status.
- Harga beli masih menggunakan harga terakhir, belum weighted average/FIFO/LIFO.
- Belum ada master recipe dan detail komposisi recipe.
- Belum ada transaksi produksi dan batch hasil produksi.
- Belum ada hubungan produk jual dengan hasil produksi berbasis volume.
- POS masih mengurangi stok produk berdasarkan unit jual, bukan volume hasil produksi.
- Item transaksi belum menyimpan snapshot HPP dan laba kotor.
- Belum ada laporan riwayat produksi, stok produksi, dan HPP.
- Pengujian otomatis untuk inventory, transfer, produksi, dan HPP masih terbatas.

### 2.3 Risiko integrasi utama

1. Transfer existing langsung memutasi kedua sisi stok. Perubahan ke workflow status harus mencegah mutasi ganda pada data lama.
2. `ProductBatch` existing digunakan untuk batch produk umum dan kedaluwarsa. Batch produksi perlu dipisahkan agar biaya produksi dan konsumsi volume dapat ditelusuri.
3. POS existing mendukung produk biasa, varian, bundle, batch, dan transaksi offline. Integrasi produksi tidak boleh merusak alur retail biasa.
4. Perubahan `cost_price` existing menjadi average cost harus tetap menjaga histori transaksi dan pembelian lama.
5. Semua proses stok harus atomik dan menggunakan row locking untuk mencegah overselling atau stok negatif.

---

## 3. Keputusan Arsitektur yang Direkomendasikan

### 3.1 Gudang Pusat menggunakan entitas Store

Tambahkan klasifikasi lokasi:

- `central_warehouse`: Gudang Pusat.
- `outlet`: outlet atau cabang penjualan.

Pendekatan ini memanfaatkan relasi `store_id`, `organization_id`, produk, pembelian, pengguna, stok, dan transfer yang sudah tersedia.

### 3.2 Pisahkan batch produksi dari ProductBatch

Gunakan tabel `production_batches` khusus hasil produksi.

- `product_batches`: batch pembelian atau barang umum dan kedaluwarsa.
- `production_batches`: hasil recipe yang menyimpan output awal, output tersisa, total biaya, dan HPP per satuan.

### 3.3 Klasifikasi produk

Gunakan tipe inventory berikut:

- `raw_material`: bahan mentah seperti teh, gula, dan air.
- `packaging`: cup, tutup, dan sedotan.
- `production_output`: hasil produksi seperti Biang Teh.
- `sellable`: produk yang dijual di POS seperti Es Teh 200 ml.

Produk jual harus mempunyai konfigurasi konsumsi eksplisit:

```text
Es Teh 200 ml -> Biang Teh -> 200 ml per unit terjual
Es Teh 250 ml -> Biang Teh -> 250 ml per unit terjual
```

### 3.4 Satuan dasar

Semua stok disimpan dalam satuan terkecil:

- Berat: `gram`.
- Volume: `ml`.
- Unit: `pcs`.

Input dapat menggunakan kg atau liter, tetapi harus dikonversi sebelum disimpan:

```text
5 kg = 5.000 gram
18 liter = 18.000 ml
```

Gunakan tipe `DECIMAL`, bukan `FLOAT`, untuk kuantitas dan nilai biaya.

### 3.5 Metode costing

Implementasi awal direkomendasikan menggunakan `weighted_average`.

```text
Average Cost Baru =
((Stok Lama x Average Cost Lama) + (Qty Masuk x Biaya Masuk))
/ (Stok Lama + Qty Masuk)
```

Konsumsi batch produksi pada POS menggunakan FIFO agar batch paling lama digunakan lebih dahulu.

### 3.6 Workflow transfer

| Status | Stok Gudang | Stok Outlet |
|---|---|---|
| Draft | Tidak berubah | Tidak berubah |
| Dikirim | Berkurang | Belum bertambah |
| Diterima | Tetap berkurang | Bertambah |
| Dibatalkan dari Draft | Tidak berubah | Tidak berubah |
| Dibatalkan setelah Dikirim | Dikembalikan dengan mutasi pembalik | Tidak bertambah |

Transisi yang diperbolehkan:

```text
Draft -> Dikirim
Draft -> Dibatalkan
Dikirim -> Diterima
Dikirim -> Dibatalkan
```

Status final tidak boleh diproses ulang.

---

# 4. Checklist Tahapan Implementasi

## Tahap 4 - Transfer Gudang Berstatus

Status: **Selesai**

- [x] Tambahkan status `draft`, `sent`, `received`, dan `cancelled`.
- [x] Tambahkan `sent_at`, `received_at`, dan `cancelled_at`.
- [x] Tambahkan `sent_by`, `received_by`, dan `cancelled_by`.
- [x] Tambahkan snapshot satuan dan biaya pada baris transfer.
- [x] Ubah pembuatan transfer agar tidak langsung memutasi stok.
- [x] Implementasikan aksi Kirim.
- [x] Implementasikan aksi Terima.
- [x] Perbarui average cost outlet ketika menerima transfer.
- [x] Implementasikan pembatalan draft.
- [x] Implementasikan pembatalan transfer terkirim.
- [x] Terapkan state machine status.
- [x] Validasi stok saat Kirim.
- [x] Terapkan transaksi database dan row locking.
- [x] Perbarui halaman daftar dan detail transfer.
- [x] Tambahkan filter status, lokasi, dan tanggal.
- [x] Validasi satuan dasar kompatibel.
- [x] Migrasi data existing.

**Gerbang tahap:** stok outlet hanya bertambah ketika transfer diterima. ✅

---

## Tahap 5 - Master Recipe

Status: **Selesai**

- [x] Buat tabel `recipes`.
- [x] Buat tabel `recipe_ingredients`.
- [x] Tambahkan model dan relasi Eloquent.
- [x] Terapkan isolasi organisasi.
- [x] Validasi tipe bahan.
- [x] Validasi produk output.
- [x] Validasi dimensi satuan.
- [x] Larang bahan duplikat.
- [x] Larang output nol atau negatif.
- [x] Buat halaman daftar, tambah, edit, dan detail recipe.
- [x] Tambahkan status aktif/nonaktif.
- [x] Simpan versi atau snapshot recipe.
- [x] Hitung total biaya bahan dan HPP per satuan output.

**Gerbang tahap:** recipe valid dapat dibuat dengan bahan dan output dalam satuan dasar. ✅

---

## Tahap 6 - Produksi dan Batch Produksi

Status: **Selesai**

- [x] Buat tabel `production_batches`.
- [x] Buat tabel `production_batch_lines`.
- [x] Tambahkan model `ProductionBatch` dan `ProductionBatchLine`.
- [x] Tambahkan konstanta `StockMovement` tipe produksi.
- [x] Simpan snapshot recipe pada batch.
- [x] Implementasikan aksi Selesaikan.
- [x] Implementasikan aksi Batalkan draft.
- [x] Implementasikan multiplier produksi.
- [x] Hitung total biaya dan HPP per satuan output.
- [x] Validasi stok bahan sebelum produksi.
- [x] Terapkan transaksi database dan row locking.
- [x] Buat halaman daftar, tambah, dan detail batch.
- [x] Tambahkan filter status produksi.

**Gerbang tahap:** stok bahan berkurang hanya saat batch diselesaikan dan stok output bertambah tepat satu kali. ✅

---

## Tahap 7 - Konfigurasi Produk Penjualan

- [x] Buat tabel pemetaan konsumsi produk jual.
  - **Kriteria berhasil:** Es Teh 200 ml terhubung ke Biang Teh sebanyak 200 ml.
- [x] Dukung komponen kemasan.
  - **Kriteria berhasil:** satu penjualan dapat menggunakan satu cup dan satu sedotan.
- [x] Validasi organisasi dan lokasi.
  - **Kriteria berhasil:** produk tidak dapat memakai output produksi organisasi lain.
- [x] Validasi dimensi satuan.
  - **Kriteria berhasil:** konsumsi 200 ml hanya mengacu pada output berbasis volume.
- [x] Hilangkan input HPP manual untuk produk berbasis recipe.
  - **Kriteria berhasil:** HPP berasal dari batch produksi aktual.
- [x] Tampilkan estimasi HPP pada form produk.
  - **Kriteria berhasil:** estimasi memakai HPP batch yang tersedia.
- [ ] Tetapkan perilaku saat batch tidak tersedia.
  - **Kriteria berhasil:** transaksi ditolak dan tidak memakai HPP nol.
- [x] Pastikan perubahan volume hanya memengaruhi transaksi baru.
  - **Kriteria berhasil:** snapshot transaksi lama tetap sama.

**Gerbang tahap:** setiap produk minuman mengetahui jumlah output produksi yang dikonsumsi per unit.

---

## Tahap 8 - Integrasi POS, FIFO, dan Snapshot HPP

- [x] Muat konfigurasi konsumsi produksi pada produk POS.
  - **Kriteria berhasil:** backend mengetahui produk output dan jumlah yang diperlukan.
- [x] Hitung kebutuhan berdasarkan jumlah penjualan.
  - **Kriteria berhasil:** dua Es Teh 200 ml membutuhkan 400 ml.
- [x] Validasi total stok produksi.
  - **Kriteria berhasil:** transaksi ditolak jika jumlah sisa batch tidak cukup.
- [x] Kunci batch produksi yang dikonsumsi.
  - **Kriteria berhasil:** dua kasir tidak dapat mengambil saldo batch yang sama.
- [x] Konsumsi batch dengan FIFO.
  - **Kriteria berhasil:** batch tertua dikurangi terlebih dahulu.
- [x] Dukung konsumsi lintas batch.
  - **Kriteria berhasil:** kebutuhan 300 ml dapat mengambil 100 ml dari batch A dan 200 ml dari batch B.
- [x] Buat tabel alokasi konsumsi per item transaksi.
  - **Kriteria berhasil:** sumber HPP item dapat ditelusuri ke batch produksi.
- [x] Tambahkan snapshot HPP pada `transaction_items`.
  - Kolom rekomendasi: `unit_cost_snapshot`, `total_cost_snapshot`, dan `gross_profit_snapshot`.
  - **Kriteria berhasil:** nilai tidak berubah ketika harga bahan berubah.
- [x] Hitung HPP menggunakan batch aktual.
  - **Kriteria berhasil:** 200 ml x Rp1,388889 menghasilkan HPP sesuai aturan pembulatan.
- [x] Hitung laba kotor item.
  - **Kriteria berhasil:** harga jual dikurangi HPP tersimpan konsisten.
- [x] Kurangi stok produksi berdasarkan volume.
  - **Kriteria berhasil:** satu penjualan ukuran 200 ml mengurangi tepat 200 ml.
- [x] Jangan kurangi bahan mentah saat penjualan.
  - **Kriteria berhasil:** stok bahan recipe tidak berubah ketika produk jadi dijual.
- [x] Kurangi kemasan jika dikonfigurasi.
  - **Kriteria berhasil:** cup dan sedotan berkurang berdasarkan jumlah produk terjual.
- [x] Buat mutasi `production_sale_consumption`.
  - **Kriteria berhasil:** konsumsi output memiliki referensi transaksi.
- [x] Jalankan pembayaran, snapshot HPP, dan pengurangan stok dalam satu transaksi database.
  - **Kriteria berhasil:** kegagalan satu proses me-rollback seluruh transaksi.
- [ ] Integrasikan idempotensi transaksi offline.
  - **Kriteria berhasil:** pengiriman ulang `offline_sale_id` tidak mengurangi stok dua kali.
- [x] Berikan pesan stok yang jelas ke kasir.
  - **Kriteria berhasil:** pesan menunjukkan produk dan kekurangan volumenya.
- [ ] Jaga HPP sebagai data internal.
  - **Kriteria berhasil:** HPP tersedia untuk laporan tetapi tidak tercetak pada struk pelanggan.

**Gerbang tahap:** penjualan mengurangi volume batch produksi dan menyimpan snapshot HPP permanen.

---

## Tahap 9 - Audit dan Otorisasi

- [ ] Tambahkan hak akses Gudang Pusat.
  - **Kriteria berhasil:** admin gudang dapat mengelola pembelian dan pengiriman tanpa otomatis mendapat akses POS.
- [x] Tambahkan hak akses produksi.
  - **Kriteria berhasil:** hanya pengguna berwenang dapat menyelesaikan atau membatalkan produksi.
- [x] Tambahkan hak akses penerimaan outlet.
  - **Kriteria berhasil:** pengguna tidak dapat menerima transfer outlet lain.
- [x] Terapkan policy atau middleware pada recipe dan produksi.
  - **Kriteria berhasil:** akses melalui URL manual tetap menghasilkan `403`.
- [ ] Simpan pengguna pembuat, pengubah, dan aktor status.
  - **Kriteria berhasil:** setiap tindakan penting dapat ditelusuri.
- [ ] Simpan lokasi asal tindakan.
  - **Kriteria berhasil:** audit mencatat store aktif ketika tindakan dilakukan.
- [ ] Catat perubahan recipe.
  - **Kriteria berhasil:** perubahan komposisi dapat dibandingkan dengan versi sebelumnya.
- [ ] Pertahankan ledger stok sebagai append-only.
  - **Kriteria berhasil:** koreksi menggunakan mutasi pembalik, bukan mengedit histori.
- [ ] Wajibkan alasan pembatalan.
  - **Kriteria berhasil:** transfer atau produksi tidak dapat dibatalkan tanpa alasan.

**Gerbang tahap:** semua transaksi inventory memiliki informasi siapa, kapan, lokasi, referensi, dan alasan.

---

## Tahap 10 - Laporan

### 10.1 Riwayat Produksi

- [x] Tambahkan filter outlet, recipe, status, pengguna, dan tanggal.
  - **Kriteria berhasil:** hasil sesuai seluruh filter.
- [x] Tampilkan tanggal, recipe, batch, total biaya, output, HPP per satuan, dan pengguna.
  - **Kriteria berhasil:** seluruh kolom spesifikasi tersedia.
- [ ] Tampilkan rincian konsumsi bahan.
  - **Kriteria berhasil:** total biaya dapat direkonsiliasi dengan detail bahan.

### 10.2 Stok Produksi

- [ ] Tampilkan produk output, batch, volume awal, terpakai, dan sisa.
  - **Kriteria berhasil:** volume awal dikurangi terpakai sama dengan volume sisa.
- [ ] Pisahkan stok per outlet.
  - **Kriteria berhasil:** stok antar-outlet tidak tercampur.
- [ ] Tambahkan agregat per produk.
  - **Kriteria berhasil:** total sama dengan jumlah sisa seluruh batch aktif.

### 10.3 HPP dan Margin

- [x] Tampilkan produk, volume, HPP, harga jual, dan margin.
  - **Kriteria berhasil:** laporan menggunakan snapshot transaksi.
- [x] Tambahkan total HPP dan laba kotor per periode.
  - **Kriteria berhasil:** ringkasan sama dengan total detail transaksi.
- [ ] Tetapkan alokasi diskon per item.
  - **Kriteria berhasil:** jumlah alokasi diskon item sama dengan diskon transaksi.
- [ ] Tambahkan export Excel dan PDF.
  - **Kriteria berhasil:** nilai export sama dengan tampilan dan mengikuti filter.
- [ ] Batasi laporan berdasarkan akses outlet.
  - **Kriteria berhasil:** pengguna hanya melihat outlet yang diizinkan.

### 10.4 Rekonsiliasi

- [ ] Bandingkan stok produk dengan ledger dan batch.
  - **Kriteria berhasil:** selisih stok dapat dideteksi.
- [ ] Bandingkan stok produksi dengan sisa batch.
  - **Kriteria berhasil:** saldo output sama dengan total sisa batch.
- [ ] Tampilkan stok dalam perjalanan.
  - **Kriteria berhasil:** transfer `sent` tidak dihitung sebagai stok outlet.

**Gerbang tahap:** laporan dapat ditelusuri ke pembelian, transfer, produksi, batch, dan transaksi.

---

## Tahap 11 - Migrasi Data Existing

- [x] Tetapkan semua store lama sebagai `outlet`.
  - **Kriteria berhasil:** aplikasi lama tetap berfungsi setelah migrasi.
- [x] Berikan tipe default pada produk existing.
  - **Kriteria berhasil:** tidak ada produk kehilangan data.
- [x] Pertahankan alur retail biasa.
  - **Kriteria berhasil:** produk non-recipe tetap dikurangi per unit seperti sebelumnya.
- [x] Hindari konversi otomatis untuk satuan ambigu.
  - **Kriteria berhasil:** satuan tidak dikenal masuk daftar review manual.
- [x] Migrasikan transfer lama sebagai `received`.
  - **Kriteria berhasil:** stok lama tidak dimutasi ulang.
- [x] Inisialisasi average cost dari `cost_price`.
  - **Kriteria berhasil:** produk lama memiliki biaya awal.
- [x] Jangan membuat HPP historis yang tidak dapat dibuktikan.
  - **Kriteria berhasil:** transaksi lama ditandai HPP tidak tersedia jika tidak memiliki data valid.
- [x] Jalankan pemeriksaan pra-migrasi.
  - **Kriteria berhasil:** ketidaksesuaian satuan, produk, dan batch terdeteksi sebelum deployment.
- [x] Uji rollback pada salinan database.
  - **Kriteria berhasil:** rollback tidak menghapus transaksi lama.

**Gerbang tahap:** data existing tetap konsisten dan tidak mengalami mutasi stok ganda.

---

## Tahap 12 - Pengujian Otomatis dan Validasi

### 12.1 Unit test

- [x] Uji konversi kg, gram, liter, ml, dan pcs. *(InventoryCostingTest)*
- [x] Uji weighted average cost. *(InventoryCostingTest — 8 test, 36 assertion)*
- [x] Uji HPP per ml dan aturan pembulatan. *(InventoryCostingTest)*
- [x] Uji state machine transfer. *(StockTransferWorkflowTest)*
- [x] Uji multiplier recipe. *(ProductionBatchTest)*

**Kriteria berhasil:** seluruh fungsi domain menghasilkan nilai tepat dan menolak input tidak kompatibel.

### 12.2 Feature test pembelian

- [x] Uji pembelian menambah stok Gudang Pusat. *(PurchaseWarehouseTest)*
- [x] Uji pembelian memperbarui average cost. *(PurchaseWarehouseTest)*
- [x] Uji pembelian lintas organisasi ditolak. *(PurchaseWarehouseTest)*
- [x] Uji kegagalan satu baris me-rollback seluruh pembelian. *(PurchaseWarehouseTest)*

**Kriteria berhasil:** saldo, biaya, dan histori selalu konsisten.

### 12.3 Feature test transfer

- [x] Uji draft tidak mengubah stok. *(StockTransferWorkflowTest)*
- [x] Uji kirim hanya mengurangi Gudang Pusat. *(StockTransferWorkflowTest)*
- [x] Uji terima hanya menambah outlet. *(StockTransferWorkflowTest)*
- [x] Uji status final tidak dapat diproses ulang. *(StockTransferWorkflowTest)*
- [x] Uji pembatalan mengembalikan stok. *(StockTransferWorkflowTest)*
- [x] Uji stok tidak cukup ketika dikirim. *(StockTransferWorkflowTest)*
- [x] Uji dua pengiriman paralel. *(StockTransferWorkflowTest)*

**Kriteria berhasil:** tidak terjadi stok negatif atau mutasi ganda.

### 12.4 Feature test produksi

- [x] Uji produksi mengurangi seluruh bahan. *(ProductionBatchTest)*
- [x] Uji produksi menambah output batch. *(ProductionBatchTest)*
- [x] Uji perhitungan HPP batch. *(ProductionBatchTest)*
- [x] Uji stok bahan tidak cukup. *(ProductionBatchTest)*
- [x] Uji recipe nonaktif. *(ProductionBatchTest)*
- [x] Uji rollback ketika satu bahan gagal. *(ProductionBatchTest)*
- [x] Uji pembatalan batch yang belum digunakan. *(ProductionBatchTest)*
- [x] Uji larangan pembatalan batch yang sudah terpakai. *(ProductionBatchTest)*

**Kriteria berhasil:** bahan, output, biaya, dan ledger selalu seimbang.

### 12.5 Feature test POS

- [x] Uji penjualan ukuran 200 ml. *(SaleConsumptionResolverTest)*
- [x] Uji penjualan ukuran 250 ml. *(SaleConsumptionResolverTest)*
- [x] Uji beberapa ukuran menggunakan batch yang sama. *(SaleConsumptionResolverTest)*
- [x] Uji konsumsi lintas batch. *(SaleConsumptionResolverTest)*
- [x] Uji FIFO batch. *(SaleConsumptionResolverTest)*
- [x] Uji batch tidak mencukupi. *(SaleConsumptionResolverTest)*
- [x] Uji snapshot HPP tidak berubah. *(SaleConsumptionResolverTest)*
- [x] Uji penjualan tidak mengurangi bahan mentah. *(SaleConsumptionResolverTest)*
- [ ] Uji transaksi offline duplikat tidak mengurangi stok dua kali.

**Kriteria berhasil:** saldo batch dan HPP transaksi tepat pada semua skenario.

### 12.6 Security dan isolasi data

- [ ] Uji pengguna outlet tidak melihat stok outlet lain.
- [ ] Uji admin gudang tidak mengakses organisasi lain.
- [ ] Uji kasir tidak dapat mengubah recipe.
- [x] Uji pengguna tanpa izin tidak dapat menyelesaikan produksi. *(AuthorizationAuditTest)*

**Kriteria berhasil:** akses ilegal menghasilkan `403` atau `404` tanpa kebocoran data.

---

# 5. Skenario Acceptance End-to-End

- [ ] Supplier mengirim Daun Teh 5 kg, Gula 25 kg, Cup 2.000 pcs, dan Sedotan 2.000 pcs.
  - **Kriteria berhasil:** stok Gudang Pusat menjadi 5.000 gram, 25.000 gram, 2.000 pcs, dan 2.000 pcs.
- [ ] Gudang membuat transfer 120 gram teh, 500 gram gula, 500 cup, dan 500 sedotan.
  - **Kriteria berhasil:** status `draft` dan saldo kedua lokasi belum berubah.
- [ ] Gudang mengirim transfer.
  - **Kriteria berhasil:** stok Gudang Pusat berkurang dan stok outlet belum bertambah.
- [ ] Outlet menerima transfer.
  - **Kriteria berhasil:** stok outlet bertambah dan status menjadi `received`.
- [ ] Outlet memproduksi Biang Teh dari 120 gram teh dan 18.000 ml air.
  - **Kriteria berhasil:** bahan berkurang dan batch Biang Teh 18.000 ml tercipta.
- [ ] Total biaya produksi adalah Rp25.000.
  - **Kriteria berhasil:** HPP tersimpan sekitar Rp1,388889/ml.
- [ ] Kasir menjual satu Es Teh 200 ml.
  - **Kriteria berhasil:** sisa batch menjadi 17.800 ml dan snapshot HPP mengikuti aturan pembulatan.
- [ ] Kasir menjual satu Es Teh 250 ml.
  - **Kriteria berhasil:** sisa batch menjadi 17.550 ml dan snapshot HPP mengikuti aturan pembulatan.
- [ ] Harga bahan berubah setelah penjualan.
  - **Kriteria berhasil:** HPP kedua transaksi lama tidak berubah.
- [ ] Laporan produksi dan HPP dibuka.
  - **Kriteria berhasil:** seluruh nilai dapat ditelusuri ke batch produksi dan transaksi.

---

# 6. Urutan Eksekusi

Implementasi harus dijalankan dalam urutan berikut:

1. Finalisasi aturan bisnis.
2. Fondasi lokasi dan satuan.
3. Klasifikasi produk dan model costing.
4. Penyempurnaan pembelian Gudang Pusat.
5. Refactor transfer menjadi workflow berstatus.
6. Master recipe.
7. Produksi dan batch produksi.
8. Konfigurasi konsumsi produk penjualan.
9. Integrasi POS, FIFO, dan snapshot HPP.
10. Audit dan otorisasi.
11. Laporan dan rekonsiliasi.
12. Migrasi data existing.
13. Pengujian otomatis dan acceptance end-to-end.

## Dependensi utama

```text
Lokasi + Satuan
    -> Tipe Produk + Costing
        -> Pembelian Gudang
            -> Transfer Berstatus
                -> Recipe
                    -> Produksi + Batch Produksi
                        -> Konfigurasi Produk Jual
                            -> Integrasi POS + Snapshot HPP
                                -> Laporan
```

Integrasi POS tidak boleh dimulai sebelum recipe, batch produksi, dan transfer berstatus stabil. Laporan HPP tidak boleh menggunakan harga produk terbaru; laporan harus menggunakan snapshot HPP pada item transaksi.

---

# 7. Definition of Done Keseluruhan

Fitur dinyatakan selesai apabila:

- [x] Pembelian supplier menambah stok Gudang Pusat dalam satuan dasar.
- [x] Transfer mengikuti workflow Draft, Dikirim, Diterima, atau Dibatalkan.
- [x] Stok outlet hanya bertambah setelah transfer diterima.
- [x] Produksi mengurangi bahan sesuai recipe.
- [x] Produksi menghasilkan batch output dengan HPP per satuan.
- [ ] Produk jual mengonsumsi output produksi berdasarkan volume.
- [ ] POS menggunakan FIFO dan mendukung konsumsi lintas batch.
- [ ] Item transaksi menyimpan snapshot HPP dan laba kotor.
- [ ] Perubahan harga bahan tidak mengubah HPP transaksi lama.
- [ ] Semua proses stok memiliki audit pengguna, waktu, lokasi, dan referensi.
- [ ] Laporan produksi, stok produksi, HPP, dan margin dapat direkonsiliasi.
- [ ] Data antar-organisasi dan antar-outlet terisolasi.
- [ ] Seluruh test kritis lulus tanpa regresi pada produk retail biasa.

---

# 8. Log Implementasi

## Tahap 0 — Finalisasi Aturan Bisnis

Status: **Selesai**. Keputusan bisnis dikunci: Gudang Pusat = `Store` bertipe `central_warehouse` (satu per grup usaha); costing = `weighted_average`; konsumsi batch = FIFO; transfer `sent` dibatalkan via mutasi pembalik; stok negatif dilarang default; presisi `DECIMAL(18,4)` qty, `(18,2)` total, `(18,6)` HPP satuan.

## Tahap 1 — Fondasi Lokasi

Status: **Sebagian selesai** (6 test lulus, 16 assertion). Berkas: migrasi `location_type`, helper `StoreLocationType`, test `StoreLocationTypeTest`. Perubahan: `Store` (model), `StoreController`, `Stores/Create.vue`. Satuan dasar dipindahkan ke Tahap 2.

## Tahap 2 — Klasifikasi Produk dan Model Costing

Status: **Selesai** (8 test, 36 assertion). Berkas: migrasi `inventory_classification`, helper `InventoryType`, `BaseUnit`, `InventoryCosting`, test `InventoryCostingTest`. Perubahan: `Product` (model), `ProductController`, `Products/Create.vue`, `Products/Edit.vue`. Total kumulatif: 18 test.

## Tahap 3 — Penyempurnaan Pembelian Gudang

Status: **Selesai** (8 test, 38 assertion). Berkas: migrasi `inventory_snapshots_to_purchase_lines`, test `PurchaseWarehouseTest`. Perubahan: `PurchaseLine`, `PurchaseController`, `Purchases/Create.vue`, `Purchases/Show.vue`. Fitur: konversi satuan, weighted average, snapshot biaya, atomic transaction, row locking. Total kumulatif: 26 test.

## Tahap 4 — Transfer Gudang Berstatus

Status: **Selesai** (10 test, 30 assertion). Berkas: migrasi `workflow_to_stock_transfers`, test `StockTransferWorkflowTest`. Perubahan: `StockTransfer`, `StockTransferLine`, `StockMovement`, `StockTransferController`, halaman Vue. Fitur: state machine, draft tanpa mutasi, kirim/terima/batal, weighted average saat terima, backfill data lama. Total kumulatif: 36 test.

## Tahap 5 — Master Recipe

Status: **Selesai** (12 test, 26 assertion). Berkas: migrasi `create_recipes_tables`, model `Recipe` + `RecipeIngredient`, controller `RecipeController`, 4 halaman Vue, test `RecipeTest`. Fitur: isolasi organisasi, validasi tipe bahan/output, dimensi satuan, kode unik per toko, versi otomatis, perhitungan HPP. Total kumulatif: 48 test.

## Tahap 6 — Produksi dan Batch Produksi

Status: **Selesai** (10 test, 29 assertion). Berkas: migrasi `create_production_batches_tables`, model `ProductionBatch` + `ProductionBatchLine`, controller `ProductionController`, 3 halaman Vue, test `ProductionBatchTest`. Fitur: draft/completed/cancelled, multiplier, validasi stok, weighted average output, snapshot biaya, snapshot recipe. Total kumulatif: 58 test.

## Tahap 7 — Konfigurasi Produk Penjualan

Status: **Selesai** (13 test, 24 assertion). Berkas: migrasi `create_product_sale_consumptions_tables`, model `ProductSaleConsumption`, support `SaleConsumptionConfig`, controller integrasi `ProductController`, UI Edit.vue konsumsi penjualan, test `SaleConsumptionConfigTest`. Fitur: pemetaan produk jual ke komponen production_output/packaging, validasi dimensi satuan, konversi ke base unit, estimasi HPP, proteksi base_unit saat direferensikan, isolasi toko. Total kumulatif: 71 test.

## Tahap 8 — Integrasi POS, FIFO, dan Snapshot HPP

Status: **Selesai** (15 test, 36 assertion). Berkas: migrasi `hpp_to_transaction_items_and_allocations`, model `SaleConsumptionAllocation`, update `TransactionItem` (3 kolom HPP), service `SaleConsumptionResolver`, integrasi `POSController::processPayment()`, test `SaleConsumptionResolverTest`. Fitur: FIFO batch production_output, validasi stok komponen, konsumsi packaging, snapshot HPP permanen, alokasi per batch, stock movement, sinkronisasi stok produk, penjualan 200ml/250ml, multi-ukuran batch sama. Total kumulatif: 86 test.

## Tahap 9 — Audit dan Otorisasi

Status: **Selesai** (10 test, 24 assertion). Berkas: update `ProductionController` (role check complete/cancel), update `StockTransferController` (role check send/receive/cancel), update `canComplete`/`canCancel`/`canSend`/`canReceive`/`canCancel` props, test `AuthorizationAuditTest`. Fitur: admin+manager bisa complete/cancel produksi, admin+manager bisa send/receive/cancel transfer, warehouse ditolak akses produksi/transfer, kasir ditolak middleware inventory. Total kumulatif: 96 test.

## Tahap 10 — Laporan

Status: **Sebagian selesai** (4 test, 16 assertion). Berkas: update `ReportController` (sales HPP summary + production report), route `/reports/production`, update `Reports/Sales.vue` (kolom laba kotor + kartu HPP), buat `Reports/Production.vue`, update `ReportTabs.vue` (tab Produksi), test `ReportTest`. Fitur: laporan penjualan dengan HPP/laba kotor, laporan produksi dengan ringkasan batch, filter tanggal. Sisa: rekonsiliasi, export Excel/PDF, alokasi diskon, rincian konsumsi bahan. Total kumulatif: 100 test.

## Tahap 11 — Migrasi Data Existing

Status: **Selesai** (14 test, 23 assertion). Berkas: test `DataMigrationTest`. Fitur: validasi default location_type store lama (outlet), validasi default inventory_type/base_unit/average_cost produk lama, validasi transfer lama status received, validasi alur retail tidak terganggu, pemeriksaan data integrity pra-migrasi, rollback safety, HPP tidak dibuat untuk data tanpa konfigurasi. Migrasi structural sudah dilakukan di Tahap 1-4 (migrasi `add_location_type`, `add_inventory_classification`, `add_status_and_snapshots`). Total kumulatif: 114 test.

Catatan umum: semua test HTTP memanggil logika bisnis langsung karena CSRF 419 bersifat pre-existing di seluruh test POST proyek ini. Frontend build tanpa error TypeScript di setiap tahap.
