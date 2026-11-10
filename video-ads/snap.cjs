/* Verifikasi visual: node snap.cjs <puppeteer-core> <file.html> <tag> t1 t2 ... -> _snap/<tag>_tXX.png */
const puppeteer = require(process.argv[2]);
const file = process.argv[3], tag = process.argv[4];
const times = process.argv.slice(5).map(Number);
const path = require('path'), fs = require('fs');
const out = path.join(__dirname, '_snap'); fs.mkdirSync(out, { recursive: true });
for (const f of fs.readdirSync(out)) if (f.startsWith(tag + '_')) fs.unlinkSync(path.join(out, f));
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--allow-file-access-from-files'] });
  const p = await b.newPage(); const errs = [];
  p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  await p.setViewport({ width: 540, height: 960, deviceScaleFactor: 1 });
  await p.goto('file:///' + path.join(__dirname, file).split(path.sep).join('/') + '?clean=1', { waitUntil: 'networkidle0' });
  await p.waitForFunction(() => window.OPENER && window.OPENER.ready, { timeout: 60000 });
  for (const t of times) { await p.evaluate((t) => window.OPENER.seek(t), t); await new Promise((r) => setTimeout(r, 150));
    await p.screenshot({ path: path.join(out, `${tag}_t${t.toFixed(2).padStart(5, '0')}.png`) }); }
  console.log(errs.length ? 'ERROR: ' + errs.join(' | ') : 'tanpa error'); await b.close();
})();
