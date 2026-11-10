"""
Buat gambar pratinjau sosial (Open Graph / Twitter) 1200x630 untuk landing page.

Output: public/og-share.jpg — otomatis dipakai LandingSeo::openGraphImage().
    python scripts/build-og-image.py
Butuh: pip install pillow  (font: Segoe UI dari Windows, atau ganti FONT_DIR)
"""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
PUBLIC = ROOT / 'public'
OUT = PUBLIC / 'og-share.jpg'
FONT_DIR = Path('C:/Windows/Fonts')

W, H = 1200, 630
TOP, BOTTOM = (83, 58, 253), (49, 27, 146)  # ungu primer landing (#533AFD) -> lebih gelap


def font(name, size):
    return ImageFont.truetype(str(FONT_DIR / name), size)


def wrap(draw, text, fnt, max_w):
    words, lines, cur = text.split(), [], ''
    for w in words:
        test = (cur + ' ' + w).strip()
        if draw.textlength(test, font=fnt) <= max_w:
            cur = test
        else:
            lines.append(cur)
            cur = w
    lines.append(cur)
    return lines


def main():
    img = Image.new('RGB', (W, H))
    px = img.load()
    for y in range(H):
        t = y / (H - 1)
        row = tuple(round(TOP[i] + (BOTTOM[i] - TOP[i]) * t) for i in range(3))
        for x in range(W):
            px[x, y] = row

    # Lingkaran dekoratif lembut di belakang maskot
    glow = Image.new('L', (W, H), 0)
    ImageDraw.Draw(glow).ellipse((700, 70, 1190, 560), fill=60)
    img.paste((255, 255, 255), (0, 0), glow)

    # Maskot
    mascot = Image.open(PUBLIC / 'logo.png').convert('RGBA')
    mascot.thumbnail((440, 440), Image.LANCZOS)
    img.paste(mascot, (725, 95), mascot)

    d = ImageDraw.Draw(img)
    x, max_w = 72, 630

    # Label merek
    brand_f = font('seguisb.ttf', 30)
    d.rounded_rectangle((x, 78, x + 20 + d.textlength('UMKM Kasir', font=brand_f) + 20, 128), radius=25, fill=(255, 255, 255))
    d.text((x + 20, 84), 'UMKM Kasir', font=brand_f, fill=BOTTOM)

    # Judul
    title_f = font('segoeuib.ttf', 60)
    y = 160
    for line in wrap(d, 'Aplikasi Kasir Online untuk UMKM', title_f, max_w):
        d.text((x, y), line, font=title_f, fill=(255, 255, 255))
        y += 72

    # Subjudul
    sub_f = font('segoeui.ttf', 28)
    y += 14
    for line in wrap(d, 'Catat penjualan, kelola stok, cetak struk, dan pantau laporan keuangan dalam satu aplikasi.', sub_f, max_w):
        d.text((x, y), line, font=sub_f, fill=(225, 220, 255))
        y += 40

    # CTA / domain
    cta_f = font('seguisb.ttf', 28)
    cta = 'Mulai gratis  →  kasirumkm.web.id'
    y = min(y + 28, H - 58 - 44)  # selalu muat di dalam bingkai
    d.rounded_rectangle((x, y, x + d.textlength(cta, font=cta_f) + 48, y + 58), radius=29, fill=(160, 234, 113))
    d.text((x + 24, y + 11), cta, font=cta_f, fill=(17, 24, 39))

    img.save(OUT, 'JPEG', quality=86, optimize=True, progressive=True)
    print(f'{OUT.name}: {W}x{H}, {OUT.stat().st_size // 1024} KB')


if __name__ == '__main__':
    main()
