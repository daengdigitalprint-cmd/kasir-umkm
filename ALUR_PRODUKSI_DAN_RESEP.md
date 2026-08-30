# Panduan Alur Produksi dan Manajemen Stok (F&B / Retail)

Aplikasi UMKM Kasir ini menggunakan pendekatan profesional untuk manajemen inventory dan produksi. Alur ini memisahkan antara **Produksi Skala Besar (Batch/Bulk)** dan **Konsumsi Penjualan (Per Cup/Porsi)**.

Hal ini bertujuan agar penghitungan HPP (Harga Pokok Penjualan) dan stok lebih presisi, terutama untuk bisnis F&B seperti kedai kopi atau teh.

---

## 1. Konsep Tipe Produk (Inventory Type)

Setiap barang yang ada di sistem dikategorikan menjadi 4 tipe:

1. **Bahan Baku (Raw Material)**
   - Barang mentah yang dibeli dari supplier. Tidak dijual langsung ke pelanggan.
   - *Contoh: Daun Teh Premium, Gula Pasir, Bubuk Coklat, Sirup, Susu UHT.*
2. **Kemasan (Packaging)**
   - Barang pembungkus yang digunakan saat penjualan.
   - *Contoh: Cup Plastik 16oz, Sedotan, Plastik Kresek, Tutup Cup.*
3. **Hasil Produksi (Production Output)**
   - Barang setengah jadi hasil masakan/racikan skala besar (batch) di dapur belakang (Back Kitchen).
   - *Contoh: Biang Teh Original 5L, Simple Syrup Buatan Sendiri 1L, Adonan Kue.*
4. **Produk Jual (Sellable)**
   - Barang akhir yang muncul di aplikasi kasir (POS) dan dibeli oleh pelanggan.
   - *Contoh: Teh Original (K), Coklat Macchiato (B).*

---

## 2. Alur 1: Produksi Skala Besar (Batch Production)

Gunakan fitur **Resep (Recipe)** dan **Produksi** jika Anda membuat suatu barang dalam jumlah besar sekaligus, dan barang tersebut disimpan untuk dipakai berjualan seharian/beberapa hari ke depan.

### Cara Kerja:
1. Anda memasak/membuat 1 Panci (5 Liter) Biang Teh.
2. Bahan yang dihabiskan: 120gr Daun Teh + 500gr Gula Pasir + 5 Liter Air.
3. Hasilnya menjadi barang baru bernama **Biang Teh 5L**.

### Langkah di Aplikasi:
1. **Buat Produk Hasil Produksinya Dulu:**
   - Pergi ke menu **Produk > Tambah Produk**.
   - Nama Produk: "Biang Teh Original"
   - Tipe Inventory: **Hasil Produksi** (Penting!)
   - Satuan: `ml` (karena berupa cairan)
2. **Buat Resep (Formula):**
   - Pergi ke menu **Resep > Tambah Resep**.
   - Pilih Produk Hasil: "Biang Teh Original".
   - Masukkan hasil yang didapat: misal `5000` ml.
   - Masukkan komposisi bahannya (Daun Teh 120g, Gula 500g).
3. **Mulai Produksi (Eksekusi Masak):**
   - Pergi ke menu **Produksi > Buat Produksi Baru**.
   - Pilih Resep "Biang Teh Original".
   - Sistem akan otomatis **memotong stok bahan baku** (Daun teh, gula) dan **menambah stok Biang Teh Original** sebanyak 5000ml.

---

## 3. Alur 2: Konsumsi Penjualan (BOM per Porsi)

Gunakan fitur **Konsumsi Penjualan** (di dalam pengaturan Produk Jual) untuk barang-barang yang baru dicampur atau diracik saat ada pesanan dari pelanggan.

### Cara Kerja:
1. Pelanggan memesan "Teh Original (K)".
2. Kasir memproses pesanan di halaman POS.
3. Begitu pembayaran selesai, sistem otomatis memotong stok barang yang ada di dapur/bar.

### Langkah di Aplikasi:
1. **Pilih Produk Jual:**
   - Pergi ke menu **Produk**. Cari "Teh Original (K)".
   - Klik **Edit**.
2. **Atur Komposisi / Konsumsi Saat Penjualan:**
   - Scroll ke bagian bawah (atau tab) **Konsumsi Produk / Bahan**.
   - Masukkan bahan apa saja yang berkurang 1 cup "Teh Original (K)" terjual.
   - Contoh:
     - Biang Teh Original: `250` ml *(Tipe: Hasil Produksi)*
     - Cup Plastik 16oz: `1` pcs *(Tipe: Kemasan)*
     - Sedotan: `1` pcs *(Tipe: Kemasan)*
3. **Selesai:**
   - Setiap kali kasir menekan tombol "Bayar" untuk 1 Teh Original (K), sistem akan langsung memotong 250ml dari stok panci Biang Teh, dan 1 pcs Cup Plastik.

---

## Ringkasan Perbedaan: Kapan Pakai Yang Mana?

| Kondisi | Gunakan Fitur Ini | Kapan Stok Terpotong? |
| --- | --- | --- |
| Anda merebus teh dalam panci besar di pagi hari. | **Menu Resep & Produksi** | Saat tombol *"Selesaikan Produksi"* ditekan. |
| Pelanggan memesan minuman, barista menuang teh dari panci ke cup. | **Produk > Edit > Konsumsi Penjualan** | Otomatis saat *"Transaksi Selesai"* di kasir. |

Dengan memisahkan dua alur ini, Anda bisa melacak secara detail apakah ada kebocoran stok. Misalnya: Panci Biang Teh berisi 5000ml (cukup untuk 20 gelas @250ml). Jika di akhir hari kasir hanya mencatat 15 gelas terjual, namun panci teh sudah habis, berarti ada 5 gelas bahan yang terbuang/tumpah.