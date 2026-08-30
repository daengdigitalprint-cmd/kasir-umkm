Berikut prompt yang lebih terstruktur dan bisa langsung diberikan ke AI Developer (Claude, GPT, Cursor, Windsurf, dll).

---

# PROMPT PENGEMBANGAN MODUL GUDANG, PRODUKSI RECIPE & HPP

## Objective

Bangun modul **Inventory, Gudang, Produksi, Recipe, dan HPP** yang terintegrasi dengan POS khusus usaha minuman.

Sistem harus mampu mengelola:

* Gudang Pusat
* Transfer stok ke Outlet/Cabang
* Produksi berdasarkan Recipe
* Perhitungan HPP otomatis
* Penjualan menggunakan hasil produksi
* Laporan stok dan HPP

---

# 1. Konsep Sistem

Alur bisnis yang diinginkan adalah sebagai berikut.

```text
Supplier
      │
      ▼
Pembelian
      │
      ▼
Gudang Pusat
(Bahan Mentah)
      │
Transfer
      ▼
Outlet / Cabang
(Bahan Mentah)
      │
Produksi / Racik
(Recipe)
      │
Menghasilkan
Stok Siap Jual
(Berbasis Volume/ml)
      │
      ▼
Penjualan POS
```

---

# 2. Gudang Pusat

Semua pembelian supplier masuk ke Gudang Pusat.

Contoh

Supplier mengirim:

* Daun Teh 5 kg
* Gula 25 kg
* Cup 2.000 pcs
* Sedotan 2.000 pcs

Saat transaksi pembelian disimpan:

* stok gudang bertambah
* harga beli tersimpan
* histori pembelian tersimpan

---

# 3. Transfer Gudang

Gudang dapat mengirim bahan baku ke Outlet.

Contoh

Transfer ke Outlet A

* Daun Teh 120 gram
* Gula 500 gram
* Cup 500 pcs
* Sedotan 500 pcs

Maka

Gudang berkurang

Outlet bertambah

Semua transfer memiliki status

* Draft
* Dikirim
* Diterima
* Dibatalkan

Stok outlet hanya bertambah ketika status menjadi **Diterima**.

---

# 4. Stok Outlet

Outlet hanya melihat stok miliknya.

Contoh

Outlet A

Daun Teh

120 gram

Gula

500 gram

Cup

500 pcs

Sedotan

500 pcs

---

# 5. Produksi Berdasarkan Recipe

Di Outlet terdapat menu

Produksi.

Produksi digunakan untuk meracik bahan menjadi produk yang siap dijual.

Contoh

Nama Recipe

Biang Teh

Komposisi

Daun Teh

120 gram

Air

18 Liter

Output

18 Liter Biang Teh

atau

18.000 ml

Saat produksi dilakukan

stok bahan baku otomatis berkurang.

Contoh

Daun Teh

120 gram

↓

0 gram

Air

18 Liter

↓

0 Liter

Kemudian sistem menghasilkan stok produksi

Biang Teh

18.000 ml

---

# 6. Perhitungan HPP Produksi

Sistem wajib menghitung HPP secara otomatis.

Contoh

Daun Teh

Rp20.000

Air

Rp5.000

Total Biaya

Rp25.000

Output Produksi

18 Liter

=

18.000 ml

Maka

HPP per ml

= Rp25.000 / 18.000

=

Rp1,39/ml

Nilai HPP per ml disimpan pada batch produksi.

---

# 7. Produk Penjualan

Contoh produk

Es Teh 200 ml

Volume

200 ml

Es Teh 250 ml

Volume

250 ml

Saat produk dibuat

tidak perlu mengisi HPP secara manual.

Sistem mengambil HPP berdasarkan

HPP/ml × volume produk.

---

# 8. Contoh Perhitungan HPP

Biang Teh

HPP

Rp1,39/ml

Produk

Es Teh 200 ml

HPP

200 × Rp1,39

=

Rp278

Produk

Es Teh 250 ml

HPP

250 × Rp1,39

=

Rp347

HPP harus dihitung otomatis.

---

# 9. Pengurangan Stok Saat Penjualan

Saat kasir menjual

Es Teh 200 ml

stok produksi akan berkurang

200 ml

Contoh

Stok Produksi

18.000 ml

Setelah terjual 1 cup

↓

17.800 ml

Jika terjual ukuran 250 ml

↓

17.550 ml

Dengan cara ini

satu batch produksi dapat digunakan untuk berbagai ukuran cup.

Contoh

20 cup ukuran 200 ml

*

15 cup ukuran 250 ml

Sistem tetap dapat menghitung sisa volume secara akurat.

---

# 10. HPP Transaksi

Saat transaksi penjualan berhasil

sistem menyimpan snapshot HPP.

Contoh

Harga Jual

Rp8.000

HPP

Rp278

Laba Kotor

Rp7.722

Nilai HPP transaksi tidak boleh berubah meskipun harga bahan baku berubah di kemudian hari.

---

# 11. Laporan Produksi

Sediakan laporan

## Riwayat Produksi

Menampilkan

* Tanggal Produksi
* Nama Recipe
* Batch
* Total Biaya
* Output Produksi
* HPP/ml
* User Produksi

---

## Laporan Stok Produksi

Menampilkan

* Nama Produk
* Volume Awal
* Volume Terpakai
* Volume Sisa

---

## Laporan HPP

Menampilkan

Produk

Es Teh 200 ml

Volume

200 ml

HPP

Rp278

Margin

Harga Jual - HPP

---

# 12. Ketentuan Sistem

1. Semua stok menggunakan satuan dasar.

Contoh

* Gram
* ml
* pcs

2. HPP selalu dihitung otomatis.

3. HPP mengikuti biaya produksi terakhir atau metode Average Cost (FIFO/LIFO dapat dipilih melalui pengaturan).

4. Penjualan tidak mengurangi stok bahan baku secara langsung.

5. Penjualan hanya mengurangi stok hasil produksi (volume ml).

6. Sistem harus mendukung banyak outlet.

7. Setiap outlet memiliki stok produksi masing-masing.

8. Semua transaksi wajib memiliki histori audit (siapa, kapan, dan dari mana transaksi dilakukan).

---

# Expected Result

Setelah implementasi, alur sistem menjadi:

```text
Supplier
      │
      ▼
Pembelian
      │
      ▼
Gudang Pusat
      │
Transfer
      ▼
Outlet
      │
Produksi Recipe
      │
Menghasilkan 18.000 ml Biang Teh
      │
HPP otomatis = Rp1,39/ml
      │
      ▼
Kasir Menjual
200 ml → HPP Rp278
250 ml → HPP Rp347
      │
      ▼
Stok Produksi Berkurang Sesuai Volume Terjual
```

### Catatan Pengembangan

* Gunakan **batch production** agar setiap produksi memiliki HPP sendiri.
* Simpan **snapshot HPP** pada transaksi penjualan untuk menjaga konsistensi laporan laba.
* Semua perhitungan stok menggunakan **satuan terkecil (gram, ml, pcs)** agar akurat dan mendukung berbagai variasi ukuran produk.
