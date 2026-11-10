"""
Bangun subset Font Awesome khusus landing page (resources/js/pages/Landing/Index.vue).

Kenapa: fontawesome.css asli 648 KB (render-blocking) + font TTF ~2 MB,
padahal landing hanya memakai ~25 ikon. Skrip ini menghasilkan:
  - public/sastik-assets/fonts/landing/fa-{regular-400,solid-900,brands-400}.woff2
  - public/sastik-assets/css/fontawesome-landing.css

Jalankan ulang setiap menambah ikon baru di komponen landing, LALU bundle ulang CSS
(fontawesome-landing.css ikut digabung ke landing.min.css):
    python scripts/build-landing-icons.py
    node scripts/build-landing-css.mjs
Butuh: pip install fonttools brotli
"""

import re
from pathlib import Path

from fontTools import subset

ROOT = Path(__file__).resolve().parent.parent
ASSETS = ROOT / 'public' / 'sastik-assets'
SRC_CSS = ASSETS / 'css' / 'fontawesome.css'
OUT_CSS = ASSETS / 'css' / 'fontawesome-landing.css'
OUT_FONTS = ASSETS / 'fonts' / 'landing'

SCAN = [
    ROOT / 'resources' / 'js' / 'pages' / 'Landing' / 'Index.vue',
    *sorted((ROOT / 'resources' / 'js' / 'components' / 'landing').glob('*.vue')),
]

# Glyph yang dipakai lewat pseudo-element di main.css / landing-custom.css
# (bukan lewat class fa-*), mis. ikon +/- accordion FAQ. Diukur dari halaman.
EXTRA_CODEPOINTS = {0x2B, 0xF067, 0xF068, 0xF062, 0xF104, 0xF105, 0xF00D}

# Class yang bukan nama ikon.
NON_ICON = {
    'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-thin', 'fa-duotone',
    'fa-sharp', 'fa-classic', 'fa-fw', 'fa-spin', 'fa-pulse', 'fa-lg', 'fa-xs', 'fa-sm',
    'fa-1x', 'fa-2x', 'fa-3x',
}

FONTS = [
    # (file sumber, file output, family, weight)
    ('fa-regular-400.ttf', 'fa-regular-400.woff2', 'Font Awesome 6 Pro', 400),
    ('fa-solid-900.ttf', 'fa-solid-900.woff2', 'Font Awesome 6 Pro', 900),
    ('fa-brands-400.ttf', 'fa-brands-400.woff2', 'Font Awesome 6 Brands', 400),
]


def used_icon_names():
    names = set()
    for f in SCAN:
        for m in re.finditer(r'\bfa-[a-z0-9-]+', f.read_text(encoding='utf-8')):
            if m.group(0) not in NON_ICON:
                names.add(m.group(0)[3:])
    return names


def icon_codepoints(css, names):
    found = {}
    # Rule: ".fa-a::before, .fa-b::before { content: "\f062"; }"
    for m in re.finditer(r'([^{}]+)\{\s*content:\s*"\\([0-9a-fA-F]+)";?\s*\}', css):
        selectors, cp = m.group(1), int(m.group(2), 16)
        for sel in re.findall(r'\.fa-([a-z0-9-]+)::?before', selectors):
            if sel in names and sel not in found:
                found[sel] = cp
    return found


def main():
    css = SRC_CSS.read_text(encoding='utf-8')
    names = used_icon_names()
    icons = icon_codepoints(css, names)
    missing = sorted(names - set(icons))
    if missing:
        print('PERINGATAN: ikon tidak ditemukan di fontawesome.css:', ', '.join(missing))

    codepoints = set(icons.values()) | EXTRA_CODEPOINTS
    OUT_FONTS.mkdir(parents=True, exist_ok=True)

    faces = []
    for src, out, family, weight in FONTS:
        opts = subset.Options()
        opts.flavor = 'woff2'
        opts.layout_features = ['*']
        opts.notdef_outline = True
        opts.name_IDs = ['*']
        font = subset.load_font(str(ASSETS / 'fonts' / src), opts)
        sub = subset.Subsetter(opts)
        sub.populate(unicodes=codepoints)
        sub.subset(font)
        subset.save_font(font, str(OUT_FONTS / out), opts)
        size = (OUT_FONTS / out).stat().st_size
        print(f'{out}: {size / 1024:.1f} KB')
        faces.append(
            "@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:block;"
            'src:url("../fonts/landing/%s") format("woff2")}' % (family, weight, out)
        )

    rules = [
        '/* AUTO-GENERATED oleh scripts/build-landing-icons.py — jangan edit manual.',
        ' * Subset Font Awesome Pro 6.4.0 (Commercial License) untuk landing page. */',
        *faces,
        ":root{--fa-style-family-classic:'Font Awesome 6 Pro';--fa-style-family-brands:'Font Awesome 6 Brands';"
        "--fa-font-solid:normal 900 1em/1 'Font Awesome 6 Pro';--fa-font-regular:normal 400 1em/1 'Font Awesome 6 Pro';"
        "--fa-font-brands:normal 400 1em/1 'Font Awesome 6 Brands'}",
        '.fa{font-family:var(--fa-style-family,"Font Awesome 6 Pro");font-weight:var(--fa-style,900)}',
        '.fa,.fas,.fa-solid,.far,.fa-regular,.fab,.fa-brands{-moz-osx-font-smoothing:grayscale;'
        '-webkit-font-smoothing:antialiased;display:var(--fa-display,inline-block);font-style:normal;'
        'font-variant:normal;line-height:1;text-rendering:auto}',
        ".fas,.fa-solid,.far,.fa-regular{font-family:'Font Awesome 6 Pro'}",
        ".fab,.fa-brands{font-family:'Font Awesome 6 Brands';font-weight:400}",
        '.far,.fa-regular{font-weight:400}',
        '.fas,.fa-solid{font-weight:900}',
    ]
    for name in sorted(icons):
        rules.append('.fa-%s::before{content:"\\%x"}' % (name, icons[name]))

    OUT_CSS.write_text('\n'.join(rules) + '\n', encoding='utf-8')
    print(f'{OUT_CSS.name}: {OUT_CSS.stat().st_size / 1024:.1f} KB, {len(icons)} ikon')


if __name__ == '__main__':
    main()
