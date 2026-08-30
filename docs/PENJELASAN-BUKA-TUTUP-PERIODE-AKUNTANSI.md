# Penjelasan Buka & Tutup Periode Akuntansi

Materi singkat untuk menjelaskan ke kasir, admin, atau owner: apa itu buka/tutup jurnal, hubungannya dengan transaksi POS, dan kenapa kadang pembayaran bisa gagal.

---

## 1. Inti singkat (bisa dibaca 30 detik)

- **Periode terbuka** = buku masih hidup → transaksi boleh dicatat ke jurnal.
- **Periode ditutup** = buku dikunci → tanggal di periode itu tidak boleh diisi jurnal baru.
- Tutup periode **bukan** otomatis setiap akhir bulan. Di sistem ini, Anda yang buat periode lalu tekan **tutup**.
- Penjualan POS menghasilkan **2 hal**: data transaksi kasir + jurnal akuntansi.
- Kalau tanggal jual jatuh di periode yang sudah ditutup, sistem menolak jurnal. Dulu itu ikut menggagalkan tombol bayar.

---

## 2. Analogi sederhana

Bayangkan buku kas bulanan seperti **map arsip**:

| Status map | Artinya |
|---|---|
| Map masih terbuka di meja | Boleh menambah struk, koreksi, dan catatan |
| Map sudah disegel & disimpan | Angka di dalamnya final. Tidak boleh menambah lagi |

**Tutup periode** = menyegel map bulan itu.  
**Buka periode berikutnya** = menyiapkan map baru untuk bulan jalan.

Koreksi bulan lama **tidak dibuka lagi map lama**, melainkan dicatat di map baru (jurnal koreksi / jurnal pembalik).

---

## 3. Apa itu “buka jurnal” dan “tutup jurnal”

Di sistem ini istilah yang dipakai lebih tepat: **buka/tutup periode akuntansi**.

### Periode terbuka (open)

- Transaksi boleh dicatat
- Jurnal boleh dibuat
- Saldo akun masih bisa berubah
- Laporan periode itu **belum final**

### Periode ditutup (closed)

Sistem melakukan 2 hal:

1. **Jurnal penutup**  
   Akun sementara (pendapatan, HPP, beban) di-nol-kan.  
   Selisihnya dipindah ke **saldo laba**.  
   Jadi laba bulan itu “masuk” ke modal/laba ditahan, lalu bulan berikutnya mulai dari nol untuk pendapatan & beban.

2. **Kunci tanggal**  
   Tanggal di dalam rentang periode itu tidak boleh lagi diisi jurnal baru.

---

## 4. Apakah harus setiap akhir bulan?

**Biasanya ya** (kebiasaan kerja yang rapi), tapi **bukan otomatis**.

Pilihan umum:

| Jenis | Contoh |
|---|---|
| Bulanan | 1–31 Agustus (paling sering untuk UMKM) |
| Kuartalan | Juli–September |
| Tahunan | 1 Jan–31 Des |
| Khusus | 1–15 Agustus (jarang, untuk kasus tertentu) |

### Di aplikasi UMKM Kasir

1. User **membuat** periode (nama + tanggal mulai–selesai).
2. Status awal: **open**.
3. User menekan **tutup periode** saat sudah yakin data lengkap.
4. Sistem membuat jurnal penutup + mengunci periode.

Tidak ada penutupan otomatis setiap tanggal 31.

---

## 5. Alur kerja yang benar (mudah dijelaskan)

```
1. Buat periode (misalnya Agustus 2026)
2. Sepanjang bulan: jual / beli / biaya → jurnal berjalan
3. Akhir bulan: cek data sudah lengkap
4. Tutup periode Agustus
5. Sistem buat jurnal penutup + kunci Agustus
6. Buat / pakai periode September (open)
7. Transaksi baru masuk ke September
```

### Aturan praktis untuk toko

- Bulan yang **sudah lewat** → boleh ditutup.
- Bulan yang **masih berjalan** → biarkan terbuka.
- Jangan tutup bulan berjalan terlalu cepat.

**Contoh:** hari ini 24 Agustus  
- Juli boleh ditutup  
- Agustus sebaiknya masih open  
- September belum perlu ditutup

---

## 6. Relasi tutup periode dengan transaksi POS

Setiap pembayaran di kasir menghasilkan:

```
Transaksi kasir                 Jurnal akuntansi
─────────────────────────       ─────────────────────────
Tanggal jual: 24 Agustus   →    entry_date: 24 Agustus
Bayar tunai                →    Debit Kas / Bank
Penjualan                  →    Kredit Pendapatan
HPP (jika ada)             →    Debit HPP / Kredit Persediaan
```

Keduanya terikat lewat **tanggal**.

### Alur saat kasir menekan Bayar

1. Browser kirim `POST /pos/process-payment`
2. Server simpan transaksi penjualan
3. Sistem otomatis buat jurnal akuntansi
4. Tanggal jurnal = tanggal transaksi
5. Sistem cek: apakah tanggal itu masuk periode tertutup?
6. Jika ya → jurnal ditolak (pesan: periode sudah ditutup)
7. (Dulu) error jurnal ikut membuat pembayaran gagal 422

### Pesan error yang sering muncul

> Periode agustus sudah ditutup. Buat jurnal pembalik pada periode terbuka.

Artinya: **jangan ubah buku yang sudah dikunci**. Koreksi dicatat di periode yang masih terbuka.

---

## 7. Contoh kasus (siap dijelaskan ke orang awam)

### Kasus A — Normal

- Periode Agustus: **open**
- Kasir jual 24 Agustus
- Hasil: transaksi sukses + jurnal 24 Agustus sukses

### Kasus B — Periode ditutup terlalu cepat

- Periode Agustus: **closed** (padahal masih tanggal 24)
- Kasir jual 24 Agustus
- Hasil (lama): pembayaran gagal karena jurnal ditolak
- Hasil (setelah perbaikan POS): penjualan tetap sukses; jurnal dipindah ke awal periode terbuka berikutnya (atau dilewati jika tidak ada periode open)

### Kasus C — Koreksi setelah tutup

- Agustus sudah closed
- Ada penjualan Agustus yang salah
- Cara benar: buat jurnal koreksi/pembalik di periode open (misalnya September), bukan membuka ulang Agustus

---

## 8. Best practice: penjualan harus sinkron dengan jurnal

Ada dua pilihan desain:

| Pilihan | POS | Jurnal | Sinkron? |
|---|---|---|---|
| Peringatan saja, bayar tetap jalan | Bisa jual | Bisa kosong | **Tidak** |
| **Blokir bayar sampai ada periode open** | Tidak bisa jual | Selalu ikut tercatat | **Ya** |

Yang dipakai di sistem ini: **blokir bayar**.

Alasannya sederhana: satu penjualan = satu jurnal dengan **tanggal yang sama**. Kalau kasir tetap bisa jual saat periode tertutup, riwayat POS dan buku akuntansi pecah.

Di layar kasir akan muncul peringatan, tombol bayar nonaktif, dan server menolak pembayaran sampai ada **periode terbuka yang mencakup hari ini**.

---

## 9. Setting awal (anggap awal bulan)

Satu periode = **satu bulan kalender**, mulai tanggal **1**, tidak tumpang tindih. Bulan berjalan **jangan ditutup**.

Contoh toko mulai Agustus 2026:

| Nama | Dari | Sampai | Status |
|---|---|---|---|
| Agustus 2026 | 2026-08-01 | 2026-08-31 | **Terbuka** |

Cukup itu. Jangan buat September di tengah Agustus. Jangan tutup Agustus sebelum 31 Agustus selesai.

Awal September:

1. Tutup **Agustus 2026**.
2. Buat **September 2026**: 2026-09-01 s.d. 2026-09-30, status **Terbuka**.

Pola seterusnya:

```
Tanggal 1          → periode bulan ini OPEN
Awal bulan berikutnya → tutup bulan kemarin, buka bulan baru
```

Yang **salah**:

```
agustus    01 Agu – 31 Agu   Ditutup
September  24 Agu – 30 Sep   Ditutup   ← mulai bukan tanggal 1, overlap, ditutup terlalu cepat
```

Yang **benar**:

```
Agustus 2026    01 Agu – 31 Agu   (tutup hanya setelah bulan selesai)
September 2026  01 Sep – 30 Sep   (open selama September)
```

---

## 10. Checklist singkat untuk admin/owner

Sebelum menutup periode:

- [ ] Semua penjualan sudah masuk
- [ ] Pembelian & biaya sudah dicatat
- [ ] Laporan bulan itu sudah dicek sekilas
- [ ] Periode bulan berikutnya sudah dibuat (tanggal 1 s.d. akhir bulan) dan **masih terbuka**

Jangan tutup periode jika:

- [ ] Masih ada transaksi di tanggal dalam rentang periode itu
- [ ] Masih ada koreksi yang belum selesai
- [ ] Bulan tersebut masih berjalan dan toko masih beroperasi

---

## 11. Ringkasan satu paragraf (untuk closing presentasi)

Buka/tutup periode menjaga laporan tetap rapi: selama periode terbuka, setiap penjualan POS dicatat ke jurnal pada tanggal yang sama. Ketika periode ditutup, sistem membuat jurnal penutup (memindahkan laba/rugi ke saldo laba) lalu mengunci tanggal itu. Setting yang benar adalah satu bulan kalender (tanggal 1 sampai akhir bulan), dan hanya bulan yang sudah selesai yang ditutup. Kalau bulan berjalan ditutup, kasir tidak bisa bayar — itu disengaja agar data penjualan tidak jalan sendiri tanpa jurnal.

---

## Glosarium singkat

| Istilah | Arti sederhana |
|---|---|
| Periode akuntansi | Rentang tanggal buku (misalnya 1–31 Agustus) |
| Jurnal | Catatan debit–kredit di buku besar |
| Jurnal penutup | Jurnal yang menolkan pendapatan/beban & memindahkan laba ke saldo laba |
| Jurnal pembalik / koreksi | Jurnal di periode open untuk memperbaiki kesalahan periode lama |
| entry_date | Tanggal jurnal |
| open / closed | Status periode: masih hidup / sudah dikunci |
