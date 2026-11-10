# Brief Konsep — Demo Video Kasir MaccaPOS

- **Proyek:** Video demo Product & SaaS untuk aplikasi kasir (MaccaPOS / kasirumkm.web.id)
- **File kerja:** `C:\Users\melis\Herd\umkm-kasir\motion\`
- **Skill:** `bang-motion` (jenis: Opener/Promo produk — resep `references/opener-konsep.md`)
- **Rasio:** 9:16 (1080×1920, vertikal / story)
- **Durasi:** 40 detik
- **Audio:** musik saja (tanpa voice-over)
- **Data:** semua data dashboard diganti **dummy positif** — setiap element terisi, tidak ada angka 0/kosong

---

## 1. Ringkasan produk (hasil observasi dashboard live)

Sumber: login ke `https://www.kasirumkm.web.id/` dan screenshot halaman dashboard.

- **Brand:** MaccaPOS — "Kasir Macca, Usaha Maju", maskot rubah oranye, warna utama ungu/violet
- **Akun demo:** toko "Toko Sejahtera", paket GrowUp, status Aktif
- **Widget dashboard:** sapaan "Halo! Siap jualan hari ini?", 4 kartu statistik (Omzet, Penjualan Hari Ini, Transaksi Hari Ini, Stok Rendah), Tren Penjualan 7 Hari, Jam Ramai Bulan Ini, Transaksi Terbaru, Produk Terlaris Bulan Ini (Top 5), Stok Rendah
- **Alur POS (yang direkam/dijelaskan di video):**
  1. Klik menu **POS** di sidebar (versi mobile: ikon **di tengah bawah**)
  2. Masukkan **PIN: 111222**
  3. Layar POS terbuka → pilih produk masuk keranjang
  4. Klik **Bayar** → pilih metode → proses **selesai** (struk/sukses)

---

## 2. Style Brief (Hukum #3 — gaya lahir dari brand)

**Tema & kata sifat:** cerah, ramah, cepat, meyakinkan — "usaha kecil yang tumbuh", bukan korporat kaku. Violet MaccaPOS + maskot rubah oranye sebagai wajah brand.

**3 kandidat konsep:**

| # | Konsep | Catatan |
|---|--------|---------|
| 1 | **#19 Tur produk berselang klaim** | Tiap fitur diledakkan jadi klaim singkat, diselingi UI hidup. **(DIPILIH)** |
| 2 | #16 Ekosistem UI hidup | Semua widget hidup serentak dalam satu tarikan kamera |
| 3 | #15 Klaim → cara → hasil | Alur naratif tiga babak |

**Konsep terpilih: #19 — Tur produk berselang klaim.** Paling cocok untuk 40 detik vertikal: ritme klaim → demo → klaim menjaga energi story/reels, dan tetap menyisakan babak UI hidup penuh (wajib untuk app/SaaS).

**Palet (dari brand MaccaPOS, hasil observasi live site):**

| Warna | Hex | Sumber |
|---|---|---|
| Ungu utama | `#7C3AED` | Sidebar & logo live site |
| Lavender latar | `#EFE9FB` | Area kerja dashboard |
| Kartu putih | `#FFFFFF` | Kartu dashboard |
| Oranye maskot | `#F97316` | Logo rubah |
| Hijau naik | `#16A34A` | Omzet / stok aman |
| Merah peringatan | `#DC2626` | Stok rendah / penekanan |

**Font:**
- Display: **Bricolage Grotesque** (600–800, sentence case) — berkarakter, ramah, bukan Poppins/Inter/Roboto/Montserrat
- Body/angka: **Plus Jakarta Sans** dengan `tabular-nums`

**Penekanan teks:** `color` + `pill` pada kata kunci ("gampang", "real-time", "selesai").

**Bahasa latar per segmen:**
1. Pembuka/penutup — lavender gradien lembut + grain
2. Babak POS & dashboard — ungu pekat, supaya kartu putih "terangkat"
3. Hasil — lavender terang kembali + logo & CTA

**Tanda tangan gerak:** setiap elemen data **berhitung naik** + kartu yang mendarat (skala 0.94→1, y 24→0, `power4.out` 0.8dtk) — identitas: *data tumbuh di depan mata*. Momen PIN: tombol pad menekan + digit terisi berurutan.

**Gerak latar (§7c):** gradien parallax bergerak lambat searah + grain statis; di segmen ungu, glow radial mengikuti fokus kamera.

**Permukaan latar (§7d):** gradien berbutir (grain SVG) — lavender terang di awal/akhir, ungu→indigo saat klaim/POS.

**Gaya render objek (§7e):** UI mockup flat dengan bayangan lembut, sudut 20–24px, tanpa skeuomorphism — meniru rasa app asli.

**Transisi:** potong keras (`cut`) antar klaim + satu tarikan kamera berkedalaman (`camTo`) dari POS ke dashboard lengkap — 2 jenis, ada yang berkedalaman, tanpa wipe.

**Satu momen istimewa:** setelah transaksi POS selesai, kamera menarik mundur → dashboard lengkap terisi data dummy positif serentak (batang grafik tumbuh, jam ramai menyala, transaksi terbaru mengalir masuk, Top 5 muncul) — "semuanya hidup dalam satu tarikan napas".

---

## 3. Rundown (REVISI — memuat alur POS)

| Detik | Adegan | Kalimat (di layar) | Visual |
|---|---|---|---|
| 0–3 | Pembuka | **Jualan jadi gampang** | Logo MaccaPOS muncul + maskot rubah menyapa; lavender gradien + grain |
| 3–7 | Klaim 1 | **Omzet naik tiap hari** | Kartu Omzet **Rp 4.875.000**, angka berhitung naik, badge hijau "↑ 23%" |
| 7–10 | Klaim 2 | **Semua transaksi tercatat** | Kartu Penjualan Hari Ini **128** · Transaksi **42** mendarat satu per satu |
| 10–14 | **Masuk POS** | **Buka kasir dalam sekejap** | Kursor klik menu **POS** di sidebar (di tengah bawah jika mobile) → panel PIN muncul |
| 14–17 | **PIN** | **Aman, pakai PIN** | Keypad PIN: digit **1-1-1-2-2-2** terisi berurutan (tombol menekan), centang hijau → POS terbuka |
| 17–24 | **Transaksi di POS** | **Pilih, bayar, selesai** | Pilih produk (Es Kopi Susu, Choco Latte…) → keranjang terisi + total berhitung naik (**Rp 87.000**) → klik **Bayar** → metode Tunai → tombol **Selesai** → animasi sukses/struk keluar |
| 24–29 | **Momen istimewa** | **Semua dalam satu layar** | `camWide` tarik mundur ke dashboard lengkap: Tren 7 Hari tumbuh (**Rp 8.240.000**), Jam Ramai menyala (puncak 12.00–14.00, **68 transaksi**), Transaksi Terbaru mengalir masuk, Produk Terlaris Top 5 muncul |
| 29–33 | Klaim 3 | **Stok aman, tanpa kehabisan** | Kartu Stok Rendah (3 produk) berdenyut merah lalu tenang jadi daftar "min. stok terpenuhi" |
| 33–36 | Hasil | **Fokus ke yang penting: jualan** | Maskot rubah + ikon kasir melayang masuk; latar berbalik ke lavender terang |
| 36–40 | Penutup | **MaccaPOS — Kasir Macca, Usaha Maju** | Logo + **badge "Tersedia di Google Play"** dengan ikon Play Store yang melayang masuk → CTA **"Download aplikasinya di Play Store"**; gradien grain |

---

## 4. Data dummy positif (semua element terisi)

| Element | Nilai dummy |
|---|---|
| Omzet (kartu) | Rp 4.875.000 · badge ↑ 23% |
| Penjualan hari ini | 128 |
| Transaksi hari ini | 42 |
| Tren Penjualan 7 Hari | Total Rp 8.240.000 · 512 transaksi · batang Sen–Ming naik-turun wajar |
| Jam Ramai Bulan Ini | Puncak 12.00–14.00 · 68 transaksi · Rp 1.904.000 |
| Transaksi Terbaru | 4 baris terisi (Es Kopi Susu, Choco Latte, Bubuk Red Velvet, dll.) |
| Produk Terlaris Top 5 | Es Kopi Susu, Choco Latte, Red Velvet Latte, Croissant, Pisang Goreng |
| Stok Rendah | 3 produk (peringatan fitur tetap tampil) |
| Keranjang POS (demo) | 3 item · Total Rp 87.000 · Tunai · Selesai |

---

## 5. Aset & langkah berikutnya

- **Aset referensi:** screenshot dashboard (hasil login live) → dipakai sebagai panduan rekonstruksi UI di video; data 100% dummy positif, bukan angka asli.
- **Starter:** scaffold dari `assets/starter-opener.html` (bukan starter explainer).
- **Setelah brief disetujui:** bangun `index.html` di folder `motion/` → verifikasi frame (`snap.mjs` / `?debug=1`) → serahkan satu file `index.html` yang bisa dibuka via `file://`.
