# Rencana Pengembangan UMKM Kasir

Dokumen ini merencanakan pengembangan produk agar aplikasi dapat melayani **lebih banyak kategori usaha** (toko kelontong, kue/bakery, apotek, F&B, retail umum) tanpa kehilangan kesederhanaan untuk UMKM.

**Versi:** 1.1  
**Terakhir diperbarui:** April 2026  
**Fase aktif:** **B — Produk & stok (retail & kelontong)**

---

## 0. Status implementasi (singkat)

| Fase | Status | Catatan |
|------|--------|---------|
| **A** | **Mayoritas selesai** | Siap menjadi fondasi Fase B. |
| **B** | **Dimulai / direncanakan detail** | Lihat §3.1 urutan kerja. |
| C–F | Belum | Tetap mengikuti dependensi §6. |

**Fase A — checklist:**

| No | Item | Status |
|----|------|--------|
| A1 | Profil jenis usaha (toko + opsional user), kolom `business_type` | Selesai |
| A2 | Preset kategori & satuan dari `BusinessTypePresets` (produk, buat toko) | Selesai |
| A3 | Metode bayar POS selaras enum DB (`cash`, `card`, `ewallet`, `transfer`, `other`) | Selesai |
| A4 | Diskon & kode promo di checkout POS | Selesai |
| A5 | Struk termal (58/80 mm), pengaturan teks/logo unggah, perbaikan cetak multi-halaman | Selesai |

**Perbaruan dokumen v1.1:** §1 diselaraskan dengan kode; **§3.1** memecah Fase B menjadi sprint; typo **varian produk** diperbaiki.

---

## 1. Ringkasan kondisi saat ini

| Modul | Status singkat |
|-------|----------------|
| Multi-toko per pengguna | Ada |
| Produk (SKU, barcode, stok bilangan bulat, kategori teks, satuan, pajak per item, gambar) | Ada |
| **Jenis usaha toko & preset katalog** | Ada |
| POS & transaksi (multi metode bayar, diskon) | Ada |
| Pelanggan & agregat pembelian | Ada |
| Pajak toko (PPN) | Ada |
| Diskon & voucher; integrasi ke POS | Ada |
| Cetak struk termal & pengaturan header/logo | Ada |
| Langganan (SaaS) & Super Admin | Ada |
| Laporan | Ada |
| **Varian produk / stok desimal / jejak stok / pembelian** | **Belum (Fase B)** |

**Kesenjangan utama vs kebutuhan vertikal:** variasi data produk (varian, kuantitas desimal), bundel, `buy_x_get_y` di POS, **audit pergerakan stok**, **pembelian masuk**, batch/ED (Fase C), modifier F&B (Fase D).

---

## 2. Prinsip pengembangan

1. **Satu codebase, konfigurasi per tipe usaha** — aktifkan field/perilaku sesuai profil toko, hindari fork aplikasi per industri.
2. **Progresif** — fitur lanjutan (BOM apotek, multi-gabus) sebagai modul atau tahap berikutnya.
3. **Backward compatible** — toko yang sudah jalan tetap aman setelah migrasi (default perilaku sama seperti sekarang).
4. **Audit & kejelasan** — perubahan stok dan transaksi dapat dilacak untuk kepercayaan pengguna dan komplain.

---

## 3. Peta jalan (fase)

### Fase A — Fondasi & POS lebih lengkap (prioritas tinggi)

| No | Item | Tujuan |
|----|------|--------|
| A1 | **Profil jenis usaha** pada toko (retail / F&B / bakery / apotek / umum) | Menyalakan preset UI, satuan, dan field opsional |
| A2 | **Preset** kategori & satuan berdasarkan jenis usaha | Onboarding lebih cepat |
| A3 | **Metode pembayaran penuh di POS** selaras `transactions.payment_method` (tunai, kartu, e-wallet, transfer, dll.) | Mengurangi gesekan operasional nyata |
| A4 | **Integrasi diskon & voucher** ke alur checkout POS | Memanfaatkan tabel yang sudah ada |
| A5 | **Cetak struk / invoice** (template termal, pengaturan toko) | Standard retail & F&B |

**Kriteria selesai:** toko baru bisa memilih tipe usaha; POS mendukung promo dan metode bayar utama; bukti transaksi bisa dicetak — **terpenuhi untuk rilis fondasi.**

---

### Fase B — Produk & stok untuk retail & kelontong

| No | Item | Tujuan |
|----|------|--------|
| B1 | **Varian produk** (mis. ukuran/kemasan, SKU & barcode per varian) | SKU kompleks seperti kelontong |
| B2 | **Kuantitas desimal** untuk stok & penjualan (kg, liter) + aturan pembulatan | Jualan bahan basah / curah |
| B3 | **Bundel / paket** terhubung ke POS (`buy_x_get_y` / komposisi paket) | Promo isi banyak |
| B4 | **Riwayat pergerakan stok** (jual, penyesuaian, rusak, retur) | Audit & minim selisih |
| B5 | **Pembelian sederhana** (supplier opsional, baris pembelian, menambah stok) | Stok tidak hanya edit manual |

**Kriteria selesai:** satu produk bisa multi-varian; penjualan 0,25 kg valid; jejak stok dapat diakses per produk.

#### 3.1 Urutan eksekusi yang disarankan (Fase B)

Urutan ini meminimalkan rework: dulu **jejak stok** dan **desimal**, lalu **pembelian** (sumber mutasi), baru **varian** (mengubah model produk), terakhir **bundel** (paling bergantung pada harga & komposisi).

| Tahap | Fokus | Entitas / teknis utama | Acceptance singkat |
|-------|--------|------------------------|----------------------|
| **B4-a** | Log mutasi stok | Tabel `stock_movements` (store_id, product_id, qty_delta, type, reference_type/id, user_id, meta) | Setiap checkout POS menulis baris `sale`; stok tetap konsisten dengan jumlah baris |
| **B4-b** | UI riwayat | Halaman atau panel di detail produk + filter tanggal | Kasir/owner bisa menjelaskan “kenapa stok berubah” |
| **B2** | Desimal | Migrasi `stock_quantity` (+ kolom step/qty di `transaction_items` jika perlu); flag `allows_decimal` per produk atau infer dari `unit` | Input POS & edit stok menerima 0,25; pembulatan terdokumentasi |
| **B5** | Pembelian | `suppliers` (opsional), `purchase_orders` / `purchases`, `purchase_lines`; mutasi `purchase` | Satu alur “Beli barang → stok naik” + satu baris di riwayat |
| **B1** | Varian | `product_variants` atau produk induk + anak; POS memilih varian | Barcode unik per varian; stok per varian |
| **B3** | Bundel | Tabel komposisi paket; aturan promo `buy_x_get_y` di POS | Satu SKU paket mengurangi komponen (atau menjual paket sebagai satu baris dengan harga final) — pilih satu strategi di tiket |

**Risiko & mitigasi**

- **B2 + B4:** gunakan tipe numerik `decimal(18,4)` (atau setara) untuk qty; tetap izinkan bilangan bulat untuk PCS.
- **B1:** setelah varian ada, laporan & POS filter harus memakai `variant_id` secara konsisten; migrasi data lama = satu varian “default” per produk.
- **B3:** bedakan “paket harga khusus” vs “wajib pecah ke komponen” di dokumentasi produk agar tidak mengorbankan performa POS.

---

### Fase C — Kedaluwarsa, batch, & usaha pangan (kue/roti)

| No | Item | Tujuan |
|----|------|--------|
| C1 | **Batch / lot** + tanggal kedaluwarsa (opsional per toko) | Pangan & retail bertanggal |
| C2 | **Keluar stok FEFO** (first-expire-first-out) saat batch dipakai | Mengurangi buang stok |
| C3 | **Peringatan** stok mendekati/melewati ED | Proaktif |
| C4 | **Pre-order / DP** (opsional) | Kue custom & pesanan terjadwal |

**Kriteria selesai:** toko bisa mengaktifkan mode batch; laporan stok per batch; alarm ED (in-app / email sesuai kebijakan produk).

---

### Fase D — F&B (warung makan, kafe)

| No | Item | Tujuan |
|----|------|--------|
| D1 | **Modifier** (level pedas, topping, ukuran minuman) | Struktur menu nyata |
| D2 | **Biaya layanan & tip** (persen/nominal) | Warung & restoran kecil |
| D3 | **Tipe pesanan** (dine-in / takeaway / delivery) + opsional **meja/nomor** | Operasional harian |
| D4 | **Split bill** (tahap lanjutan) | Meja besar |
| D5 | **Dapur/KOT** (antrian sederhana atau cetak) | Koordinasi dapur |

**Kriteria selesai:** item di POS bisa carry modifier dengan harga tambahan; struk menampilkan layanan & tipe pesanan.

---

### Fase E — Modul opsional apotek (compliance-aware)

| No | Item | Tujuan |
|----|------|--------|
| E1 | Obat: **OTC vs resep**, flag khusus, **nomor izin edar** (jika dipakai) | Pembedaan dasar |
| E2 | Stok **per batch** wajib jika modul apotek aktif | Traceability |
| E3 | **Retur** ke supplier & alasan | Audit |
| E4 | Disclaimer UI: tidak menggantikan **aturan apoteker & perundangan setempat** | Risiko produk |

**Kriteria selesai:** modul hanya aktif jika toko memilih tipe apotek; dokumentasi batasan hukum untuk pengguna.

---

### Fase F — Skala & organisasi

| No | Item | Tujuan |
|----|------|--------|
| F1 | **Multi-outlet** (beberapa toko di bawah satu entitas) | Rantai kecil |
| F2 | **Transfer stok** antar outlet | Distribusi |
| F3 | **Peran** (kasir, admin gudang, owner) | Keamanan akses |
| F4 | **Shift kasir** & laporan per shift (opsional) | F&B & retail ramai |

---

## 4. Non-fungsional & teknis

- **Migrasi database** bertahap; field nullable dengan default aman untuk data lama.
- **API / Inertia** tetap konsisten; hindari breaking tanpa versi.
- **Uji otomatis** untuk: checkout, stok tidak minus, perhitungan pajak & diskon — **tambah uji untuk setiap jenis `stock_movements` setelah B4.**
- **Performa:** indeks pada kolom pencarian POS (barcode, nama) dan laporan tanggal; indeks `(store_id, product_id, created_at)` pada riwayat stok.
- **A11y & mobile:** POS tetap nyaman di tablet.

---

## 5. Indikator keberhasilan (KPI produk)

| Metrik | Arah |
|--------|------|
| Waktu setup toko baru (sampai transaksi pertama) | Turun |
| Keluhan “fitur kurang untuk jenis usaha X” | Terukur & menurun per vertikal |
| Retensi langganan | Stabil/naik setelah Fase A–B |
| Insiden stok salah tanpa jejak | Minim setelah B4 |

---

## 6. Daftar dependensi antar fase

```text
Fase A ──► Fase B (varian & desimal membutuhkan POS stabil)
Fase B ──► Fase C (batch menjembatani stok detail)
Fase A ──► Fase D (modifier lebih mudah setelah checkout & pajak matang)
Fase C ──► Fase E (apotek membutuhkan batch kuat)
Fase B + D ──► Fase F (multi-outlet saat model data stok sudah jelas)
```

---

## 7. Catatan tindak lanjut

- **Sekarang:** eksekusi **Fase B** mengikuti **§3.1** (mulai B4-a → B4-b → B2 → B5 → B1 → B3).
- Prioritas implementasi dapat disesuaikan dengan **segmen pasar utama** (mis. percepat B2 jika target kelontong curah).
- Setiap fase layak dipecah menjadi **epik → sprint** dengan acceptance criteria per tiket.
- Dokumen ini harus direview berkala ketika satu fase selesai atau strategi bisnis berubah.

---

## 8. Log perubahan dokumen

| Versi | Tanggal | Ringkasan |
|-------|---------|-----------|
| 1.0 | Apr 2026 | Rilis awal peta jalan A–F |
| 1.1 | Apr 2026 | Status Fase A; fase aktif B; §3.1 urutan sprint B; §1 & §4 diselaraskan kode |
