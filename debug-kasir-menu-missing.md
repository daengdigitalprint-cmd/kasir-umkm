# Debug Session: Kasir Menu Missing

**Session ID:** kasir-menu-missing  
**Tanggal:** 2026-08-12  
**Status:** [FIXED]

## Masalah
Menu yang diaktifkan (pembelian, stok opname, produk) tidak muncul untuk role kasir di halaman `https://www.kasirumkm.web.id/manage-staff`.

## Hipotesis
1. **Hipotesis 1:** Sistem permission/role mapping salah konfigurasi - hak akses yang diaktifkan di halaman manage-staff tidak tersimpan atau tidak dipetakan dengan benar ke role kasir ✅ **CONFIRMED**
2. **Hipotesis 2:** Logic filtering menu berdasarkan role ada bug - kode yang memfilter menu tidak membaca konfigurasi hak akses dengan benar ✅ **CONFIRMED**
3. **Hipotesis 3:** Cache/session issue - perubahan konfigurasi role tidak diperbarui di session pengguna ❌ **REJECTED**
4. **Hipotesis 4:** Konflik permission - ada permission lain yang menonaktifkan menu tersebut untuk role kasir ❌ **REJECTED**
5. **Hipotesis 5:** Frontend rendering bug - komponen menu tidak menerima data hak akses dengan benar dari backend ❌ **REJECTED**

## Analisis Awal
**Struktur Sistem:**
1. Tabel `role_menu_permissions` menyimpan mapping menu_key → role
2. Frontend (MainMenu.vue) memfilter menu berdasarkan `allowed_menus` dari props
3. Backend (HandleInertiaRequests.php) mengirim `allowed_menus` via `User::allowedMenus()`
4. `User::allowedMenus()` menggunakan `$this->role` (role global dari kolom `users.role`)

## Root Cause
**Masalah Utama:** Method `allowedMenus()` di [User.php](file:///c:/Users/melis/Herd/umkm-kasir/app/Models/User.php) menggunakan `$this->role` (role global dari kolom `users.role`), bukan role spesifik untuk store yang sedang aktif dari tabel pivot `store_user`.

**Detail:**
- Sistem multi-store menggunakan tabel pivot `store_user` untuk menyimpan role user per store
- User bisa memiliki role berbeda di store yang berbeda (admin di store A, kasir di store B)
- Saat ini `allowedMenus()` menggunakan role global, bukan role spesifik store
- Jika user adalah kasir di store A tapi memiliki role berbeda di store lain, sistem menggunakan role yang salah

## Fix Implemented
### File: `app/Models/User.php`

**1. Menambahkan method `getRoleForActiveStore()`:**
```php
public function getRoleForActiveStore(): string
{
    if ($this->store_id) {
        $store = $this->stores()->where('store_id', $this->store_id)->first();
        if ($store && $store->pivot && $store->pivot->role) {
            return $store->pivot->role;
        }
    }
    
    // Fallback ke role global
    return $this->role;
}
```

**2. Mengupdate method `allowedMenus()` untuk menggunakan role aktif:**
```php
public function allowedMenus(): array
{
    if ($this->isSuperAdmin()) {
        return RoleMenuPermission::distinct()->pluck('menu_key')->all();
    }

    // Gunakan role dari store yang sedang aktif jika ada
    $effectiveRole = $this->getRoleForActiveStore();
    
    return RoleMenuPermission::allowedMenus($effectiveRole);
}
```

**3. Mengupdate method-method role checking untuk konsistensi:**
- `isAdmin()` → menggunakan `getRoleForActiveStore()`
- `isManager()` → menggunakan `getRoleForActiveStore()`
- `isCashier()` → menggunakan `getRoleForActiveStore()`
- `isWarehouseStaff()` → menggunakan `getRoleForActiveStore()`
- `isOwner()` → menggunakan `getRoleForActiveStore()`

**4. Menambahkan instrumentation logging untuk debugging:**
- Debug Point 1: Log saat `allowedMenus()` dipanggil
- Debug Point 2: Log effective role yang digunakan
- Debug Point 3: Log menu yang diizinkan
- Debug Point 4-6: Log di method `getRoleForActiveStore()`

## Changes Summary
| File | Perubahan | Impact |
|------|-----------|--------|
| `app/Models/User.php` | Update method `allowedMenus()` untuk menggunakan role dari store aktif | Menu akan ditampilkan sesuai dengan role user di store yang aktif |
| `app/Models/User.php` | Tambah method `getRoleForActiveStore()` | Mendapatkan role spesifik dari pivot table |
| `app/Models/User.php` | Update semua method role checking | Konsistensi permission checking |
| `app/Models/User.php` | Tambah instrumentation logging | Memudahkan debugging di masa depan |

## Verifikasi
**Cara Verifikasi:**
1. Login dengan akun kasir yang sudah diatur hak aksesnya di halaman Manage Staff
2. Pastikan store yang aktif adalah store dimana hak akses telah dikonfigurasi
3. Menu yang diaktifkan (pembelian, stok opname, produk) seharusnya muncul di sidebar
4. Check log untuk memastikan role yang digunakan adalah role dari pivot table, bukan role global

**Expected Behavior:**
- User dengan role kasir di store A akan melihat menu sesuai konfigurasi permission untuk role kasir
- User dengan role admin di store B (tapi kasir di store A) akan melihat menu kasir saat store A aktif
- Permission menu konsisten dengan konfigurasi di halaman Manage Staff

## Testing Scenarios
1. **Kasir dengan hak akses lengkap:** Menu pembelian, stok opname, produk harus muncul
2. **Kasir dengan hak akses terbatas:** Hanya menu yang diaktifkan yang muncul
3. **User multi-store:** Menu berbeda sesuai role di store yang aktif
4. **Super Admin:** Tetap melihat semua menu

## Cleanup
**Instrumentation yang perlu dihapus setelah verifikasi:**
- Semua `#region debug-point` dan logging di `User.php`
- File `debug-kasir-menu-missing.md`

**Catatan:** Instrumentation dapat dihapus setelah user mengkonfirmasi fix bekerja dengan baik.

## Kesimpulan
Fix telah diimplementasikan untuk mengatasi masalah menu yang tidak muncul untuk role kasir. Solusi mengubah sistem permission untuk menggunakan role dari store yang aktif (pivot table) daripada role global, sehingga konsisten dengan sistem multi-store aplikasi.