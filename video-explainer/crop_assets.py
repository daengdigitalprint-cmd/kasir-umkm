"""Potong elemen UI dari screenshot akun demo -> assets/ (aset video explainer)."""
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).parent
SHOTS = ROOT / 'shots'
OUT = ROOT / 'assets'
OUT.mkdir(exist_ok=True)

# nama aset: (file sumber, kotak (x0, y0, x1, y1) dalam piksel gambar 2x)
CROPS = {
    'card-semen': ('pos-1-semen.png', (64, 1366, 796, 1552)),
    'card-total-produk': ('hp-produk.png', (32, 184, 418, 432)),
    'card-stok-rendah': ('hp-produk.png', (442, 184, 828, 432)),
    'card-stok-menipis': ('hp-stok.png', (34, 912, 826, 1172)),
    'card-nilai-stok': ('hp-stok-full.png', (34, 1512, 826, 1666)),
    'card-total-piutang': ('hp-hutang.png', (64, 356, 418, 672)),
    'card-lewat-tempo': ('hp-hutang.png', (442, 356, 796, 672)),
    'row-hutang-karya': ('hp-hutang.png', (66, 1012, 794, 1266)),
    'row-hutang-darmawan': ('hp-hutang.png', (66, 1268, 794, 1524)),
    'pay-total': ('pos-4-bayar.png', (74, 1090, 786, 1320)),
    'cart-sheet': ('pos-3-keranjang.png', (24, 536, 836, 1330)),
    'tren-omzet': ('hp-laporan-full.png', (16, 3860, 844, 4720)),
    'produk-terlaris': ('hp-laporan-full.png', (16, 7520, 844, 8150)),
    'metode-bayar': ('hp-laporan-full.png', (16, 4905, 844, 5520)),
    'laba-bersih': ('hp-laba-rugi-full.png', (16, 2735, 844, 2905)),
    'laba-rows': ('hp-laba-rugi-full.png', (16, 1860, 844, 2640)),
    'omzet-kpi': ('hp-laporan.png', (32, 950, 828, 1402)),
}


def main():
    sheet_items = []
    for name, (src, box) in CROPS.items():
        im = Image.open(SHOTS / src).convert('RGB')
        c = im.crop(box)
        c.save(OUT / f'{name}.png', optimize=True)
        sheet_items.append((name, c))
        print(f'{name}: {c.size}')
    # lembar kontak untuk diperiksa
    cols, cell = 4, 420
    rows = (len(sheet_items) + cols - 1) // cols
    sheet = Image.new('RGB', (cols * cell, rows * (cell + 30)), (235, 235, 235))
    d = ImageDraw.Draw(sheet)
    for i, (name, c) in enumerate(sheet_items):
        t = c.copy()
        t.thumbnail((cell - 10, cell - 10))
        x, y = (i % cols) * cell, (i // cols) * (cell + 30)
        sheet.paste(t, (x + 5, y + 5))
        d.text((x + 6, y + cell + 6), name, fill=(0, 0, 0))
    sheet.save(ROOT / '_crops-sheet.png')


if __name__ == '__main__':
    main()
