/* Verifikasi visual: potret detik kunci (OPENER.seek) -> _snap/tXX.png. Bukan render penuh.
   node snap.cjs <puppeteer-core path> 1 3.5 8 ... */
const puppeteer = require(process.argv[2]);
const times = process.argv.slice(3).map(Number);
const path = require('path');
const fs = require('fs');
const out = path.join(__dirname, '_snap');
fs.mkdirSync(out, { recursive: true });
(async () => {
    const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--allow-file-access-from-files'] });
    const p = await b.newPage();
    const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await p.setViewport({ width: 540, height: 960, deviceScaleFactor: 1 });
    await p.goto('file:///' + path.join(__dirname, 'index.html').replace(/\\/g, '/') + '?clean=1', { waitUntil: 'networkidle0' });
    await p.waitForFunction(() => window.OPENER && window.OPENER.ready, { timeout: 60000 });
    for (const t of times) {
        await p.evaluate((t) => window.OPENER.seek(t), t);
        await new Promise((r) => setTimeout(r, 120));
        await p.screenshot({ path: path.join(out, `t${String(t.toFixed(2)).padStart(5, '0')}.png`) });
    }
    console.log(errs.length ? 'ERROR: ' + errs.join(' | ') : 'tanpa error');
    await b.close();
})();
