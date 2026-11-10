/**
 * Bundle + purge + minify CSS landing page (resources/js/pages/Landing/Index.vue).
 *
 * Kenapa: landing memuat bootstrap.min.css (233 KB) + main.css template
 * Sastik (444 KB, tidak di-minify) + animate.css (69 KB) + landing-custom.css,
 * padahal sebagian besar selector tidak dipakai (Lighthouse: "Kurangi CSS
 * yang tidak digunakan" & "Kecilkan CSS").
 *
 * Cara kerja (mirip PurgeCSS, konservatif):
 *  1. Kumpulkan semua token kata dari sumber konten: Index.vue + komponen yang
 *     di-import-nya + app.blade.php, ditambah SAFELIST class yang ditambahkan
 *     JS saat runtime (main.js, bootstrap tab/collapse, marquee, lenis).
 *     JS template sengaja TIDAK dipindai: isinya penuh nama komponen yang tidak
 *     dipakai (modal, carousel, swiper, ...) sehingga CSS-nya ikut tertahan.
 *  2. Selector dipertahankan hanya jika SEMUA class/id-nya ada di token.
 *     Class di dalam :not()/:is()/:where()/:has() tidak diwajibkan.
 *  3. @keyframes / @font-face yang tidak lagi direferensikan dibuang.
 *  4. Digabung jadi satu file lalu di-minify dengan lightningcss.
 *
 * app.css (Tailwind aplikasi) juga ikut dipurge & digabung: landing tidak
 * memuat app.css lagi (lihat app.blade.php), tapi tetap bergantung pada
 * preflight Tailwind (img block, ul tanpa bullet, reset button) dan beberapa
 * utilitas yang namanya bentrok dengan class template (mis. `.collapse`).
 * Sumbernya hasil build Vite (public/build/manifest.json), jadi:
 *
 * Output: public/sastik-assets/css/landing.min.css
 * Jalankan ulang setiap mengubah komponen landing / CSS sumbernya,
 * SETELAH `npm run build` (butuh app.css hasil build):
 *     npm run build && node scripts/build-landing-css.mjs
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { transform } from 'lightningcss';
import postcss from 'postcss';
import selectorParser from 'postcss-selector-parser';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const pub = (p) => join(ROOT, 'public', p);

/** app.css hasil build Vite (Tailwind). Semuanya di dalam @layer, jadi posisinya
 * di bundle tidak mengubah cascade terhadap CSS template (unlayered). */
function builtAppCss() {
    const manifest = JSON.parse(readFileSync(pub('build/manifest.json'), 'utf8'));
    const file = manifest['resources/css/app.css']?.file ?? manifest['resources/js/app.ts']?.css?.[0];
    if (!file) throw new Error('app.css tidak ditemukan di public/build/manifest.json — jalankan `npm run build` dulu');
    return 'build/' + file;
}

/** Urutan = urutan cascade aslinya (app.css dulu, lalu CSS template dari Index.vue). */
const CSS_SOURCES = [
    builtAppCss(),
    'sastik-assets/css/bootstrap.min.css',
    'sastik-assets/css/fontawesome-landing.css',
    'sastik-assets/css/main.css',
    'css/landing-custom.css',
    'sastik-assets/css/animate.css',
];

const INDEX_VUE = join(ROOT, 'resources/js/pages/Landing/Index.vue');
const landingImports = [...readFileSync(INDEX_VUE, 'utf8').matchAll(/from '@\/components\/landing\/([\w-]+\.vue)'/g)].map(
    (m) => join(ROOT, 'resources/js/components/landing', m[1]),
);
if (landingImports.length === 0) throw new Error('Tidak menemukan import komponen landing di Index.vue');

const CONTENT_SOURCES = [INDEX_VUE, join(ROOT, 'resources/views/app.blade.php'), ...landingImports];

/**
 * Class yang ditambahkan JS saat runtime (tidak tertulis di template).
 * Tambah ke sini bila main.js / plugin mulai memakai class baru.
 */
const SAFELIST = [
    'html', 'body', 'dark',
    // main.js (sticky header, preloader, reveal, menu, wow, accordion, service list)
    'active', 'active-block', 'animated', 'current', 'is-active', 'is-sticky', 'mleave', 'open', 'original',
    'preloader-hidden', 'xb-loader-hidden', 'xb-header-fixed', 'xb-header-area-sticky', 'xb-sticky-stt',
    'xb-sticky-stb', 'xb-word-cache', 'xb-word-inner', 'xb-menu-toggle', 'sub-menu',
    // bootstrap tab / collapse (bootstrap.bundle.min.js)
    'show', 'showing', 'hiding', 'fade', 'collapse', 'collapsing', 'collapse-horizontal',
    // jquery.marquee
    'js-marquee', 'js-marquee-wrapper',
    // lenis
    'lenis', 'lenis-smooth', 'lenis-stopped', 'lenis-scrolling', 'lenis-prevent', 'lenis-prevent-touch', 'lenis-prevent-wheel',
];

// ---------------------------------------------------------------- tokens
const tokens = new Set(SAFELIST);
for (const file of CONTENT_SOURCES) {
    const text = readFileSync(file, 'utf8');
    for (const m of text.matchAll(/[A-Za-z0-9_-]+/g)) tokens.add(m[0]);
    // Class utuh dari atribut class="..." (agar varian Tailwind seperti md:flex,
    // w-1/2 ikut terbaca, bukan terpecah di ':' atau '/').
    for (const m of text.matchAll(/class="([^"]*)"/g)) for (const c of m[1].split(/\s+/)) if (c) tokens.add(c);
    // Token ber-escape di template (mis. "w-1\/2") jarang di sini; cukup kata biasa.
}

/** Negasi/relasional: class di dalamnya tidak diwajibkan ada. */
const IGNORE_INSIDE = new Set([':not', ':has']);
/** "Salah satu dari": dipakai bila minimal satu alternatif dipakai (mis. Tailwind :where(.space-y-2>...)). */
const ANY_OF = new Set([':is', ':where', ':matches', ':-webkit-any', ':-moz-any']);

function nodeIsUsed(node) {
    if (node.type === 'class' || node.type === 'id') return tokens.has(node.value);
    if (node.type === 'pseudo') {
        const name = node.value.toLowerCase();
        if (IGNORE_INSIDE.has(name)) return true;
        if (ANY_OF.has(name) && node.nodes.length) return node.nodes.some((s) => selectorIsUsed(s));
    }
    return true;
}

function selectorIsUsed(sel) {
    return sel.nodes.every(nodeIsUsed);
}

function purgeSelectors(rule) {
    let changed = false;
    let result;
    try {
        result = selectorParser((root) => {
            root.each((sel) => {
                // Clone supaya pengosongan :not() di selectorIsUsed tidak merusak output.
                if (!selectorIsUsed(sel.clone())) {
                    sel.remove();
                    changed = true;
                }
            });
        }).processSync(rule.selector);
    } catch {
        return; // selector aneh: biarkan apa adanya
    }
    if (!changed) return;
    if (!result.trim()) rule.remove();
    else rule.selector = result;
}

// ---------------------------------------------------------------- purge
let css = '';
for (const src of CSS_SOURCES) {
    css += `\n/* ${src} */\n` + readFileSync(pub(src), 'utf8').replace(/@charset[^;]+;/gi, '');
}

const root = postcss.parse(css);
const before = root.toString().length;

/**
 * Pseudo-class usang IE10/Firefox<=18. Chrome mengabaikannya, tapi lightningcss
 * menerjemahkannya ke :placeholder-shown yang MENGENAI input-nya sendiri
 * (input jadi abu-abu). Buang agar perilaku sama dengan aslinya.
 */
const OBSOLETE_PSEUDO = /:-moz-placeholder|:-ms-input-placeholder/i;

root.walkRules((rule) => {
    const parent = rule.parent;
    if (parent && parent.type === 'atrule' && /keyframes$/i.test(parent.name)) return;
    if (OBSOLETE_PSEUDO.test(rule.selector)) {
        const keep = rule.selectors.filter((s) => !OBSOLETE_PSEUDO.test(s));
        if (keep.length === 0) {
            rule.remove();
            return;
        }
        rule.selectors = keep;
    }
    purgeSelectors(rule);
});

// Hapus at-rule kosong (media/supports) berulang sampai stabil.
let removed;
do {
    removed = 0;
    root.walkAtRules((at) => {
        if (/^(media|supports|layer|container)$/i.test(at.name) && (!at.nodes || at.nodes.length === 0)) {
            at.remove();
            removed++;
        }
    });
} while (removed);

// @keyframes yang tidak direferensikan.
const declText = [];
root.walkDecls((d) => {
    if (/^(-webkit-|-moz-|-o-)?animation(-name)?$/i.test(d.prop)) declText.push(d.value);
});
const animUsed = declText.join(' ');
root.walkAtRules(/keyframes$/i, (at) => {
    const name = at.params.trim().replace(/^["']|["']$/g, '');
    if (!new RegExp(`(^|[\\s,])${name.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')}($|[\\s,;])`).test(animUsed)) at.remove();
});

// @keyframes duplikat (nama + vendor sama di level atas): yang berlaku hanya
// definisi TERAKHIR, jadi definisi sebelumnya aman dibuang.
const lastKeyframes = new Map();
root.each((n) => {
    if (n.type === 'atrule' && /keyframes$/i.test(n.name)) {
        const key = n.name.toLowerCase() + ' ' + n.params.trim();
        if (lastKeyframes.has(key)) lastKeyframes.get(key).remove();
        lastKeyframes.set(key, n);
    }
});

// @font-face yang family-nya tidak dipakai.
const fontUses = [];
root.walkDecls((d) => {
    if (/^(font-family|font|--[\w-]*font[\w-]*)$/i.test(d.prop)) fontUses.push(d.value.toLowerCase());
});
const fontText = fontUses.join(' ');
root.walkAtRules(/^font-face$/i, (at) => {
    let fam = '';
    at.walkDecls(/^font-family$/i, (d) => (fam = d.value.replace(/["']/g, '').trim().toLowerCase()));
    if (fam && !fontText.includes(fam)) at.remove();
});

// Custom property Tailwind yang tidak direferensikan (tema 400+ variabel,
// fallback --tw-* di @layer properties, dan @property). Hanya dibuang bila
// namanya tidak muncul di var(...) mana pun di CSS akhir MAUPUN di sumber
// landing (inline style / CSS scoped Vue) — diulang sampai stabil.
function isPrunableScope(decl) {
    for (let n = decl.parent; n && n.type !== 'root'; n = n.parent) {
        if (n.type === 'atrule' && n.name === 'layer' && /^(theme|properties)$/.test(n.params.trim())) return true;
    }
    return false;
}
for (let pass = 0; pass < 10; pass++) {
    const referenced = new Set();
    root.walkDecls((d) => {
        for (const m of d.value.matchAll(/var\(\s*(--[\w-]+)/g)) referenced.add(m[1]);
    });
    let removedVars = 0;
    root.walkDecls(/^--/, (d) => {
        if (isPrunableScope(d) && !referenced.has(d.prop) && !tokens.has(d.prop)) {
            d.remove();
            removedVars++;
        }
    });
    root.walkAtRules('property', (at) => {
        const name = at.params.trim();
        let declaredElsewhere = false;
        root.walkDecls(name, () => (declaredElsewhere = true));
        if (!referenced.has(name) && !declaredElsewhere && !tokens.has(name)) {
            at.remove();
            removedVars++;
        }
    });
    // Rule / at-rule yang jadi kosong.
    root.walkRules((r) => {
        if (r.nodes && r.nodes.length === 0) r.remove();
    });
    root.walkAtRules((at) => {
        if (/^(media|supports|layer)$/i.test(at.name) && at.nodes && at.nodes.length === 0) at.remove();
    });
    if (!removedVars) break;
}

const purged = root.toString();

// ---------------------------------------------------------------- minify
/**
 * Target browser modern: lightningcss membuang prefix vendor yang tidak lagi
 * dibutuhkan (-o-transition, -ms-border-radius, -moz-..., -webkit-transform, ...)
 * yang tersebar di seluruh main.css, tapi tetap mempertahankan yang masih
 * diperlukan (mis. -webkit-backdrop-filter untuk Safari < 18).
 */
const v = (major) => major << 16;
const TARGETS = { chrome: v(100), edge: v(100), firefox: v(100), safari: v(15), ios_saf: v(15), samsung: v(16), opera: v(86) };

const { code, warnings } = transform({
    filename: 'landing.min.css',
    code: Buffer.from(purged),
    minify: true,
    targets: TARGETS,
    errorRecovery: true,
});
for (const w of warnings.slice(0, 10)) console.warn('lightningcss:', w.message);

const out = pub('sastik-assets/css/landing.min.css');
writeFileSync(out, code);
console.log(
    `CSS landing: ${(before / 1024).toFixed(0)} KB -> purge ${(purged.length / 1024).toFixed(0)} KB -> minify ${(code.length / 1024).toFixed(0)} KB`,
);
