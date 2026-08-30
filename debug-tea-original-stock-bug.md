# Debug: stok teh original habis saat transaksi

Status: [OPEN]
Session: tea-original-stock-bug

## Gejala
Produk Teh Original memiliki komponen konsumsi penjualan. Pengguna mengharapkan transaksi hanya mengurangi stok Biang Teh, tetapi transaksi dilaporkan stok habis meskipun Biang Teh tersedia 12.000 ml.

## Hipotesis
1. Konfigurasi konsumsi varian terbaca ganda atau salah cakupan varian.
2. Satuan stok Biang Teh tidak sama dengan satuan kebutuhan konsumsi.
3. Validasi masih memeriksa stok induk/varian Teh Original.
4. Komponen lain dianggap wajib tersedia.
5. Transaksi rollback setelah gagal pada komponen lain.

## Rencana
1. Tambahkan instrumentation minimal pada alur resolver konsumsi dan validasi stok.
2. Reproduksi satu transaksi Teh Original.
3. Ambil log runtime dan cocokkan dengan hipotesis.
4. Terapkan perbaikan minimal berdasarkan bukti.
5. Reproduksi ulang dan bandingkan log sebelum/sesudah.

## Catatan sesi
- Belum ada perubahan logika bisnis.
