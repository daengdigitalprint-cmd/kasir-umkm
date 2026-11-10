/* Render index.html frame demi frame -> MP4 1080x1920 + suara VO (tanpa frame drop).
   node export.cjs <puppeteer-core path> [fps=30] [out=umkm-kasir-explainer.mp4]
   Butuh ffmpeg di PATH. Frame dikirim langsung ke ffmpeg (tidak menyimpan ribuan PNG). */
const puppeteer = require(process.argv[2]);
const FPS = Number(process.argv[3] || 30);
const path = require('path');
const OUT = path.join(__dirname, process.argv[4] || 'umkm-kasir-explainer.mp4');
const { spawn } = require('child_process');

(async () => {
    const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--allow-file-access-from-files', '--autoplay-policy=user-gesture-required'] });
    const p = await b.newPage();
    await p.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });
    await p.goto('file:///' + path.join(__dirname, 'index.html').replace(/\\/g, '/') + '?clean=1', { waitUntil: 'networkidle0' });
    await p.waitForFunction(() => window.OPENER && window.OPENER.ready, { timeout: 120000 });
    await p.evaluate(() => document.fonts.ready);
    const DUR = await p.evaluate(() => window.OPENER.DURATION);
    const total = Math.round(DUR * FPS);

    const ff = spawn('ffmpeg', [
        '-y', '-loglevel', 'error',
        '-f', 'image2pipe', '-framerate', String(FPS), '-i', '-',
        '-i', path.join(__dirname, 'vo.mp3'),
        '-map', '0:v', '-map', '1:a',
        '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-pix_fmt', 'yuv420p', '-r', String(FPS),
        '-c:a', 'aac', '-b:a', '160k', '-af', 'apad',
        '-t', String(DUR), '-movflags', '+faststart', OUT,
    ], { stdio: ['pipe', 'inherit', 'inherit'] });
    const write = (buf) => new Promise((res) => (ff.stdin.write(buf) ? res() : ff.stdin.once('drain', res)));

    const t0 = Date.now();
    for (let i = 0; i < total; i++) {
        const t = i / FPS;
        await p.evaluate(async (time) => {
            window.OPENER.seek(time);
            await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        }, t);
        await write(await p.screenshot({ type: 'png', clip: { x: 0, y: 0, width: 1080, height: 1920 }, optimizeForSpeed: true }));
        if (i % (FPS * 5) === 0) console.log(`${i}/${total} frame (${t.toFixed(0)} dtk video) — ${((Date.now() - t0) / 1000).toFixed(0)} dtk berjalan`);
    }
    ff.stdin.end();
    await new Promise((res, rej) => ff.on('close', (c) => (c === 0 ? res() : rej(new Error('ffmpeg keluar dengan kode ' + c)))));
    console.log(`selesai: ${OUT} (${total} frame @ ${FPS} fps) dalam ${((Date.now() - t0) / 1000).toFixed(0)} dtk`);
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
