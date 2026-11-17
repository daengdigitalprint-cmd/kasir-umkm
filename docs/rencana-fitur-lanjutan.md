# Rencana Fitur Lanjutan — Kasir UMKM

Disusun 17 November 2026 dari permintaan calon klien F&B multi-outlet dengan Central Kitchen. Tujuannya **bukan** memenuhi permintaan satu klien itu, melainkan mengambil bagian yang berguna untuk semua pengguna (warung, kafe, toko, usaha multi-outlet) supaya aplikasi lebih lengkap.

## Prinsip pengerjaan

- **Modul baru mati secara bawaan**, dinyalakan per toko lewat `PlanModules` (route, menu, saklar Pengaturan). Pengguna yang tidak memakainya tidak melihat perubahan apa pun.
- **Tambah, jangan ubah.** Pakai tabel dan halaman baru. Alur lama (transfer stok, pembelian, HPP rata-rata tertimbang) tetap berjalan seperti sekarang.
- **Setiap modul punya tes otomatis sendiri**, dan seluruh tes lama tetap harus lulus sebelum dikirim ke server.
- Istilah di layar mengikuti istilah yang sudah dipakai aplikasi (Stok Minimum, Stok Rendah, Gudang Pusat, Transfer Stok, Pembelian).

## Yang sudah ada (tidak perlu dibangun)

| Kebutuhan | Sudah tersedia |
|---|---|
| Inventori terpisah per lokasi dalam satu usaha | Gudang Pusat & Outlet, masing-masing punya produk dan stok sendiri |
| Resep / BOM dan produksi | Resep, paket, produksi batch, bahan terpotong otomatis saat menu terjual |
| Pesan dari meja | Scan Meja: menu digital, pesanan masuk ke kasir |
| CRM dasar | Pelanggan, Total Belanja, poin & hadiah loyalitas, voucher, diskon |
| Absensi & gaji | Absensi, jadwal, cuti, lembur otomatis, penggajian (add-on) |
| Pembukuan | Laba Rugi, Neraca, Arus Kas; per outlet atau gabungan untuk owner |
| Pesanan ojol | Sumber pesanan GoFood/GrabFood/ShopeeFood + komisi dicatat di kasir |

## Daftar fitur yang akan dilengkapi

Perkiraan dalam hari kerja, dari pembacaan kode saat ini. Risiko = kemungkinan mengganggu fitur yang sudah ada.

### Tahap 1 — Stok untuk semua pengguna

| # | Fitur | Masalah yang diselesaikan | Yang dibangun | Risiko | Perkiraan |
|---|---|---|---|---|---|
| 1 | **Penanda stok menipis di kasir** | Kasir baru tahu barang hampir habis setelah benar-benar habis | Label kuning "Sisa N" di kartu produk POS saat stok ≤ Stok Minimum (sekarang hanya ada "Stok: Habis") | 🟢 Rendah | 1 |
| 2 | **Daftar Belanja otomatis** | Pemilik harus mengecek satu per satu barang yang perlu dibeli | Halaman berisi produk/bahan di bawah Stok Minimum + saran jumlah beli; tombol "Jadikan Pembelian" yang mengisi form Pembelian | 🟢 Rendah | 3–4 |
| 3 | **Satuan beli & isi** | Supplier menjual per karton/pcs, stok dicatat per gram/pcs | Satuan tambahan per produk (mis. 1 karton = 24 pcs, 1 pcs = 250 gram), dipakai di Pembelian. Produk lama otomatis isi 1, jadi perilakunya tidak berubah | 🟡 Sedang | 5–8 |

### Tahap 2 — Multi-outlet & Gudang Pusat (paket GrowUp)

| # | Fitur | Masalah yang diselesaikan | Yang dibangun | Risiko | Perkiraan |
|---|---|---|---|---|---|
| 4 | **Surat jalan transfer** | Pengiriman antar lokasi tanpa dokumen fisik | Cetak PDF surat jalan dari halaman Transfer Stok | 🟢 Rendah | 1–2 |
| 5 | **Terima sebagian & selisih** | Barang yang tiba kurang/rusak tidak tercatat | Saat menerima transfer, jumlah diterima bisa diisi per baris + alasan selisih; laporan selisih | 🟡 Sedang | 3–4 |
| 6 | **Permintaan Stok antar lokasi** | Outlet harus minta stok lewat chat, lalu gudang mengetik ulang | Outlet membuat permintaan (bisa terisi otomatis dari Daftar Belanja #2) → muncul di Gudang Pusat → jumlah bisa direvisi → disetujui jadi Transfer Stok yang sudah ada. Notifikasi realtime (Reverb). Arah sebaliknya juga bisa | 🟢 Rendah | 10–15 |
| 7 | **Pantau Stok Semua Lokasi** | Owner harus membuka lokasi satu per satu | Tabel produk × lokasi untuk owner, filter stok menipis/habis, ekspor Excel | 🟢 Rendah | 4–6 |

### Tahap 3 — Penjualan F&B

| # | Fitur | Masalah yang diselesaikan | Yang dibangun | Risiko | Perkiraan |
|---|---|---|---|---|---|
| 8 | **Saran tambahan menu (upsell)** | Kasir lupa menawarkan minuman/topping | Pasangan menu diatur manual ("Nasi goreng → Es teh") dan saran "sering dibeli bersama" dari riwayat; muncul di POS dan Scan Meja | 🟢 Rendah | 5–7 |
| 9 | **Segmentasi pelanggan** | Pemilik tidak tahu pelanggan mana yang mulai jarang datang | Kelompok otomatis: Setia, Baru, Lama tidak datang; tombol chat WhatsApp per pelanggan (tautan wa.me, tanpa biaya API) | 🟢 Rendah | 4–6 |
| 10 | **Pesanan Antar mandiri** | Usaha dengan kurir sendiri tidak punya alur pengantaran | Alamat, ongkir, pilih kurir (karyawan), status Disiapkan → Diantar → Selesai, ongkir tercetak di struk | 🟢 Rendah | 8–12 |
| 11 | **QRIS dinamis** | QRIS sekarang hanya dicatat; kasir harus mengecek mutasi manual | Integrasi payment gateway: QR muncul di kasir/Scan Meja dan transaksi otomatis lunas. Butuh akun merchant gateway (ada potongan per transaksi) | 🟡 Sedang | 7–10 |

## Sengaja ditunda

| Fitur | Alasan |
|---|---|
| Integrasi otomatis GoFood/GrabFood | API hanya dibuka untuk mitra/integrator resmi. Pencatatan kanal + komisi sudah ada |
| HPP metode FIFO | Perhitungan HPP dipakai di 52 titik dalam 12 berkas (kasir, pembelian, produksi, transfer, opname). Kalau nanti dibutuhkan, dibuat sebagai pilihan metode per toko dalam proyek terpisah, bukan mengganti metode rata-rata tertimbang untuk semua |
| Order otomatis ke Gudang Pusat | Akan mudah setelah #2 dan #6 jadi: tinggal menjadwalkan pembuatan Permintaan Stok dari Daftar Belanja |

## Urutan yang disarankan

1. **#1 Penanda stok menipis di kasir**: cepat, langsung terasa oleh semua pengguna.
2. **#2 Daftar Belanja** → **#3 Satuan beli & isi**: melengkapi alur stok dan pembelian.
3. **#4 Surat jalan** → **#5 Terima sebagian** → **#6 Permintaan Stok** → **#7 Pantau Stok Semua Lokasi**: paket multi-outlet lengkap, layak jadi nilai jual GrowUp.
4. **#8–#11** sesuai permintaan pasar.

Total Tahap 1–2: ±27–40 hari kerja. Tahap 3: ±24–35 hari kerja.
