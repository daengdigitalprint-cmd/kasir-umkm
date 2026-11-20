# Naskah Voice-Over — Edukasi A12 "Bedah Menu #4: nasi kucing"

Episode keempat seri **Bedah Menu**, dibuat dengan kit video (`video.json` + `_kit/buat.py`). Formatnya mengikuti riset 18 November 2026 (swipe.md): harga murah + pembanding yang bikin heran ("padahal beras Rp13.700 sekilo"), angka modal di kalimat kedua, satu rahasia, harga pasar bertanggal.
Durasi akhir 67,7 detik, disinkronkan otomatis ke `vo.wav` lewat penanda `[...]` di `video.json` (96% kata cocok; batas adegan: 0 · 10,6 · 17,2 · 33,8 · 41,5 · 45,8 · 57,3 · 63,5). Rekaman sesuai naskah.

## Hook (0–3 detik)

> **"Nasi kucing tiga ribu. Modalnya cuma seribu lima ratus. Padahal beras sekarang tiga belas ribu tujuh ratus sekilo. Kok bisa?"**

## Naskah per adegan

| Adegan | Yang terlihat di layar | Kalimat yang dibacakan |
|---|---|---|
| 1 | Nasi kucing + Rp3.000, stempel modal Rp1.500, karung beras + Rp13.700/kg, tanda tanya | Nasi kucing tiga ribu. Modalnya cuma seribu lima ratus. Padahal beras sekarang tiga belas ribu tujuh ratus sekilo. Kok bisa? |
| 2 | BEDAH MENU #4 nasi kucing, kaca pembesar, label "harga Bapanas · Nov 2026" | Bedah Menu nomor empat. Kita hitung pakai harga beras Bapanas, November dua ribu dua puluh enam. |
| 3 | Struk "Modal 1 bungkus": Nasi (40 g beras) 550, Teri 5 g 400, Sambal 200, Kertas & karet 150, Gas & minyak 200 → Rp1.500 | Nasinya dari empat puluh gram beras, lima ratus lima puluh. Teri lima gram, empat ratus. Sambal dua ratus. Kertas dan karet seratus lima puluh. Gas dan minyak dua ratus. Total seribu lima ratus. |
| 4 | Karung beras 1 kg, 25 bungkus muncul, 25 × 3.000, Rp75.000 | Rahasianya di porsi. Sekilo beras jadi dua puluh lima bungkus. Dijual semua, tujuh puluh lima ribu. |
| 5 | Nasi kucing, stempel untung Rp1.500, lingkaran 50% | Untung sebungkus seribu lima ratus. Separuh dari harga jualnya. |
| 6 | Chip 2 nasi kucing, 2 gorengan, es teh; stempel −Rp1.000 salah hitung; kartu untung Rp1.500 → Rp500 | Tapi jualannya kecil-kecil. Pembeli ambil dua nasi, dua gorengan, es teh. Salah hitung seribu saja, dua pertiga untung sebungkus hilang. |
| 7 | Ponsel Keranjang: Nasi kucing ×2 Rp6.000, Gorengan ×2 Rp3.000, Es teh Rp3.000, Total naik ke Rp12.000 | Di Kasir UMKM, cukup ketuk tiap menu. Totalnya langsung terhitung, tanpa hitung di kepala. |
| 8 | **Ajakan follow:** kartu profil @kasir_umkm, "Ikuti" → "Mengikuti", maskot | Mau menu lain dibedah? Follow Kasir UMKM. |

## Hitungan dan sumber (⚑ dicek 18 Nov 2026)

| Bahan | Takaran | Harga acuan | Per bungkus |
|---|---|---|---|
| Beras medium | 40 g (±100 g nasi, sekepal) | Rp13.693/kg, Panel Harga Pangan Bapanas 12 Nov 2026 ([Katadata](https://databoks.katadata.co.id/ekonomi-makro/statistik/8240e3c33ab9300/harga-pangan-hari-ini-1211-di-indonesia-harga-telur-beras-dan-cabai-rawit-turun), [Antara](https://megapolitan.antaranews.com/berita/442428/harga-beras-medium-turun-jadi-rp13652-kg)) — dibulatkan Rp13.700 | Rp548 → **550** |
| Teri kering | 5 g | Rp80.543/kg, TEPAS Jawa Barat 24 Okt 2026 ([Katadata](https://databoks.katadata.co.id/pasar/statistik/dbef1c05731023a/harga-pangan-kategori-ikan-jawa-barat-hari-ini)) | Rp403 → **400** |
| Sambal | sesendok kecil | **perkiraan** (cabai, bawang, minyak) | **200** |
| Kertas nasi & karet | 1 set | **perkiraan** | **150** |
| Gas & minyak | — | **perkiraan** | **200** |
| **Modal** | | | **1.500** |

- Harga jual Rp3.000: kisaran Rp3.000–4.000 di Yogyakarta ([detikJogja](https://www.detik.com/jogja/kuliner/d-7088744/20-menu-angkringan-favorit-dan-sejarahnya-sebagai-ikon-jogja)), Rp2.000–3.000 di Tangerang ([Mojok](https://mojok.co/terminal/nasi-kucing-memang-makanan-murah-tapi-lebih-manusiawi-kan/)). Di Kendal April 2026 sudah Rp4.000–5.000 ([Mojok](https://mojok.co/terminal/angkringan-kendal-mahal-tak-lagi-merakyat-makan-jadi-cemas/amp/)).
- 1.000 g ÷ 40 g = **25 bungkus**; 25 × 3.000 = **Rp75.000** (dari beras Rp13.700).
- Untung 3.000 − 1.500 = **1.500**, separuh harga jual (50%).
- Salah hitung Rp1.000 = 1.000 ÷ 1.500 = **dua pertiga** untung sebungkus.
- Isi keranjang adalah contoh: es teh Rp3.000 (Kendal, Mojok April 2026), gorengan Rp1.500 per biji (pembanding yang dipakai kanal franz di riset swipe).

Istilah aplikasi: "Keranjang" dan "Total" dari panel keranjang kasir (`resources/js/pages/POS/Index.vue`).

## Naskah utuh (untuk dibaca sekali jalan)

> Nasi kucing tiga ribu. Modalnya cuma seribu lima ratus. Padahal beras sekarang tiga belas ribu tujuh ratus sekilo. Kok bisa?
>
> Bedah Menu nomor empat. Kita hitung pakai harga beras Bapanas, November dua ribu dua puluh enam.
>
> Nasinya dari empat puluh gram beras, lima ratus lima puluh. Teri lima gram, empat ratus. Sambal dua ratus. Kertas dan karet seratus lima puluh. Gas dan minyak dua ratus. Total seribu lima ratus.
>
> Rahasianya di porsi. Sekilo beras jadi dua puluh lima bungkus. Dijual semua, tujuh puluh lima ribu.
>
> Untung sebungkus seribu lima ratus. Separuh dari harga jualnya.
>
> Tapi jualannya kecil-kecil. Pembeli ambil dua nasi, dua gorengan, es teh. Salah hitung seribu saja, dua pertiga untung sebungkus hilang.
>
> Di Kasir UMKM, cukup ketuk tiap menu. Totalnya langsung terhitung, tanpa hitung di kepala.
>
> Mau menu lain dibedah? Follow Kasir UMKM.

Rekam atau generate dengan suara pilihanmu, jeda sekitar satu detik antarparagraf, lalu kirim filenya.
