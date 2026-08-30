# Analisis Implementasi Modul: Pelanggan Scan Barcode Meja → Lihat Menu → Pesan → Bayar

> **Dokumen ini adalah analisis dan rekomendasi — bukan instruksi eksekusi.**
> Dibuat tanggal: 22 Agustus 2026
> Proyek: UMKM Kasir (Laravel 12 + Vue 3 + Inertia.js v2)

---

## Daftar Isi

1. [Ringkasan Fitur](#1-ringkasan-fitur)
2. [Analisis Kesiapan Project Saat Ini](#2-analisis-kesiapan-project-saat-ini)
3. [Arsitektur yang Diusulkan](#3-arsitektur-yang-diusulkan)
4. [Alur Sistem (User Flow)](#4-alur-sistem-user-flow)
5. [Detail Komponen](#5-detail-komponen)
   - [5.1 Database & Model](#51-database--model)
   - [5.2 Backend (Controller & Service)](#52-backend-controller--service)
   - [5.3 Frontend (Halaman Vue)](#53-frontend-halaman-vue)
   - [5.4 Routes](#54-routes)
6. [Best Practice & Saran Implementasi](#6-best-practice--saran-implementasi)
7. [Matrix Prioritas Fitur (MVP vs Lanjutan)](#7-matrix-prioritas-fitur-mvp-vs-lanjutan)
8. [Potensi Risiko & Mitigasi](#8-potensi-risiko--mitigasi)

---

## 1. Ringkasan Fitur

Pelanggan di sebuah meja restoran/kafe dapat:
1. **Scan QR Code** yang ditempel di meja menggunakan HP mereka
2. **Melihat menu digital** (daftar produk + kategori + harga)
3. **Memilih pesanan** dan mengirimkannya ke dapur
4. **Melakukan pembayaran** (mandiri via QRIS/transfer, atau panggil kasir)

---

## 2. Analisis Kesiapan Project Saat Ini

### 2.1. Yang SUDAH tersedia (tidak perlu dibangun dari nol)

| Komponen | Status | Keterangan |
|---|---|---|
| **Model Produk** (`Product`) | ✅ Lengkap | Sudah ada `barcode`, `sku`, `price`, `modifier_options` (JSON untuk F&B), `category`, `images`, `has_variants`, `track_stock` |
| **Kategori Produk** (`ProductCategory`) | ✅ Lengkap | Sudah ada relasi dengan Product |
| **Model Pelanggan** (`Customer`) | ✅ Lengkap | Sudah ada `name`, `phone`, `total_spent`, `total_orders`, `points_balance` |
| **Model Transaksi** (`Transaction`) | ✅ Lengkap | Sudah punya `order_type` (dine_in/takeaway/delivery), `table_reference`, `status` (pending/completed/cancelled/refunded), `payment_method`, `payment_details` |
| **Model Item Transaksi** (`TransactionItem`) | ✅ Lengkap | Sudah punya `product_snapshot`, `modifiers` (JSON), `quantity`, `unit_price`, `total_price` |
| **Model Antrian** (`Queue`, `QueueEntry`) | ✅ Lengkap | Sudah punya `order_type`, `table_number` — siap untuk dapur |
| **Barcode Scanner** (`BarcodeScanner.vue`) | ✅ Lengkap | Kamera + keyboard wedge, support QR_CODE |
| **Fitur F&B** (`FnBFeatures`) | ✅ Ada | Service charge, split bill, order types |
| **Cetak Struk Termal** | ✅ Ada | ESC/POS via Bluetooth, thermal receipt |
| **Offline Mode** | ✅ Ada | IndexedDB + `useOfflineSync` |

### 2.2. Yang PERLU dibangun

| Komponen | Prioritas | Keterangan |
|---|---|---|
| **Model & Migrasi Meja** (`Table`) | 🔴 Tinggi | Saat ini hanya string `table_reference` di Transaction. Perlu model terpisah dengan relasi ke Store. |
| **QR Code Generator** (server-side) | 🔴 Tinggi | Perlu package seperti `simplesoftwareio/simple-qrcode` atau `endroid/qr-code` |
| **Halaman Menu Publik** (tanpa login) | 🔴 Tinggi | Halaman Vue khusus untuk pelanggan lihat menu — harus publik (tanpa middleware auth) |
| **Keranjang Belanja Sesi** (guest) | 🔴 Tinggi | Pelanggan belum login — perlu keranjang berbasis session/localStorage |
| **Checkout/Payment Flow untuk Guest** | 🟡 Sedang | Alur pembayaran yang disederhanakan untuk pelanggan mandiri |
| **Notifikasi Dapur** (real-time) | 🟡 Sedang | Push notifikasi atau polling untuk pesanan baru |
| **Manajemen Meja CRUD** | 🟡 Sedang | Admin perlu bisa manage meja (nomor, kapasitas, QR code) |
| **Role Customer** (login opsional) | 🟢 Rendah | Login via OTP/whatsapp untuk track history pesanan |

---

## 3. Arsitektur yang Diusulkan

```
┌─────────────────────────────────────────────────────────────┐
│                    HP Pelanggan (Browser)                    │
│  Scan QR → Halaman Menu Publik → Pilih Item → Checkout     │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP (Inertia.js / Axios)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                  Laravel Backend (Web Routes)                 │
│  ┌──────────┐  ┌──────────────┐  ┌───────────────────────┐  │
│  │ MejaCtrl │  │ MenuCtrl     │  │ GuestOrderCtrl        │  │
│  │ (Admin)  │  │ (Publik)     │  │ (Create/Pay)          │  │
│  └──────────┘  └──────────────┘  └───────────────────────┘  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                  Services Layer                      │   │
│  │  MenuService │ GuestCartService │ PaymentService     │   │
│  └──────────────────────────────────────────────────────┘   │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                     Database (PostgreSQL)                    │
│  tables │ products │ transactions │ transaction_items        │
│  queues │ queue_entries │ customers                         │
└─────────────────────────────────────────────────────────────┘
```

### 3.1. Pendekatan Routing

Dua pintu masuk berbeda:

| Jalur | Audience | Auth | Middleware |
|---|---|---|---|
| `/meja/{slug}/{nomor_meja}` | Pelanggan (publik) | ❌ Tidak perlu | `pub` (tanpa auth) |
| `/admin/tables` | Admin/Kasir | ✅ Perlu login | `auth` + role |

**Mengapa Inertia?** Aplikasi sudah full Inertia SPA. Untuk halaman publik pelanggan, kita bisa:
- **Opsi A (Rekomendasi)**: Tetap pakai Inertia dengan layout berbeda (`resources/js/Layouts/GuestLayout.vue`) — konsisten dengan stack, reuse komponen POS.
- **Opsi B**: Blade standalone — lebih ringan, tapi tidak bisa reuse komponen Vue yang sudah ada.
- **Opsi C**: Pisah jadi subdomain `order.namaoutlet.com` — skalabilitas tinggi, tapi kompleks.

**Rekomendasi: Opsi A** — Gunakan Inertia dengan GuestLayout. Buat route publik yang tidak melewati middleware `auth`.

---

## 4. Alur Sistem (User Flow)

### 4.1. Alur Utama (Happy Path)

```
┌──────────┐     ┌──────────────┐     ┌────────────┐     ┌──────────┐
│  Scan QR  │ ──► │  Lihat Menu  │ ──► │  Keranjang │ ──► │  Bayar   │
│  di Meja  │     │  + Pilih     │     │  + Review  │     │          │
└──────────┘     └──────────────┘     └────────────┘     └──────────┘
     │                 │                   │                  │
     ▼                 ▼                   ▼                  ▼
• Buka QR      • Tampilkan produk   • Lihat subtotal   • Pilih metode
  di meja        per kategori      • Edit quantity      bayar
• Redirect ke   • Lihat variant     • Tambah catatan   • QRIS/Transfer
  halaman menu  • Tambah modifier   • Konfirmasi        • Bayar di kasir
  (guest)                                              • Status: Pending
                                                             │
                                                             ▼
┌──────────┐     ┌──────────────┐     ┌────────────┐
│  Selesai │ ◄── │  Proses       │ ◄── │  Notifikasi │
│  + Struk  │     │  Dapur       │     │  Dapur      │
└──────────┘     └──────────────┘     └────────────┘
     │                 │                   │
     ▼                 ▼                   ▼
• Tampilkan       • Queue baru       • Real-time
  halaman sukses    masuk dapur        notif ke
• Unduh struk     • Status:           layar dapur
  digital           In-Progress
```

### 4.2. Detail Langkah

#### Langkah 1: Scan QR Code
- QR code ditempel di meja, berisi URL: `https://domain.com/meja/{store-slug}/{table-number}`
- QR di-generate dari halaman admin manajemen meja
- Saat discan: redirect ke URL tersebut → halaman menu publik

#### Langkah 2: Lihat Menu & Pilih
- Tampilkan semua produk dengan kategori
- Pelanggan bisa tap untuk lihat detail (gambar, deskripsi, variant, modifier)
- Tambah ke keranjang (disimpan di localStorage/session — guest)
- Jumlah item real-time di ikon keranjang

#### Langkah 3: Review & Konfirmasi
- Halaman keranjang: tampilkan semua item, quantity, subtotal
- Bisa tambah catatan per item
- Input nama pelanggan (opsional — untuk panggilan)
- Tombol "Pesan Sekarang"

#### Langkah 4: Pembayaran
Dua opsi pembayaran:

| Opsi | Alur | Cocok Untuk |
|---|---|---|
| **Bayar Mandiri** | QRIS/Transfer — pelanggan bayar sendiri, status transaksi jadi "completed" setelah terverifikasi | Kafe self-service |
| **Panggil Kasir** | Notifikasi ke kasir, kasir datang ke meja untuk proses payment | Restoran fine dining |

#### Langkah 5: Notifikasi Dapur
- Transaksi baru dengan status "pending" tercatat
- Queue entry dibuat otomatis
- Layar dapur (Kitchen Display) mendapat notifikasi (polling atau WebSocket)
- Status pesanan: pending → in_progress → completed

#### Langkah 6: Selesai
- Pelanggan melihat halaman konfirmasi dengan nomor pesanan
- Link unduh struk digital (PDF)
- Opsi untuk pesan lagi

---

## 5. Detail Komponen

### 5.1. Database & Model

#### 5.1.1. Migrasi Baru: `create_tables_table`

```php
Schema::create('tables', function (Blueprint $table) {
    $table->id();
    $table->foreignId('store_id')->constrained()->cascadeOnDelete();
    $table->string('table_number', 20);           // misal: "A1", "Meja 3"
    $table->string('label', 100)->nullable();      // nama opsional: "VIP 1"
    $table->unsignedTinyInteger('capacity')->default(2); // kapasitas kursi
    $table->string('qr_code', 255)->nullable();    // path/nama file QR
    $table->boolean('is_active')->default(true);
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->unique(['store_id', 'table_number']);
});
```

#### 5.1.2. Model `Table` (App\Models\Table)

```php
class Table extends Model
{
    protected $fillable = [
        'store_id', 'table_number', 'label', 'capacity',
        'qr_code', 'is_active', 'notes'
    ];

    public function store(): BelongsTo { ... }
    public function transactions(): HasMany { ... } // transaksi di meja ini
    public function scopeActive($q) { $q->where('is_active', true); }
}
```

#### 5.1.3. Modifikasi Minimal ke Model yang Ada

- **`Transaction`**: Tidak perlu perubahan — kolom `table_reference` sudah ada dan bisa diisi dengan `table_number`.
- **`QueueEntry`**: Tidak perlu perubahan — kolom `table_number` sudah ada.

#### 5.1.4. Diagram Relasi

```
Store (1) ──< (N) Tables          → setiap meja punya QR code
Store (1) ──< (N) Products        → menu digital
Table (1) ──< (N) Transactions    → via table_reference
Transaction (1) ──< (N) TransactionItems
Transaction (1) ──< (N) QueueEntries
Customer (1) ──< (N) Transactions  → opsional
```

### 5.2. Backend (Controller & Service)

#### 5.2.1. Controller Baru

| Controller | Method | Route | Fungsi |
|---|---|---|---|
| `TableController` | `index`, `create`, `store`, `edit`, `update`, `destroy`, `printQr` | `/admin/tables` | CRUD meja + generate QR |
| `GuestMenuController` | `show` | `/meja/{store}/{table}` | Halaman menu publik |
| `GuestCartController` | `addItem`, `removeItem`, `updateQty`, `summary` | `/meja/{store}/{table}/cart/*` | Keranjang guest |
| `GuestOrderController` | `store`, `confirm`, `payment`, `status` | `/meja/{store}/{table}/order/*` | Checkout & bayar |
| `KitchenDisplayController` | `index`, `updateStatus` | `/kitchen` | Layar dapur (sudah ada sebagian) |

#### 5.2.2. Service Baru

| Service | Tanggung Jawab |
|---|---|
| `MenuService` | Query produk + kategori untuk tampilan publik, filter aktif/stok tersedia |
| `GuestCartService` | Kelola keranjang berbasis session (array produk + quantity + modifier) |
| `GuestOrderService` | Konversi keranjang → Transaction + TransactionItems + QueueEntries |
| `PaymentService` | Generate QRIS, verifikasi pembayaran, callback |
| `QrCodeService` | Generate QR code untuk meja, simpan file |

#### 5.2.3. Contoh Struktur Service (pseudocode)

```
GuestCartService:
  - getCart($storeId, $sessionId): CartItem[]
  - addItem($storeId, $sessionId, $productId, $qty, $modifiers): void
  - removeItem($storeId, $sessionId, $itemKey): void
  - updateQuantity($storeId, $sessionId, $itemKey, $qty): void
  - clearCart($storeId, $sessionId): void
  - calculateSubtotal($storeId, $sessionId): float

GuestOrderService:
  - createOrder($storeId, $tableNumber, $sessionId, $customerName): Transaction
  - processPayment($transactionId, $paymentMethod, $paymentData): Transaction
  - notifyKitchen($transactionId): void
```

### 5.3. Frontend (Halaman Vue)

#### 5.3.1. Halaman Baru

| Halaman | File | Deskripsi |
|---|---|---|
| **Menu Publik** | `resources/js/pages/Guest/Menu.vue` | Grid produk + kategori + keranjang floating |
| **Detail Produk** | `resources/js/pages/Guest/ProductDetail.vue` | Modal/halaman detail (gambar, variant, modifier) |
| **Keranjang** | `resources/js/pages/Guest/Cart.vue` | Review pesanan sebelum checkout |
| **Checkout** | `resources/js/pages/Guest/Checkout.vue` | Opsi pembayaran + input nama |
| **Pembayaran** | `resources/js/pages/Guest/Payment.vue` | QRIS / instruksi transfer |
| **Konfirmasi** | `resources/js/pages/Guest/OrderSuccess.vue` | Nomor pesanan, status, struk |
| **Manajemen Meja** | `resources/js/pages/Tables/Index.vue` | CRUD meja (admin) — bisa merge dengan halaman admin yang ada |

#### 5.3.2. Komponen Reusable

| Komponen | File | Berasal Dari |
|---|---|---|
| `ProductCard.vue` | `components/guest/ProductCard.vue` | Adaptasi dari POS |
| `CartDrawer.vue` | `components/guest/CartDrawer.vue` | Baru — sliding drawer dari kanan |
| `ModifierPicker.vue` | `components/guest/ModifierPicker.vue` | Adaptasi dari POS |
| `QuantityStepper.vue` | `components/guest/QuantityStepper.vue` | Adaptasi dari POS |
| `QrDisplay.vue` | `components/QrDisplay.vue` | Baru — tampilkan QR code |

#### 5.3.3. Layout Guest

Layout khusus untuk halaman publik:
- Header minimal: logo outlet + ikon keranjang
- Tanpa sidebar navigasi
- Mobile-first responsive (HP pelanggan)
- Warna sesuai tema toko

### 5.4. Routes

#### 5.4.1. Route Publik (tanpa middleware auth)

```php
// routes/public.php (di-include dari web.php)
Route::prefix('meja/{store:slug}')->group(function () {
    // Halaman menu
    Route::get('/{table:table_number}', [GuestMenuController::class, 'show'])
        ->name('guest.menu');

    // Keranjang (session-based)
    Route::post('/cart/add', [GuestCartController::class, 'addItem']);
    Route::post('/cart/remove', [GuestCartController::class, 'removeItem']);
    Route::post('/cart/update', [GuestCartController::class, 'updateQty']);
    Route::get('/cart/summary', [GuestCartController::class, 'summary']);

    // Order
    Route::post('/order', [GuestOrderController::class, 'store']);
    Route::get('/order/{transaction}', [GuestOrderController::class, 'status']);
    Route::post('/order/{transaction}/pay', [GuestOrderController::class, 'processPayment']);
});
```

#### 5.4.2. Route Admin (dengan middleware auth)

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('tables', TableController::class);
    Route::post('tables/{table}/print-qr', [TableController::class, 'printQr']);
    Route::get('tables/{table}/qr-code', [TableController::class, 'downloadQr']);
});
```

---

## 6. Best Practice & Saran Implementasi

### 6.1. Keamanan

| Praktik | Detail |
|---|---|
| **Rate Limiting** | Batasi akses route publik (`throttle:30,1`) untuk mencegah abuse |
| **Validasi Input** | Harga produk diambil dari server, jangan kirim harga dari client |
| **Session Expiry** | Keranjang guest expired dalam 1 jam — timeout dan bersihkan |
| **CORS** | Tidak perlu CORS karena rute publik masih dalam domain yang sama (Inertia) |
| **No SQL Injection** | Pastikan semua query parameter via Eloquent, bukan raw SQL |
| **QR Code Tamper** | QR berisi nomor meja + slug toko, jangan sertakan data sensitif |

### 6.2. Performa

| Praktik | Detail |
|---|---|
| **Caching Menu** | Cache daftar produk + kategori (cache key: `menu:{store_id}`) — refresh saat produk diubah |
| **Lazy Load Gambar** | Gunakan lazy loading untuk gambar produk (gambar produk bisa banyak) |
| **Pagination Menu** | Jika produk > 50, gunakan pagination atau infinite scroll |
| **Session Storage** | Gunakan `sessionStorage` (bukan `localStorage`) untuk keranjang — hilang saat tab ditutup |
| **Optimasi Gambar** | Kompres gambar produk, gunakan WebP bila memungkinkan |
| **Minimal Request** | Bundle semua data menu dalam 1 request saat halaman dimuat |

### 6.3. UX (User Experience)

| Praktik | Detail |
|---|---|
| **Mobile-First** | 90% pelanggan akses dari HP — desain untuk layar kecil dulu |
| **QR Code Visible** | Tempel QR di tempat yang mudah discan (sudut meja, gantungan) |
| **Loading State** | Tampilkan skeleton loading saat menu dimuat (koneksi HP bisa lambat) |
| **No Auth Required** | Pelanggan TIDAK perlu login/register — cukup scan QR, langsung lihat menu |
| **Search Produk** | Sediakan search bar untuk cari menu cepat |
| **Nomor Pesanan** | Tampilkan nomor pesanan besar dan jelas setelah checkout |
| **Estimasi Waktu** | Tampilkan estimasi waktu tunggu (jika bisa dihitung) |
| **Call Waiter** | Sediakan tombol "Panggil Pelayan" (notifikasi ke kasir) |

### 6.4. Pengembangan (Developer Experience)

| Praktik | Detail |
|---|---|
| **Gunakan Feature Flag** | Bungkus fitur baru dalam `FnBFeatures` atau `StorePlanLimits` — aktifkan per store |
| **Modular Controller** | Pisahkan controller publik dan admin, jangan campur dalam satu controller |
| **Service Layer** | Semua logika bisnis di Service, Controller hanya untuk routing + response |
| **Testing** | Buat test untuk: scan → order → payment flow (Pest) |
| **Session-Based Cart** | Gunakan session Laravel (bukan database) untuk keranjang guest — lebih ringan |
| **Consistent Naming** | Gunakan prefix `guest_` untuk route publik, `admin_` untuk admin |

### 6.5. Generate QR Code

**Rekomendasi package: `endroid/qr-code`** (PHP 8.3 compatible, aktif maintenance).

Alternatif: `simplesoftwareio/simple-qrcode` (wrapper untuk BaconQrCode).

**Isi QR Code:**
```
https://domain.com/meja/warung-sembako/A1
```

Cukup URL saja — tidak perlu encode data lain. QR cukup kecil (versi 4-6) sehingga mudah discan.

### 6.6. Output QR Code

| Metode | Kelebihan | Kekurangan |
|---|---|---|
| **Cetak label sticker** | Tahan lama, waterproof | Perlu printer label |
| **Cetak kertas biasa + laminating** | Murah | Kurang tahan lama |
| **Frame akrilik meja** | Profesional, tahan lama | Biaya lebih tinggi |
| **Digital (ditampilkan di layar)** | Gratis | Pelanggan harus punya HP |

**Rekomendasi:** Cetak QR di kertas stiker thermal (60x40mm) + laminating — ekonomis dan cukup tahan lama.

### 6.7. Format QR Code

```php
// Contoh generate QR di TableController
$qrData = route('guest.menu', [
    'store' => $store->slug,
    'table' => $table->table_number
]);

// Simpan sebagai file PNG
$result = QrCodeService::generate($qrData, storage_path("app/public/qr-codes/{$store->id}-{$table->table_number}.png"));
```

---

## 7. Matrix Prioritas Fitur (MVP vs Lanjutan)

### MVP (Minimum Viable Product) — Fase 1

| Fitur | Estimasi | Dependensi |
|---|---|---|
| Migrasi `tables` + Model `Table` | 4 jam | - |
| CRUD Meja (Admin) | 6 jam | Migrasi Tables |
| Generate QR Code Meja | 4 jam | Package QR, CRUD Meja |
| Halaman Menu Publik (Guest) | 12 jam | - |
| Guest Cart (session-based) | 8 jam | Halaman Menu |
| Checkout → Transaction | 6 jam | Guest Cart, Produk |
| Notifikasi Dapur (polling) | 4 jam | Checkout |
| Bayar di Kasir (default) | 4 jam | Checkout |
| **Total MVP** | **~48 jam (6 hari kerja)** | |

### Fase 2 (Lanjutan)

| Fitur | Estimasi | Dependensi |
|---|---|---|
| Pembayaran Mandiri (QRIS) | 16 jam | Integrasi payment gateway |
| Verifikasi Pembayaran Otomatis | 8 jam | Payment gateway |
| Cetak Struk Digital (PDF) | 4 jam | Checkout |
| Order History (per pelanggan) | 6 jam | Customer model |
| Real-time WebSocket (Pusher) | 12 jam | Infrastruktur |
| Multi-bahasa (menu) | 8 jam | - |
| Tombol "Panggil Pelayan" | 4 jam | Notifikasi |
| Split Bill | 8 jam | FnBFeatures |
| **Total Fase 2** | **~66 jam** | |

### Fase 3 (Opsional)

| Fitur | Keterangan |
|---|---|
| Login via OTP (WhatsApp) | Track history pesanan |
| Favorite menu | Personalisasi |
| Rating & Review | Feedback pelanggan |
| Rekomendasi menu (AI/rule-based) | Upsell |
| Multi-meja (satu akun pesan untuk banyak meja) | Event gathering |

---

## 8. Potensi Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| **Pelanggan input harga sendiri** | Tinggi | Harga wajib dari server, jangan trusted input client |
| **Session hilang** | Sedang | Simpan session di server (file/database) — jangan andalkan cookie saja |
| **QR code discan orang luar** | Rendah | Tidak ada data sensitif di QR, hanya URL menu |
| **Beban server naik** | Sedang | Cache menu, rate limiting, pagination |
| **Koneksi HP lemot** | Tinggi | Optimasi gambar, bundle kecil, lazy load |
| **Pesanan double** | Sedang | Locking meja saat ada transaksi aktif (status pending) |
| **Printer dapur tidak nyala** | Sedang | Queue tetap tersimpan di DB, bisa dicetak ulang |
| **Pelanggan pesan lalu pergi tanpa bayar** | Tinggi | Wajib bayar di muka (pre-payment) atau verifikasi kasir |

---

## Catatan Implementasi Teknis

### Package yang Perlu Ditambahkan

```json
// composer.json
"endroid/qr-code": "^6.0"

// package.json (jika perlu QR generation di frontend)
// Catatan: @zxing/browser sudah ada untuk scan QR
```

### Environment Variables

```env
# Untuk real-time notification (Fase 2)
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=

# Atau gunakan polling sederhana untuk MVP (tanpa env tambahan)
```

### Contoh Inertia Shared Data untuk Guest Pages

```php
// AppServiceProvider atau middleware
Inertia::share('guest', function () {
    return [
        'store' => request()->route('store'),
        'table' => request()->route('table'),
    ];
});
```

---

## Kesimpulan

Proyek UMKM Kasir sudah memiliki **80% fondasi** yang dibutuhkan untuk fitur ini:
- Manajemen produk, kategori, varian, dan modifier → **Siap**
- Sistem transaksi dan antrian → **Siap**
- Barcode scanner (QR) → **Siap**
- Cetak struk dan notifikasi dapur → **Siap**

Yang perlu dibangun dari awal:
1. **Model & manajemen meja** (tabel baru)
2. **Halaman publik untuk pelanggan** (tanpa login)
3. **Keranjang belanja guest** (session-based)
4. **QR code generator** (server-side)

**Rekomendasi pendekatan:** Gunakan pola yang sama dengan yang sudah ada (Inertia + Vue 3) dengan layout khusus Guest, reuse komponen POS yang sudah ada, dan tambahkan service layer baru untuk logika guest order. Jangan buat aplikasi terpisah — cukup perluas routing yang sudah ada dengan group publik tanpa middleware auth.