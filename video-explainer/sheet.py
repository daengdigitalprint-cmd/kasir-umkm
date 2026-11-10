"""Gabungkan _snap/*.png jadi lembar kontak berlabel detik."""
import sys
from pathlib import Path

from PIL import Image, ImageDraw

d = Path(__file__).parent / '_snap'
files = sorted(d.glob('t*.png'), key=lambda f: float(f.stem[1:]))
cols = int(sys.argv[1]) if len(sys.argv) > 1 else 6
w, h = 270, 480
rows = (len(files) + cols - 1) // cols
sheet = Image.new('RGB', (cols * w, rows * (h + 26)), (30, 30, 36))
dr = ImageDraw.Draw(sheet)
for i, f in enumerate(files):
    im = Image.open(f).convert('RGB').resize((w, h))
    x, y = (i % cols) * w, (i // cols) * (h + 26)
    sheet.paste(im, (x, y))
    dr.text((x + 6, y + h + 6), f'{float(f.stem[1:]):.2f}s', fill=(240, 240, 240))
out = Path(__file__).parent / (sys.argv[2] if len(sys.argv) > 2 else '_sheet.png')
sheet.save(out)
print(out, sheet.size)
