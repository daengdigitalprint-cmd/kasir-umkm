// Render frame demi frame (bukan rekam layar) lalu alirkan ke ffmpeg.
// Bila <folder-video>/vo.wav ada, suaranya ikut digabung.
// pakai: node export.mjs <folder-video> <out.mp4> [fps]
import puppeteer from 'puppeteer';
import { spawn } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { resolve, join } from 'node:path';
import { existsSync } from 'node:fs';
const [dir, out, fpsArg] = process.argv.slice(2);
const FPS = Number(fpsArg || 30);
const url = pathToFileURL(resolve(dir, 'index.html')).href + '?clean=1';
const vo = ['vo.wav', 'vo.mp3', 'vo.m4a'].map(f => join(dir, f)).find(existsSync);
const browser = await puppeteer.launch({ headless: 'new' });
const page = await browser.newPage();
page.on('pageerror', e => console.log('PAGEERROR', e.message));
await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });
await page.goto(url, { waitUntil: 'networkidle0' });
await page.waitForFunction('window.OPENER && window.OPENER.ready', { timeout: 60000 });
const DUR = await page.evaluate(() => window.OPENER.DURATION);
const total = Math.round(DUR * FPS);
const args = ['-y', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'png', '-i', '-'];
if (vo) args.push('-i', vo, '-c:a', 'aac', '-b:a', '192k');
args.push('-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '17', '-preset', 'medium', '-movflags', '+faststart', '-t', String(DUR), out);
const ff = spawn('ffmpeg', args, { stdio: ['pipe', 'ignore', 'ignore'] });
for (let i = 0; i < total; i++) {
  await page.evaluate(async (time) => { window.OPENER.seek(time); await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); }, i / FPS);
  const buf = await page.screenshot({ type: 'png', optimizeForSpeed: true });
  if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
  if (i % 90 === 0) console.log(`${i}/${total}`);
}
ff.stdin.end();
const code = await new Promise(r => ff.on('close', r));
await browser.close(); console.log(code === 0 ? 'selesai' : 'ffmpeg gagal ' + code, out, vo ? '(dengan suara)' : '(tanpa suara)');
