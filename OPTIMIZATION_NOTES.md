# Optimasi Performa Landing Page - FOUC Prevention

## Masalah yang Diatasi
Pada akses pertama kali ke landing page, terjadi **Flash of Unstyled Content (FOUC)** di mana tampilan halaman terlihat berantakan sebelum CSS sepenuhnya dimuat.

## Solusi yang Diterapkan

### 1. Critical CSS Inline (app.blade.php)
Menambahkan CSS kritis langsung di `<head>` untuk mencegah FOUC:
- Background color untuk `.body-bg-2` (#EDF1FF)
- Font family fallback (DM Sans → Inter → system)
- Base layout (margin, padding, overflow-x)
- Color dan typography dasar

### 2. Async Loading untuk CSS Non-Kritis (Index.vue)
Memisahkan CSS menjadi dua kategori:

**Blocking (Critical):**
- `bootstrap.min.css` - framework layout
- `main.css` - styling utama

Dengan preload:
```vue
<link rel="preload" as="style" href="/buyer-assets/css/bootstrap.min.css" />
<link rel="preload" as="style" href="/buyer-assets/css/main.css" />
```

**Async (Non-Critical):**
Menggunakan teknik `media="print"` + `onload="this.media='all'"` untuk:
- all.min.css (icons)
- animate.css (animations)
- magnific-popup.css (lightbox)
- meanmenu.css (mobile menu)
- swiper-bundle.min.css (carousel)
- nice-select.css (dropdown)
- flaticon.css (icon fonts)

### 3. Font Loading Optimization (app.blade.php)
- Preconnect ke Google Fonts CDN
- Preload font Nunito & Poppins untuk landing page
- Menggunakan `display=swap` untuk mencegah FOIT (Flash of Invisible Text)
- Fallback font stack lengkap

### 4. Penghapusan Efek Custom Cursor
Menghapus elemen `<div class="mouseCursor">` yang tidak diperlukan dan dapat memperlambat rendering.

## Hasil yang Diharapkan

✅ **First Contentful Paint (FCP)** lebih cepat karena critical CSS inline
✅ **Largest Contentful Paint (LCP)** tidak terblokir oleh CSS non-kritis
✅ Tidak ada flash/lompatan layout saat halaman dimuat
✅ Font langsung muncul dengan fallback, tidak ada teks invisible
✅ CSS non-kritis dimuat secara asynchronous tanpa memblokir rendering

## Cara Testing

1. **Hard refresh** dengan cache kosong (Ctrl+Shift+R / Cmd+Shift+R)
2. Gunakan **Chrome DevTools**:
   - Network tab → pilih "Slow 3G" throttling
   - Performance tab → record page load
   - Lighthouse → audit performa
3. Periksa metrik:
   - FCP harus < 1.8s
   - LCP harus < 2.5s
   - Tidak ada layout shift besar (CLS < 0.1)

## File yang Dimodifikasi

1. `resources/views/app.blade.php` - Critical CSS inline + font preload
2. `resources/js/pages/Landing/Index.vue` - CSS async loading + preload
3. `resources/js/pages/Landing/Index.vue` - Hapus custom cursor elements

## Catatan Teknis

- Critical CSS hanya mencakup above-the-fold styling untuk performa optimal
- Teknik `media="print" onload="this.media='all'"` adalah hack untuk async CSS yang kompatibel dengan semua browser
- Preload memberikan hint ke browser untuk download CSS lebih awal
- Font swap mencegah teks invisible selama font custom dimuat

---
Dibuat: 2026-06-26
