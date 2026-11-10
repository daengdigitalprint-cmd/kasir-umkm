"""python sheet.py <tag> [cols] -> _sheet_<tag>.png"""
import sys
from pathlib import Path
from PIL import Image, ImageDraw
d = Path(__file__).parent / '_snap'; tag = sys.argv[1]
files = sorted(d.glob(f'{tag}_t*.png'), key=lambda f: float(f.stem.split('_t')[1]))
cols = int(sys.argv[2]) if len(sys.argv) > 2 else 6
w, h = 270, 480; rows = (len(files) + cols - 1) // cols
sheet = Image.new('RGB', (cols * w, rows * (h + 26)), (30, 30, 36)); dr = ImageDraw.Draw(sheet)
for i, f in enumerate(files):
    x, y = (i % cols) * w, (i // cols) * (h + 26)
    sheet.paste(Image.open(f).convert('RGB').resize((w, h)), (x, y)); dr.text((x + 6, y + h + 6), f.stem.split('_t')[1] + 's', fill=(240, 240, 240))
out = Path(__file__).parent / f'_sheet_{tag}.png'; sheet.save(out); print(out, sheet.size)
