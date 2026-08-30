# Roadmap Pengembangan Modul Apotek

Dokumen ini menjadi rencana pengembangan setelah Tahap 1–3:

- Tahap 1: blokir batch kedaluwarsa saat penjualan.
- Tahap 2: kewajiban ED pada adjustment dan pelacakan batch transfer.
- Tahap 3: resep terstruktur dan snapshot resep transaksi.

## Prinsip Implementasi

- Perubahan dilakukan bertahap.
- Perilaku toko non-Apotek tidak berubah.
- Validasi server menjadi sumber kebenaran.
- Setiap perubahan stok wajib memiliki stock movement dan audit.
- Perubahan tidak boleh menghapus atau merusak transaksi lama.
- Setiap tahap harus memiliki test regresi sebelum masuk tahap berikutnya.

---

## Tahap 4 — Karantina dan Pemusnahan Batch

### Tujuan

Mencegah batch bermasalah masuk penjualan dan menyediakan alur resmi untuk karantina, pengembalian, serta pemusnahan stok.

### Tahap 4A — Status Batch dan Filter POS

#### Perubahan

1. Tambah status batch:
   - `available`
   - `quarantined`
   - `expired`
   - `destroyed`
   - `returned`
2. Tetapkan status default batch baru ke `available`.
3. POS hanya memilih batch berstatus `available`.
4. Batch dengan ED lewat otomatis tidak dapat dijual.
5. Batch Apotek tanpa ED ditolak pada seluruh jalur pembuatan stok.
6. Pastikan batch lama tetap dapat dibaca tanpa migrasi destruktif.

#### Test minimum

- Batch `available` dapat dijual.
- Batch `quarantined` tidak dapat dijual.
- Batch `expired` tidak dapat dijual.
- Batch `destroyed` tidak dapat dijual.
- Toko non-Apotek tetap berjalan seperti sebelumnya.

### Tahap 4B — Karantina Manual

#### Perubahan

1. Tambah aksi karantina batch.
2. Wajibkan alasan karantina.
3. Simpan petugas dan waktu perubahan.
4. Catat stock movement.
5. Tampilkan status dan alasan pada detail batch.
6. Sediakan aksi pelepasan karantina dengan otorisasi yang sesuai.

#### Aturan

- Batch karantina tidak boleh dipakai POS.
- Pelepasan karantina hanya mengubah status jika batch belum expired dan data ED valid.
- Jumlah stok tidak berubah saat hanya mengubah status.

### Tahap 4C — Pemusnahan dan Pengembalian

#### Perubahan

1. Tambah aksi pemusnahan batch.
2. Wajibkan:
   - jumlah
   - alasan
   - petugas
   - waktu
3. Kurangi stok secara atomik.
4. Ubah status menjadi `destroyed` jika saldo batch menjadi nol.
5. Tambah aksi pengembalian ke supplier dengan referensi dokumen.
6. Simpan stock movement untuk pemusnahan dan pengembalian.

#### Test minimum

- Pemusnahan tidak boleh melebihi stok tersedia.
- Pemusnahan mengurangi stok dan membuat movement.
- Pemusnahan gagal seluruhnya jika transaksi tidak valid.
- Pengembalian mempertahankan kode batch dan ED pada catatan.

---

## Tahap 5 — Hak Akses dan Approval Apotek

### Tujuan

Memisahkan kewenangan kasir, pengelola stok, dan apoteker penanggung jawab.

### Perubahan

1. Tambah permission khusus:
   - melihat batch
   - mengubah status batch
   - melakukan adjustment
   - melakukan pemusnahan
   - memproses obat resep
   - memverifikasi resep
   - melihat laporan resep
2. Batasi penjualan obat resep untuk petugas berwenang.
3. Tambah approval apoteker untuk transaksi obat resep bila kebijakan operasional mengharuskan.
4. Catat user yang membuat, memeriksa, menyetujui, atau menolak resep.
5. Batasi perubahan kelas obat, kode batch, dan ED.
6. Terapkan kontrol permission pada backend, bukan hanya frontend.

### Alur Approval Resep

1. Kasir memasukkan data resep.
2. Sistem memvalidasi nomor resep dan item keranjang.
3. Apoteker memeriksa resep.
4. Apoteker menyetujui atau menolak.
5. Transaksi hanya dapat dibayar setelah status disetujui jika approval diwajibkan.
6. Sistem menyimpan petugas dan waktu verifikasi.

### Status Resep

- `pending`
- `verified`
- `rejected`
- `cancelled`

### Test minimum

- Kasir tanpa permission tidak dapat menyetujui resep.
- User tanpa permission tidak dapat memusnahkan batch.
- Resep ditolak tidak dapat dipakai menyelesaikan transaksi.
- Semua aksi backend tetap terlindungi walau request dibuat manual.

---

## Tahap 6 — Audit dan Laporan Apotek

### Tujuan

Menyediakan jejak audit lengkap dan laporan operasional untuk stok serta penjualan Apotek.

### Audit

Catat perubahan berikut:

- kode batch
- ED
- status batch
- jumlah stok
- kelas penjualan obat
- data resep
- approval resep
- pemusnahan
- pengembalian
- adjustment
- transfer

Setiap audit minimal menyimpan:

- store
- user
- jenis aksi
- entitas dan ID entitas
- nilai sebelum
- nilai sesudah
- alasan
- waktu

### Laporan

1. Stok mendekati ED.
2. Batch expired.
3. Batch karantina.
4. Batch dimusnahkan.
5. Pengembalian ke supplier.
6. Penjualan obat resep.
7. Penjualan obat terbatas.
8. Resep berdasarkan periode.
9. Resep berdasarkan dokter.
10. Resep berdasarkan petugas verifikasi.
11. Riwayat batch dari pembelian sampai penjualan.

### Aturan Laporan

- Filter berdasarkan toko dan periode.
- Gunakan data snapshot transaksi untuk histori.
- Jangan mengubah histori transaksi lama saat data produk berubah.
- Bedakan stok aktif, karantina, expired, dan destroyed.
- Batasi laporan resep sesuai permission.

### Test minimum

- Laporan tidak mencampur toko.
- Periode tanggal bersifat inklusif dan konsisten.
- Batch expired muncul pada laporan yang benar.
- Transaksi resep lama tetap dapat dibaca melalui fallback legacy.
- Nilai laporan sesuai stock movement.

---

## Tahap 7 — Hardening Operasional

### Tujuan

Mengurangi risiko duplikasi, konflik stok, kehilangan data, dan kegagalan sinkronisasi.

### Validasi dan Konsistensi

1. Cegah nomor resep duplikat dalam konteks toko dan periode yang ditetapkan.
2. Validasi nomor izin edar sesuai format yang disepakati.
3. Validasi tanggal resep dan masa berlaku resep bila diperlukan.
4. Pastikan stok tidak menjadi negatif pada transaksi bersamaan.
5. Gunakan transaksi DB dan locking pada operasi stok kritis.
6. Pastikan retry tidak membuat transaksi atau stock movement ganda.

### Offline dan Sinkronisasi

1. Simpan ID idempotensi pada transaksi offline.
2. Tolak duplikasi saat sinkronisasi.
3. Pertahankan payload resep canonical dan field legacy.
4. Tampilkan konflik stok dengan pesan yang jelas.
5. Jangan menganggap sinkronisasi berhasil sebelum server mengonfirmasi.
6. Simpan log kegagalan sinkronisasi untuk ditindaklanjuti.

### Backup dan Retensi

1. Jadwalkan backup database.
2. Uji pemulihan backup secara berkala.
3. Tentukan retensi audit dan transaksi sesuai kebutuhan operasional.
4. Lindungi data pasien dan resep dari akses tidak berwenang.
5. Hindari data resep masuk ke log aplikasi atau pesan error.

### Keamanan dan Privasi

- Terapkan permission pada semua endpoint.
- Jangan log nomor resep, nama pasien, atau data sensitif secara berlebihan.
- Batasi akses laporan resep.
- Audit perubahan permission.
- Pastikan ekspor data mengikuti hak akses user.

### Test minimum

- Dua transaksi bersamaan tidak menjual stok yang sama dua kali.
- Retry request tidak menggandakan transaksi.
- Sinkronisasi offline duplikat ditolak secara aman.
- Backup dapat dipulihkan.
- Data pasien tidak muncul pada log teknis yang tidak perlu.

---

## Urutan Eksekusi yang Disarankan

1. Tahap 4A: status batch dan filter POS.
2. Tahap 4B: karantina manual.
3. Tahap 4C: pemusnahan dan pengembalian.
4. Tahap 5: permission dan approval resep.
5. Tahap 6: audit dan laporan.
6. Tahap 7: hardening, offline, backup, dan keamanan.

Setiap sub-tahap harus selesai dengan:

- migration yang aman
- validasi backend
- pembaruan UI seperlunya
- test regresi
- verifikasi toko non-Apotek
- pemeriksaan data lama
