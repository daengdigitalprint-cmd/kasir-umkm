// Jelajah aplikasi lokal di viewport HP dan potret tiap langkah (tampilan asli untuk video tutorial).
// pakai: node app-snap.mjs <folder-out> <langkah>   — langkah: lihat switch di bawah
import puppeteer from 'puppeteer';
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const [out, step] = process.argv.slice(2);
mkdirSync(join(out, 'shots'), { recursive: true });
const BASE = 'https://umkm-kasir.test';
const metaFile = join(out, 'shots', 'meta.json');
const meta = existsSync(metaFile) ? JSON.parse(readFileSync(metaFile, 'utf8')) : {};
const profile = join(out, '..', '_profile');           // sesi login bertahan antar langkah
const browser = await puppeteer.launch({ headless: 'new', acceptInsecureCerts: true, userDataDir: profile, args: ['--ignore-certificate-errors'] });
const page = await browser.newPage();
await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
page.on('pageerror', e => console.log('PAGEERROR', e.message.slice(0, 200)));
const wait = ms => new Promise(r => setTimeout(r, ms));
const shot = async (name, sels = {}) => {
  await wait(700);
  await page.screenshot({ path: join(out, 'shots', name + '.png') });
  const rects = {};
  for (const [k, sel] of Object.entries(sels)) {
    rects[k] = await page.evaluate(s => { const e = typeof s === 'string' ? document.querySelector(s) : null; if (!e) return null; const r = e.getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)]; }, sel);
  }
  meta[name] = rects; writeFileSync(metaFile, JSON.stringify(meta, null, 1));
  console.log('shot', name, page.url(), JSON.stringify(rects));
};
const dump = async () => console.log(await page.evaluate(() => [...document.querySelectorAll('input,select,textarea,button,a[href]')].filter(e => e.offsetParent).slice(0, 60).map(e => `${e.tagName}#${e.id}|${e.name || ''}|${e.type || ''}|${(e.innerText || e.placeholder || e.value || '').trim().slice(0, 40)}|${e.getAttribute('href') || ''}`).join('\n')));
const go = async (path) => { await page.goto(BASE + path, { waitUntil: 'networkidle2', timeout: 60000 }); await wait(1200); };
const mod = await import(pathToFileURL(resolve(out, 'steps.mjs')).href);
await mod.default({ page, go, shot, dump, wait, step, meta });
await browser.close();
