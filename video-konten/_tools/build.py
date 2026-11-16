"""Rakit index.html video baru dari kerangka v2.

pakai: python build.py <folder-baru> "<judul>" <berkas-css-tambahan> "<style brief>"
Mengambil kepala, CSS, helper umum, dan perekat dari video 03 (kerangka terbaru),
lalu menyisipkan <folder-baru>/_scenes.js (panggung, array C, tick) dan CSS tambahan.
"""
import io, os, sys
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
os.chdir(root)
folder, title, css_file, brief = sys.argv[1:5]
src = io.open('03-nota-vs-kasir/index.html', encoding='utf-8').read()
scenes = io.open(os.path.join(folder, '_scenes.js'), encoding='utf-8').read()
extra_css = io.open(css_file, encoding='utf-8').read()


def between(s, a, b):
    i = s.index(a)
    return s[i:s.index(b, i)]


css = between(src, '<style>', '</style>') + '\n' + extra_css
helpers = between(src, 'const $=', '/* ---------- batas panggung')
perekat = src[src.index('/* ============ PEREKAT'):]
head = src[:src.index('<style>')]
t0 = head.index('<title>')
head = head[:t0] + '<title>' + title + '</title>' + head[head.index('</title>') + 8:]
a = head.index('<!--\n  STYLE BRIEF')
b = head.index('-->', a) + 3
head = head[:a] + '<!--\n  STYLE BRIEF — kerangka v2 (sama dengan video 01–03): panggung pendek, satu objek + satu frasa, cut di ketukan,\n  label struk bergerigi, kulit brand MaccaPOS (ungu #7943e5, hijau #a0ea71, koin/oranye dari maskot).\n' + brief + '\n-->' + head[b:]
body = src[src.index('</style>'):src.index('<script>\n') + 9]
out = head + css + body + helpers + scenes + '\n' + perekat
io.open(os.path.join(folder, 'index.html'), 'w', encoding='utf-8', newline='\n').write(out)
print(len(out))
