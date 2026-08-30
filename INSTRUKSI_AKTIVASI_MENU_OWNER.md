# Instruksi Aktivasi Menu "Laporan" dan "Biaya Usaha" untuk Role Owner

## Ringkasan Perubahan

Telah dilakukan perubahan untuk mengaktifkan menu **"Laporan"** dan **"Biaya Usaha"** untuk role Owner:

### 1. ✅ File yang Sudah Diupdate

#### a. `database/seeders/RoleMenuPermissionSeeder.php`
- Menambahkan `'reports'` dan `'expenses'` ke array konfigurasi role `owner` (baris 49-54)
- Perubahan ini akan menambahkan permission ketika seeder dijalankan

#### b. `app/Console/Commands/AddOwnerMenuPermissions.php` (BARU)
- Command artisan khusus untuk menambahkan permission tanpa perlu running seeder lengkap
- Dapat dijalankan kapan saja: `php artisan permission:add-owner-menus`

#### c. `add_owner_menus.sql` (BARU)
- Script SQL untuk dijalankan manual jika lebih prefer query langsung

### 2. ✅ Validasi Backend

Method `canAccessBackOffice()` di `app/Models/User.php` (line 144) sudah mengizinkan role Owner:
```php
return $this->isAdmin() || $this->isManager() || $this->isOwner();
```

Jadi tidak ada perubahan middleware yang diperlukan.

### 3. ✅ Validasi Frontend

Menu di `resources/js/components/MainMenu.vue`:
- **Laporan** → `menuKey: 'reports'` (line 106)
- **Biaya Usaha** → `menuKey: 'expenses'` (line 107)

Keduanya menggunakan `backofficeOnly: true`, yang sesuai dengan middleware backend.

---

## Cara Menjalankan

### Pastikan Database Aktif Terlebih Dahulu

Sebelum menjalankan command di bawah, pastikan:
1. **Herd** sudah berjalan (buka aplikasi Herd Desktop)
2. **MySQL** berjalan di port 8889
3. Database **kasirumkm** dapat diakses

### Opsi 1: Menggunakan Artisan Command (DIREKOMENDASIKAN)

Jalankan command berikut di terminal:

```bash
php artisan permission:add-owner-menus
```

Command ini akan:
- Menambahkan menu `reports` ke role owner
- Menambahkan menu `expenses` ke role owner
- Menampilkan daftar lengkap menu yang dimiliki role owner

### Opsi 2: Menggunakan Seeder Lengkap

Jika ingin menjalankan seeder lengkap (akan update semua role):

```bash
php artisan db:seed --class=RoleMenuPermissionSeeder
```

### Opsi 3: Menggunakan SQL Manual

Jika prefer menjalankan SQL langsung, buka `add_owner_menus.sql` dan jalankan di MySQL client Anda.

---

## Verifikasi

Setelah menjalankan salah satu opsi di atas:

### 1. Cek Database
```sql
SELECT * FROM role_menu_permissions WHERE role = 'owner' ORDER BY menu_key;
```

Pastikan ada baris dengan:
- `role = 'owner'` dan `menu_key = 'reports'`
- `role = 'owner'` dan `menu_key = 'expenses'`

### 2. Test di Browser

1. **Login sebagai Owner**
   - Buka aplikasi di browser
   - Login menggunakan akun dengan role Owner

2. **Verifikasi Sidebar**
   - Menu **"Laporan"** harus muncul di section "Laporan & Keuangan"
   - Menu **"Biaya Usaha"** harus muncul di section "Laporan & Keuangan"

3. **Test Akses Langsung**
   - Akses URL: `https://umkm-kasir.test/reports`
   - Akses URL: `https://umkm-kasir.test/expenses`
   - Keduanya harus dapat diakses tanpa redirect atau error 403

4. **Test Fungsionalitas**
   - Coba semua fitur di halaman Laporan (filter, export, dll)
   - Coba semua fitur di halaman Biaya Usaha (tambah, edit, hapus)

### 3. Test Role Lain

Pastikan perubahan tidak mengganggu role lain:
- Login sebagai **Admin** → kedua menu tetap muncul
- Login sebagai **Manager** → kedua menu tetap muncul
- Login sebagai **Cashier** → kedua menu TIDAK muncul (sesuai desain)
- Login sebagai **Warehouse** → kedua menu TIDAK muncul (sesuai desain)

---

## Troubleshooting

### Problem: Database connection refused
**Solusi**: 
- Buka aplikasi Herd Desktop
- Pastikan MySQL service berjalan
- Cek di terminal: `herd status`

### Problem: Menu masih tidak muncul setelah update database
**Solusi**:
1. Clear cache aplikasi: `php artisan cache:clear`
2. Clear config cache: `php artisan config:clear`
3. Logout dan login ulang di browser
4. Hard refresh browser (Ctrl+Shift+R atau Cmd+Shift+R)

### Problem: Akses URL langsung tetap redirect
**Solusi**:
1. Cek di database apakah permission sudah ada
2. Cek apakah user yang login benar-benar memiliki role 'owner'
3. Cek log aplikasi: `storage/logs/laravel.log`

---

## Daftar File yang Dimodifikasi/Dibuat

- ✅ `database/seeders/RoleMenuPermissionSeeder.php` (MODIFIED)
- ✅ `app/Console/Commands/AddOwnerMenuPermissions.php` (CREATED)
- ✅ `add_owner_menus.sql` (CREATED)
- ✅ `INSTRUKSI_AKTIVASI_MENU_OWNER.md` (CREATED)

---

## Status Implementasi

- [x] Update seeder untuk menambahkan permission
- [x] Buat Artisan command untuk update permission
- [x] Buat SQL script untuk update manual
- [x] Validasi middleware backend sudah support Owner
- [x] Validasi konfigurasi menu frontend
- [ ] **MENUNGGU**: Jalankan command/seeder (butuh database aktif)
- [ ] **MENUNGGU**: Verifikasi di browser
- [ ] **MENUNGGU**: Test fungsionalitas lengkap
- [ ] **MENUNGGU**: Test role lain tidak terpengaruh

---

**Next Step**: Jalankan `php artisan permission:add-owner-menus` setelah database aktif, lalu test di browser.
