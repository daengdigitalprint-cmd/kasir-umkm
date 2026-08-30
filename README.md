# Aplikasi Kasir Modern - UMKM Kasir

Aplikasi kasir modern dan ramah pengguna yang dirancang khusus untuk UMKM (Usaha Mikro, Kecil, dan Menengah) dengan fitur-fitur lengkap untuk manajemen toko, produk, dan transaksi.

## 🚀 Fitur Utama

### ✅ Sudah Diimplementasi

- **Dukungan Multi-Pengguna**: Setiap pengguna dapat mengelola toko mereka sendiri dengan data terpisah
- **Manajemen Produk**: CRUD lengkap untuk produk dengan upload gambar, SKU, barcode, dan tracking stok
- **Point of Sale (POS)**: Interface kasir yang modern dan mudah digunakan
- **Dashboard**: Tampilan statistik penjualan, stok rendah, dan transaksi terbaru
- **Autentikasi**: Sistem login/register dengan Laravel Breeze
- **Database Schema**: Struktur database yang lengkap untuk semua fitur

### 🔄 Dalam Pengembangan

- **Sistem Invoice**: Cetak invoice dan receipt transaksi
- **Laporan Keuangan**: Dashboard laporan dengan grafik penjualan
- **Sistem Diskon**: Berbagai jenis diskon dan promo
- **Manajemen Inventaris**: Notifikasi stok rendah dan auto-restock
- **Manajemen Pelanggan**: Database pelanggan dan riwayat transaksi
- **Pemindaian Barcode**: Integrasi scanner barcode/QR code
- **Perhitungan Pajak**: Auto-calculate PPN dan pajak lainnya
- **Backup Data**: Sistem backup otomatis

## 🛠️ Teknologi yang Digunakan

- **Backend**: Laravel 12.x dengan PHP 8.2+
- **Frontend**: Vue 3 + TypeScript + Inertia.js
- **UI Framework**: Tailwind CSS + shadcn/ui components
- **Database**: MySQL/PostgreSQL/SQLite
- **Authentication**: Laravel Breeze
- **Icons**: Lucide Vue Next

## 📦 Instalasi

### Prerequisites

- PHP 8.2 atau lebih tinggi
- Composer
- Node.js 18+ dan npm
- Database (MySQL/PostgreSQL/SQLite)

### Langkah Instalasi

1. **Clone repository**
```bash
git clone <repository-url>
cd umkm-kasir
```

2. **Install dependencies**
```bash
composer install
npm install
```

3. **Setup environment**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Konfigurasi database di `.env`**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=umkm_kasir
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

5. **Jalankan migration dan seeder**
```bash
php artisan migrate
php artisan db:seed
```

6. **Build assets**
```bash
npm run build
```

7. **Jalankan aplikasi**
```bash
php artisan serve
```

Aplikasi akan berjalan di `http://localhost:8000`

### Data Sample

Setelah menjalankan seeder, Anda dapat login dengan:
- **Email**: admin@tokomajujaya.com
- **Password**: password

## 📊 Struktur Database

### Tabel Utama

- **stores**: Informasi toko/warung
- **users**: Data pengguna dengan role (admin, manager, cashier)
- **products**: Master data produk
- **customers**: Data pelanggan
- **transactions**: Data transaksi penjualan
- **transaction_items**: Detail item dalam transaksi
- **discounts**: Data diskon dan promo

### Relasi

- Setiap user memiliki store_id untuk pemisahan data
- Produk, customer, dan transaksi terkait dengan store
- Transaction memiliki relasi dengan customer dan user
- TransactionItem menyimpan snapshot produk saat transaksi

## 🎯 Cara Penggunaan

### 1. Setup Toko
- Login ke aplikasi
- Buat toko baru atau gunakan toko sample
- Konfigurasi informasi toko (nama, alamat, mata uang, dll)

### 2. Kelola Produk
- Tambah produk dengan informasi lengkap
- Upload gambar produk
- Set harga, stok, dan kategori
- Atur level stok minimum untuk notifikasi

### 3. Gunakan POS
- Buka halaman POS
- Pilih produk dari grid
- Atur jumlah dan hitung total
- Pilih metode pembayaran
- Proses transaksi

### 4. Monitor Dashboard
- Lihat statistik penjualan harian
- Monitor produk dengan stok rendah
- Review transaksi terbaru

## 🔧 Konfigurasi

### Pengaturan Toko
- Mata uang (IDR, USD, EUR)
- Zona waktu
- Rate pajak default
- Footer receipt

### Pengaturan Produk
- Kategori produk
- Satuan (pcs, kg, liter, dll)
- Rate pajak per produk
- Tracking stok

## 📱 Responsive Design

Aplikasi dirancang responsif dan dapat digunakan di:
- Desktop/Laptop
- Tablet
- Smartphone

## 🔒 Keamanan

- Autentikasi multi-user dengan pemisahan data
- Authorization policy untuk setiap resource
- Validasi input yang ketat
- CSRF protection
- SQL injection prevention

## 🚀 Deployment

### Production Setup

1. **Environment**
```bash
APP_ENV=production
APP_DEBUG=false
```

2. **Optimize**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```

3. **Web Server**
- Apache/Nginx dengan PHP-FPM
- SSL certificate
- Database server terpisah

## 🤝 Kontribusi

1. Fork repository
2. Buat feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit changes (`git commit -m 'Add some AmazingFeature'`)
4. Push ke branch (`git push origin feature/AmazingFeature`)
5. Buat Pull Request

## 📄 Lisensi

Distributed under the MIT License. See `LICENSE` for more information.

## 📞 Support

Untuk pertanyaan atau dukungan, silakan buat issue di repository ini.

---

**Dibuat dengan ❤️ untuk UMKM Indonesia**
