# Kit video — satu file data per video

Video kerangka v2 dibuat dari **`video.json`**. Kode animasinya ada di pustaka komponen: `komponen.js` untuk adegan dan `komponen.css` untuk gaya (gabungan A9–A11). Sinkron ke VO dikerjakan otomatis oleh `sinkron.py`.

## Alur

Semua perintah dijalankan dari folder `video-konten`:

```
python _kit/buat.py baru edukasi-A12-nama        # folder baru: aset + video.json contoh (A11)
python _kit/buat.py edukasi-A12-nama --cek       # rakit + lembar cek 1 potret per adegan (_sheet.png)
python _kit/buat.py edukasi-A12-nama --render    # MP4 (tanpa VO: preview-tanpa-suara.mp4)
```

Setelah VO masuk, simpan rekamannya sebagai `vo.wav` di folder video, lalu jalankan `--cek` atau `--render` lagi. Langkah sinkronnya:

- Whisper mentranskrip rekaman satu kali saja. Hasilnya disimpan di `_kata.json`.
- Kata di rekaman dicocokkan dengan naskah. Angka seperti "6.800" dieja dulu jadi "enam ribu delapan ratus" supaya bisa dicocokkan.
- Batas adegan dan semua penanda waktu lalu dihitung ulang.

Kalau penanda di naskah dipindah, jalankan perintahnya lagi. Rekaman tidak perlu ditranskrip ulang.

Jangan mengedit `_scenes.js`, `_extra.css`, atau `index.html`. Ketiganya dibuat ulang setiap kali perintah dijalankan.

## video.json

```json
{
  "judul": "Bedah Menu #3: es kopi keliling",
  "brief": "catatan singkat isi video",
  "ekor": 1.1,
  "css": ".stag{...} /* opsional, gaya khusus video ini */",
  "adegan": [
    {
      "bg": "dark",
      "vo": "Es kopi keliling delapan ribu. Modalnya [modal]enam ribu delapan ratus.",
      "isi": [ {"jenis": "stempel", "besar": "Rp6.800", "kecil": "modal segelas", "at": "@modal"} ],
      "cap": [ {"kecil": "keliling Rp8.000, modalnya", "kunci": "rp6.800", "kw": "@modal"} ]
    }
  ]
}
```

Kolom-kolomnya:

- **`bg`**: latar adegan. Pilihannya `dark`, `cream`, `orange`, `green`, atau `purple`.
- **`vo`**: kalimat naskah untuk adegan itu. Tulis `[nama]` tepat sebelum kata yang jadi ketukan animasi.
- **`ekor`**: lama video tetap berjalan setelah kata terakhir, dalam detik. Bawaannya 1,1.
- **`css`**: opsional, gaya tambahan khusus video ini.

Waktu elemen ditulis dengan salah satu bentuk ini:

- **Angka**: detik sejak awal adegan, misalnya `0.55`.
- **`"@nama"`**: tepat di kata yang diberi penanda `[nama]`.
- **`"@nama+0.3"`** atau **`"@nama-0.2"`**: sedikit sesudah atau sebelum kata itu.
- **`"@3:nama"`**: memakai penanda dari adegan lain (di sini adegan nomor 3).

Kolom waktu: `at`, `hilang`, `kw`, `keluar`, `coret`, `sorot`, `ubahAt`, `ketuk`, dan kolom ketiga di baris struk.

Semua elemen bisa diberi `"kelas"` (kelas CSS tambahan) dan `"hilang"` (waktu elemen memudar).

## Jenis elemen

| jenis | isi | contoh pakai |
|---|---|---|
| `objek` | `svg` (kopi, kopi-kafe, susu, gerobak, mie, cabai, telur, nasi-kucing, beras, gorengan, sampah), `x y w`, `rot`, `goyang` (0 = diam) | gelas, mangkuk |
| `label` | `teks x y`, `rot`, `ukuran` | label harga Rp8.000 |
| `stempel` | `besar`, `kecil`, `kecilAtas`, `y`, `getar` (bawaan true), `miring` | modal Rp6.800, +Rp1,2 juta |
| `struk` | `judul`, `baris: [[nama, nilai, waktu]]`, `total: {label, nilai, at}` | rincian modal |
| `batang` | `baris: [[nama, nilai, true=disorot]]`, `sorot` (waktu denyut) | biaya terbesar |
| `chip` | `teks x y`, `coret` (waktu dicoret) | sewa, AC, kursi |
| `tanya` | `x y`, `teks` | "?" |
| `seri` | `judul` (bawaan BEDAH MENU), `sub` | judul seri |
| `kaca` | — | kaca pembesar menyapu |
| `pil` | `teks y` | "harga eceran 2026" |
| `grid` | `svg n kolom x0 y0 dx dy w`, `hitung` ("gelas"), `hitungKelas`, `hitungHilang` | 84 gelas |
| `persamaan` | `bagian: ["84","×","1.200"]`, `jarak` | hitungan besar |
| `angka` | `ke`, `awalan` (Rp), `akhiran`, `bulat` | Rp100.800/hari |
| `kartu` | `judul dari ke ubahAt catatan`, `y` | untung 1.200 → 900 |
| `bulat` | `teks x y` | −25% |
| `ponsel` | `judul sub baris[[a,b,at?]] sorot{baris,badge,at} ringkasan[[label,akhiran]] dari ke ubahAt` atau `langkah[[at,nilai]]` | Master Recipe, keranjang kasir |
| `rak` | `svg` (bawaan susu), `y w jarak x0`, `isi` [teks…] depan→belakang, `masuk` [[teks, waktu]], `dari` (depan/belakang), `ambil` [waktu…] (barang terdepan terjual), `basi` {at, teks} (barang paling belakang dicap), `panah` {teks, at} | rak susu FIFO (D1) |
| `follow` | `ketuk`, `bio` | penutup ajakan follow |
| `getar` | `at` | guncang layar |
| `kode` | `js` (dijalankan dengan `S`, `t`) | animasi khusus yang belum ada komponennya |

Teks layar (`cap`) punya kolom `kecil` (baris kecil), `kunci` (label besar), `at` (muncul), `kw` (label besar tercetak), dan `keluar` (opsional).

## Menambah komponen

- **Objek baru**: tambahkan SVG di objek `SVG` di `komponen.js`.
- **Jenis elemen baru**: tambahkan fungsi di objek `K`, lalu gayanya di `komponen.css`.
- **Pakai sekali saja**: cukup tulis `{"jenis": "kode", "js": "..."}` di video.json.

`contoh-A11.json` adalah A11 lengkap. Isinya juga jadi `video.json` awal setiap kali memakai perintah `baru`.
