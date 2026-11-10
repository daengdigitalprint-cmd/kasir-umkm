// scene.js — mesin timeline video demo MaccaPOS (40 detik, 10 babak, loop).
// Merakit DOM dari js/ui.js, lalu membangun SATU timeline GSAP:
// kamera berpindah babak (bukan section yang di-fade), pintu teks per kata
// dengan motion blur, kartu masuk-keluar outIn, angka berjalan deterministik.
// Semua nilai fungsi murni dari tl.time() — tanpa Math.random / Date.now.
// Rundown: motion/brief-konsep.md.

(function () {
  'use strict';

  var U = window.UI;
  var DUR = 40;
  var stage = document.getElementById('stage');
  var field = document.getElementById('field');
  var cam = window.makeCam();

  // ── Pasang panggung ke viewport ─────────────────────────────────────────
  function fit() {
    var s = Math.min(innerWidth / 1080, innerHeight / 1920);
    stage.style.setProperty('--fit', s);
  }
  addEventListener('resize', fit);
  fit();

  // ── Rakit DOM tiap babak ────────────────────────────────────────────────
  var actEls = [];
  for (var i = 0; i < 10; i++) {
    var a = document.getElementById('act' + (i + 1));
    a.style.left = '0px';
    a.style.top = (i * 1920) + 'px';
    actEls.push(a);
  }

  // Klaim: pecah per kata → span.w (pintu teks per kata)
  function claimHTML(text) {
    return '<div class="claim">' + text.split(' ').map(function (w) {
      return '<span class="w">' + w + '</span>';
    }).join(' ') + '</div>';
  }

  var CURSOR_SVG =
    '<span class="cursor__ring"></span>' +
    '<svg viewBox="0 0 24 24" width="56" height="56">' +
    '<path fill="#ffffff" stroke="#241545" stroke-width="1.4" stroke-linejoin="round" ' +
    'd="M5.5 3.2 L19 11.4 L12.4 12.6 L15.4 19.4 L12.6 20.6 L9.6 13.8 L5.5 17.8 Z"/></svg>';

  // 1) Babak 1 (0–3) — pembuka: maskot + brand, sudah berjalan di frame 1
  actEls[0].innerHTML =
    claimHTML('Jualan jadi gampang') +
    '<div class="brandblock">' +
      '<div class="brandblock__fox"><img src="../public/favicon.png" alt="MaccaPOS"></div>' +
      '<div class="pill">MaccaPOS</div>' +
    '</div>';

  // 2) Babak 2 (3–7) — kartu omzet berhitung naik
  actEls[1].innerHTML =
    claimHTML('Omzet naik tiap hari') +
    U.statCard({
      label: 'Omzet bulan ini',
      value: '<span data-count="4875000" data-pre="Rp ">Rp 0</span>',
      sub: '↑ 23% dari bulan lalu',
      tone: 'green'
    });

  // 3) Babak 3 (7–10) — penjualan & transaksi hari ini
  actEls[2].innerHTML =
    claimHTML('Semua transaksi tercatat') +
    '<div class="statrow">' +
      U.statCard({ label: 'Penjualan hari ini', value: '<span data-count="128">0</span>', sub: 'Hari ini' }) +
      U.statCard({ label: 'Transaksi hari ini', value: '<span data-count="42">0</span>', sub: 'Hari ini' }) +
    '</div>';

  // 4) Babak 4 (10–14) — klik menu POS (desktop: sidebar · mobile: ikon tengah)
  actEls[3].innerHTML =
    claimHTML('Buka kasir dalam sekejap') +
    '<div class="app">' +
      '<div class="app__side">' +
        '<div class="app__brand">MaccaPOS</div>' +
        '<div class="menu">Dashboard</div>' +
        '<div class="menu menu--head">Kasir</div>' +
        '<div class="menu menu-pos" data-menu="pos">POS</div>' +
        '<div class="menu">Riwayat Transaksi</div>' +
        '<div class="menu menu--head">Produk &amp; Stok</div>' +
        '<div class="menu">Produk</div>' +
        '<div class="menu">Lihat Stok</div>' +
      '</div>' +
      '<div class="app__main">' +
        '<div class="app__ghost">Klik <b>POS</b><br>untuk mulai jualan</div>' +
      '</div>' +
      '<span class="cursor cursor-pos">' + CURSOR_SVG + '</span>' +
    '</div>';

  // 5) Babak 5 (14–17) — keypad PIN 111222
  actEls[4].innerHTML =
    claimHTML('Aman, pakai PIN') +
    '<div class="pad">' + U.keypad() +
      '<div class="pad__ok">PIN cocok — masuk POS ✓</div>' +
    '</div>';

  // 6) Babak 6 (17–24) — transaksi di POS: pilih → bayar → selesai
  actEls[5].innerHTML =
    claimHTML('Pilih, bayar, selesai') +
    '<div class="pos">' +
      U.prodGrid() +
      U.cart() +
      '<span class="cursor cursor-pos2">' + CURSOR_SVG + '</span>' +
      '<div class="paid">' +
        '<div class="paid__check">✓</div>' +
        '<div class="paid__title">Transaksi selesai</div>' +
        '<div class="paid__meta">Tunai · Kembalian Rp 14.000</div>' +
      '</div>' +
    '</div>';

  // 7) Babak 7 (24–29) — dashboard lengkap terisi ✦ momen istimewa
  actEls[6].innerHTML =
    claimHTML('Semua dalam satu layar') +
    '<div class="dash">' +
      '<div class="dash__stats">' +
        '<div class="mini" data-tone="green"><div class="mini__label">Omzet</div>' +
          '<div class="mini__value" data-count="4875000" data-pre="Rp ">Rp 0</div>' +
          '<div class="mini__sub">↑ 23%</div></div>' +
        '<div class="mini"><div class="mini__label">Hari ini</div>' +
          '<div class="mini__value" data-count="3180000" data-pre="Rp ">Rp 0</div>' +
          '<div class="mini__sub">128 penjualan</div></div>' +
        '<div class="mini"><div class="mini__label">Transaksi</div>' +
          '<div class="mini__value" data-count="42">0</div>' +
          '<div class="mini__sub">hari ini</div></div>' +
        '<div class="mini" data-tone="red"><div class="mini__label">Stok rendah</div>' +
          '<div class="mini__value" data-count="3">0</div>' +
          '<div class="mini__sub">perlu restok</div></div>' +
      '</div>' +
      '<div class="dash__row">' +
        '<div class="panel"><div class="panel__title">Tren Penjualan 7 Hari</div>' +
          U.bars() +
          '<div class="panel__meta">Total <b data-count="8240000" data-pre="Rp ">Rp 0</b> · 512 transaksi</div>' +
        '</div>' +
        '<div class="panel"><div class="panel__title">Jam Ramai Bulan Ini</div>' +
          U.heat() +
          '<div class="panel__meta">Puncak 12.00–14.00 · <b>68 transaksi</b></div>' +
        '</div>' +
      '</div>' +
      '<div class="dash__row">' +
        '<div class="panel"><div class="panel__title">Transaksi Terbaru</div>' +
          '<div class="txns">' +
            U.txnRow({ name: 'Es Kopi Susu ×2', time: '10:42', amount: 36000 }) +
            U.txnRow({ name: 'Choco Latte', time: '10:38', amount: 20000 }) +
            U.txnRow({ name: 'Red Velvet Latte', time: '10:31', amount: 22000 }) +
          '</div>' +
        '</div>' +
        '<div class="panel"><div class="panel__title">Produk Terlaris Bulan Ini</div>' +
          '<div class="tops">' +
            U.topRow({ rank: 1, name: 'Es Kopi Susu', qty: 214, amount: 3852000 }) +
            U.topRow({ rank: 2, name: 'Choco Latte', qty: 168, amount: 3360000 }) +
            U.topRow({ rank: 3, name: 'Red Velvet Latte', qty: 141, amount: 3102000 }) +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';

  // 8) Babak 8 (29–33) — stok aman tanpa kehabisan
  actEls[7].innerHTML =
    claimHTML('Stok aman, tanpa kehabisan') +
    '<div class="stockcard">' +
      '<div class="stockcard__head">' +
        '<div class="stockcard__title">Stok Rendah</div>' +
        '<div class="stockcard__badge">3 perlu perhatian</div>' +
      '</div>' +
      '<div class="stocks">' +
        U.stockRow({ name: 'Bubuk Red Velvet', qty: 0, min: 5, pct: 4, tone: 'warn' }) +
        U.stockRow({ name: 'Cup Besar', qty: 52, min: 200, pct: 26, tone: 'warn' }) +
        U.stockRow({ name: 'Plastik 1 Cup', qty: 100, min: 350, pct: 29, tone: 'warn' }) +
        U.stockRow({ name: 'Es Kopi Susu (bahan)', qty: 86, min: 40, pct: 100, tone: 'ok' }) +
      '</div>' +
    '</div>';

  // 9) Babak 9 (33–36) — hasil: fokus ke jualan
  actEls[8].innerHTML =
    claimHTML('Fokus ke yang penting: jualan') +
    '<div class="mascot">' +
      '<div class="mascot__fox">🦊</div>' +
      '<div>' +
        '<div class="mascot__cap">Kasir Macca, Usaha Maju</div>' +
        '<div class="mascot__sub">GrowUp · Aktif 282 hari lagi</div>' +
      '</div>' +
    '</div>' +
    '<div class="chip">Omzet ↑ · Transaksi ↑ · Stok terkendali</div>';

  // 10) Babak 10 (36–40) — penutup: wordmark + download di Play Store
  actEls[9].innerHTML =
    U.wordmark() +
    '<div class="outro__tag">Kasir Macca, Usaha Maju</div>' +
    U.playBadge() +
    '<div class="outro__cta">Download di Play Store</div>';

  // ── Pintu teks per kata + blur berarah (signature: outIn vertikal) ──────
  function words(act) { return act.querySelectorAll('.claim .w'); }

  function inUp(tl, ws, t, opts) {
    opts = opts || {};
    tl.fromTo(ws,
      { y: opts.y != null ? opts.y : 70, autoAlpha: 0, filter: 'blur(14px)' },
      { y: 0, autoAlpha: 1, filter: 'blur(0px)', duration: opts.dur || 0.7,
        stagger: opts.stagger || 0.07, ease: 'power3.out' }, t);
  }
  function outUp(tl, ws, t, opts) {
    opts = opts || {};
    tl.fromTo(ws,
      { y: 0, autoAlpha: 1, filter: 'blur(0px)' },
      { y: opts.y != null ? opts.y : -46, autoAlpha: 0, filter: 'blur(12px)',
        duration: opts.dur || 0.5, stagger: opts.stagger || 0.05,
        ease: 'power2.in', immediateRender: false }, t);
  }
  // Kartu/objek: masuk dari bawah, keluar ke atas (tanda tangan gerak)
  function cardIn(tl, el, t, opts) {
    opts = opts || {};
    tl.fromTo(el,
      { y: opts.y != null ? opts.y : 80, autoAlpha: 0, scale: opts.scale || 1, filter: 'blur(16px)' },
      { y: 0, autoAlpha: 1, scale: 1, filter: 'blur(0px)', duration: opts.dur || 0.7,
        stagger: opts.stagger || 0, ease: 'power3.out' }, t);
  }
  function cardOut(tl, el, t, opts) {
    opts = opts || {};
    tl.fromTo(el,
      { y: 0, autoAlpha: 1, filter: 'blur(0px)' },
      { y: opts.y != null ? opts.y : -70, autoAlpha: 0, filter: 'blur(16px)',
        duration: opts.dur || 0.5, stagger: opts.stagger || 0,
        ease: 'power2.in', immediateRender: false }, t);
  }

  // Angka berjalan (deterministik: dari objek proxy)
  function fmtCount(el, v) {
    var n = Math.round(v);
    var pre = el.dataset.pre || '';
    var suf = el.dataset.suf || '';
    var sign = el.dataset.sign ? '+' : '';
    if (pre.indexOf('Rp') === 0) {
      el.textContent = sign + 'Rp ' + n.toLocaleString('id-ID');
    } else {
      el.textContent = sign + pre + n + suf;
    }
  }
  function countUp(tl, el, t, dur, to) {
    var o = { v: 0 };
    tl.fromTo(o, { v: 0 }, {
      v: to, duration: dur || 1.4, ease: 'power2.out', immediateRender: false,
      onUpdate: function () { fmtCount(el, o.v); }
    }, t);
  }

  // ── Latar per segmen (pemicu terlihat: field wipe & cut di ketukan) ─────
  var BG = [
    '#efe9fb', // 1 pembuka: lavender terang
    '#efe9fb', // 2 omzet: lavender terang
    '#efe9fb', // 3 transaksi
    '#efe9fb', // 4 klik POS
    '#efe9fb', // 5 PIN
    '#efe9fb', // 6 POS transaksi
    '#efe9fb', // 7 dashboard ✦
    '#efe9fb', // 8 stok
    '#ffffff', // 9 hasil: latar putih bersih
    '#8654ea'  // 10 penutup: medan ungu
  ];

  // ── Timeline ────────────────────────────────────────────────────────────
  var tl = gsap.timeline({ paused: true, onUpdate: onTick });
  window.__tl = tl;

  function onTick() {
    cam.update(tl.time());
    if (dbgOn) {
      dbgT.textContent = tl.time().toFixed(2);
      dbgAct.textContent = Math.floor(cam.K.a + 0.001) + 1;
      if (!dbgScrubbing) dbgRange.value = tl.time();
    }
  }

  // Transisi push-through: bidang warna menyapu → lompat kamera → buka
  function push(t, toAct, fieldColor) {
    tl.set(field, { '--field': fieldColor }, t - 0.55);
    tl.fromTo(field, { scaleY: 0 },
      { scaleY: 1, duration: 0.45, ease: 'power3.in', immediateRender: false }, t - 0.45);
    tl.set(cam.K, { a: toAct }, t);
    tl.fromTo(field, { scaleY: 1 },
      { scaleY: 0, duration: 0.5, ease: 'power3.out', immediateRender: false }, t);
    tl.set(stage, { '--bg': BG[toAct] }, t);
    // Dorongan kamera kecil saat tertutup (menambah tenaga transisi)
    tl.fromTo(cam.K, { zoom: 1.07 }, { zoom: 1, duration: 0.8, ease: 'power2.out', immediateRender: false }, t);
  }

  // Transisi whip pan: kamera meluncur cepat ke babak berikutnya
  function whip(t, toAct, dir) {
    tl.to(cam.K, { x: dir * 320, rot: dir * 1.4, duration: 0.35, ease: 'power2.in' }, t - 0.35);
    tl.to(cam.K, { a: toAct, duration: 0.45, ease: 'power4.inOut' }, t);
    tl.to(cam.K, { x: 0, rot: 0, duration: 0.5, ease: 'power3.out' }, t + 0.45);
    tl.fromTo(cam.K, { zoom: 1.05 }, { zoom: 1, duration: 0.8, ease: 'power2.out', immediateRender: false }, t + 0.3);
  }

  // ═══ ADEGAN 1 (0–3): pembuka — maskot + brand sudah berjalan di frame 1 ══
  var A1 = actEls[0];
  var fox1 = A1.querySelector('.brandblock__fox');
  var pill1 = A1.querySelector('.pill');
  // Fox & klaim tanpa animasi masuk = sudah hidup di frame 1
  tl.fromTo(pill1, { y: 40, autoAlpha: 0, scale: 0.8 },
    { y: 0, autoAlpha: 1, scale: 1, duration: 0.5, ease: 'back.out(2)' }, 0.6);
  tl.fromTo(fox1, { rotation: -8 }, { rotation: 8, duration: 1.1, ease: 'sine.inOut', yoyo: true, repeat: 1 }, 0.5);
  inUp(tl, words(A1), 0.15, { dur: 0.75 });
  outUp(tl, words(A1), 2.35);
  cardOut(tl, [fox1, pill1], 2.4, { stagger: 0.06 });

  // ═══ ADEGAN 2 (3–7): kartu omzet berhitung naik ═════════════════════════
  push(3, 1, '#efe9fb');
  var A2 = actEls[1];
  inUp(tl, words(A2), 3.55);
  var omz = A2.querySelector('.stat');
  cardIn(tl, omz, 3.75, { y: 80, dur: 0.7, scale: 0.94 });
  countUp(tl, omz.querySelector('[data-count]'), 4.1, 1.6, 4875000);
  tl.fromTo(omz.querySelector('.stat__sub'), { y: 30, autoAlpha: 0 },
    { y: 0, autoAlpha: 1, duration: 0.5, ease: 'power2.out' }, 5.1);
  cardOut(tl, omz, 6.1, { y: -70 });
  outUp(tl, words(A2), 6.3);

  // ═══ ADEGAN 3 (7–10): penjualan & transaksi hari ini ════════════════════
  push(7, 2, '#efe9fb');
  var A3 = actEls[2];
  inUp(tl, words(A3), 7.5);
  var cards3 = A3.querySelectorAll('.stat');
  cardIn(tl, cards3, 7.7, { y: 70, dur: 0.65, stagger: 0.14 });
  var c3 = A3.querySelectorAll('[data-count]');
  for (var k3 = 0; k3 < c3.length; k3++) {
    countUp(tl, c3[k3], 7.95 + k3 * 0.2, 1.2, parseFloat(c3[k3].dataset.count));
  }
  cardOut(tl, cards3, 9.2, { y: -60, stagger: 0.08 });
  outUp(tl, words(A3), 9.4);

  // ═══ ADEGAN 4 (10–14): klik menu POS ════════════════════════════════════
  whip(10, 3, 1);
  var A4 = actEls[3];
  inUp(tl, words(A4), 10.55);
  var app4 = A4.querySelector('.app');
  cardIn(tl, app4, 10.7, { y: 90, dur: 0.75, scale: 0.96 });
  // Kursor melayang ke menu POS → klik dengan cincin → menu menyala
  var cur4 = A4.querySelector('.cursor-pos');
  var posMenu = A4.querySelector('.menu-pos');
  var ring4 = cur4.querySelector('.cursor__ring');
  tl.fromTo(cur4, { x: 760, y: 780, autoAlpha: 0 },
    { x: 760, y: 780, autoAlpha: 1, duration: 0.3, ease: 'power2.out' }, 11.3);
  tl.to(cur4, { x: 76, y: 306, duration: 0.7, ease: 'power2.inOut' }, 11.6);
  // klik 1
  tl.fromTo(ring4, { scale: 0.3, opacity: 0.9 }, { scale: 1, opacity: 0, duration: 0.5, ease: 'power2.out', immediateRender: false }, 12.35);
  tl.fromTo(cur4, { scale: 1 }, { scale: 0.86, duration: 0.09, yoyo: true, repeat: 1, immediateRender: false }, 12.35);
  tl.fromTo(posMenu, { scale: 1 }, { scale: 1.05, duration: 0.1, yoyo: true, repeat: 1, immediateRender: false }, 12.35);
  // klik 2 (masuk POS: ghost berdenyut, transisi ke babak PIN)
  tl.fromTo(ring4, { scale: 0.3, opacity: 0.9 }, { scale: 1.1, opacity: 0, duration: 0.5, ease: 'power2.out', immediateRender: false }, 13.05);
  tl.fromTo(cur4, { scale: 1 }, { scale: 0.86, duration: 0.09, yoyo: true, repeat: 1, immediateRender: false }, 13.05);
  tl.fromTo(A4.querySelector('.app__ghost'), { scale: 1 }, { scale: 1.04, duration: 0.14, yoyo: true, repeat: 1, immediateRender: false }, 13.05);
  cardOut(tl, app4, 13.3, { y: -70 });
  outUp(tl, words(A4), 13.4);

  // ═══ ADEGAN 5 (14–17): PIN 111222 ═══════════════════════════════════════
  push(14, 4, '#efe9fb');
  var A5 = actEls[4];
  inUp(tl, words(A5), 14.45);
  var pad5 = A5.querySelector('.pad');
  cardIn(tl, pad5, 14.6, { y: 80, dur: 0.7, scale: 0.95 });
  // 1-1-1-2-2-2: digit terisi berurutan, tombol menekan (deterministik)
  var PIN = ['1', '1', '1', '2', '2', '2'];
  var keys5 = A5.querySelectorAll('.key--num');
  var dots5 = A5.querySelectorAll('.dot');
  // Peta angka → indeks key pada grid 1..9,0 (urutan DOM: 1..9, ⌫, 0, ✓)
  function keyEl(k) {
    if (k === '0') return keys5[9];
    return keys5[parseInt(k, 10) - 1];
  }
  PIN.forEach(function (k, idx) {
    var t = 14.95 + idx * 0.24;
    var kel = keyEl(k);
    tl.fromTo(kel, { scale: 1 }, { scale: 0.9, duration: 0.08, yoyo: true, repeat: 1, immediateRender: false }, t);
    tl.set(dots5[idx], { backgroundColor: '#8654ea', borderColor: '#8654ea', scale: 1.15 }, t + 0.06);
    tl.to(dots5[idx], { scale: 1, duration: 0.2, ease: 'back.out(2)' }, t + 0.06);
  });
  tl.fromTo(A5.querySelector('.pad__ok'), { y: 30, autoAlpha: 0 },
    { y: 0, autoAlpha: 1, duration: 0.5, ease: 'back.out(1.8)', immediateRender: false }, 16.4);
  cardOut(tl, pad5, 16.6, { y: -70 });
  outUp(tl, words(A5), 16.7);

  // ═══ ADEGAN 6 (17–24): transaksi di POS — pilih, bayar, selesai ═════════
  whip(17, 5, -1);
  var A6 = actEls[5];
  inUp(tl, words(A6), 17.5);
  var pos6 = A6.querySelector('.pos');
  cardIn(tl, pos6, 17.7, { y: 90, dur: 0.75, scale: 0.96 });
  var prods6 = A6.querySelectorAll('.prod');
  cardIn(tl, prods6, 18.0, { y: 50, dur: 0.5, stagger: 0.07 });
  var rows6 = A6.querySelectorAll('.cart__row');
  // Pilih 2 produk → keranjang terisi berurutan → total berhitung naik
  var cur6 = A6.querySelector('.cursor-pos2');
  var ring6 = cur6.querySelector('.cursor__ring');
  tl.fromTo(cur6, { x: 860, y: 700, autoAlpha: 0 },
    { x: 860, y: 700, autoAlpha: 1, duration: 0.3, ease: 'power2.out', immediateRender: false }, 18.3);
  // klik produk #1 (Es Kopi Susu) → muncul baris 1
  tl.to(cur6, { x: 130, y: 240, duration: 0.5, ease: 'power2.inOut' }, 18.5);
  clickPulse(ring6, cur6, 19.05);
  tl.set(prods6[0], { className: 'prod is-sel' }, 19.1);
  tl.fromTo(rows6[0], { y: 30, autoAlpha: 0, filter: 'blur(8px)' },
    { y: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.4, ease: 'power3.out', immediateRender: false }, 19.15);
  // klik produk #2 (Choco Latte) → muncul baris 2
  tl.to(cur6, { x: 440, y: 240, duration: 0.5, ease: 'power2.inOut' }, 19.55);
  clickPulse(ring6, cur6, 20.1);
  tl.set(prods6[1], { className: 'prod is-sel' }, 20.15);
  tl.fromTo(rows6[1], { y: 30, autoAlpha: 0, filter: 'blur(8px)' },
    { y: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.4, ease: 'power3.out', immediateRender: false }, 20.2);
  tl.fromTo(rows6[2], { y: 30, autoAlpha: 0, filter: 'blur(8px)' },
    { y: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.4, ease: 'power3.out', immediateRender: false }, 20.5);
  countUp(tl, A6.querySelector('[data-count]'), 20.5, 1.4, 86000);
  // klik Bayar → struk sukses
  var pay6 = A6.querySelector('.cart__pay');
  tl.to(cur6, { x: 700, y: 690, duration: 0.6, ease: 'power2.inOut' }, 21.0);
  clickPulse(ring6, cur6, 21.7);
  tl.fromTo(pay6, { scale: 1 }, { scale: 0.94, duration: 0.1, yoyo: true, repeat: 1, immediateRender: false }, 21.7);
  var paid6 = A6.querySelector('.paid');
  tl.fromTo(paid6, { autoAlpha: 0, scale: 0.9 },
    { autoAlpha: 1, scale: 1, duration: 0.45, ease: 'back.out(1.6)', immediateRender: false }, 21.85);
  tl.fromTo(paid6.querySelector('.paid__check'), { scale: 0.4, rotation: -20 },
    { scale: 1, rotation: 0, duration: 0.55, ease: 'back.out(2.4)', immediateRender: false }, 22.0);
  cardOut(tl, pos6, 23.1, { y: -70 });
  outUp(tl, words(A6), 23.3);

  // ═══ ADEGAN 7 (24–29): dashboard lengkap terisi ✦ momen istimewa ════════
  push(24, 6, '#efe9fb');
  var A7 = actEls[6];
  inUp(tl, words(A7), 24.5);
  var minis = A7.querySelectorAll('.mini');
  cardIn(tl, minis, 24.7, { y: 70, dur: 0.6, stagger: 0.1 });
  var panels7 = A7.querySelectorAll('.panel');
  cardIn(tl, panels7, 25.1, { y: 80, dur: 0.7, stagger: 0.14 });
  // Semua angka berjalan naik serentak
  var cnt7 = A7.querySelectorAll('[data-count]');
  for (var k7 = 0; k7 < cnt7.length; k7++) {
    countUp(tl, cnt7[k7], 25.3 + (k7 % 4) * 0.12, 1.5, parseFloat(cnt7[k7].dataset.count));
  }
  // Batang tren tumbuh berurutan (kiri → kanan)
  var fills = A7.querySelectorAll('.bar__fill');
  for (var b7 = 0; b7 < fills.length; b7++) {
    var h = parseFloat(fills[b7].dataset.h);
    tl.fromTo(fills[b7], { scaleY: 0, transformOrigin: 'bottom' },
      { scaleY: h / 100, duration: 0.55, ease: 'power3.out', immediateRender: false }, 25.7 + b7 * 0.1);
  }
  // Heatmap jam menyala per kolom
  var cells = A7.querySelectorAll('.cell');
  for (var c7 = 0; c7 < cells.length; c7++) {
    tl.fromTo(cells[c7], { opacity: 0.12 },
      { opacity: parseFloat(cells[c7].dataset.w), duration: 0.4, ease: 'power2.out', immediateRender: false },
      26.1 + (c7 % 12) * 0.04 + Math.floor(c7 / 12) * 0.07);
  }
  // Transaksi & top produk masuk berurutan
  var txns7 = A7.querySelectorAll('.txn');
  cardIn(tl, txns7, 26.6, { y: 40, dur: 0.45, stagger: 0.12 });
  var tops7 = A7.querySelectorAll('.top');
  cardIn(tl, tops7, 26.8, { y: 40, dur: 0.45, stagger: 0.12 });
  cardOut(tl, [minis, panels7], 28.2, { y: -70, stagger: 0.06 });
  outUp(tl, words(A7), 28.4);

  // ═══ ADEGAN 8 (29–33): stok aman ════════════════════════════════════════
  whip(29, 7, 1);
  var A8 = actEls[7];
  inUp(tl, words(A8), 29.5);
  var card8 = A8.querySelector('.stockcard');
  cardIn(tl, card8, 29.7, { y: 80, dur: 0.7, scale: 0.95 });
  var stocks8 = A8.querySelectorAll('.stock');
  cardIn(tl, stocks8, 30.0, { y: 50, dur: 0.5, stagger: 0.12 });
  var fills8 = A8.querySelectorAll('.stock__fill');
  for (var s8 = 0; s8 < fills8.length; s8++) {
    tl.fromTo(fills8[s8], { scaleX: 0, transformOrigin: 'left' },
      { scaleX: parseFloat(fills8[s8].dataset.w) / 100, duration: 0.7, ease: 'power3.out', immediateRender: false }, 30.3 + s8 * 0.12);
  }
  // Badge merah berdenyut lalu tenang
  var badge8 = A8.querySelector('.stockcard__badge');
  tl.fromTo(badge8, { scale: 1 }, { scale: 1.12, duration: 0.35, yoyo: true, repeat: 3, immediateRender: false }, 30.6);
  cardOut(tl, card8, 32.1, { y: -70 });
  outUp(tl, words(A8), 32.3);

  // ═══ ADEGAN 9 (33–36): hasil — fokus ke jualan ══════════════════════════
  push(33, 8, '#ffffff');
  var A9 = actEls[8];
  var w9 = A9.querySelectorAll('.claim .w');
  // Mode terang: teks gelap (kantong teks tidak perlu)
  inUp(tl, w9, 33.5);
  var mascot9 = A9.querySelector('.mascot');
  cardIn(tl, mascot9, 33.7, { y: 70, dur: 0.7, scale: 0.94 });
  var fox9 = A9.querySelector('.mascot__fox');
  tl.fromTo(fox9, { rotation: -6 }, { rotation: 6, duration: 0.9, ease: 'sine.inOut', yoyo: true, repeat: 1 }, 33.9);
  var chip9 = A9.querySelector('.chip');
  cardIn(tl, chip9, 34.4, { y: 50, dur: 0.6 });
  cardOut(tl, [chip9, mascot9], 35.2, { stagger: 0.08, y: -60 });
  outUp(tl, w9, 35.4);

  // ═══ ADEGAN 10 (36–40): penutup — wordmark + Google Play ════════════════
  tl.set(cam.K, { a: 9 }, 36);
  tl.set(stage, { '--bg': BG[9] }, 36);
  var A10 = actEls[9];
  var mark = A10.querySelector('.mark');
  var tag = A10.querySelector('.outro__tag');
  var play = A10.querySelector('.play');
  var cta = A10.querySelector('.outro__cta');
  tl.fromTo(mark, { y: 90, autoAlpha: 0, scale: 0.92, filter: 'blur(18px)' },
    { y: 0, autoAlpha: 1, scale: 1, filter: 'blur(0px)', duration: 0.8, ease: 'power3.out' }, 36.3);
  tl.fromTo(tag, { y: 40, autoAlpha: 0 },
    { y: 0, autoAlpha: 1, duration: 0.5, ease: 'power2.out', immediateRender: false }, 36.95);
  tl.fromTo(play, { y: 60, autoAlpha: 0, scale: 0.9 },
    { y: 0, autoAlpha: 1, scale: 1, duration: 0.6, ease: 'back.out(1.8)', immediateRender: false }, 37.4);
  tl.fromTo(cta, { y: 50, autoAlpha: 0, scale: 0.9 },
    { y: 0, autoAlpha: 1, scale: 1, duration: 0.55, ease: 'back.out(1.8)', immediateRender: false }, 38.0);
  // Keluar halus supaya loop mulai bersih
  cardOut(tl, [cta, play, tag, mark], 39.4, { stagger: 0.06, y: -50, dur: 0.45 });

  // Kursor: pulse cincin klik (dipakai babak 4 & 6)
  function clickPulse(ring, cur, t) {
    tl.fromTo(ring, { scale: 0.3, opacity: 0.9 },
      { scale: 1.1, opacity: 0, duration: 0.5, ease: 'power2.out', immediateRender: false }, t);
    tl.fromTo(cur, { scale: 1 }, { scale: 0.86, duration: 0.09, yoyo: true, repeat: 1, immediateRender: false }, t);
  }

  // ── Gerak latar sepanjang video (blob mengambang + sapuan cahaya) ───────
  tl.fromTo('#blobA', { x: -50, y: 30 }, { x: 70, y: -50, duration: 16, ease: 'none', repeat: 2, yoyo: true }, 0);
  tl.fromTo('#blobB', { x: 60, y: -30 }, { x: -60, y: 50, duration: 16, ease: 'none', repeat: 2, yoyo: true }, 0);
  [3, 7, 10, 14, 17, 24, 29, 33, 36].forEach(function (t) {
    tl.fromTo('#sweep', { xPercent: -80 }, { xPercent: 80, duration: 1.0, ease: 'power2.inOut', immediateRender: false }, t - 0.3);
  });

  tl.set({}, {}, DUR); // pastikan durasi pas 40 detik

  // ── Putar otomatis + loop (satu sumber waktu: gsap.ticker) ──────────────
  var exportMode = /[?&]export=1/.test(location.search);
  var cleanMode = /[?&]clean=1/.test(location.search);
  var playing = !exportMode && !cleanMode;

  gsap.ticker.add(function (_t, dt) {
    if (!playing) return;
    var nt = tl.time() + dt / 1000;
    if (nt >= DUR) nt -= DUR;
    tl.time(nt, false);
  });

  // Mode ekspor MP4: bekukan, kontrol frame dari luar lewat window.__setTime
  if (exportMode) {
    playing = false;
    tl.time(0, false);
    window.__DUR = DUR;
    window.__setTime = function (t) { tl.time(Math.min(t, DUR - 0.0001), false); };
  }

  // Kontrol tersembunyi: Space jeda/lanjut, R ulang
  addEventListener('keydown', function (e) {
    if (e.code === 'Space') { e.preventDefault(); playing = !playing; }
    if (e.key === 'r' || e.key === 'R') { tl.time(0, false); playing = true; }
  });

  // ── Panel debug (?debug=1): scrub per detik ─────────────────────────────
  var dbgOn = /[?&]debug=1/.test(location.search);
  var dbgEl = document.getElementById('debug');
  var dbgT = document.getElementById('dbgT');
  var dbgAct = document.getElementById('dbgAct');
  var dbgRange = document.getElementById('dbgRange');
  var dbgScrubbing = false;
  if (dbgOn) {
    dbgEl.classList.add('on');
    dbgRange.max = DUR;
    dbgRange.addEventListener('pointerdown', function () { dbgScrubbing = true; playing = false; });
    dbgRange.addEventListener('input', function () { tl.time(parseFloat(dbgRange.value)); });
    dbgRange.addEventListener('pointerup', function () { dbgScrubbing = false; });
  }
  if (/[?&]clean=1/.test(location.search)) playing = false;

  onTick();
})();
