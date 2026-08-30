# Rekomendasi Desain UI: Modul Scan Meja untuk Pelanggan

> **Dokumen ini adalah rekomendasi desain — bukan instruksi implementasi.**
> Target: Mobile-first (360px–640px) + Tablet (768px–1024px)
> Berdasarkan identitas visual yang sudah ada di proyek UMKM Kasir.

---

## Daftar Isi

1. [Prinsip Desain](#1-prinsip-desain)
2. [Design Tokens (Konsisten dengan yang Ada)](#2-design-tokens-konsisten-dengan-yang-ada)
3. [Struktur Halaman & Alur Mobile](#3-struktur-halaman--alur-mobile)
4. [Spesifikasi Halaman Per Layar](#4-spesifikasi-halaman-per-layar)
   - [4.1 Halaman Menu Publik](#41-halaman-menu-publik)
   - [4.2 Detail Produk](#42-detail-produk)
   - [4.3 Keranjang](#43-keranjang)
   - [4.4 Checkout & Pembayaran](#44-checkout--pembayaran)
   - [4.5 Konfirmasi Pesanan](#45-konfirmasi-pesanan)
   - [4.6 Manajemen Meja (Admin)](#46-manajemen-meja-admin)
5. [Breakpoint Responsif](#5-breakpoint-responsif)
6. [Optimasi Interaksi Sentuh](#6-optimasi-interaksi-sentuh)
7. [Navigasi per Ukuran Layar](#7-navigasi-per-ukuran-layar)
8. [Prioritas Konten (Mobile-First)](#8-prioritas-konten-mobile-first)
9. [Kriteria Keberhasilan (Success Criteria)](#9-kriteria-keberhasilan-success-criteria)
10. [Mockup Struktur Layout](#10-mockup-struktur-layout)

---

## 1. Prinsip Desain

### 1.1. Core Principles

| Prinsip | Penerapan |
|---|---|
| **Mobile-first** | Desain dimulai dari layar 360px, lalu diperkaya untuk tablet |
| **Thumb-friendly** | Semua interaksi utama dalam zona jangkauan thumb (bagian tengah-bawah layar) |
| **Satu tujuan per layar** | Setiap halaman punya 1 primary action yang jelas |
| **Konsistensi visual** | Mengikuti design tokens yang sudah ada (warna, font, radius, shadow) |
| **Progress feedback** | Setiap aksi pelanggan memberikan feedback visual segera |
| **Guest-first** | Tidak perlu login — desain untuk pengguna anonim |

### 1.2. Mental Model

Desain ini mengikuti mental model **e-commerce mobile** yang sudah dikenal pelanggan:
- Lihat produk → Tambah ke keranjang → Checkout → Bayar → Selesai

Bukan model POS yang kompleks.

---

## 2. Design Tokens (Konsisten dengan yang Ada)

### 2.1. Warna

Semua warna menggunakan token dari `resources/css/app.css` yang sudah ada — **tidak boleh ada warna baru di luar palet ini**.

| Token | Value | Penggunaan |
|---|---|---|
| `--color-primary` | `#7C3AED` (violet-600) | Tombol utama, active tab, link, harga |
| `--color-primary-foreground` | `#FFFFFF` | Teks di tombol primary |
| `--color-secondary` | `#F5F3FF` | Background kategori terpilih, badge |
| `--color-background` | `#F8FAFC` | Background halaman |
| `--color-card` | `#FFFFFF` | Kartu produk, keranjang, card |
| `--color-foreground` | `#0F172A` | Teks utama |
| `--color-muted-foreground` | `#64748B` | Teks sekunder, deskripsi, harga coret |
| `--color-border` | `#E2E8F0` | Garis pemisah, border card |
| `--color-destructive` | `#EF4444` | Hapus item, error |
| `--color-accent` | `rgba(124,58,237,0.1)` | Hover state, highlight |
| `--color-success` | `#10B981` | Status sukses, pesanan siap (tidak ada token? Pakai `text-emerald-500`) |

> **Catatan**: Tidak ada token `--color-success` di design system. Untuk status sukses/hijau, gunakan `text-emerald-500` atau `bg-emerald-500` sesuai utility Tailwind yang sudah ada di project.

### 2.2. Tipografi

| Level | Font | Size | Weight | Tracking |
|---|---|---|---|---|
| **H1 (halaman)** | `font-display` (Plus Jakarta Sans) | `text-2xl` (24px) → `lg:text-3xl` (30px) | `font-bold` | `tracking-tight` |
| **H2 (section)** | `font-display` | `text-xl` (20px) | `font-bold` | `tracking-tight` |
| **H3 (produk)** | `font-sans` | `text-base` (16px) | `font-semibold` | normal |
| **Body** | `font-sans` | `text-sm` (14px) — `text-base` (16px) | `font-normal` | normal |
| **Caption** | `font-sans` | `text-xs` (12px) | `font-normal` | normal |
| **Meta / Label** | `font-sans` | `text-[10px]` | `font-black` | `uppercase tracking-widest` |
| **Harga** | `font-sans` | `text-lg` (18px) | `font-bold` | normal |

### 2.3. Radius & Shadow

| Elemen | Token | Value |
|---|---|---|
| **Card produk** | `rounded-2xl` | 1.5rem |
| **Card section** | `rounded-3xl` | 2rem |
| **Tombol** | `rounded-xl` | 1.25rem |
| **Input** | `rounded-xl` | 1.25rem |
| **Modal / Sheet** | `rounded-3xl` | 2rem |
| **Card shadow** | `shadow-sm` + `shadow-slate-200/50` | — |
| **Floating element** | `shadow-lg` | — |

### 2.4. Ikon

Gunakan **lucide-vue-next** — konsisten dengan seluruh UI yang sudah ada.

### 2.5. Glass Effect

Untuk header sticky atau elemen overlay, gunakan class `.glass` yang sudah ada:
```css
.glass { bg-white/70 backdrop-blur-xl border-white/30 }
```

---

## 3. Struktur Halaman & Alur Mobile

### 3.1. Sitemap (Guest Flow)

```
Scan QR Code
    │
    ▼
┌──────────────────────────────────────────────┐
│           Halaman Menu Publik                 │
│  ┌─ Kategori (horizontal scroll) ──────────┐ │
│  │ [Semua] [Makanan] [Minuman] [Snack]     │ │
│  └─────────────────────────────────────────┘ │
│  ┌─ Grid/Card Produk ──────────────────────┐ │
│  │  ┌──────┐ ┌──────┐ ┌──────┐            │ │
│  │  │Produk│ │Produk│ │Produk│            │ │
│  │  │  A   │ │  B   │ │  C   │            │ │
│  │  └──────┘ └──────┘ └──────┘            │ │
│  └─────────────────────────────────────────┘ │
│              [Floating Cart Button]           │
└──────────────────────┬───────────────────────┘
                       │ Tap produk
                       ▼
┌──────────────────────────────────────────────┐
│           Detail Produk (Modal/Screen)        │
│  [Gambar] [Nama] [Harga] [Deskripsi]         │
│  [Variant] [Modifier Options]                 │
│  [Quantity Stepper]                           │
│  ┌────────────────────────────────────────┐   │
│  │  [+] Tambah ke Keranjang              │   │
│  └────────────────────────────────────────┘   │
└──────────────────────┬───────────────────────┘
                       │ Tap ikon keranjang
                       ▼
┌──────────────────────────────────────────────┐
│           Keranjang (Drawer/Halaman)          │
│  ┌─ Item List ─────────────────────────────┐ │
│  │ [Item A] [-] 2 [+] Rp20.000  [Hapus]   │ │
│  │ [Item B] [-] 1 [+] Rp15.000  [Hapus]   │ │
│  └─────────────────────────────────────────┘ │
│  [Catatan untuk pesanan]                     │
│  [Nama Pelanggan (opsional)]                  │
│  ─────────────────────────────────────       │
│  Subtotal                        Rp35.000    │
│  ┌────────────────────────────────────────┐   │
│  │  Pesan Sekarang                       │   │
│  └────────────────────────────────────────┘   │
└──────────────────────┬───────────────────────┘
                       │ Tap "Pesan Sekarang"
                       ▼
┌──────────────────────────────────────────────┐
│           Pilih Metode Pembayaran             │
│  ┌───────┐  ┌───────┐  ┌───────┐            │
│  │ QRIS  │  │ Bayar │  │ Bayar │            │
│  │Mandiri│  │Di Kasir│  │Nanti  │            │
│  └───────┘  └───────┘  └───────┘            │
│  ┌────────────────────────────────────────┐   │
│  │  Konfirmasi Pesanan                   │   │
│  └────────────────────────────────────────┘   │
└──────────────────────┬───────────────────────┘
                       │ Setelah bayar / konfirmasi
                       ▼
┌──────────────────────────────────────────────┐
│           Konfirmasi Pesanan                  │
│  ✅ Pesanan Berhasil!                         │
│  Nomor: #024                                 │
│  Status: Menunggu Diproses                    │
│  ┌────────────────────────────────────────┐   │
│  │  Lihat Status Pesanan                 │   │
│  └────────────────────────────────────────┘   │
│  ┌────────────────────────────────────────┐   │
│  │  Pesan Lagi                            │   │
│  └────────────────────────────────────────┘   │
└──────────────────────────────────────────────┘
```

---

## 4. Spesifikasi Halaman Per Layar

### 4.1. Halaman Menu Publik

#### 4.1.1. Tata Letak Mobile (360px–640px)

```
┌────────────────────────────────┐ ← .glass, sticky top-0, z-20
│  Logo Outlet    [Cari] [🛒 3] │ ← Header dengan ikon keranjang + badge
├────────────────────────────────┤
│ ◄ Makanan │ Minuman │ Snack ► │ ← Horizontal scroll, tab aktif primary
├────────────────────────────────┤
│ ┌──────┐ ┌──────┐             │
│ │Foto  │ │Foto  │             │ ← 2 kolom grid, gap-3
│ │Nama  │ │Nama  │             │
│ │Harga │ │Harga │             │ ← ProdukCard
│ │[+ T] │ │[+ T] │             │ ← Tombol tambah floating di card
│ └──────┘ └──────┘             │
│ ┌──────┐ ┌──────┐             │
│ │Foto  │ │Foto  │             │
│ │...   │ │...   │             │
│ └──────┘ └──────┘             │
│ ...                            │
├────────────────────────────────┤
│  ┌──────────────┐  ┌────────┐ │ ← Floating cart summary (fixed bottom)
│  │ 3 item · Rp45k│  │ Lihat  │ │
│  └──────────────┘  └────────┘ │
└────────────────────────────────┘
```

#### 4.1.2. Tata Letak Tablet (768px–1024px)

```
┌──────────────────────────────────────────────┐
│  Logo Outlet              [Cari]    [🛒 3]   │
├──────────────────────────────────────────────┤
│  Makanan │ Minuman │ Snack │ Semua            │ ← Tab penuh (tidak scroll)
├──────────────────────────────────────────────┤
│ ┌──────┐ ┌──────┐ ┌──────┐                   │
│ │Foto  │ │Foto  │ │Foto  │                   │ ← 3 kolom grid
│ │Nama  │ │Nama  │ │Nama  │                   │
│ │Harga │ │Harga │ │Harga │                   │
│ │[+ T] │ │[+ T] │ │[+ T] │                   │
│ └──────┘ └──────┘ └──────┘                   │
│ ┌──────┐ ┌──────┐ ┌──────┐                   │
│ │...   │ │...   │ │...   │                   │
│ └──────┘ └──────┘ └──────┘                   │
└──────────────────────────────────────────────┘
```

#### 4.1.3. Spesifikasi Komponen

**Header:**
- `.glass` sticky top-0, z-20
- Logo outlet (nama toko) — kiri
- Ikon pencarian (lucide `Search`) — kanan
- Ikon keranjang (lucide `ShoppingCart`) — kanan, dengan badge jumlah item (`.bg-primary text-white text-[10px] font-bold rounded-full w-5 h-5`)

**Category Tabs:**
- Horizontal scroll (`.overflow-x-auto .flex .gap-2 .scrollbar-hide`)
- Chip-style tab: `.px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors`
- Active: `.bg-primary text-primary-foreground`
- Inactive: `.bg-secondary text-secondary-foreground`
- Padding: `px-2` (total scrollable area)

**Product Card:**
- **Mobile**: 2 kolom, `gap-3`, `rounded-2xl border shadow-sm`
- **Tablet**: 3 kolom, `gap-4`
- **Image**: Aspect ratio 1:1 (square), `rounded-xl` (inherited), `bg-slate-100` placeholder
- **Content**: Nama (`.text-sm font-semibold`), Harga (`.text-base font-bold text-primary`), Tombol tambah (`.w-8 h-8 rounded-full bg-primary text-white`)
- **Card padding**: `p-3`

**Floating Cart Summary (Mobile only):**
- Fixed bottom, `z-30`, `p-4` (safe area padding)
- Card: `.rounded-2xl bg-white shadow-lg border`
- Menampilkan: jumlah item + total harga
- Tombol "Lihat": `.btn-premium rounded-xl bg-primary text-primary-foreground`

**Empty State (saat tidak ada produk):**
- Ilustrasi: ikon lucide `UtensilsCrossed` ukuran `size-16`
- Teks: "Belum ada menu tersedia"
- Subteks: "Silakan cek kembali nanti"

### 4.2. Detail Produk

#### 4.2.1. Mobile (Sheet dari bawah)

```
┌────────────────────────────────┐
│  ┌──────────────────────────┐  │ ← Drag handle (bar horizontal)
│  │                          │  │    (w-10 h-1 bg-slate-300 rounded-full)
│  └──────────────────────────┘  │
│  ┌──────────────────────────┐  │
│  │       Gambar Produk       │  │ ← aspect-video rounded-xl bg-slate-100
│  │       (aspect video)      │  │
│  └──────────────────────────┘  │
│                                │
│  Nama Produk                   │ ← text-xl font-bold
│  Rp 25.000                     │ ← text-lg font-bold text-primary
│  ─────────────────────         │ ← border-t
│  Deskripsi                     │
│  Deskripsi singkat produk...   │ ← text-sm text-muted-foreground
│                                │
│  Varian (jika ada)             │ ← text-sm font-semibold
│  [Pilih] ──── ▼                │ ← Select button
│                                │
│  Tambahan (opsional)           │ ← text-sm font-semibold
│  ☐ Extra cheese  +5.000       │ ← Checkbox item
│  ☐ Sambal        +2.000       │
│  ☐ Es batu       Gratis       │
│                                │
│  Catatan                        │
│  ┌──────────────────────────┐  │
│  │ Contoh: tidak pedas...   │  │ ← textarea rounded-xl
│  └──────────────────────────┘  │
│                                │
│  ┌──────┐ ┌────────────────┐  │
│  │ [-] 1 [+] │  + Tambah   │  │ ← Quantity stepper + tombol primary
│  └──────┘ └────────────────┘  │
└────────────────────────────────┘
```

#### 4.2.2. Spesifikasi

- **Trigger**: Bottom Sheet (`.fixed inset-0 z-50`) dengan backdrop `.bg-black/30`
- **Animasi**: Slide up `transform translateY(0)` dari `translateY(100%)`, `transition-transform duration-300`
- **Sheet height**: Max 90vh, scrollable (`.overflow-y-auto`)
- **Handle**: `.w-10 h-1 bg-slate-300 rounded-full mx-auto mt-2`
- **Variant picker**: Gunakan tombol pill (`.rounded-full px-4 py-2 border`) — active: `.bg-primary text-white border-primary`
- **Modifier options**: Checkbox list dengan harga tambahan di kanan
- **Quantity stepper**: `.w-12 h-12 rounded-xl border flex items-center justify-center` untuk tombol `-` dan `+`, `.w-16 text-center` untuk angka
- **Primary button**: `.w-full rounded-xl h-12 bg-primary text-primary-foreground font-semibold`

#### 4.2.3. Tablet (Dialog)

- Gunakan dialog modal (`.fixed inset-0 z-50 flex items-center justify-center`)
- Width: `max-w-lg`
- Layout: Gambar di kiri, konten di kanan (2 kolom pada layar landscape)
- Tidak ada drag handle

### 4.3. Keranjang

#### 4.3.1. Mobile (Halaman Penuh)

```
┌────────────────────────────────┐
│  ← Keranjang          [Hapus] │ ← Header dengan tombol hapus semua
├────────────────────────────────┤
│ ┌─ Item ─────────────────────┐ │
│ │ [Gambar] Nama         Rp20k│ │ ← Item card
│ │          [-] 2 [+]         │ │
│ │          Total: Rp40.000   │ │
│ │          [Hapus]           │ │
│ ├────────────────────────────┤ │
│ │ [Gambar] Nama 2       Rp15k│ │
│ │          [-] 1 [+]         │ │
│ │          Total: Rp15.000   │ │
│ │          [Hapus]           │ │
│ └────────────────────────────┘ │
│                                │
│  Catatan pesanan               │
│  ┌──────────────────────────┐  │
│  │ Contoh: tidak pedas...   │  │ ← textarea
│  └──────────────────────────┘  │
│                                │
│  Nama Pelanggan (opsional)      │
│  ┌──────────────────────────┐  │
│  │ Masukkan nama Anda...    │  │
│  └──────────────────────────┘  │
│                                │
│  ─────────────────────         │
│  Subtotal              Rp55.000│ ← text-base
│  ─────────────────────         │
│                                │
│  ┌────────────────────────────┐│
│  │  Pesan Sekarang    Rp55.000││ ← Sticky bottom, rounded-2xl
│  └────────────────────────────┘│
└────────────────────────────────┘
```

#### 4.3.2. Spesifikasi

- **Item card**: `.rounded-2xl border p-3 flex gap-3`
- **Gambar item**: `.w-16 h-16 rounded-xl bg-slate-100 shrink-0`
- **Quantity stepper**: Sama seperti di detail produk
- **Tombol hapus**: Teks merah (`.text-destructive text-sm font-medium`)
- **Hapus semua**: Di header kanan (`.text-destructive text-sm`)
- **Catatan**: `textarea` dengan `.rounded-xl border p-3 text-sm`
- **Input nama**: `input` dengan `.rounded-xl border p-3 text-sm`
- **Sticky bottom**: `.sticky bottom-0 bg-white p-4 border-t`
- **Primary button**: `.w-full rounded-2xl h-14 bg-primary text-primary-foreground font-semibold text-base`

#### 4.3.3. Empty State

```
┌────────────────────────────────┐
│                                │
│         🛒 (icon size-20)      │
│     Keranjang masih kosong     │
│  Tambahkan menu favorit Anda   │
│                                │
│  ┌──────────────────────────┐  │
│  │  Lihat Menu              │  │
│  └──────────────────────────┘  │
└────────────────────────────────┘
```

### 4.4. Checkout & Pembayaran

#### 4.4.1. Mobile

```
┌────────────────────────────────┐
│  ← Pilih Pembayaran            │
├────────────────────────────────┤
│  Ringkasan Pesanan             │
│  ┌──────────────────────────┐  │
│  │ Meja: A1                 │  │
│  │ 3 item                   │  │
│  │ Total: Rp55.000          │  │
│  └──────────────────────────┘  │
│                                │
│  Metode Pembayaran              │
│                                │
│  ┌──────────────────────────┐  │
│  │ 💳  Bayar di Kasir      │  │ ← Card opsi
│  │     Bayar langsung ke    │  │
│  │     kasir kami           │  │
│  └──────────────────────────┘  │
│                                │
│  ┌──────────────────────────┐  │
│  │ 📱  QRIS Mandiri         │  │ ← Card opsi (dengan icon)
│  │     Scan QR untuk bayar  │  │
│  └──────────────────────────┘  │
│                                │
│  ┌──────────────────────────┐  │
│  │ ⏳  Bayar Nanti          │  │
│  │     Bayar setelah makan  │  │
│  └──────────────────────────┘  │
│                                │
│  ┌────────────────────────────┐│
│  │  Konfirmasi Pesanan       ││ ← Sticky bottom
│  └────────────────────────────┘│
└────────────────────────────────┘
```

#### 4.4.2. Spesifikasi

- **Payment method card**: `.rounded-2xl border p-4 flex items-center gap-3` — dengan efek tap (`.active:scale-[0.98]`)
- **Icon**: Gunakan lucide icons (`Wallet`, `QrCode`, `Clock`)
- **Radio selection**: Lingkaran di kanan (`.w-5 h-5 rounded-full border-2`) — active: `.border-primary bg-primary`
- **Ringkasan**: `.rounded-2xl bg-secondary p-4`
- **Sticky bottom**: Sama seperti keranjang

#### 4.4.3. Halaman QRIS (setelah pilih QRIS)

```
┌────────────────────────────────┐
│  ← Pembayaran QRIS             │
├────────────────────────────────┤
│                                │
│        ┌──────────────┐        │
│        │  ██ █  ████  │        │ ← QR code placeholder
│        │  █ ██ ██ █   │        │    size: 240x240px
│        │  ████  █ ██  │        │
│        └──────────────┘        │
│                                │
│  Total Pembayaran               │
│  Rp55.000                       │ ← text-2xl font-bold text-primary
│                                │
│  Scan QR ini untuk bayar       │
│  atau screenshot untuk         │
│  scan di m-banking              │
│                                │
│  ┌────────────────────────────┐│
│  │  Saya Sudah Bayar         ││ ← Button verifikasi manual
│  └────────────────────────────┘│
│                                │
│  ┌────────────────────────────┐│
│  │  ⏳  Tunggu... Batalkan    ││ ← Link text
│  └────────────────────────────┘│
└────────────────────────────────┘
```

### 4.5. Konfirmasi Pesanan

#### 4.5.1. Mobile

```
┌────────────────────────────────┐
│                                │
│       ✅ (icon lucide Check)    │ ← size-20, text-emerald-500
│                                │
│     Pesanan Berhasil!          │ ← text-2xl font-bold
│                                │
│     Nomor Pesanan               │ ← text-sm text-muted-foreground
│     #024                       │ ← text-4xl font-black tracking-tight
│                                │
│  ┌──────────────────────────┐  │
│  │  Status: Menunggu Proses  │  │ ← Badge status
│  │  Estimasi: 10-15 menit    │  │
│  │  Meja: A1                 │  │
│  └──────────────────────────┘  │
│                                │
│  ┌──────────────────────────┐  │
│  │  📄  Lihat Struk         │  │ ← Button outline
│  └──────────────────────────┘  │
│                                │
│  ┌────────────────────────────┐│
│  │  👍  Pesan Lagi            ││ ← Button primary
│  └────────────────────────────┘│
│                                │
│  ┌────────────────────────────┐│
│  │  🔔  Status Pesanan        ││ ← Link: track status real-time
│  └────────────────────────────┘│
└────────────────────────────────┘
```

#### 4.5.2. Spesifikasi

- **Success icon**: `lucide CheckCircle2` dengan `text-emerald-500`
- **Nomor pesanan**: Sangat besar (`.text-4xl font-black`) — sebagai screenshootable element
- **Status card**: `.rounded-2xl bg-secondary p-4 text-center`
- **Button "Lihat Struk"**: `.rounded-xl border h-12 font-medium`
- **Button "Pesan Lagi"**: `.rounded-xl bg-primary text-primary-foreground h-12 font-semibold`

### 4.6. Manajemen Meja (Admin)

#### 4.6.1. Mobile vs Tablet

| Elemen | Mobile (< 768px) | Tablet (≥ 768px) |
|---|---|---|
| **Layout** | List vertikal | Grid 2-3 kolom |
| **QR Code** | Download saja | Preview + download |
| **FAB Tambah** | `.fixed bottom-24 right-6` | Button di header |
| **Filter** | Dropdown tersembunyi | Sidebar filter |

#### 4.6.2. Tampilan Card Meja

```
┌──────────────────────┐
│  Meja A1             │ ← text-base font-semibold
│  Kapasitas: 2 org    │ ← text-xs text-muted-foreground
│  Status: Aktif        │ ← Badge .bg-emerald-100 text-emerald-700
│  ┌────────┐          │
│  │  QR    │ [Download]│ ← QR preview kecil + link
│  └────────┘          │
│  [Edit] [Hapus]      │ ← Tombol aksi
└──────────────────────┘
```

---

## 5. Breakpoint Responsif

### 5.1. Definisi Breakpoint

Menggunakan breakpoint **default Tailwind** yang sudah ada di project:

| Breakpoint | Min Width | Target Device | Layout Change |
|---|---|---|---|
| **Default** | 0px | Mobile kecil (360px+) | 2 kolom grid, bottom sheet, floating cart |
| `sm` | 640px | Mobile besar (phablet) | 2 kolom grid lebih lebar, card lebih besar |
| `md` | 768px | Tablet portrait | 3 kolom grid, tab penuh, dialog modal |
| `lg` | 1024px | Tablet landscape / desktop kecil | 4 kolom grid, sidebar meja admin |

### 5.2. Matrix Responsif Per Halaman

| Halaman | < 640px | 640–767px | 768–1023px | ≥ 1024px |
|---|---|---|---|---|
| **Menu Publik** | 2 kolom grid, floating cart, bottom sheet detail | 2 kolom grid, floating cart, bottom sheet | 3 kolom grid, dialog modal, cart sidebar | 4 kolom grid, dialog modal, cart sidebar |
| **Detail Produk** | Bottom Sheet (90vh) | Bottom Sheet (80vh) | Dialog modal (max-w-lg) | Dialog modal (max-w-xl) |
| **Keranjang** | Halaman penuh | Halaman penuh | Sidebar/drawer | Sidebar/drawer |
| **Checkout** | Halaman penuh | Halaman penuh | Halaman penuh | Halaman penuh (max-w-xl centered) |
| **Konfirmasi** | Halaman penuh | Halaman penuh | Halaman penuh (max-w-lg centered) | Halaman penuh (max-w-lg centered) |
| **Admin Meja** | List vertikal | List vertikal | Grid 2 kolom | Grid 3 kolom |

### 5.3. Container Sizes

| Breakpoint | Max Width | Padding |
|---|---|---|
| Mobile (< 640px) | 100% | `px-4` (16px) |
| Tablet (768px+) | `max-w-7xl` | `px-6` (24px) |
| Desktop (1024px+) | `max-w-7xl` | `px-8` (32px) |

### 5.4. Font Size Responsif

| Elemen | Mobile | Tablet+ |
|---|---|---|
| Halaman title | `text-2xl` | `text-3xl` |
| Harga produk | `text-lg` | `text-xl` |
| Nama produk | `text-sm` | `text-base` |
| Section title | `text-xl` | `text-2xl` |

---

## 6. Optimasi Interaksi Sentuh

### 6.1. Minimum Touch Target

Semua elemen interaktif **WAJIB** memiliki minimum area sentuh **48x48px** (WCAG 2.1 Guideline 2.5.8).

| Elemen | Ukuran Minimal | Catatan |
|---|---|---|
| Tombol (primary, secondary) | `h-12` (48px) | `min-h-[48px]` |
| Tombol icon (+, -, hapus) | `w-12 h-12` (48px) | Atau `p-3` + icon |
| Tab kategori | `h-10` (40px) | Minimal 40px + padding horizontal |
| Quantity stepper | `w-12 h-12` (48px) | Tidak boleh lebih kecil |
| Badge keranjang | `w-5 h-5` (20px) | Ini adalah badge indikator, bukan target |
| Link text | `min-h-[44px]` | Padding vertikal untuk area sentuh |
| Checkbox item | `w-6 h-6` (24px) + `p-2` wrapper | Total area sentuh 48px |
| Card produk | Full card width | Tap area minimal card height |

### 6.2. Thumb Zone Optimization

Mengacu pada zona jangkauan thumb rata-rata layar 6.1" (360px–414px):

```
┌────────────────────────────────┐
│  ╔══════════════════════════╗  │
│  ║     Zona Sulit (atas)     ║  │ ← Hindari interaksi penting di sini
│  ║     ╔════════════════╗    ║  │
│  ║     ║  Zona Netral   ║    ║  │
│  ║     ║                ║    ║  │
│  ║     ╚════════════════╝    ║  │
│  ║  ╔══════════════════════╗  ║  │
│  ║  ║  Zona Nyaman (bawah) ║  ║  │ ← Primary actions di sini
│  ║  ║  [+ Tambah] [Pesan]  ║  ║  │
│  ║  ╚══════════════════════╝  ║  │
│  ╚══════════════════════════╝  │
└────────────────────────────────┘
```

**Aturan penempatan interaksi:**
- **Zona nyaman (bawah)**: Tombol primary (Tambah, Pesan, Bayar, Konfirmasi), quantity stepper, navigasi
- **Zona netral (tengah)**: Konten (produk, item keranjang), scrollable area
- **Zona sulit (atas)**: Informasi (header, judul, total harga), tombol back/kembali

### 6.3. Touch Feedback

| Interaksi | Visual Feedback | Class |
|---|---|---|
| **Tap tombol** | Scale down 2% | `active:scale-[0.98] transition-transform duration-150` |
| **Tap card** | Scale down 1% + shadow | `active:scale-[0.99] active:shadow-sm` |
| **Long press** | Haptic feedback (opsional) | CSS `:active` state |
| **Swipe item** | Opacity reduce | `transition-opacity` |
| **Loading state** | Spinner + disable | `opacity-70 pointer-events-none` + lucide `LoaderCircle` |

### 6.4. Gesture Support

| Gesture | Aksi | Halaman |
|---|---|---|
| **Swipe right** | Kembali ke halaman sebelumnya | Semua halaman |
| **Swipe down** | Tutup bottom sheet (detail produk) | Detail produk |
| **Tap (double)** | Zoom gambar (jika ada) | Detail produk |
| **Pull to refresh** | Reload menu | Menu publik |

### 6.5. Safe Area Handling

```css
/* Untuk perangkat notched (iPhone, Android modern) */
.pb-safe {
    padding-bottom: env(safe-area-inset-bottom, 16px);
}
.pt-safe {
    padding-top: env(safe-area-inset-top, 16px);
}
```

- **Floating cart**: Gunakan `.pb-safe` untuk padding bawah
- **Header sticky**: Gunakan `.pt-safe` untuk padding atas
- **Bottom sheet**: Padding bawah minimal 16px + safe area

---

## 7. Navigasi per Ukuran Layar

### 7.1. Navigasi Mobile (< 768px)

```
┌────────────────────────────────┐
│  ← [Judul Halaman]    [Ikon]   │ ← Top bar (Back + Title + Action)
├────────────────────────────────┤
│                                │
│         KONTEN UTAMA           │ ← Scrollable area
│                                │
│                                │
├────────────────────────────────┤
│       [Primary Action]         │ ← Sticky bottom bar (CTA)
└────────────────────────────────┘
```

**Pola navigasi mobile:**
- **Back navigation**: Tombol panah kiri di top bar, atau swipe right gesture
- **No bottom tab bar**: Halaman guest tidak perlu bottom tab — hanya 1 alur linear
- **Modal/Sheet**: Detail produk muncul sebagai bottom sheet (bukan navigasi halaman baru)
- **Floating cart**: Tombol keranjang di header + floating summary di bottom

### 7.2. Navigasi Tablet (768px–1024px)

```
┌──────────────────────────────────────────────┐
│  Logo Outlet      [Cari]    [🛒 3]            │ ← Top bar (lebih lebar)
├──────────────────────────────────────────────┤
│                                              │
│              KONTEN UTAMA                     │ ← Konten lebih luas
│              (3-4 kolom grid)                 │
│                                              │
│                                              │
└──────────────────────────────────────────────┘
```

**Pola navigasi tablet:**
- **Sidebar cart**: Keranjang sebagai sidebar/drawer dari kanan (bukan halaman penuh)
- **Dialog modal**: Detail produk sebagai dialog (bukan bottom sheet)
- **No floating cart**: Karena layar lebih lebar, cart info bisa di sidebar
- **Landscape support**: Pastikan konten tidak terlalu stretched (max-w-7xl)

### 7.3. Navigasi Admin (Manajemen Meja)

| View | Mobile | Tablet |
|---|---|---|
| **List meja** | Scroll vertikal | Grid |
| **Tambah/edit** | Halaman penuh | Slide-over / modal |
| **Generate QR** | Download button | Preview + download |

### 7.4. Transisi Antar Halaman

| From → To | Animasi | Durasi | Tipe |
|---|---|---|---|
| Menu → Detail Produk | Slide up (bottom sheet) | 300ms | `ease-out` |
| Detail → Menu (close) | Slide down | 250ms | `ease-in` |
| Menu → Keranjang | Slide in from right | 300ms | `ease-out` |
| Keranjang → Menu (back) | Slide in from left | 250ms | `ease-in` |
| Keranjang → Checkout | Slide in from right | 300ms | `ease-out` |
| Checkout → Konfirmasi | Fade in + scale up | 400ms | `ease-out` |

---

## 8. Prioritas Konten (Mobile-First)

### 8.1. Hierarki Prioritas per Halaman

#### Halaman Menu Publik

| Prioritas | Elemen | Alasan |
|---|---|---|
| **P1 (Wajib)** | Daftar produk + harga | Alasan utama pelanggan datang |
| **P1 (Wajib)** | Kategori/tab filter | Navigasi menu cepat |
| **P1 (Wajib)** | Floating cart + total | Feedback jumlah pesanan |
| **P2 (Penting)** | Gambar produk | Meningkatkan minat beli |
| **P2 (Penting)** | Search bar | Cari menu cepat |
| **P3 (Fungsional)** | Nama outlet | Informasi konteks |
| **P4 (Opsional)** | Deskripsi singkat (di card) | Layar kecil — pindah ke detail |

#### Detail Produk

| Prioritas | Elemen | Alasan |
|---|---|---|
| **P1 (Wajib)** | Nama + harga + gambar | Informasi inti produk |
| **P1 (Wajib)** | Quantity + tombol tambah | Primary action |
| **P2 (Penting)** | Modifier/variant options | Kustomisasi pesanan |
| **P3 (Fungsional)** | Deskripsi | Informasi tambahan |
| **P3 (Fungsional)** | Catatan | Opsional |

#### Keranjang

| Prioritas | Elemen | Alasan |
|---|---|---|
| **P1 (Wajib)** | Daftar item + harga | Review pesanan |
| **P1 (Wajib)** | Total harga + tombol pesan | Primary action |
| **P2 (Penting)** | Quantity stepper per item | Edit pesanan |
| **P3 (Fungsional)** | Tombol hapus | Manajemen item |
| **P3 (Fungsional)** | Catatan + nama | Opsional — di bawah |

### 8.2. Strategi Collapsible / Progressive Disclosure

| Elemen | Default State | Trigger |
|---|---|---|
| **Deskripsi produk** (di card) | Tersembunyi | Muncul di halaman detail |
| **Catatan pesanan** | Collapsed | Expand jika diperlukan |
| **Nama pelanggan** | Collapsed | Expand jika diperlukan |
| **Modifier options** | Visible | Langsung terlihat (penting untuk F&B) |
| **Variant** | Visible | Langsung terlihat |

### 8.3. Loading Strategy

| Kondisi | Feedback | Komponen |
|---|---|---|
| **Menu loading** | Skeleton grid | 6 card skeleton (`.animate-pulse bg-slate-200 rounded-2xl`) |
| **Gambar produk** | Lazy load + blur placeholder | `loading="lazy"` + `blur-up` effect |
| **Add to cart** | Button feedback + micro-animation | Button jadi `✓` lalu kembali |
| **Checkout loading** | Button spinner + disable | `opacity-70 pointer-events-none` |
| **Network error** | Toast notification | `.fixed top-4 z-50` red toast |

---

## 9. Kriteria Keberhasilan (Success Criteria)

### 9.1. Kriteria Wajib (Harus Dipenuhi)

| ID | Kriteria | Cara Ukur | Target |
|---|---|---|---|
| **SC-01** | Semua tombol interaktif memiliki area sentuh ≥ 48x48px | Inspeksi CSS | 100% tombol terverifikasi |
| **SC-02** | Halaman menu dapat diakses tanpa login | Test flow | Guest user bisa lihat menu |
| **SC-03** | Grid produk menyesuaikan 2 kolom (mobile) dan 3 kolom (tablet) | Resize browser | Sesuai breakpoint |
| **SC-04** | Primary action (Tambah, Pesan, Bayar) berada di zona bawah layar | Visual check | Posisi sticky bottom |
| **SC-05** | Floating cart summary muncul di mobile dan hilang di tablet | Resize browser | Sesuai breakpoint |
| **SC-06** | Detail produk muncul sebagai bottom sheet (mobile) dan dialog (tablet) | Test kedua mode | Sesuai spesifikasi |
| **SC-07** | Semua warna menggunakan token dari design system yang ada | Audit CSS | Tidak ada warna hardcoded baru |
| **SC-08** | Font menggunakan Plus Jakarta Sans (font-sans / font-display) | Audit CSS | Konsisten |
| **SC-09** | Radius card dan tombol konsisten (rounded-2xl / rounded-xl) | Visual check | Sesuai design tokens |
| **SC-10** | Halaman dapat diakses di layar 360px tanpa horizontal scroll | Test emulator | Tidak ada overflow |

### 9.2. Kriteria Performance

| ID | Kriteria | Target |
|---|---|---|
| **SC-P1** | Waktu muat halaman menu (first paint) | < 2 detik (3G) |
| **SC-P2** | Waktu muat gambar produk (lazy load) | < 1 detik per gambar |
| **SC-P3** | Animasi transisi halaman | 60fps, tidak ada jank |
| **SC-P4** | Total bundle size (halaman guest) | < 200KB (gzipped) |
| **SC-P5** | Lighthouse Performance score (mobile) | ≥ 85 |

### 9.3. Kriteria UX

| ID | Kriteria | Target |
|---|---|---|
| **SC-U1** | Jumlah tap untuk checkout (dari menu) | ≤ 4 tap |
| **SC-U2** | Feedback visual setiap aksi (tap, loading, error) | 100% aksi |
| **SC-U3** | Empty state untuk keranjang kosong, menu kosong, error | 3 kondisi |
| **SC-U4** | Error state untuk network error, server error, invalid QR | 3 kondisi |
| **SC-U5** | Nomor pesanan tampil besar dan jelas setelah checkout | ≥ 36px font size |

### 9.4. Kriteria Konsistensi Visual

| ID | Kriteria | Check |
|---|---|---|
| **SC-V1** | Ikon menggunakan lucide-vue-next | ✅ / ❌ |
| **SC-V2** | Class glass/glass-dark untuk elemen overlay | ✅ / ❌ |
| **SC-V3** | Shadow menggunakan shadow-sm + shadow-slate-200/50 | ✅ / ❌ |
| **SC-V4** | Premium interaction class (btn-premium, card-premium) digunakan | ✅ / ❌ |
| **SC-V5** | Meta text menggunakan format uppercase tracking-widest text-[10px] | ✅ / ❌ |

### 9.5. Checklist Evaluasi Pra-Implementasi

Gunakan checklist ini untuk mengevaluasi desain sebelum implementasi kode:

```
[ ] 1.  Semua halaman sudah di-desain untuk 360px dan 768px
[ ] 2.  Touch target ≥ 48px terverifikasi di semua elemen interaktif
[ ] 3.  Primary action di zona thumb-friendly (bawah layar)
[ ] 4.  Warna menggunakan token CSS yang sudah ada
[ ] 5.  Font menggunakan Plus Jakarta Sans
[ ] 6.  Radius konsisten (rounded-2xl card, rounded-xl button)
[ ] 7.  Ikon menggunakan lucide-vue-next
[ ] 8.  Glass effect untuk sticky header
[ ] 9.  Floating cart hanya muncul di mobile (< 768px)
[ ] 10. Bottom sheet untuk detail produk (mobile), dialog (tablet)
[ ] 11. Loading skeleton untuk semua data fetching
[ ] 12. Empty state untuk semua kondisi kosong
[ ] 13. Error state untuk network/server error
[ ] 14. Transisi antar halaman halus (300ms ease-out)
[ ] 15. Safe area handling untuk perangkat notched
[ ] 16. Scrollbar hidden untuk horizontal scroll kategori
[ ] 17. Nomor pesanan tampil besar (≥ 36px) di halaman konfirmasi
[ ] 18. Guest dapat mengakses semua halaman tanpa login
```

---

## 10. Mockup Struktur Layout

### 10.1. Mobile (360px) — Wireframe Layout

```
┌─────── PADDING: px-4 ────────┐
┌────────────────────────────────┐ ← .glass sticky top-0 z-20 pt-safe
│  Logo Outlet       🔍  🛒3    │ ← h-14
├────────────────────────────────┤
│  [Makanan] [Minuman] [Snack]  │ ← h-10, overflow-x-auto
├────────────────────────────────┤
│ ┌──────┐ ┌──────┐             │ ← gap-3
│ │      │ │      │             │ ← aspect-square
│ │ Nama │ │ Nama │             │ ← p-3
│ │ Rp20k│ │ Rp15k│             │ ← text-sm / text-base
│ │  [+ ]│ │  [+ ]│             │ ← w-8 h-8
│ └──────┘ └──────┘             │
│ ┌──────┐ ┌──────┐             │
│ │ ...  │ │ ...  │             │
│ └──────┘ └──────┘             │
│ ...                            │
├────────────────────────────────┤ ← sticky bottom-0 z-30 pb-safe
│  ┌──────────────────────────┐  │ ← bg-white rounded-2xl shadow-lg
│  │ 3 item · Rp55.000 [Lihat]│  │ ← h-14 p-4
│  └──────────────────────────┘  │
└────────────────────────────────┘
```

### 10.2. Tablet (768px) — Wireframe Layout

```
┌──────────────────────────────────────────────────┐
│  Logo Outlet                    🔍      🛒 3     │
├──────────────────────────────────────────────────┤
│  Makanan  │  Minuman  │  Snack  │  Semua         │
├──────────────────────────────────────────────────┤
│ ┌──────┐ ┌──────┐ ┌──────┐                      │
│ │      │ │      │ │      │                      │
│ │ Nama │ │ Nama │ │ Nama │                      │
│ │ Rp20k│ │ Rp15k│ │ Rp18k│                      │
│ │  [+ ]│ │  [+ ]│ │  [+ ]│                      │
│ └──────┘ └──────┘ └──────┘                      │
│ ┌──────┐ ┌──────┐ ┌──────┐                      │
│ │ ...  │ │ ...  │ │ ...  │                      │
│ └──────┘ └──────┘ └──────┘                      │
└──────────────────────────────────────────────────┘
```

### 10.3. Component Tree (Vue)

```
GuestLayout.vue
├── GuestHeader.vue (sticky, glass)
│   ├── StoreLogo.vue
│   ├── SearchBar.vue
│   └── CartBadge.vue (icon + count)
│
├── CategoryTabs.vue (horizontal scroll)
│   └── CategoryTab.vue (per item, active state)
│
├── ProductGrid.vue
│   └── ProductCard.vue (per item, 2/3/4 kolom)
│       ├── ProductImage.vue (lazy load)
│       ├── ProductName.vue
│       ├── ProductPrice.vue
│       └── AddToCartButton.vue (floating +)
│
├── FloatingCartSummary.vue (mobile only, sticky bottom)
│   ├── CartTotal.vue
│   └── ViewCartButton.vue
│
├── ProductDetailSheet.vue (bottom sheet / dialog)
│   ├── ProductImage.vue
│   ├── ProductInfo.vue
│   ├── VariantPicker.vue
│   ├── ModifierOptions.vue
│   ├── QuantityStepper.vue
│   └── AddToCartButton.vue
│
├── CartPage.vue (halaman penuh / sidebar)
│   ├── CartItem.vue (per item)
│   │   ├── CartItemImage.vue
│   │   ├── CartItemInfo.vue
│   │   ├── QuantityStepper.vue
│   │   └── RemoveButton.vue
│   ├── CartNotes.vue (textarea)
│   ├── CustomerNameInput.vue
│   └── CheckoutButton.vue (sticky bottom)
│
├── CheckoutPage.vue
│   ├── OrderSummary.vue
│   ├── PaymentMethodCard.vue (per opsi)
│   └── ConfirmButton.vue (sticky bottom)
│
├── QrPaymentPage.vue
│   ├── QrCodeDisplay.vue
│   ├── PaymentTotal.vue
│   └── ConfirmPaymentButton.vue
│
└── OrderSuccessPage.vue
    ├── SuccessIcon.vue
    ├── OrderNumber.vue
    ├── OrderStatus.vue
    ├── ViewReceiptButton.vue
    ├── OrderAgainButton.vue
    └── TrackOrderButton.vue
```

---

## Lampiran: Referensi Visual

### Warna yang Digunakan (dari design system)

| Warna | Hex | Tailwind Class | Penggunaan |
|---|---|---|---|
| Primary | `#7C3AED` | `bg-primary / text-primary` | Tombol utama, tab aktif, harga |
| Primary hover | `#6D28D9` | `hover:bg-primary/90` | Hover state |
| Background | `#F8FAFC` | `bg-background` | Background halaman |
| Card | `#FFFFFF` | `bg-card` | Kartu produk |
| Foreground | `#0F172A` | `text-foreground` | Teks utama |
| Muted | `#64748B` | `text-muted-foreground` | Teks sekunder |
| Border | `#E2E8F0` | `border-border` | Garis batas |
| Destructive | `#EF4444` | `text-destructive` | Hapus, error |
| Accent | `rgba(124,58,237,0.1)` | `bg-accent` | Hover, highlight |
| Success | `#10B981` | `text-emerald-500` | Status sukses |

### Class Utama yang Perlu Digunakan

```vue
<!-- Kartu produk -->
<div class="rounded-2xl border bg-card shadow-sm shadow-slate-200/50 p-3">

<!-- Tombol primary -->
<button class="rounded-xl bg-primary text-primary-foreground font-semibold h-12 active:scale-[0.98] transition-all duration-150">

<!-- Tombol outline -->
<button class="rounded-xl border border-slate-200 font-medium h-12 active:scale-[0.98]">

<!-- Tab kategori -->
<button class="rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap"
        :class="active ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground'">

<!-- Header glass -->
<header class="glass sticky top-0 z-20">

<!-- Badge keranjang -->
<span class="bg-primary text-white text-[10px] font-bold rounded-full w-5 h-5 flex items-center justify-center">

<!-- Skeleton loading -->
<div class="rounded-2xl bg-slate-200 animate-pulse h-48">

<!-- Empty state -->
<div class="flex flex-col items-center justify-center py-20 gap-4 text-muted-foreground">
```

---

> **Dokumen ini siap digunakan sebagai acuan desain sebelum implementasi.**
> Seluruh kriteria keberhasilan di **Section 9** harus dievaluasi sebelum kode ditulis.
> Setiap penyimpangan dari design tokens yang ada harus melalui review terlebih dahulu.