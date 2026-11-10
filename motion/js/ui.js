// ui.js — komponen antarmuka MaccaPOS: kartu statistik, grafik batang,
// heatmap jam ramai, transaksi terbaru, top produk, stok rendah, keypad PIN,
// POS (produk + keranjang), badge Play Store, wordmark.
// Objek murni: mengembalikan HTML. Tanpa animasi (urusan js/scene.js).
// Semua data dummy positif — lihat motion/brief-konsep.md.
// Ditulis sebagai skrip klasik (window.UI) agar index.html bisa dibuka
// langsung lewat file:// tanpa server.

window.UI = (function () {
  'use strict';

  var rp = function (n) { return 'Rp ' + n.toLocaleString('id-ID'); };

  // ── Kartu statistik (omzet / penjualan / transaksi / stok) ──────────────
  function statCard(o) {
    return '<div class="stat" data-tone="' + (o.tone || 'plain') + '">' +
      '<div class="stat__label">' + o.label + '</div>' +
      '<div class="stat__value">' + o.value + '</div>' +
      (o.sub ? '<div class="stat__sub">' + o.sub + '</div>' : '') +
      '</div>';
  }

  // ── Grafik batang tren 7 hari (Sen–Ming) ────────────────────────────────
  var TREND = [
    { d: 'Sen', v: 62 }, { d: 'Sel', v: 78 }, { d: 'Rab', v: 71 },
    { d: 'Kam', v: 89 }, { d: 'Jum', v: 96 }, { d: 'Sab', v: 100 },
    { d: 'Min', v: 84 }
  ];

  function bars() {
    return '<div class="bars">' + TREND.map(function (b) {
      return '<div class="bar">' +
        '<div class="bar__col"><div class="bar__fill" data-h="' + b.v + '"></div></div>' +
        '<div class="bar__day">' + b.d + '</div>' +
        '</div>';
    }).join('') + '</div>';
  }

  // ── Heatmap jam ramai (6 minggu × 12 slot 2 jam) ────────────────────────
  // Intensitas deterministik: puncak 12.00–14.00, sisanya terisi wajar.
  var HOUR_W = [
    0.15, 0.08, 0.05, 0.05, 0.08, 0.18, 0.32, 0.45, 0.55, 0.62, 0.70, 0.85,
    1.00, 0.95, 0.68, 0.58, 0.62, 0.72, 0.80, 0.66, 0.52, 0.40, 0.28, 0.20
  ];

  function heat() {
    var cells = '';
    for (var r = 0; r < 6; r++) {
      for (var s = 0; s < 12; s++) {
        // 12 slot per 2 jam; dua jam dirata-ratakan lalu digeser per minggu
        var base = (HOUR_W[s * 2] + HOUR_W[s * 2 + 1]) / 2;
        var w = base * (0.84 + 0.16 * Math.abs(Math.sin((s + 1) * (r + 1) * 1.7)));
        if (w > 1) w = 1;
        cells += '<span class="cell" data-w="' + w.toFixed(2) + '"></span>';
      }
    }
    var labels = '';
    for (var h2 = 0; h2 < 24; h2 += 4) {
      labels += '<span>' + h2 + '</span>';
    }
    return '<div class="heat"><div class="heat__grid">' + cells + '</div>' +
      '<div class="heat__axis">' + labels + '</div></div>';
  }

  // ── Baris transaksi terbaru ──────────────────────────────────────────────
  function txnRow(o) {
    return '<div class="txn">' +
      '<span class="txn__dot"></span>' +
      '<span class="txn__name">' + o.name + '</span>' +
      '<span class="txn__time">' + o.time + '</span>' +
      '<span class="txn__amt">' + rp(o.amount) + '</span>' +
      '</div>';
  }

  // ── Baris produk terlaris ────────────────────────────────────────────────
  function topRow(o) {
    return '<div class="top">' +
      '<span class="top__rank">' + o.rank + '</span>' +
      '<span class="top__name">' + o.name + '</span>' +
      '<span class="top__qty">' + o.qty + ' cup</span>' +
      '<span class="top__amt">' + rp(o.amount) + '</span>' +
      '</div>';
  }

  // ── Baris stok ───────────────────────────────────────────────────────────
  function stockRow(o) {
    return '<div class="stock" data-tone="' + (o.tone || 'warn') + '">' +
      '<span class="stock__name">' + o.name + '</span>' +
      '<span class="stock__lvl"><span class="stock__bar"><span class="stock__fill" data-w="' + o.pct + '"></span></span>' +
      '<span class="stock__num">' + o.qty + '/' + o.min + '</span></span>' +
      '</div>';
  }

  // ── Sidik jari kode POS: produk bisa dipilih ─────────────────────────────
  var PRODUCTS = [
    { id: 'p1', name: 'Es Kopi Susu', price: 18000 },
    { id: 'p2', name: 'Choco Latte', price: 20000 },
    { id: 'p3', name: 'Red Velvet', price: 22000 },
    { id: 'p4', name: 'Croissant', price: 15000 },
    { id: 'p5', name: 'Pisang Goreng', price: 12000 },
    { id: 'p6', name: 'Es Teh Manis', price: 8000 }
  ];

  function prodGrid() {
    return '<div class="prods">' + PRODUCTS.map(function (p) {
      return '<div class="prod" data-id="' + p.id + '">' +
        '<div class="prod__name">' + p.name + '</div>' +
        '<div class="prod__price">' + rp(p.price) + '</div>' +
        '</div>';
    }).join('') + '</div>';
  }

  var CART = [
    { name: 'Es Kopi Susu', qty: 2, amount: 36000 },
    { name: 'Choco Latte', qty: 1, amount: 20000 },
    { name: 'Croissant', qty: 2, amount: 30000 }
  ];

  function cart() {
    return '<div class="cart">' +
      '<div class="cart__title">Keranjang</div>' +
      '<div class="cart__rows">' + CART.map(function (c) {
        return '<div class="cart__row">' +
          '<span class="cart__name">' + c.name + '</span>' +
          '<span class="cart__qty">×' + c.qty + '</span>' +
          '<span class="cart__amt">' + rp(c.amount) + '</span>' +
          '</div>';
      }).join('') + '</div>' +
      '<div class="cart__total"><span>Total</span><span data-count="86000" data-pre="Rp ">Rp 0</span></div>' +
      '<div class="cart__pay">Bayar</div>' +
      '</div>';
  }

  // ── Keypad PIN (111222) ──────────────────────────────────────────────────
  function keypad() {
    var keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', '✓'];
    return '<div class="pad">' +
      '<div class="pad__dots">' +
        '<span class="dot" data-i="0"></span><span class="dot" data-i="1"></span>' +
        '<span class="dot" data-i="2"></span><span class="dot" data-i="3"></span>' +
        '<span class="dot" data-i="4"></span><span class="dot" data-i="5"></span>' +
      '</div>' +
      '<div class="pad__keys">' + keys.map(function (k) {
        var isNum = k >= '0' && k <= '9';
        return '<span class="key' + (isNum ? ' key--num' : '') + '" data-k="' + k + '">' + k + '</span>';
      }).join('') + '</div>' +
      '</div>';
  }

  // ── Wordmark MaccaPOS (teks, bukan gambar) ───────────────────────────────
  function wordmark() {
    return '<div class="mark"><span class="mark__a">Macca</span><span class="mark__b">POS</span></div>';
  }

  // ── Badge Google Play ────────────────────────────────────────────────────
  function playBadge() {
    return '<div class="play">' +
      '<svg class="play__icon" viewBox="0 0 24 24" width="52" height="52" aria-hidden="true">' +
      '<path fill="#00D2FF" d="M3.6 1.8c-.3.3-.5.8-.5 1.4v17.6c0 .6.2 1.1.5 1.4l.1.1 9.9-9.9v-.2L3.7 1.7l-.1.1z"/>' +
      '<path fill="#FFCE00" d="M17 16.1l-3.4-3.4v-.2l3.4-3.4.1 0 4.1 2.3c1.2.7 1.2 1.8 0 2.5L17 16.1z"/>' +
      '<path fill="#FF3A44" d="M17.1 16L13.6 12.5 3.6 22.5c.4.4 1 .5 1.8.1l11.7-6.6"/>' +
      '<path fill="#00F076" d="M17.1 8L5.4 1.4C4.6 1 4 1.1 3.6 1.5l10 10 3.5-3.5z"/>' +
      '</svg>' +
      '<span class="play__txt"><span class="play__small">Dapatkan di</span>' +
      '<span class="play__big">Google Play</span></span>' +
      '</div>';
  }

  return {
    rp: rp, statCard: statCard, bars: bars, heat: heat,
    txnRow: txnRow, topRow: topRow, stockRow: stockRow,
    prodGrid: prodGrid, cart: cart, keypad: keypad,
    wordmark: wordmark, playBadge: playBadge,
    PRODUCTS: PRODUCTS, CART: CART, TREND: TREND
  };
})();
