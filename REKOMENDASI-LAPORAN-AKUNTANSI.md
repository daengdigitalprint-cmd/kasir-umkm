# Rekomendasi Laporan Akuntansi

## Status Implementasi

### Sudah selesai

- Tutup buku dan jurnal penutup ke saldo laba.
- Penguncian periode tertutup pada posting jurnal.
- Penguncian pengeluaran operasional, pelunasan pembelian, hapus transaksi, hapus biaya, hapus pembelian, dan pembatalan penyesuaian stok.
- Klasifikasi neraca dasar: aset lancar, aset tidak lancar, liabilitas jangka pendek, liabilitas jangka panjang, dan ekuitas.
- Subtotal kategori neraca pada UI dan ekspor.
- Referensi jurnal unik untuk setiap cicilan pembelian.
- Jurnal kas masuk/keluar dan validasi jurnal seimbang.
- Tes lengkap guard periode tertutup: hapus transaksi, hapus pembelian, pembatalan penyesuaian stok, dan pelunasan pembelian.
- Jurnal pembalik resmi untuk koreksi setelah periode ditutup.
- Rekonsiliasi kas, bank, piutang, utang, dan persediaan.
- Laporan perubahan ekuitas.
- Laporan arus kas akuntansi berbasis jurnal.
- CaLK naratif dan kebijakan akuntansi.
- Audit trail perubahan dan pembatalan jurnal.
- Klasifikasi akun lanjutan: aset tetap, akumulasi penyusutan, modal disetor, saldo laba, laba tahun berjalan, dan pajak.

### Belum selesai — lanjutkan dari sini

- [x] Status laporan: draft, final, dan periode ditutup.
- [x] Nomor laporan, tanggal penerbitan, penyusun, dan pemeriksa.
- [x] Konsistensi filter outlet pada seluruh laporan dan ekspor.
- [x] Laba rugi rinci: rincian per akun, laba sebelum pajak, pajak, laba bersih, perbandingan periode sebelumnya, margin kotor/bersih.
- [x] Validasi tanggal: format `Y-m-d`, `start_date <= end_date`, `as_of` validasi format.
- [x] Neraca saldo: saldo awal, mutasi debit/kredit, saldo akhir per akun.

**Semua fase rekomendasi utama sudah selesai.** Laporan keuangan sudah memiliki: status dokumen, identitas dokumen, konsistensi filter outlet, laba rugi rinci dengan perbandingan periode dan margin, validasi tanggal server, neraca saldo saldo awal/akhir, klasifikasi neraca profesional (aset lancar/tetap, liabilitas jangka pendek/panjang), dan laba berjalan melalui tutup buku.

## Penilaian Umum

| Area | Penilaian |
|---|---:|
| Kelayakan sebagai laporan aplikasi kasir UMKM | 7/10 |
| Kesesuaian dengan laporan akuntansi profesional | 5,5/10 |

Struktur laporan sudah cukup luas dan memiliki fondasi jurnal berpasangan. Tersedia ringkasan penjualan, pembelian, stok, arus kas, utang usaha, neraca, neraca saldo, laba rugi, PPN, dan CaLK.

Namun, laporan belum layak disebut laporan keuangan profesional final tanpa rekonsiliasi, penyempurnaan klasifikasi akun, proses tutup buku, dan penguatan audit trail.

## Fitur yang Sudah Baik

- Neraca memakai saldo kumulatif sampai tanggal tertentu.
- Neraca memiliki validasi aset = kewajiban + ekuitas.
- Neraca saldo menghitung debit, kredit, dan saldo per akun.
- Laba rugi sudah memisahkan pendapatan, HPP, laba kotor, beban operasional (tanpa pajak), pajak penghasilan, dan laba bersih.
- Laba rugi memiliki perbandingan periode sebelumnya dan margin kotor/bersih.
- HPP diambil dari jurnal, bukan hanya perkiraan dari omzet.
- Rekap shift sudah memuat kas awal, penjualan tunai, kas masuk, kas keluar, kas akhir, dan selisih.
- Rekap PPN sudah memisahkan PPN keluaran dan PPN masukan.
- Tersedia dukungan multi-outlet dan ekspor laporan.

## Temuan dan Rekomendasi

### 1. ~~Perbandingan periode belum konsisten untuk multi-outlet~~ ✅

Perhitungan periode berjalan dan periode sebelumnya sudah menggunakan `resolveStoreIds()` dan `whereIn('store_id', $storeIds)` yang sama.

### 2. ~~Laba rugi rinci sudah diterapkan dan terpisah detail~~ ✅

Laporan sudah menampilkan:

- Pendapatan bersih, HPP, laba kotor, beban operasional (tanpa pajak), laba operasi, pajak penghasilan (dari akun `6.1.03`), dan laba bersih.
- Rincian pendapatan dan beban per akun.
- Perbandingan dengan periode sebelumnya (panjang periode sama, outlet sama).
- Margin laba kotor dan margin laba bersih.
- Perubahan persentase pendapatan dan laba bersih vs periode sebelumnya.

Pajak penghasilan dipisahkan dari beban operasional berdasarkan kode akun `CODE_BEBAN_PAJAK` (`6.1.03`).

### 3. ~~Neraca sudah memiliki klasifikasi profesional~~ ✅

Neraca sudah mengelompokkan akun berdasarkan `balance_sheet_group`: Aset Lancar, Aset Tidak Lancar, Liabilitas Jangka Pendek, Liabilitas Jangka Panjang, dan Ekuitas. Subtotal per grup sudah ditampilkan di UI dan ekspor.

### 4. ~~Laba berjalan sudah melalui proses tutup buku~~ ✅

Tutup buku sudah diimplementasi: jurnal penutup memindahkan laba/rugi ke saldo laba, periode akuntansi terkunci setelah ditutup, jurnal pembalik tersedia untuk koreksi.

### 5. ~~Neraca saldo sudah menampilkan saldo awal dan saldo akhir~~ ✅

Neraca saldo sudah menampilkan saldo awal, sisi saldo awal, mutasi debit, mutasi kredit, saldo akhir, sisi saldo akhir, dan total mutasi. Saldo awal dihitung dari selisih kumulatif `< startDate`, mutasi dari `BETWEEN startDate AND endDate`.

### 6. ~~Arus kas akuntansi berbasis jurnal sudah diimplementasi~~ ✅

Laporan arus kas akuntansi berbasis jurnal sudah tersedia, mengklasifikasikan mutasi kas ke aktivitas operasi, investasi, dan pendanaan.

### 7. ~~CaLK naratif sudah diimplementasi~~ ✅

CaLK sudah berisi kebijakan akuntansi naratif dan dokumen yang dapat dikelola.

### 8. ~~Validasi tanggal sudah diperkuat~~ ✅

Semua endpoint laporan menggunakan `validateDateRange()` atau `validateAsOf()` yang memvalidasi format `date_format:Y-m-d` dan `start_date <= end_date` (`after_or_equal:start_date`) di server. Validasi berlaku untuk laporan keuangan dan non-keuangan.

**Yang sudah divalidasi:**
- Format tanggal: `date_format:Y-m-d`
- Urutan tanggal: `after_or_equal:start_date`
- Format `as_of`: `date_format:Y-m-d`
- Semua endpoint: index, sales, purchases, production, cash-shifts, financial (semua jenis), export, PPN

## Prioritas Implementasi

### Prioritas 1 — Wajib ✅

1. ~~Perbaiki perhitungan pembanding multi-outlet.~~
2. ~~Tambahkan saldo awal dan saldo akhir pada neraca saldo.~~
3. ~~Tambahkan rincian akun pada laba rugi.~~
4. ~~Pisahkan laba operasi, laba sebelum pajak, dan laba bersih.~~
5. ~~Tambahkan validasi keseimbangan jurnal dan periode.~~
6. ~~Pastikan seluruh transaksi menghasilkan jurnal double-entry.~~

### Prioritas 2 — Penguatan Akuntansi ✅

1. ~~Implementasikan tutup buku.~~
2. ~~Kunci periode akuntansi.~~
3. ~~Tambahkan jurnal penutup.~~
4. ~~Kelompokkan akun menjadi aset lancar, aset tetap, kewajiban lancar, kewajiban jangka panjang, dan ekuitas.~~
5. ~~Tambahkan rekonsiliasi kas, bank, piutang, utang, dan persediaan.~~
6. ~~Tambahkan laporan perubahan ekuitas.~~

### Prioritas 3 — Audit dan Penyajian ✅

1. ~~Tambahkan nomor laporan dan tanggal penerbitan.~~
2. ~~Tampilkan nama penyusun dan pemeriksa.~~
3. ~~Pastikan filter outlet dan konsolidasi konsisten.~~
4. ~~Sediakan ekspor PDF dengan format formal.~~
5. ~~Tambahkan audit trail perubahan jurnal.~~
6. ~~Tambahkan status `draft`, `final`, dan `periode ditutup`.~~
7. ~~Perluas CaLK menjadi dokumen naratif yang dapat dikelola.~~

## Kesimpulan

Fondasi laporan sudah baik untuk sistem POS dengan modul akuntansi dasar. Saat ini klasifikasi yang paling tepat adalah:

> Laporan keuangan manajerial berbasis jurnal.

Sudah memiliki tutup buku, jurnal penutup, penguncian periode, neraca saldo saldo awal/akhir, laba rugi rinci dengan perbandingan periode dan margin, arus kas akuntansi, CaLK naratif, dan audit trail. Yang masih perlu diperkuat:

- Klasifikasi neraca profesional (aset lancar/tetap, kewajiban jangka pendek/panjang, ekuitas detail).
- Validasi tanggal server yang lebih ketat.
- Rincian CaLK substantif (rekening, piutang, utang, modal).
