/**
 * Mengambil screenshot halaman asli aplikasi untuk dipakai di video Remotion.
 *
 * Jalankan: node scripts/capture.mjs
 *
 * Memakai Chrome yang sudah terpasang di sistem (puppeteer-core, tanpa unduhan
 * browser tambahan). Hasilnya PNG beresolusi 3x supaya tetap tajam saat
 * ditampilkan di video 1080x1920.
 */

import { mkdir, readdir } from "node:fs/promises";
import path from "node:path";
import puppeteer from "puppeteer-core";

const CHROME =
  process.env.CHROME_PATH ??
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe";

const BASE = process.env.APP_URL ?? "https://umkm-kasir.test";
const EMAIL = process.env.DEMO_EMAIL ?? "demo@umkm-kasir.test";
const PASSWORD = process.env.DEMO_PASSWORD ?? "password";

const OUT = path.resolve("public/shots");

/** Lebar 430 = iPhone 15 Pro Max, rasionya dekat dengan 9:16. */
const PHONE = { width: 430, height: 932, deviceScaleFactor: 3 };
/** Dipakai untuk halaman yang butuh tabel lebar seperti laporan. */
const DESKTOP = { width: 1440, height: 900, deviceScaleFactor: 2 };

const SHOTS = [
  { name: "pos", url: "/pos", viewport: PHONE, wait: 4000 },
  { name: "pos-desktop", url: "/pos", viewport: DESKTOP, wait: 4000 },
  { name: "dashboard", url: "/dashboard", viewport: PHONE, wait: 4500 },
  { name: "dashboard-desktop", url: "/dashboard", viewport: DESKTOP, wait: 4500 },
  { name: "products", url: "/products", viewport: PHONE, wait: 3500 },
  { name: "stock", url: "/stock-overview", viewport: PHONE, wait: 3500 },
  { name: "reports", url: "/reports", viewport: DESKTOP, wait: 4000 },
  { name: "reports-sales", url: "/reports/sales", viewport: DESKTOP, wait: 4000 },
  { name: "transactions", url: "/pos/transactions", viewport: PHONE, wait: 3500 },
];

/**
 * Herd menyuntikkan tombol "Edit with Herd" ke setiap halaman. Elemen itu
 * bukan bagian dari aplikasi, jadi disembunyikan sebelum screenshot diambil.
 */
const HIDE_DEV_OVERLAYS = `
  const kill = [];
  document.querySelectorAll('div,iframe,button').forEach((el) => {
    const text = (el.textContent || '').trim();
    const id = (el.id || '') + ' ' + (el.className || '');
    if (/herd/i.test(id) || /Edit with/i.test(text) && el.children.length < 6) {
      kill.push(el);
    }
  });
  kill.forEach((el) => el.remove());
  document.querySelectorAll('[data-herd], #herd-toolbar, .herd-toolbar').forEach((el) => el.remove());
`;

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: "networkidle2", timeout: 60000 });
  await page.waitForSelector('input[type="email"]', { timeout: 30000 });
  await page.type('input[type="email"]', EMAIL, { delay: 15 });
  await page.type('input[type="password"]', PASSWORD, { delay: 15 });
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  // Inertia menyelesaikan redirect lewat XHR, jadi tunggu URL-nya berubah.
  await page.waitForFunction(() => !location.pathname.includes("/login"), {
    timeout: 45000,
  });
  console.log("Login berhasil sebagai", EMAIL);
}

async function capture(page, shot) {
  await page.setViewport(shot.viewport);
  await page.goto(`${BASE}${shot.url}`, {
    waitUntil: "networkidle2",
    timeout: 60000,
  });
  await new Promise((r) => setTimeout(r, shot.wait));
  await page.evaluate(HIDE_DEV_OVERLAYS);
  // Animasi masuk (fade/slide) perlu diselesaikan dulu agar tidak tertangkap setengah jalan.
  await new Promise((r) => setTimeout(r, 600));

  const file = path.join(OUT, `${shot.name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log(
    `  ${shot.name}.png  (${shot.viewport.width}x${shot.viewport.height} @${shot.viewport.deviceScaleFactor}x)`,
  );
}

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: "new",
  acceptInsecureCerts: true,
  args: ["--hide-scrollbars", "--force-color-profile=srgb"],
});

try {
  await mkdir(OUT, { recursive: true });
  const page = await browser.newPage();
  page.setDefaultTimeout(60000);

  await login(page);

  console.log("Mengambil screenshot:");
  for (const shot of SHOTS) {
    try {
      await capture(page, shot);
    } catch (err) {
      console.warn(`  ${shot.name} GAGAL: ${err.message}`);
    }
  }

  const files = await readdir(OUT);
  console.log(`\nSelesai. ${files.length} berkas di public/shots/`);
} finally {
  await browser.close();
}
