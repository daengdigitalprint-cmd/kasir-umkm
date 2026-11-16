// Langkah perekaman tampilan asli aplikasi untuk video tutorial "dari daftar sampai jualan".
// Dijalankan lewat: node ../_tools/app-snap.mjs . <langkah>
// Akun contoh hanya ada di database lokal (umkm-kasir.test).
const AKUN = { name: 'Rahma', email: 'rahma@tokoberkah.id', whatsapp: '0812 3456 7890' };
const SANDI = process.env.TUTORIAL_SANDI || 'Tutorial-' + 'Berkah#2026';

export default async function ({ page, go, shot, dump, wait, step }) {
  const type = async (sel, text) => { await page.click(sel); await page.type(sel, text, { delay: 12 }); };

  if (step === 'lihat') {            // intip halaman mana pun: TUJUAN=/path
    await go(process.env.TUJUAN || '/');
    await dump();
    await shot('_intip');
  }

  if (step === 'daftar') {
    await go('/register');
    await dump();
    await shot('01-daftar-kosong', { name: '#name', email: '#email', whatsapp: '#whatsapp', password: '#password', konfirmasi: '#password_confirmation', tombol: 'button[type=submit]' });
    await type('#name', AKUN.name);
    await type('#email', AKUN.email);
    await type('#whatsapp', AKUN.whatsapp);
    await type('#password', SANDI);
    await type('#password_confirmation', SANDI);
    await page.evaluate(() => document.activeElement.blur());
    await shot('02-daftar-terisi', { name: '#name', email: '#email', whatsapp: '#whatsapp', password: '#password', konfirmasi: '#password_confirmation', tombol: 'button[type=submit]' });
    await page.click('button[type=submit]');
    await page.waitForFunction(() => !location.pathname.startsWith('/register'), { timeout: 60000 });
    await wait(1500);
    console.log('setelah daftar:', page.url());
  }

  if (step === 'daftar-potret') {      // isi form daftar tanpa mengirim (akun contoh sudah ada)
    await go('/login');
    const kue = await page.cookies(); if (kue.length) await page.deleteCookie(...kue);   // tamu baru
    await go('/register');
    await wait(1000);
    const R = { name: '#name', email: '#email', whatsapp: '#whatsapp', password: '#password', konfirmasi: '#password_confirmation', tombol: 'button[type=submit]' };
    await shot('01-daftar-kosong', R);
    await type('#name', AKUN.name);
    await type('#email', AKUN.email);
    await type('#whatsapp', AKUN.whatsapp);
    await type('#password', SANDI);
    await type('#password_confirmation', SANDI);
    await page.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
    await wait(600);
    await shot('02-daftar-terisi', R);
    await page.evaluate(() => document.querySelector('button[type=submit]').scrollIntoView({ block: 'center' }));
    await shot('02b-daftar-tombol', R);
  }

  // Cookie sesi tidak bertahan antar-jalanan, jadi langkah selain "daftar" login dulu.
  if (step !== 'daftar' && step !== 'daftar-potret') {
    await go('/login');
    if (page.url().includes('/login')) {
      await type('#email', AKUN.email);
      await type('#password', SANDI);
      await page.click('button[type=submit]');
      await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 60000 });
      await wait(1200);
    }
    console.log('masuk:', page.url());
  }

  if (step === 'toko') {
    await go('/stores/create');
    const R = { nama: '#store-name', fnb: 'input[value=fnb]', tombol: 'button[type=submit]' };
    await shot('03-toko-kosong', R);
    await type('#store-name', 'Warung Berkah');
    await page.evaluate(() => { const r = document.querySelector('input[value=fnb]'); (r.closest('label') || r).click(); document.activeElement.blur(); });
    await shot('04-toko-terisi', R);
    await page.evaluate(() => document.querySelector('button[type=submit]').scrollIntoView({ block: 'center' }));
    await shot('04b-toko-tombol', R);
    await page.click('button[type=submit]');
    await page.waitForFunction(() => !location.pathname.startsWith('/stores/create'), { timeout: 60000 });
    await wait(2500);
    console.log('setelah toko:', page.url());
    await shot('05-dashboard');
  }

  if (step === 'produk') {
    const MENU = [['Nasi Goreng', 'Makanan Utama', '15000', '7000'], ['Mie Ayam', 'Makanan Utama', '12000', '5500'], ['Ayam Geprek', 'Makanan Utama', '16000', '8000'],
      ['Es Teh Manis', 'Minuman', '5000', '1500'], ['Kopi Susu', 'Minuman', '8000', '3000'], ['Air Mineral', 'Minuman', '4000', '2000']];
    const R = { nama: '#name', kategori: '#product_category_id', harga: '#price', modal: '#cost_price', stok: '#stock_quantity', simpan: 'button[type=submit]' };
    for (const [i, [nm, kat, harga, modal]] of MENU.entries()) {
      await go('/products/create');
      if (i === 0) await shot('06-produk-kosong', R);
      await type('#name', nm);
      await page.evaluate(k => { const s = document.querySelector('#product_category_id'); const o = [...s.options].find(o => o.text.trim() === k); if (o) { s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); } }, kat);
      await page.click('#price', { clickCount: 3 }); await page.type('#price', harga, { delay: 12 });
      await page.click('#cost_price', { clickCount: 3 }); await page.type('#cost_price', modal, { delay: 12 });
      await page.click('#stock_quantity', { clickCount: 3 }); await page.type('#stock_quantity', '50', { delay: 12 });
      await page.evaluate(() => document.activeElement.blur());
      if (i === 0) await shot('07-produk-terisi', R);
      await page.evaluate(() => { const b = [...document.querySelectorAll('button[type=submit]')].find(b => b.innerText.includes('Simpan Produk')); b.scrollIntoView({ block: 'center' }); });
      if (i === 0) await shot('07b-produk-simpan', R);
      await page.evaluate(() => [...document.querySelectorAll('button[type=submit]')].find(b => b.innerText.includes('Simpan Produk')).click());
      await page.waitForFunction(() => !location.pathname.endsWith('/create'), { timeout: 60000 });
      await wait(1200);
      console.log('tersimpan', nm, page.url());
    }
    await go('/products');
    await shot('08-daftar-produk');
  }

  if (step === 'produk-potret') {      // isi form tanpa menyimpan, untuk potret yang bersih
    await go('/products/create');
    await wait(1500);
    await type('#name', 'Nasi Goreng');
    await page.evaluate(() => { const s = document.querySelector('#product_category_id'); const o = [...s.options].find(o => o.text.trim() === 'Makanan Utama'); s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await page.evaluate(() => window.scrollTo(0, 0));
    await wait(900);
    await shot('07-produk-nama', { nama: '#name', kategori: '#product_category_id' });
    await page.type('#price', '15000', { delay: 12 });
    await page.type('#cost_price', '7000', { delay: 12 });
    await page.click('#stock_quantity', { clickCount: 3 }); await page.type('#stock_quantity', '50', { delay: 12 });
    await page.evaluate(() => { document.activeElement.blur(); document.querySelector('#price').scrollIntoView({ block: 'start' }); window.scrollBy(0, -110); });
    await wait(1500);
    await shot('07-produk-harga', { harga: '#price', modal: '#cost_price', stok: '#stock_quantity' });
  }

  if (step === 'shift') {
    await go('/pos');
    await shot('09-pos-shift');
    await go('/cash-shifts');
    await wait(1000);
    await page.evaluate(() => { document.querySelector('#opening_cash').scrollIntoView({ block: 'center' }); });
    await shot('10-buka-kasir', { kas: '#opening_cash' });
    await page.click('#opening_cash', { clickCount: 3 });
    await page.type('#opening_cash', '200000', { delay: 15 });
    await page.evaluate(() => document.activeElement.blur());
    await shot('11-kas-awal', { kas: '#opening_cash' });
    await page.evaluate(() => [...document.querySelectorAll('button[type=submit]')].find(b => b.innerText.includes('Buka shift')).click());
    await wait(3000);
    console.log('shift dibuka ->', page.url());
    await go('/pos');
    await wait(1500);
    await shot('12-pos-siap');
  }

  // tombol/elemen yang terlihat dan teksnya cocok (penuh atau awalan)
  const klik = async (teks, { awal = false } = {}) => { const ok = await page.evaluate((teks, awal) => {
    const el = [...document.querySelectorAll('button,a,[role=button],label')].filter(e => e.offsetParent).reverse().find(e => { const t = e.innerText.trim().replace(/\s+/g, ' '); return awal ? t.startsWith(teks) : t === teks; });
    if (!el) return false; el.scrollIntoView({ block: 'center' }); el.click(); return true; }, teks, awal); if (!ok) throw new Error('tidak ketemu: ' + teks); await wait(900); };
  const daftar = async () => console.log(await page.evaluate(() => [...document.querySelectorAll('button,a,input')].filter(e => e.offsetParent).map(e => { const r = e.getBoundingClientRect(); return `${e.tagName}|${(e.innerText || e.placeholder || e.value || '').trim().replace(/\s+/g, ' ').slice(0, 40)}|${Math.round(r.x)},${Math.round(r.y)}`; }).filter(l => !l.includes('|-2')).join(String.fromCharCode(10))));

  if (step === 'jual') {
    await go('/pos');
    await page.evaluate(() => Object.keys(localStorage).filter(k => /cart|keranjang/i.test(k)).forEach(k => localStorage.removeItem(k)));
    await go('/pos');
    await wait(1500);
    // tombol + di kartu produk: urutan sama dengan daftar (Nasi Goreng, Mie Ayam, Ayam Geprek, Es Teh, Kopi Susu, Air Mineral)
    // tombol + = tombol di dalam kartu yang memuat nama produk
    const plus = async nama => { await page.evaluate(nama => {
      const t = [...document.querySelectorAll('h3,h4,p,span,div')].find(e => e.childElementCount === 0 && e.textContent.trim() === nama && e.offsetParent);
      let c = t; while (c && !c.querySelector('button')) c = c.parentElement;
      const b = [...c.querySelectorAll('button')].pop(); b.scrollIntoView({ block: 'center' }); b.click(); }, nama); await wait(800); };
    await plus('Nasi Goreng');
    await shot('13-tambah-1');
    await plus('Es Teh Manis'); await plus('Es Teh Manis');
    await page.evaluate(() => window.scrollTo(0, 0));
    await shot('14-keranjang-isi');
    await klik('items', { awal: false }).catch(() => {});
    await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(b => /items/.test(b.innerText) && b.offsetParent); b && b.click(); });
    await wait(1200);
    await shot('15-keranjang-buka');
    await daftar();
    if (process.env.LANJUT) {
      await klik(process.env.LANJUT, { awal: true }); await shot('16-bayar-metode');
      await page.evaluate(() => [...document.querySelectorAll('button')].find(b => b.innerText.trim() === 'Proses Pembayaran').scrollIntoView({ block: 'end' }));
      await wait(600);
      await klik('Tunai');
      await shot('17-pilih-tunai');
      await klik('Rp 50.000');
      await shot('18-uang-diterima');
      await klik('Selesaikan', { awal: true });
      await page.waitForFunction(() => !document.body.innerText.includes('Memproses...'), { timeout: 60000 });
      await wait(2000);
      await shot('19-selesai');
      await daftar();
    }
    const btns = '' || await page.evaluate(() => [...document.querySelectorAll('button,a')].filter(e => e.offsetParent).map((e, i) => { const r = e.getBoundingClientRect(); return `${i}|${e.tagName}|${(e.innerText || e.getAttribute('aria-label') || e.title || '').trim().replace(/\s+/g, ' ').slice(0, 40)}|${Math.round(r.x)},${Math.round(r.y)},${Math.round(r.width)}x${Math.round(r.height)}`; }).join(String.fromCharCode(10)));
    console.log(btns);
  }

  if (step === 'intip') {
    await go(process.env.TUJUAN || '/dashboard');
    await dump();
    await shot(process.env.NAMA || '_intip');
  }
}
