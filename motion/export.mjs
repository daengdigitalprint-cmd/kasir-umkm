// export.mjs — ekspor video promo MaccaPOS ke MP4 (30 fps, 1080×1920).
// Cara kerja: Chrome headless → seek timeline frame-per-frame (deterministik,
// tanpa rekam realtime) → screenshot PNG → di-pipe langsung ke ffmpeg
// (frame tidak pernah disimpan ke disk).
// Pakai: node export.mjs  (hasil: macca-promo-9x16.mp4)

import { spawn } from 'node:child_process';
import { once } from 'node:events';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FPS = 30;
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const SRC = 'file:///' + path.join(HERE, 'index.html').replace(/\\/g, '/') + '?export=1';
const OUT = path.join(HERE, 'macca-promo-9x16.mp4');

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--hide-scrollbars', '--force-device-scale-factor=1', '--disable-gpu']
});

try {
  const page = await browser.newPage();
  await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });
  await page.goto(SRC, { waitUntil: 'networkidle0', timeout: 60000 });
  await page.evaluate(() => document.fonts.ready);

  const DUR = await page.evaluate(() => window.__DUR);
  if (!DUR) throw new Error('window.__DUR tidak terbaca — mode export tidak aktif?');

  const ff = spawn('ffmpeg', [
    '-y', '-f', 'image2pipe', '-framerate', String(FPS), '-i', '-',
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '18',
    '-pix_fmt', 'yuv420p', '-movflags', '+faststart', OUT
  ], { stdio: ['pipe', 'inherit', 'inherit'] });

  const done = new Promise((res) => ff.on('close', res));

  const N = Math.round(DUR * FPS);
  console.log(`Render ${N} frame (${DUR}s @ ${FPS}fps) → ${OUT}`);
  const t0 = Date.now();

  for (let i = 0; i < N; i++) {
    const t = Math.min(i / FPS, DUR - 0.0001);
    await page.evaluate((tt) => window.__setTime(tt), t);
    const png = await page.screenshot({ type: 'png' });
    if (!ff.stdin.write(png)) await once(ff.stdin, 'drain');
    if (i % 90 === 0) {
      const s = (Date.now() - t0) / 1000;
      console.log(`  frame ${i}/${N} — ${s.toFixed(0)}s terlewati`);
    }
  }

  ff.stdin.end();
  const code = await done;
  if (code !== 0) throw new Error('ffmpeg keluar dengan kode ' + code);
  console.log('Selesai →', OUT);
} finally {
  await browser.close();
}
