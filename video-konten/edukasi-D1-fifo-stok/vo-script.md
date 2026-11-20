# Naskah Voice-Over — Edukasi D1 "Tips Stok #1: FIFO"

Video pertama seri **Tips Stok**, dibuat dengan kit video dan komponen baru **rak**: kotak susu bertanggal ED di rak dengan sisi DEPAN dan BELAKANG. Rak muncul dua kali. Pertama cara yang salah, yaitu barang baru ditaruh di depan sehingga yang lama terdorong ke belakang sampai lewat ED. Kedua cara FIFO, yaitu barang baru di belakang sehingga yang lama laku duluan. Ditutup soft selling satu kalimat dan ajakan follow.

Durasi akhir 58,3 detik, disinkronkan otomatis ke `vo.wav` (57,4 detik) lewat penanda `[...]` di `video.json` (98% kata cocok; batas adegan: 0 · 7,6 · 13,2 · 23,8 · 31,5 · 40,5 · 47,0 · 54,2). Rekaman sesuai naskah.

Catatan sinkron: pada transkripsi pertama whisper melewatkan 10 detik awal dan menulis "Setahun tiga juta lebih" dua kali, sehingga hanya 85% kata yang cocok. Potongan 11 detik awal ditranskrip ulang lalu digabung ke `_kata.json`, dan duplikatnya dibuang. Kalau `vo.wav` diganti, `_kata.json` dibuat ulang otomatis, jadi periksa lagi angka kecocokannya. "FIFO" di rekaman terbaca "vivo", tapi waktunya tetap tepat.

## Hook (0–3 detik)

> **"Cuma tiga kotak susu basi seminggu. Tapi setahun, tiga juta rupiah masuk tempat sampah."**

## Naskah per adegan

| Adegan | Yang terlihat di layar | Kalimat yang dibacakan |
|---|---|---|
| 1 | Tiga kotak susu + label "basi", masuk tempat sampah, stempel "setahun dibuang Rp3 juta" | Cuma tiga kotak susu basi seminggu. Tapi setahun, tiga juta rupiah masuk tempat sampah. |
| 2 | TIPS STOK #1 FIFO, chip "belanja kebanyakan?" dicoret, pil "cara naruh di rak" | Padahal belanjanya nggak kebanyakan. Masalahnya ada di cara naruh di rak. |
| 3 | **Rak (salah):** ED 22 Nov lalu ED 25 Nov masuk dari depan, dua yang baru terjual, ED 12 Nov di belakang dicap LEWAT ED | Susu baru datang, ditaruh di depan. Datang lagi, di depan lagi. Yang laku selalu yang baru. Yang lama makin ke belakang, sampai lewat tanggal. |
| 4 | 3 kotak × 21.000 → sebulan Rp252.000 → setahun Rp3.024.000 | Tiga kotak seminggu, dua puluh satu ribu sekotak. Sebulan dua ratus lima puluh dua ribu. Setahun tiga juta lebih. |
| 5 | **Rak (FIFO):** ED 22 Nov masuk dari belakang, ED 12 Nov di depan terjual, stempel FIFO "masuk duluan, keluar duluan" | Caranya FIFO: masuk duluan, keluar duluan. Barang baru taruh di belakang. Yang lama geser ke depan, laku duluan. |
| 6 | Kotak susu, chip "tulis tanggal datang" dan "cek ED tiap belanja" | Dua kebiasaan kecil: tulis tanggal datang pakai spidol, dan cek tanggal kedaluwarsa tiap belanjaan datang. |
| 7 | **Soft selling:** ponsel "Batch mendekati ED", Susu UHT 1L, tiga batch; batch ED 12 Nov diberi lencana "dijual duluan"; label "Tips Stok · Kasir UMKM" | Di Kasir UMKM, isi ED tiap batch saat catat pembelian. Kasir otomatis jual yang paling dekat kedaluwarsa. |
| 8 | **Ajakan follow:** kartu profil @kasir_umkm, "Ikuti" → "Mengikuti", maskot | Mau tips stok lainnya? Follow Kasir UMKM. |

Catatan baca: "FIFO" dibaca "fai-fo" atau "fi-fo", pilih salah satu dan konsisten. Penanda sinkron ada di kata "FIFO" itu sendiri.

## Hitungan dan sumber

Contoh kedai kopi yang memakai susu UHT 1 liter. Angka 3 kotak basi seminggu adalah **contoh**, bukan data rata-rata.

- Harga susu UHT 1 L **Rp21.000**: sama dengan acuan A11 (Ultra Milk 1 L, listing Lazada; grosir Indotrading Rp26.000, Feb 2026). Memakai harga termurah supaya kerugiannya tidak dilebih-lebihkan.
- 3 × 21.000 = **Rp63.000/minggu**; × 4 minggu = **Rp252.000/bulan**; × 12 bulan = **Rp3.024.000/tahun** (dibaca "tiga juta lebih").
- FIFO (*first in, first out*) adalah istilah umum manajemen persediaan. Untuk barang yang punya tanggal kedaluwarsa, yang dijual duluan adalah yang ED-nya paling dekat (FEFO). Di contoh ini barang yang datang duluan juga yang ED-nya paling dekat, jadi keduanya sama.

## Kaitan aplikasi (dicek di kode 19 Nov 2026)

- **Pembelian**: bila pelacakan batch aktif (Pengaturan toko), tabel item punya kolom **Kode batch** dan **ED** (`resources/js/pages/Purchases/Create.vue`).
- **Kasir**: stok batch dipotong berurutan dari ED terdekat, baru kemudian batch tanpa ED (`POSController`, `orderBy('expiry_date')`). Ini dasar kalimat "kasir otomatis jual yang paling dekat kedaluwarsa".
- **Dasbor**: kartu **Batch mendekati ED** menampilkan batch yang ED-nya dalam N hari (pengaturan **Peringatan ED (hari)**, bawaan 30) atau sudah lewat.
- Fitur ini hanya berjalan bila pelacakan batch diaktifkan. Naskah tidak menyebutnya supaya tetap satu kalimat, jadi sebaiknya ditulis di caption.
