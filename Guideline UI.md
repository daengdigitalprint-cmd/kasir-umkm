# 💎 Master Design System Guideline
## Version 1.0 | The "Premium & Modern" Blueprint

Panduan master ini dirancang untuk menciptakan antarmuka (UI) yang mewah, bersih, dan fungsional yang dapat diterapkan pada berbagai proyek web.

---

### 🏛️ 1. Filosofi Desain
*   **Minimalisme Mewah**: Menghilangkan elemen yang tidak perlu, tetapi memberikan detail tinggi pada elemen yang tersisa.
*   **Kedalaman (Depth)**: Menggunakan layer, shadow, dan blur untuk menciptakan hierarki visual.
*   **Kejelasan Data**: Informasi penting harus langsung terlihat melalui kontras warna dan ukuran tipografi.

---

### 🎨 2. Sistem Warna (Semantic Palette)
*Gunakan nama-nama berikut untuk memudahkan penggantian tema antar proyek.*

| Nama Brand | Token Utama | Karakteristik |
| :--- | :--- | :--- |
| **Project Primary** | `--primary` | Warna identitas utama (misal: Indigo, Emerald, atau Rose). |
| **App Surface** | `--surface` | Latar belakang bersih (`#F8FAFC`). |
| **Card Background** | `--card` | Putih bersih (`#FFFFFF`) dengan border halus. |
| **Accent Glow** | `--accent` | Warna primary dengan opasitas 10-20% untuk overlay. |
| **Neutral Text** | `--text-main` | Slate Gelap (`#1E293B`) untuk keterbacaan tinggi. |

---

### 🖋️ 3. Tipografi (Typography)
*Rekomendasi font: **Inter, Outfit, Roboto, atau Cabinet Grotesk**.*

*   **Headline (H1, H2)**: Bobot `700 (Bold)` atau `900 (Black)`. Gunakan kerning (letter-spacing) yang rapat untuk judul besar.
*   **Body Text**: Bobot `400` atau `500`. Ukuran standar `14px` - `16px`.
*   **Metadata Labels**:
    *   Style: `Uppercase`, `Font-Black`, `Tracking-Widest`.
    *   Ukuran: `10px` - `11px`.
    *   Warna: `Slate-400`.

---

### 🧱 4. Standar Komponen (UI Components)

#### 🖼️ Kartu & Container (Premium Cards)
*   **Border Radius**: Gunakan radius ekstrem untuk kesan modern (`rounded-3xl` atau `rounded-[2rem]`).
*   **Shadow System**:
    *   `Soft`: `box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1)`.
    *   `Glowing`: Tambahkan shadow warna primary dengan opasitas rendah (`shadow-primary/20`).
*   **Border**: Gunakan border tipis (`1px`) dengan warna sangat terang (`slate-100/200`).

#### ⚡ Tombol & Interaksi (Interactive Elements)
*   **Hover State**: Gunakan `scale-[1.02]` dan `transition-all`.
*   **Active State**: Gunakan `scale-[0.98]` untuk memberikan feedback tactile.
*   **Transitions**: Selalu gunakan durasi `300ms` dengan easing `ease-in-out` atau `cubic-bezier`.

---

### ✨ 5. Estetika Visual Khusus

#### 💎 Glassmorphism (Efek Kaca)
Gunakan pada navbar, modal, atau card highlight:
- `background: rgba(255, 255, 255, 0.7);`
- `backdrop-filter: blur(12px);`
- `border: 1px solid rgba(255, 255, 255, 0.3);`

#### 📊 Visualisasi Data
- **Gradients**: Gunakan gradasi linear halus dari warna primary ke warna sekundernya yang lebih terang.
- **Progress Bars**: Ujung bundar (full rounded) dengan kilauan di dalamnya (`bg-white/20`).

---

### 📱 6. Strategi Layar (Responsive Design)
1.  **Mobile (768px)**: Sembunyikan elemen dekoratif, fokus pada navigasi satu tangan (Bottom Nav).
2.  **Desktop (1280px+)**: Gunakan sidebar tetap dan card grid yang luas.
3.  **Spasi**: Gunakan skala 4 (p-4, p-8, p-12) untuk menjaga konsistensi ritme visual.

---

### 🛠️ 7. Checklist Penerapan (Implementation)
- [ ] Apakah font sudah menggunakan **Google Fonts/Modern Sans**?
- [ ] Apakah radius sudah konsisten di seluruh elemen?
- [ ] Apakah ada feedback visual (hover/click) pada setiap elemen interaktif?
- [ ] Apakah kontras warna sudah memenuhi standar aksesibilitas?
- [ ] Apakah metadata kecil sudah menggunakan gaya **Uppercase + High Tracking**?

---

> [!TIP]
> **"Less is more, but details are everything."**
> Jangan takut memberikan padding ekstra. Ruang kosong (White Space) adalah kunci dari desain yang terasa premium dan tidak melelahkan mata.
