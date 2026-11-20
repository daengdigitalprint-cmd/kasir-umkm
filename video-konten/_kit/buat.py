"""Buat video dari <folder>/video.json dengan pustaka komponen (_kit/komponen.js + komponen.css).

pakai (dari folder video-konten):
  python _kit/buat.py baru <folder>           folder video baru (aset + video.json contoh)
  python _kit/buat.py <folder>                 sinkron + rakit index.html
  python _kit/buat.py <folder> --cek           + lembar cek (1 potret per adegan, 0,35 dtk sebelum adegan berakhir) → <folder>/_sheet.png
  python _kit/buat.py <folder> --cek 4.8,9.8   + lembar cek di detik tertentu
  python _kit/buat.py <folder> --render        + render MP4 (dengan suara bila ada vo.wav, kalau tidak: preview-tanpa-suara.mp4)

Waktu elemen boleh angka (detik sejak awal adegan) atau "@penanda", "@penanda+0.3", "@penanda-0.2"
(penanda ditulis di naskah adegan itu sebagai [penanda]); "@adegan:penanda" untuk penanda dari adegan lain.
"""
import io
import json
import os
import re
import shutil
import subprocess
import sys

KIT = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(KIT)
TOOLS = os.path.join(ROOT, '_tools')
sys.path.insert(0, KIT)
import sinkron  # noqa: E402


def resolve(v, i, C, cue):
    if isinstance(v, dict):
        return {k: resolve(x, i, C, cue) for k, x in v.items()}
    if isinstance(v, list):
        return [resolve(x, i, C, cue) for x in v]
    if isinstance(v, str) and v.startswith('@'):
        m = re.match(r'^@(?:(\d+):)?(\w+)([+-]\d*\.?\d+)?$', v)
        if not m:
            raise SystemExit(f'adegan {i}: waktu tidak dikenali "{v}"')
        key = f'{m.group(1) or i}:{m.group(2)}'
        if key not in cue:
            raise SystemExit(f'adegan {i}: penanda [{m.group(2)}] tidak ada di naskah ("{v}")')
        return round(cue[key] - C[i] + float(m.group(3) or 0), 3)
    return v


def baru(folder):
    """Folder video baru: aset bawaan + video.json awal dari contoh A11."""
    if os.path.exists(os.path.join(folder, 'video.json')):
        raise SystemExit('video.json sudah ada di ' + folder)
    os.makedirs(os.path.join(folder, 'assets'), exist_ok=True)
    for f in os.listdir(os.path.join(KIT, 'assets')):
        shutil.copy(os.path.join(KIT, 'assets', f), os.path.join(folder, 'assets', f))
    shutil.copy(os.path.join(KIT, 'contoh-A11.json'), os.path.join(folder, 'video.json'))
    print('folder baru siap:', folder, '— ubah video.json lalu jalankan: python _kit/buat.py', os.path.relpath(folder, ROOT), '--cek')


def main():
    args = sys.argv[1:]
    if args[0] == 'baru':
        return baru(os.path.abspath(os.path.join(ROOT, args[1])))
    folder = os.path.abspath(args[0])
    cek = None
    if '--cek' in args:
        k = args.index('--cek')
        cek = args[k + 1] if k + 1 < len(args) and not args[k + 1].startswith('--') else ''
    data = json.load(io.open(os.path.join(folder, 'video.json'), encoding='utf-8'))
    C, cue = sinkron.main(folder)
    data['adegan'] = [resolve(a, i, C, cue) for i, a in enumerate(data['adegan'])]
    for i, a in enumerate(data['adegan']):           # peringatan: ketukan di luar adegannya
        dur = C[i + 1] - C[i]
        for e in a.get('isi', []):
            t = e.get('at')
            if isinstance(t, (int, float)) and not -0.01 <= t <= dur:
                print(f'  ! adegan {i} ({e["jenis"]}): at={t} di luar durasi adegan {dur:.2f}')
    waktu = {'C': C}
    js = ('/* dibuat otomatis oleh _kit/buat.py dari video.json — jangan diedit, ubah video.json */\n'
          + 'const DATA=' + json.dumps(data, ensure_ascii=False) + ';\nconst WAKTU=' + json.dumps(waktu) + ';\n'
          + io.open(os.path.join(KIT, 'komponen.js'), encoding='utf-8').read())
    io.open(os.path.join(folder, '_scenes.js'), 'w', encoding='utf-8', newline='\n').write(js)
    css = io.open(os.path.join(KIT, 'komponen.css'), encoding='utf-8').read() + '\n/* ---- khusus video ini ---- */\n' + data.get('css', '')
    io.open(os.path.join(folder, '_extra.css'), 'w', encoding='utf-8', newline='\n').write(css)
    rel = os.path.relpath(folder, ROOT)
    subprocess.run([sys.executable, os.path.join(TOOLS, 'build.py'), rel, data['judul'], os.path.join(rel, '_extra.css'), data.get('brief', '')],
                   cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    print('index.html siap:', os.path.join(rel, 'index.html'))
    if cek is not None:
        ts = cek.split(',') if cek else [f'{max(C[i] + .5, C[i + 1] - .35):.2f}' for i in range(len(C) - 1)]
        subprocess.run(['node', 'sheet.mjs', folder, os.path.join(folder, '_sheet.png'), *ts], cwd=TOOLS, check=True)
    if '--render' in args:
        has_vo = any(os.path.exists(os.path.join(folder, f)) for f in ('vo.wav', 'vo.mp3', 'vo.m4a'))
        out = os.path.join(folder, (os.path.basename(folder) if has_vo else 'preview-tanpa-suara') + '.mp4')
        subprocess.run(['node', 'export.mjs', folder, out, '30'], cwd=TOOLS, check=True)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main()
