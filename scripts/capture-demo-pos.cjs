/**
 * Screenshot berurutan alur kasir (POS) akun demo toko bangunan — aset adegan "UI hidup".
 * Tidak menyelesaikan pembayaran (tidak membuat transaksi baru).
 *   node scripts/capture-demo-pos.cjs <puppeteer-core> <output dir> [base]
 */
const puppeteer = require(process.argv[2]);
const out = process.argv[3];
const base = process.argv[4] || 'https://umkm-kasir.test';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function clickText(p, selector, text) {
    const ok = await p.evaluate((selector, text) => {
        const el = [...document.querySelectorAll(selector)].find((e) => e.textContent.replace(/\s+/g, ' ').trim().includes(text) && e.offsetParent !== null);
        if (el) { el.scrollIntoView({ block: 'center' }); el.click(); }
        return !!el;
    }, selector, text);
    if (!ok) console.log('  (tidak ketemu)', selector, text);
    return ok;
}

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

    await p.setViewport({ width: 430, height: 932, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    await p.goto(base + '/pos', { waitUntil: 'networkidle0', timeout: 120000 });
    await sleep(2500);

    // 1) filter kategori Semen & Mortar, kartu Semen Tiga Roda di tengah layar
    await clickText(p, 'button', 'Semen & Mortar');
    await sleep(1200);
    const centerOn = (name) => p.evaluate((name) => {
        const h = [...document.querySelectorAll('h3')].find((x) => x.textContent.includes(name) && x.offsetParent !== null);
        if (h) h.scrollIntoView({ block: 'center' });
    }, name);
    await centerOn('Semen Tiga Roda 50 kg');
    await sleep(600);
    await p.screenshot({ path: `${out}/pos-1-semen.png` });

    // 2) tambah produk lewat tombol + di kartunya
    const addTo = async (name, times) => {
        for (let i = 0; i < times; i++) {
            const ok = await p.evaluate((name) => {
                const h = [...document.querySelectorAll('h3')].find((x) => x.textContent.includes(name) && x.offsetParent !== null);
                if (!h) return false;
                let root = h;
                for (let k = 0; k < 6 && root && !root.querySelector('button'); k++) root = root.parentElement;
                const btn = root && [...root.querySelectorAll('button')].find((x) => x.offsetParent !== null);
                (btn || h).click();
                return true;
            }, name);
            if (!ok) { console.log('  produk tidak ketemu:', name); break; }
            await sleep(400);
        }
    };
    await addTo('Semen Tiga Roda 50 kg', 5);
    await addTo('Semen Gresik 40 kg', 3);
    await centerOn('Semen Tiga Roda 50 kg');
    await sleep(700);
    await p.screenshot({ path: `${out}/pos-2-tambah.png` });

    // 3) buka keranjang lewat bar "n item"
    await clickText(p, 'button', 'item');
    await sleep(1500);
    await p.screenshot({ path: `${out}/pos-3-keranjang.png` });
    const btns = await p.$$eval('button', (bs) => bs.filter((b) => b.offsetParent !== null).map((b) => b.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
    console.log('tombol di keranjang:', JSON.stringify(btns.slice(-25)));

    // 4) dialog pembayaran (TIDAK diselesaikan)
    const payLabel = btns.find((t) => /^(Bayar|Proses|Checkout|Lanjut)/i.test(t)) || btns.find((t) => /bayar/i.test(t));
    if (payLabel) {
        await clickText(p, 'button', payLabel);
        await sleep(1500);
        await p.screenshot({ path: `${out}/pos-4-bayar.png` });
        console.log('dialog bayar dari tombol:', payLabel);
    } else {
        console.log('tombol bayar tidak ditemukan');
    }
    await b.close();
})();
