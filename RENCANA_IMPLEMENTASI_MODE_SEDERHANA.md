# Rencana Implementasi Mode Sederhana

Tampilan ringkas untuk toko baru: hanya menu dan pengaturan inti yang muncul, fitur lanjutan dinyalakan satu per satu lewat halaman "Fitur Tambahan".

## Status Progres

| Tahap | Nama | Status |
|---|---|---|
| 0 | Keputusan aturan bisnis | Selesai (usulan disetujui) |
| 1 | Fondasi: penyimpanan mode dan daftar menu inti | Selesai |
| 2 | Saringan menu di sidebar | Selesai (belum dicek visual) |
| 3 | Pemilihan mode (pendaftaran dan pengaturan) | Selesai (belum dicek visual) |
| 4 | Halaman "Fitur Tambahan" | Selesai (belum dicek visual) |
| 5 | Penyederhanaan halaman Pengaturan Toko | Selesai (belum dicek visual) |
| 6 | Aplikasi mobile (NativePHP) | Selesai (belum dicoba di perangkat) |
| 7 | Test dan peluncuran | Belum |

Tahap 1–3 sudah menghasilkan Mode Sederhana yang bisa dipakai. Tahap 4–6 bisa dirilis menyusul.

---

## 1. Tujuan

- Pemilik toko baru bisa mulai berjualan tanpa melihat ±40 menu dan 19 saklar fitur sekaligus.
- Pengguna lama tidak terganggu: toko yang sudah ada tetap di Mode Lengkap.
- Naik dari sederhana ke lengkap tidak kehilangan data apa pun.

## 2. Prinsip

1. **Mode hanya menyembunyikan tampilan.** Jurnal, HPP, dan pergerakan stok tetap tercatat di belakang layar.
2. **Mode bukan hak akses.** Rute tidak diblokir oleh mode; yang memblokir tetap role (`EnsureMenuAccess`) dan paket (`EnsureAdvancedPlan`). Membuka URL menu tersembunyi tetap berfungsi.
3. **Urutan saringan menu:** saklar fitur toko → role → mode. Mode adalah saringan terakhir dan hanya bisa mengurangi.
4. **Mode berlaku per toko**, diubah oleh owner/admin, tidak dikaitkan dengan paket langganan.
5. **Fitur yang sedang dipakai tidak pernah disembunyikan** (lihat 4.3).

## 3. Keputusan yang perlu diambil (Tahap 0)

| # | Pertanyaan | Usulan |
|---|---|---|
| 1 | Mode bawaan toko baru | Sederhana |
| 2 | Mode toko yang sudah ada | Lengkap (tidak berubah, tanpa banner ajakan, tanpa tombol baru di sidebar) |
| 3 | Mode dikaitkan paket langganan? | Tidak |
| 4 | Outlet baru dalam satu grup usaha | Mengikuti mode outlet asal |
| 5 | Kasir/gudang/dapur ikut terpengaruh? | Ya, tapi menu mereka memang sudah sedikit; saringan role tetap berlaku |
| 6 | Nama di layar | "Mode Sederhana" dan "Mode Lengkap" |

## 4. Rancangan

### 4.1 Penyimpanan

Tanpa migrasi: simpan di kolom JSON `stores.settings`.

```text
settings.ui_mode         = 'simple' | 'full'     (tidak ada = 'full')
settings.simple_extras   = ['receivables', ...]  (menu lanjutan yang dinyalakan satu per satu)
```

Kelas baru `app/Support/UiMode.php`:

- `UiMode::of(Store $store): string`
- `UiMode::isSimple(Store $store): bool`
- `UiMode::coreMenus(Store $store): array` — menu inti menurut jenis usaha
- `UiMode::visibleMenus(Store $store): array` — inti + `simple_extras` + menu yang sedang dipakai

### 4.2 Menu inti per jenis usaha

Selalu tampil di Mode Sederhana (memakai `menu_key` yang sudah ada):

`dashboard`, `pos`, `pos_transactions`, `cash_shifts`, `products`, `product_categories`, `stock_overview`, `purchases`, `customers`, `reports`, `expenses`, `settings`, `manage_staff`, `cashier_pin_settings`

Tambahan otomatis menurut `business_type`:

| Jenis usaha | Tambahan |
|---|---|
| `fnb`, `bakery` | `tables`, `table_orders` |
| `laundry` | `laundry_orders` (menggantikan `pos`) |
| `barbershop` | `queues` |
| `pharmacy` | — (batch tetap lewat saklar fitur) |

Disembunyikan sampai dinyalakan: `cash_transactions`, `stock_movements`, `stock_adjustment`, `stock_opnames`, `stock_transfers`, `recipes`, `productions`, `purchase_returns`, `sales_returns`, `pre_orders`, `receivables`, `loyalty`, `discounts`, `kitchen_queue`, `activity_logs`, `kpi`, `attendance`, `payroll`, `manage_stores`, `manage_products`, serta seluruh menu akuntansi.

Catatan: `purchases` saat ini bertanda `advancedRetailOnly`. Aturan paket itu tetap berlaku di atas mode.

### 4.3 Aturan "sedang dipakai"

Menu tetap tampil di Mode Sederhana bila datanya aktif, supaya pengguna tidak kehilangan jejak:

| Menu | Syarat tetap tampil |
|---|---|
| `receivables` | ada transaksi dengan piutang belum lunas |
| `pre_orders` | ada pre-order belum selesai |
| `stock_transfers` | ada transfer belum diterima |
| `stock_opnames` | ada sesi opname masih draft |
| `kitchen_queue`, `queues` | saklar modulnya menyala |
| `attendance`, `payroll` | add-on aktif dan saklarnya menyala |

Hitungan ini di-cache 5 menit per toko agar tidak menambah query di setiap halaman.

### 4.4 Alur data ke frontend

`HandleInertiaRequests` menambah dua props:

```text
ui_mode              = 'simple' | 'full'
simple_visible_menus = [...]   (kosong bila mode lengkap)
```

`MainMenu.vue` menambah satu saringan setelah saringan role:

```text
jika ui_mode = 'simple' dan item.menuKey tidak ada di simple_visible_menus → sembunyikan
```

Item tanpa `menuKey` (menu akuntansi) diberi penanda baru `advancedOnly`.

## 5. Tahapan

### Tahap 1 — Fondasi

- `app/Support/UiMode.php` beserta daftar menu inti.
- Props baru di `app/Http/Middleware/HandleInertiaRequests.php`.
- Tipe props di `resources/js/types`.
- Selesai bila: unit test `UiMode` lulus untuk tiap jenis usaha dan untuk toko tanpa `ui_mode`.

### Tahap 2 — Saringan sidebar

- Saringan mode dan penanda `advancedOnly` di `resources/js/components/MainMenu.vue`.
- Tautan kecil di bawah sidebar: "Tampilkan semua menu" (hanya owner/admin).
- Selesai bila: toko `simple` menampilkan ±12–14 menu; toko `full` tidak berubah sama sekali.

### Tahap 3 — Pemilihan mode

- Pendaftaran (`RegisteredUserController`, `auth/Register.vue`): toko baru diisi `ui_mode = simple`.
- Outlet baru (`StoreController`, `Stores/Create.vue`): mengikuti outlet asal.
- Pengaturan Toko: kartu "Tampilan Aplikasi" dengan dua pilihan dan penjelasan singkat. Validasi di `StoreSettingsController::update`.
- Perubahan mode dicatat ke `module_activity_logs` (mekanisme yang sudah ada).
- Selesai bila: pindah mode bolak-balik tidak mengubah data atau saklar fitur lain.

### Tahap 4 — Halaman "Fitur Tambahan"

- Rute `settings/features`, halaman `resources/js/pages/Settings/Features.vue`.
- Kartu per fitur: nama, satu kalimat manfaat, tombol Aktifkan/Matikan, label paket bila perlu upgrade.
- Mengaktifkan kartu = menyalakan saklar fitur yang sudah ada **dan** menambah menunya ke `simple_extras`.
- Kelompok kartu: Stok lanjutan, Pembelian dan retur, Pelanggan dan promo, Dapur dan meja, Keuangan, SDM.
- Selesai bila: satu fitur bisa dinyalakan tanpa pindah ke Mode Lengkap, dan menunya langsung muncul.

### Tahap 5 — Pengaturan Toko yang ringkas

- Di Mode Sederhana hanya tampil: profil toko, struk, printer, pajak, metode bayar, PIN kasir.
- Bagian lain dilipat di bawah "Pengaturan lanjutan".
- Tidak ada perubahan pada data yang disimpan.

### Tahap 6 — Aplikasi mobile

- `app/NativeComponents`: menu bawah dan daftar menu memakai `UiMode::visibleMenus()` yang sama.

### Tahap 7 — Test dan peluncuran

- Feature test: props `ui_mode`, daftar menu per jenis usaha, aturan "sedang dipakai", pindah mode, rute tetap bisa diakses di Mode Sederhana.
- Peluncuran: toko lama tetap `full`. ~~Banner sekali tampil untuk owner: "Coba tampilan yang lebih ringkas".~~ **Dibatalkan** atas keputusan pemilik produk: toko lama yang sudah aktif berjualan tidak diubah dan tidak diajak beralih. Sidebar toko Mode Lengkap juga tidak mendapat tombol baru; pilihan mode hanya ada di Pengaturan → tab Toko.
- Ukur setelah 2–4 minggu: persentase toko baru yang membuat transaksi pertama dalam 24 jam, dan fitur tambahan yang paling sering dinyalakan.

## 6. Risiko

| Risiko | Penanganan |
|---|---|
| Pengguna mengira fitur hilang | Tautan "Tampilkan semua menu" selalu ada; pencarian menu tetap menemukan menu tersembunyi dan menawarkan mengaktifkannya |
| Dua sumber kebenaran (saklar fitur vs `simple_extras`) | Kartu Fitur Tambahan selalu mengubah keduanya sekaligus; mode lengkap mengabaikan `simple_extras` |
| Tambahan query per halaman | Hitungan "sedang dipakai" di-cache per toko; mode lengkap tidak menghitung apa pun |
| Panduan/video lama menunjukkan menu yang tidak tampil | Halaman bantuan menyebut cara beralih ke Mode Lengkap |

## 7. Di luar cakupan

- Mengubah hak akses role atau aturan paket langganan.
- Menyederhanakan layar POS itu sendiri.
- Mengubah pencatatan akuntansi atau stok.

## 8. Log Implementasi

### Tahap 0–3 (12 November 2026)

- Semua usulan di bagian 3 disetujui.
- `app/Support/UiMode.php`: mode, menu inti per jenis usaha, `visibleMenus()`, aturan "sedang dipakai" (piutang belum lunas, pre-order terbuka, transfer stok draft/terkirim; cache 5 menit).
- `HandleInertiaRequests`: props `ui_mode` dan `simple_visible_menus`.
- `MainMenu.vue`: saringan Mode Sederhana setelah saringan role, dan tombol "Tampilkan semua menu" / "Ringkas menu" untuk owner/admin.
- `PUT /settings/ui-mode` (`StoreSettingsController::updateUiMode`, middleware `role.store_admin`), dicatat ke log aktivitas (`ui_mode.changed`).
- Kartu "Tampilan Aplikasi" di tab Toko halaman Pengaturan (`settings/Profile.vue`).
- `StoreController::store`: toko pertama `simple`; outlet berikutnya mengikuti outlet lain di grup yang sama atau outlet aktif pembuatnya.
- Test: `tests/Feature/UiModeTest.php` (9 test).

Penyimpangan dari rancangan:

- Penanda `advancedOnly` tidak dibuat: semua item sidebar kecuali "Bantuan" sudah punya `menuKey`.
- Pendaftaran (`RegisteredUserController`) tidak diubah: toko dibuat di `StoreController::store`, jadi cukup di sana.
- Aturan "sedang dipakai" untuk stok opname draft belum dibuat.
- Perubahan mode dicatat ke log aktivitas umum, bukan `module_activity_logs` (tabel itu khusus saklar modul).

### Tahap 4 (12 November 2026)

- `app/Support/OptionalFeatures.php`: katalog 16 fitur dalam 6 kelompok, beserta aturan aktif, perlu upgrade, dan sedang dipakai.
- `Settings/FeatureController`: `GET /settings/features` dan `PUT /settings/features/{feature}` (middleware `role.store_admin`).
- Halaman `resources/js/pages/settings/Features.vue`: kartu per fitur dengan tombol Aktifkan/Matikan.
- Mengaktifkan = menyalakan saklar modul (lewat `ModuleService::toggle`, tercatat di `module_activity_logs`) dan menambah menunya ke `settings.simple_extras`. Mematikan = kebalikannya.
- Ditolak bila paket tidak mendukung (fitur bertanda paket berbayar) atau, saat mematikan, datanya masih berjalan.
- Tautan masuk: tombol "Fitur Tambahan" di sidebar (Mode Sederhana, owner/admin) dan tautan di kartu "Tampilan Aplikasi".
- Test: 6 test tambahan di `tests/Feature/UiModeTest.php`.

Penyimpangan dari rancangan:

- Akuntansi dan Absensi/Payroll tidak masuk katalog: akuntansi butuh penyiapan bagan akun, absensi/payroll adalah add-on berbayar. Keduanya tetap diatur dari Pengaturan Toko.
- Kelompok "SDM" diganti "Multi-outlet" (outlet, gudang, distribusi produk, transfer stok).
- Di Mode Lengkap, fitur tanpa saklar modul tidak bisa diubah dari halaman ini (menunya selalu tampil).

### Tahap 5 (12 November 2026)

- `settings/Profile.vue`, tab Toko: di Mode Sederhana blok "Modul Fitur" dilipat di balik tombol "Pengaturan lanjutan" (Tampilkan/Sembunyikan). Di Mode Lengkap tidak ada perubahan.
- Blok hanya disembunyikan (`v-show`), jadi nilai form tetap ikut tersimpan dan tidak ada perubahan pada data.
- Yang tetap tampil: informasi toko, biaya layanan F&B, tampilan struk, dan pengaturan PPN. Printer dan backup ada di tab masing-masing.

Penyimpangan dari rancangan:

- Saklar "Login Kasir PIN" dan metode pembayaran Scan Meja ikut terlipat, karena keduanya berada di dalam blok "Modul Fitur". Halaman pengelolaan PIN kasir sendiri tetap ada di menu "Login Kasir".

### Tahap 6 (12 November 2026)

- `app/NativeComponents/Lainnya/Index.php` dan `resources/views/native/lainnya/index.blade.php`: di Mode Sederhana, "Kas Masuk / Keluar", "Absensi Karyawan", dan "Penggajian" disembunyikan kecuali menunya ada di `UiMode::visibleMenus()`. Ada catatan kecil bahwa fitur lain dinyalakan dari versi web.
- Mode Lengkap tidak berubah.
- Test: 2 test tambahan di `tests/Feature/UiModeTest.php`.

Penyimpangan dari rancangan:

- Tab bawah (Kasir, Produk, Laporan, Lainnya) tidak diubah: keempatnya menu inti.
- Aplikasi mobile belum punya layar ganti mode atau Fitur Tambahan; keduanya diatur dari versi web.
- Layar tetap bisa dibuka lewat tautan langsung; mode hanya menyembunyikan dari daftar, sama seperti di web.
