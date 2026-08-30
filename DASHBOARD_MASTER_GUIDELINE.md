# 🚀 Dashboard UI Master Guideline
## Version 2.0 | High-Performance & Premium Aesthetic

Panduan ini adalah standar emas (Gold Standard) untuk membangun Dashboard Admin yang modern, mewah, dan fungsional. Dirancang untuk memberikan pengalaman pengguna yang intuitif di berbagai jenis aplikasi (SaaS, ERP, E-commerce, dll), memastikan konsistensi visual yang premium di seluruh ekosistem aplikasi Anda.

---

### 🏛️ 1. Arsitektur Layout (The Structure)
Dashboard yang baik memiliki hierarki yang jelas. Gunakan pembagian area berikut sebagai fondasi:

- **Sidebar (The Hub)**: 
    - Lebar dasar: `280px`.
    - Style: Glassmorphism (`backdrop-blur`) atau Solid White dengan border kanan tipis (`1px`).
    - Fungsi: Navigasi utama, Branding, dan User Profile.
- **Header (Utility Bar)**: 
    - Tinggi: `72px`.
    - Elemen: Breadcrumbs (sisi kiri), Search/Notifications (tengah), Quick Actions (kanan).
- **Content Area (Main Stage)**: 
    - Background: `--surface` (`#F8FAFC`).
    - Spacing: Gunakan `p-8` (32px) untuk memberikan ruang bernapas yang cukup.
- **Footer (Context)**: 
    - Tinggi: `60px`.
    - Minimalis, berisi copyright dan link dokumentasi/bantuan.

---

### 🎨 2. Design Tokens (The Soul)
Gunakan sistem token agar mudah diimplementasikan ke proyek lain hanya dengan mengubah variabel di satu tempat.

#### A. Color Palette (Semantic)
| Token | Hex/Value | Kegunaan |
| :--- | :--- | :--- |
| **Primary** | `--primary` (`#6366F1`) | Warna brand, CTA utama, active indicator. |
| **Surface** | `--surface` (`#F8FAFC`) | Latar belakang aplikasi (canvas). |
| **Card** | `--card` (`#FFFFFF`) | Latar belakang konten/data storage. |
| **Subtle** | `--subtle` (`#F1F5F9`) | Background input, divider, atau header tabel. |
| **Success** | `--success` (`#10B981`) | Status Berhasil, Transaksi Selesai. |
| **Warning** | `--warning` (`#F59E0B`) | Peringatan, Pending, Perhatian. |
| **Danger** | `--danger` (`#EF4444`) | Error, Destructive Action, Gagal. |

#### B. Depth & Elevation
- **Standard Card**: `rounded-[2rem]` (32px) dengan `shadow-sm`.
- **Floating Card**: `shadow-xl shadow-primary/5` (Efek melayang sangat halus).
- **High-End Blur**: `backdrop-filter: blur(12px)` untuk modal atau navigasi overlay.

---

### 🖋️ 3. Tipografi (The Voice)
Pilihan font sangat menentukan "kelas" desain. Gunakan **Inter**, **Outfit**, atau **Roboto**.

- **Title Large (H1)**: `32px`, `Bold (700)`, `Tracking Tight (-0.02em)`.
- **Title Medium (H2)**: `24px`, `Bold (700)`.
- **Body Regular**: `15px`, `Medium (500)`, `Leading Relaxed`.
- **Meta/Caption**: `11px`, `Black (900)`, `Uppercase`, `Tracking-[0.1em]`, `Color: Slate-400`.

---

### 🧱 4. Komponen Utama (The Building Blocks)

#### 📊 Widget Statistik (Stats Card)
- **Visual**: Icon berlatar belakang Primary dengan opasitas 10% di sisi kiri.
- **Karakter**: Gunakan font title yang tegas untuk angka.
- **Hover**: Transisi halus pada border atau penambahan bayangan (`shadow-md`).

#### 🗂️ Data Table (The Core)
- **Style**: Tanpa border luar, gunakan dividder antar baris saja.
- **Header**: Text alignment konsisten, font Meta/Caption.
- **Row Interaction**: Background highlight saat hover (`bg-slate-50`).
- **Empty State**: Ilustrasi minimalis + Judul informatif + Tombol "Tambah Data" (Call to Action).

#### 🔘 Buttons & Interactions
- **Primary**: Gradient halus (`from-primary/90 to-primary`), rounded-xl.
- **Interaction**: 
    - Hover: `scale-[1.02]` (Transformasi cepat 200ms).
    - Active: `scale-[0.98]` (Feel menekan).

---

### ✨ 5. Micro-Animations (The Polish)
Desain yang "Premium" tidak statis.
- **Loading State**: Gunakan **Skeleton Screen** dengan efek shimmer (pulse). Hindari spinner yang membosankan.
- **Success Feedback**: Gunakan "Checkmark Animation" yang memancar keluar.
- **Enter/Exit**: Modals harus `fade-in` dan `scale-up` (0.95 -> 1.0) dalam durasi 300ms.

---

### 📱 6. Mobile Strategy
1. **The Drawer**: Sidebar berubah menjadi menu samping yang bisa di-swipe atau dipanggil via Hanburger.
2. **Compact View**: Sembunyikan kolom tabel yang tidak kritikal pada mobile, sediakan "View Detail".
3. **Bottom Sticky**: Penting untuk tombol "Save" atau "Submit" agar selalu terjangkau ibu jari.

---

### 🛠️ 7. Tailwind Implementation (Base Template)
Salin ini ke `tailwind.config.js` Anda:

```javascript
module.exports = {
  theme: {
    extend: {
      colors: {
        'app-primary': '#6366F1',
        'app-surface': '#F8FAFC',
        'app-card': '#FFFFFF',
        'app-meta': '#94A3B8',
      },
      borderRadius: {
        'app-card': '2rem', // 32px
        'app-btn': '0.75rem', // 12px
      },
      boxShadow: {
        'premium': '0 10px 15px -3px rgba(99, 102, 241, 0.05), 0 4px 6px -2px rgba(99, 102, 241, 0.025)',
      }
    }
  }
}
```

---

### 💡 Golden Rules (Pro-Tips)
1. **White Space is Luxury**: Ruang kosong bukan ruang terbuang; itu adalah pemandu mata.
2. **Icon Consistency**: Jangan campur library berbeda. (Lucide sangat disarankan).
3. **Standard Radius**: Jangan gunakan `rounded-md` di satu tempat dan `rounded-full` di tempat lain secara acak. Pilih satu bahasa visual.

---
> "Great UI is invisible. Premium UI is unforgettable."
