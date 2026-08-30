# 📐 Master Design: Premium Sidebar & Iconography
## Spesifikasi Standar untuk Navigasi Web Modern

Sidebar adalah tulang punggung navigasi. Master design ini fokus pada **kejelasan hirarki**, **feedback visual**, dan **estetika icon** yang konsisten.

---

### 🎨 1. Anatomi Sidebar Premium
Sidebar dibagi menjadi 3 area utama:
1.  **Header (Branding)**: Tinggi standar `80px`. Berisi Logo + Judul Aplikasi.
2.  **Navigation (Menu List)**: Body utama dengan scrollbar tersembunyi/halus.
3.  **Footer (User Profile)**: Area profil pengguna dengan akses logout cepat.

---

### 🖋️ 2. Standar Iconography
*Gunakan Library: **Lucide Icons** atau **Material Symbols (Rounded/Sharp edge)**.*

- **Ukuran Icon**: `20px` (atau `size-5` di Tailwind).
- **Stroke Width**: `2.0` atau `2.25` (Semi-bold) untuk keterbacaan tinggi.
- **Warna Inaktif**: `Slate-400` atau `Slate-500`.
- **Warna Aktif**: Identitas Brand (`Primary Color`).
- **Standard Spacing**: Jarak antara icon dan teks adalah `16px` (`ml-4`).

---

### 🧱 3. Gaya Menu & State (UI Logic)

#### 🔘 Menu Inaktif (Default)
- **Background**: Transparent.
- **Teks**: `Font-Bold`, `Text-Sm`, `Tracking-Tight`.
- **Hover**: `bg-slate-50` atau `bg-slate-100/50`. Icon melakukan `scale-110`.

#### 🔵 Menu Aktif (Focused)
- **Background**: `bg-primary/10` (Opasitas rendah dari warna brand).
- **Teks**: Warna brand solid (`text-primary`).
- **Indicator**: Side border tipis (`4px`) di sisi kiri/kanan dengan `rounded-full`.
- **Icon**: `text-primary`.

---

### 📑 4. Section & Badging
Gunakan "Section Headers" untuk membagi menu yang panjang:
- **Style**: `Uppercase`, `Font-Black`, `Tracking-[0.2em]`, `Text-[10px]`, `Text-Slate-400`.
- **Margin**: `mt-8` (Atas), `mb-4` (Bawah).

**Badge Notifikasi:**
- Letakkan di sisi kanan menu (`ml-auto`).
- **Style**: Kecil, solid color (Rose/Amber), font-black, rounded-lg.

---

### 💻 5. Implementasi Kode (Tailwind Blueprint)

```html
<!-- Sidebar Container -->
<aside class="w-72 bg-white border-r border-slate-200 h-screen flex flex-col">
    
    <!-- Branding -->
    <div class="h-20 px-6 flex items-center border-b border-slate-100">
        <div class="size-10 bg-primary rounded-xl shadow-lg shadow-primary/20 flex items-center justify-center">
            <!-- Brand Icon -->
        </div>
        <span class="ml-4 font-black text-xl tracking-tight">BRAND NAME</span>
    </div>

    <!-- Navigation List -->
    <nav class="flex-1 px-4 py-8 space-y-1">
        
        <!-- Section Header -->
        <p class="px-4 text-meta-label mb-4 mt-8">DATABASE UTAMA</p>

        <!-- Menu Item (Active) -->
        <a href="#" class="flex items-center px-4 py-3.5 rounded-2xl bg-primary/10 text-primary relative group">
            <svg class="size-5 group-hover:scale-110 transition-transform"><!-- Icon --></svg>
            <span class="ml-4 font-bold text-sm tracking-tight">Dashboard</span>
            <!-- Indicator -->
            <div class="absolute left-0 top-2 bottom-2 w-1 bg-primary rounded-r-full"></div>
        </a>

        <!-- Menu Item (Inactive) -->
        <a href="#" class="flex items-center px-4 py-3.5 rounded-2xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition-all group">
            <svg class="size-5 group-hover:scale-110 transition-transform"><!-- Icon --></svg>
            <span class="ml-4 font-bold text-sm tracking-tight text-slate-500 group-hover:text-slate-800">Users</span>
        </a>

    </nav>

    <!-- Footer Profile -->
    <div class="p-4 border-t border-slate-50">
        <div class="flex items-center p-3 rounded-3xl bg-slate-50 border border-slate-100">
            <img src="avatar.png" class="size-9 rounded-2xl border border-white shadow-sm" />
            <div class="ml-3">
                <p class="font-bold text-xs text-slate-800">Admin Utama</p>
                <p class="text-[9px] font-black text-slate-400 tracking-widest uppercase">Level 1</p>
            </div>
        </div>
    </div>

</aside>
```

---

### ✨ 6. Tips Icon Premium
1.  **Konsistensi Sudut**: Jika icon brand membulat, pastikan semua icon di sidebar juga memiliki `stroke-linecap="round"` dan `stroke-linejoin="round"`.
2.  **Satu Library**: Jangan mencampur Lucide dengan FontAwesome di dalam satu menu.
3.  **Warna Soft**: Gunakan warna inaktif yang tidak terlalu kontras agar mata fokus pada menu yang sedang aktif atau label notifikasi.

---

> [!NOTE]
> **Mobile Optimization**: Pada layar kecil, sidebar harus menggunakan efek `backdrop-blur-sm` pada overlay-nya untuk memberikan kesan kedalaman saat ditarik keluar (slide-in).
