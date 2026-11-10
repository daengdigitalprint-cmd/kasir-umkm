/**
 * Screenshot halaman aplikasi dari akun demo toko bangunan (aset video explainer).
 *   node scripts/capture-demo-screens.cjs <puppeteer-core path> <output dir> [base url]
 * Login: demo.bangunan@umkm-kasir.test / password (TokoBangunanDemoSeeder).
 */
const puppeteer = require(process.argv[2]);
const out = process.argv[3];
const base = process.argv[4] || 'https://umkm-kasir.test';
const fs = require('fs');
fs.mkdirSync(out, { recursive: true });

const PAGES = [
    ['dashboard', '/dashboard'],
    ['produk', '/products'],
    ['stok', '/stock-overview'],
    ['kasir', '/pos'],
    ['pelanggan', '/customers'],
    ['hutang', '/receivables'],
    ['pembelian', '/purchases'],
    ['laporan', '/reports'],
    ['laporan-penjualan', '/reports/sales'],
    ['laba-rugi', '/financial/income-statement'],
    ['neraca', '/financial/balance-sheet'],
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
    const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--ignore-certificate-errors'] });
    const p = await b.newPage();
    await p.setBypassServiceWorker(true);
    await p.setViewport({ width: 1440, height: 900 });
    await p.goto(base + '/login', { waitUntil: 'networkidle0', timeout: 120000 });
    await sleep(1500);
    await p.type('#email', 'demo.bangunan@umkm-kasir.test');
    await p.type('#password', 'password');
    await p.click('button[type=submit]');
    for (let i = 0; i < 90 && p.url().includes('/login'); i++) await sleep(1000);
    console.log('login ->', p.url());

    for (const vp of [{ tag: 'hp', width: 430, height: 932, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, { tag: 'desktop', width: 1440, height: 900, deviceScaleFactor: 2 }]) {
        await p.setViewport(vp);
        for (const [name, path] of PAGES) {
            try {
                const res = await p.goto(base + path, { waitUntil: 'networkidle0', timeout: 120000 });
                await sleep(2500);
                // tutup dialog/toast yang mungkin muncul (onboarding, info)
                await p.keyboard.press('Escape').catch(() => {});
                await sleep(300);
                const file = `${out}/${vp.tag}-${name}.png`;
                await p.screenshot({ path: file });
                await p.screenshot({ path: `${out}/${vp.tag}-${name}-full.png`, fullPage: true });
                console.log(`${vp.tag} ${name}: HTTP ${res && res.status()} -> ${p.url().replace(base, '')}`);
            } catch (e) {
                console.log(`${vp.tag} ${name}: GAGAL ${e.message}`);
            }
        }
    }
    await b.close();
})();
