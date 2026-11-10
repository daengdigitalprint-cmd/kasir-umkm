"""
Hasilkan versi WebP yang sudah di-resize untuk gambar landing page.

Lebar target = ~2x lebar tampil maksimum (diukur di viewport 1920/1366/412),
sehingga tetap tajam di layar retina tapi tidak mengirim piksel berlebih.
File asli TIDAK ditimpa (dipakai halaman lain / og:image).

    python scripts/optimize-landing-images.py
Butuh: pip install pillow
"""

from pathlib import Path

from PIL import Image

PUBLIC = Path(__file__).resolve().parent.parent / 'public'

# (sumber, output, lebar target atau None = lebar asli, lossless)
JOBS = [
    ('logo-macca.png', 'logo-macca-sm.webp', 360, False),            # tampil 148-180px
    ('Multi-Cabang.png', 'Multi-Cabang-sm.webp', 566, False),        # tampil <=283px
    ('cecklist.webp', 'cecklist-sm.webp', 556, False),               # tampil <=278px
    ('mockup-akses.png', 'mockup-akses.webp', None, False),          # tampil 215px (asli 307)
    ('struk.png', 'struk.webp', None, False),                        # tampil <=353px (asli 354)
    ('card produk.webp', 'card-produk-sm.webp', 572, False),         # tampil <=286px
    ('googleplay-qr.png', 'googleplay-qr-sm.webp', 278, True),       # QR: lossless, tampil 139px
    ('Pencatatan.webp', 'Pencatatan-800.webp', 800, False),          # srcset mobile (tampil 328px)
    ('kontrol-produk.webp', 'kontrol-produk-800.webp', 800, False),  # srcset mobile (tampil 320px)
    ('produk.webp', 'produk-800.webp', 800, False),
    ('laporan-keuangan.webp', 'laporan-keuangan-800.webp', 800, False),
    ('multi cabang.webp', 'multi-cabang-800.webp', 800, False),
    ('sastik-assets/img/shape/circle-blur.png', 'sastik-assets/img/shape/circle-blur.webp', 664, False),  # gradien blur: setengah resolusi tak terlihat bedanya
    ('sastik-assets/img/pricing/img01.png', 'sastik-assets/img/pricing/img01.webp', None, False),
]

# Ikon PNG berukuran benar. favicon.png / logo.png / icons/icon-*.png yang ada
# semuanya salinan logo 512px (±253 KB) — terlalu berat untuk favicon 16-32px.
# (sumber, output, ukuran persegi)
ICON_JOBS = [
    ('logo.png', 'favicon-32.png', 32),
    ('logo.png', 'apple-touch-icon-180.png', 180),
    ('logo.png', 'icons/pwa-192.png', 192),
]


def main():
    for src, out, width, lossless in JOBS:
        sp, op = PUBLIC / src, PUBLIC / out
        im = Image.open(sp)
        im = im.convert('RGBA') if im.mode in ('P', 'LA', 'RGBA') or 'transparency' in im.info else im.convert('RGB')
        if width and im.width > width:
            im = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
        if lossless:
            im.save(op, 'WEBP', lossless=True, method=6)
        else:
            im.save(op, 'WEBP', quality=80, method=6)
        print(f'{src} ({sp.stat().st_size // 1024} KB) -> {out} {im.width}x{im.height} ({op.stat().st_size // 1024} KB)')

    for src, out, size in ICON_JOBS:
        sp, op = PUBLIC / src, PUBLIC / out
        im = Image.open(sp).convert('RGBA').resize((size, size), Image.LANCZOS)
        # Palet 256 warna (dengan alfa): jauh lebih kecil, beda tak terlihat di ukuran ikon.
        im = im.quantize(colors=256, method=Image.Quantize.FASTOCTREE)
        im.save(op, 'PNG', optimize=True)
        print(f'{src} ({sp.stat().st_size // 1024} KB) -> {out} {size}x{size} ({op.stat().st_size / 1024:.1f} KB)')


if __name__ == '__main__':
    main()
