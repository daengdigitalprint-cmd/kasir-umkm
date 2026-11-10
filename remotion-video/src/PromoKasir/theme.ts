/** Warna diambil dari resources/css/app.css milik aplikasi. */
export const COLORS = {
  primary: "#7943e5",
  primaryDeep: "#3b1d7a",
  primarySoft: "#ede9fe",
  accent: "#a0ea71",
  accentInk: "#1a3a0a",
  ink: "#0f172a",
  muted: "#64748b",
  surface: "#f8fafc",
  white: "#ffffff",
};

export const FONT =
  '"Plus Jakarta Sans", "Segoe UI", system-ui, -apple-system, sans-serif';

export const FPS = 30;

/**
 * 1800 frame = 60 detik. Tiap adegan dijumlahkan persis ke angka itu.
 * Panjangnya mengikuti durasi narasi di public/vo/ supaya tidak ada
 * kalimat yang terpotong saat adegan berganti.
 */
export const SCENES = {
  hook: 180, // narasi 5,6d
  pos: 360, // narasi 11,8d
  stock: 300, // narasi 8,8d
  dashboard: 315, // narasi 9,8d
  reports: 330, // narasi 10,4d
  outro: 315, // narasi 9,6d
} as const;

export const TOTAL_FRAMES = Object.values(SCENES).reduce((a, b) => a + b, 0);
