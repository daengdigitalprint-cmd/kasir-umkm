/**
 * Prerender landing page (Landing/Index) menjadi HTML statis untuk SEO.
 *
 * Kenapa: aplikasi dirender di browser (CSR), sehingga HTML awal landing kosong
 * ("Memuat...") — crawler tanpa JavaScript (Bing, preview WhatsApp/Facebook,
 * crawler AI) tidak melihat konten apa pun. Hasil prerender disisipkan ke dalam
 * <div id="app"> oleh app.blade.php; saat JavaScript jalan, Vue (createApp().mount)
 * mengganti isinya dengan versi interaktif yang identik. Tidak butuh proses Node
 * di server.
 *
 * Langkah:
 *   npx vite build --ssr                       # SSR bundle (lokal saja, di-ignore git)
 *   node scripts/prerender-landing.mjs [URL]   # URL sumber props, default produksi
 *
 * Output: resources/prerender/landing.html  (ikut di-deploy)
 * Jalankan ulang setiap mengubah komponen landing.
 */

import { spawn, execSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SSR_BUNDLE = join(ROOT, 'bootstrap/ssr/ssr.js');
const OUT = join(ROOT, 'resources/prerender/landing.html');
const SOURCE = process.argv[2] || 'https://kasirumkm.web.id/';
const PORT = 13714;

if (!existsSync(SSR_BUNDLE)) {
    console.error('SSR bundle tidak ada. Jalankan dulu: npx vite build --ssr');
    process.exit(1);
}
if (/\.test\b|localhost|127\.0\.0\.1/.test(SOURCE)) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; // sertifikat lokal (Herd)
}

const decodeEntities = (s) =>
    s.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

async function sourcePage() {
    const res = await fetch(SOURCE, { headers: { 'User-Agent': 'umkm-kasir-prerender' } });
    if (!res.ok) throw new Error(`Gagal mengambil ${SOURCE}: HTTP ${res.status}`);
    const html = await res.text();
    const m = html.match(/<div id="app" data-page="([^"]*)"/);
    if (!m) throw new Error('data-page tidak ditemukan di halaman sumber');
    const page = JSON.parse(decodeEntities(m[1]));
    if (page.component !== 'Landing/Index') throw new Error(`Komponen sumber bukan Landing/Index: ${page.component}`);
    return page;
}

async function waitForServer() {
    for (let i = 0; i < 60; i++) {
        try {
            const r = await fetch(`http://127.0.0.1:${PORT}/health`);
            if (r.ok) return;
        } catch {
            /* belum siap */
        }
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('SSR server tidak merespons');
}

function stop(child) {
    if (!child.pid) return;
    try {
        if (process.platform === 'win32') execSync(`taskkill /pid ${child.pid} /T /F`, { stdio: 'ignore' });
        else process.kill(-child.pid, 'SIGTERM');
    } catch {
        /* sudah berhenti */
    }
}

const server = spawn(process.execPath, [SSR_BUNDLE], { cwd: ROOT, stdio: ['ignore', 'inherit', 'inherit'], detached: process.platform !== 'win32' });
try {
    const page = await sourcePage();
    await waitForServer();
    const res = await fetch(`http://127.0.0.1:${PORT}/render`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(page),
    });
    if (!res.ok) throw new Error(`SSR render gagal: HTTP ${res.status}`);
    const { body } = await res.json();

    // Ambil isi <div id="app" ...> saja (wrapper dirender ulang oleh blade).
    const inner = body.replace(/^\s*<div id="app"[^>]*>/, '').replace(/<\/div>\s*$/, '');
    for (const must of ['<h1', 'Pertanyaan yang Sering Diajukan', 'Paket Starter']) {
        if (!inner.includes(must)) throw new Error(`Hasil prerender tidak memuat "${must}" — periksa error SSR`);
    }

    mkdirSync(dirname(OUT), { recursive: true });
    writeFileSync(OUT, `<!-- prerender: scripts/prerender-landing.mjs dari ${SOURCE} pada ${new Date().toISOString()} -->\n${inner}\n`);
    const text = inner.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    console.log(`Prerender OK -> ${OUT}`);
    console.log(`  ${(inner.length / 1024).toFixed(1)} KB HTML, ±${text.split(' ').length} kata, h1=${(inner.match(/<h1/g) || []).length}, h2=${(inner.match(/<h2/g) || []).length}, h3=${(inner.match(/<h3/g) || []).length}`);
} finally {
    stop(server);
}
