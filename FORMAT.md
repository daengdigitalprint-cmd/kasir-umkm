# Panduan Alur Produk, Resep & Produksi

## Konsep 3 Tingkat

Sistem ini punya 3 tingkat produk yang saling terhubung:

```
TINGKAT 1: BAHAN BAKU (Raw Material)
    Contoh: Bubuk Teh, Gula Pasir, Air, Es
    Tersedia di: Produk → Daftar Produk (tipe: Bahan Mentah)
                    │
                    ▼  (dimasukkan ke dalam resep sebagai bahan)
                    
TINGKAT 2: PRODUK HASIL PRODUKSI (Intermediate / Semi-Finish)
    Contoh: Biang Teh Original 5L (hasil produksi dari bahan baku)
    Dibuat lewat: Produksi → Buat Batch Produksi
    Punya resep sendiri yang bahan bakunya tingkat 1
                    │
                    ▼  (dimasukkan ke dalam resep produk jual sebagai bahan)
                    
TINGKAT 3: PRODUK JUAL (Finished Goods / Final)
    Contoh: Teh Ori Besar, Teh Lemon Kecil, Coklat Macciato
    Dijual lewat: POS
    Punya resep sendiri yang bahan bakunya tingkat 2
```

---

## Contoh Kasus: Teh

### Langkah 1: Buat Bahan Baku (Tingkat 1)

Produk-produk yang sudah ada sebagai bahan mentah:
- Bubuk Teh 120 gr + Air + Es
- Gula Pasir
- Cream Macciato
- Bubuk Greentea
- Bubuk Lemon, dll.

> Bahan baku ini stok-nya diinput atau dibeli lewat menu **Pembelian**.

### Langkah 2: Buat Resep Biang Teh (Tingkat 2)

Buat resep di `/recipes/create`:

| Field | Nilai |
|-------|-------|
| Nama | Biang Teh Original 5L |
| Kode | BT-001 |
| Tipe | Hasil Produksi |
| Produk | Biang Teh Original 5L (dibuat dulu sebagai produk di `/products/create`) |
| Output Qty | 20 |
| Output Satuan | liter |

**Bahan / Ingredients:**
| Bahan | Jumlah |
|-------|--------|
| Bubuk Teh 120 gr + Air + Es | 1 |
| Gula Pasir | 500 |

> Output: 1 kali produksi menghasilkan 20 liter Biang Teh Original 5L

### Langkah 3: Buat Resep Produk Jual (Tingkat 3)

Untuk setiap varian rasa dan ukuran, buat resep terpisah:

**Contoh: Teh Ori Besar**
| Field | Nilai |
|-------|-------|
| Nama | Teh Ori Besar |
| Kode | TO-BESAR |
| Tipe | Hasil Produksi |
| Produk | Teh Ori Besar |
| Output Qty | 1 |
| Output Satuan | cup |

**Bahan:**
| Bahan | Jumlah |
|-------|--------|
| Biang Teh Original 5L | 0.5 |

**Contoh: Teh Ori Kecil**
| Field | Nilai |
|-------|-------|
| Nama | Teh Ori Kecil |
| Kode | TO-KECIL |
| Tipe | Hasil Produksi |
| Produk | Teh Ori Kecil |
| Output Qty | 1 |
| Output Satuan | cup |

**Bahan:**
| Bahan | Jumlah |
|-------|--------|
| Biang Teh Original 5L | 0.25 |

**Contoh: Teh Lemon Besar**
| Field | Nilai |
|-------|-------|
| Nama | Teh Lemon Besar |
| Kode | TL-BESAR |
| Tipe | Hasil Produksi |
| Produk | Teh Lemon Besar |
| Output Qty | 1 |
| Output Satuan | cup |

**Bahan:**
| Bahan | Jumlah |
|-------|--------|
| Biang Teh Original 5L | 0.5 |
| Bubuk Lemon | 0.1 |

---

## Alur Produksi di Sistem

### 1. Produksi Bahan Setengah Jadi (Biang Teh)

```
Menu: Produksi → Buat Batch Produksi (tombol "+ Buat Batch Baru")
  - Pilih recipe: "Biang Teh Original 5L"
  - Multiplier: 5 (menghasilkan 100 liter)
```

**Yang terjadi:**
- Stok Bubuk Teh berkurang (1 × 5 = 5 pack)
- Stok Gula Pasir berkurang (500 × 5 = 2500 gr)
- Stok Biang Teh Original 5L **bertambah** (20 × 5 = 100 liter)

### 2. Produksi Produk Jual (Teh Ori, Teh Lemon, dll)

```
Menu: Produksi → Buat Batch Produksi
  - Pilih recipe: "Teh Ori Besar"
  - Multiplier: 10 (menghasilkan 10 cup)
```

**Yang terjadi:**
- Stok Biang Teh Original 5L berkurang (0.5 × 10 = 5 liter)
- Stok Teh Ori Besar **bertambah** 10 cup

> Ulangi untuk setiap varian (Teh Lemon, Coklat Macciato, dll.)

### 3. Jual ke Pelanggan (POS)

```
Menu: POS
  - Pilih "Teh Ori Besar"
  - Tambah ke keranjang
  - Bayar
```

**Yang terjadi:**
- Stok Teh Ori Besar berkurang 1 cup
- Uang masuk ke kasir

---

## Ringkasan Pergerakan Stok

```
Bahan Baku (Bubuk Teh, Gula, dll)
    │
    │  Produksi batch "Biang Teh"
    │  ↓ berkurang
    │
Biang Teh Original 5L
    │
    │  Produksi batch "Teh Ori"
    │  ↓ berkurang
    │
Produk Jual (Teh Ori Besar)
    │
    │  Jual di POS
    │  ↓ berkurang
    │
Selesai → stok = 0, uang masuk
```

Semua pergerakan stok terjadi **otomatis** saat:
- Batch produksi diselesaikan (stok bahan turun, stok hasil naik)
- Transaksi POS (stok produk jual turun)

Tidak perlu input manual stok untuk setiap langkah.

---

## Tips

- **Satuan harus konsisten.** Kalau resep Biang Teh output-nya dalam liter, maka resep produk jual yang pakai Biang Teh harus pakai satuan liter juga untuk bahan-nya.
- **Multiplier** memudahkan produksi massal. Kalau butuh 100 cup Teh Ori, cukup buat 1 batch dengan multiplier 10.
- **Cek stok sebelum produksi.** Form produksi akan menampilkan estimasi kebutuhan bahan dan stok yang tersedia. Kalau stok kurang, produksi tidak bisa dilakukan.
- **Resep tidak harus berlapis.** Untuk produk sederhana, bisa langsung pakai bahan baku sebagai bahan resep tanpa perlu tingkat 2.
