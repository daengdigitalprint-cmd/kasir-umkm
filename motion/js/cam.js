// cam.js — rig kamera tunggal. Seluruh dunia (10 babak dalam #world) ikut
// saat kamera bergerak (Hukum #1). Model: kamera fokus ke babak ke-`a`
// (pecahan boleh, untuk whip), dengan zoom, geser-x, dan rotasi kecil.
// Semua gerak deterministik: fungsi murni dari state + waktu tl (Hukum #2).
// Skrip klasik (window.makeCam) agar index.html bisa dibuka lewat file://.

window.makeCam = function () {
  'use strict';

  var el = document.getElementById('cam');
  var grid = document.getElementById('grid');
  var K = { a: 0, zoom: 1, x: 0, rot: 0 };

  // Terapkan state → DOM. Dipanggil tiap update timeline.
  function update(t) {
    // Napas kamera (§3b): sinus halus deterministik, hanya beberapa persen.
    var br = 1 + 0.016 * Math.sin(t * 0.9) + 0.008 * Math.sin(t * 0.37 + 1.3);
    var s = K.zoom * br;
    var ty = -s * K.a * 1920; // pusat babak ke-K.a selalu di tengah layar
    el.style.transform =
      'translate(' + K.x + 'px, ' + ty + 'px) rotate(' + K.rot + 'deg) scale(' + s + ')';

    // Grid parallax lawan arah kamera (latar gerak §7c), modulo 90px
    // supaya pola grid selalu seamlessly mengisi.
    var gy = (((s * K.a * 1920 * 0.22) % 90) + 90) % 90;
    var gx = (((K.x * 0.25) % 90) + 90) % 90;
    grid.style.transform = 'translate(' + (gx - 90) + 'px, ' + (gy - 90) + 'px)';
  }

  update(0);
  return { K: K, update: update };
};
