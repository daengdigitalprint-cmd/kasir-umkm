// Potret detik kunci lalu susun jadi satu lembar kontak (skala kecil) untuk diperiksa.
// pakai: node sheet.mjs <folder-video> <out.png> <detik...>
import puppeteer from 'puppeteer';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
const [dir, out, ...times] = process.argv.slice(2);
const url = pathToFileURL(resolve(dir, 'index.html')).href + '?clean=1';
const browser = await puppeteer.launch({ headless: 'new' });
const page = await browser.newPage();
page.on('pageerror', e => console.log('PAGEERROR', e.message));
page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE', m.text()); });
await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 0.3 });
await page.goto(url, { waitUntil: 'networkidle0' });
await page.waitForFunction('window.OPENER && window.OPENER.ready', { timeout: 60000 });
const shots = [];
for (const ts of times) {
  const t = Number(ts);
  await page.evaluate(async (time) => { window.OPENER.seek(time); await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); window.OPENER.seek(time); }, t);
  shots.push({ t, b64: await page.screenshot({ encoding: 'base64' }) });
}
const cols = Math.min(6, shots.length), cw = 324, ch = 576, rows = Math.ceil(shots.length / cols);
const html = `<body style="margin:0;background:#111;display:grid;grid-template-columns:repeat(${cols},${cw}px);gap:6px;padding:6px;font:16px system-ui;color:#fff">` +
  shots.map(s => `<div style="position:relative"><img src="data:image/png;base64,${s.b64}" width="${cw}" height="${ch}"><b style="position:absolute;left:4px;top:4px;background:#000a;padding:1px 6px">${s.t}s</b></div>`).join('') + '</body>';
const p2 = await browser.newPage();
await p2.setViewport({ width: cols * (cw + 6) + 6, height: rows * (ch + 6) + 6, deviceScaleFactor: 1 });
await p2.setContent(html);
await p2.screenshot({ path: out });
await browser.close(); console.log('ok', out);
