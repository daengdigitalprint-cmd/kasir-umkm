/**
 * Warna utama halaman pelanggan (menu digital & pesan antar) pilihan toko.
 *
 * Halaman itu ditulis dengan kelas ungu Tailwind (`bg-violet-600`, `text-violet-700`, …), dan Tailwind v4
 * menerjemahkannya ke variabel CSS `--color-violet-*`. Jadi cukup menimpa variabel itu pada elemen
 * pembungkus halaman: semua yang ungu di dalamnya ikut berganti warna tanpa mengubah markup.
 */

type Rgb = [number, number, number];

const PUTIH: Rgb = [255, 255, 255];
const HITAM: Rgb = [0, 0, 0];

/** Warna bawaan (ungu Tailwind 600). Dipakai sebagai nilai awal pemilih warna. */
export const DEFAULT_ACCENT = '#7c3aed';

export const ACCENT_PRESETS: { name: string; hex: string }[] = [
    { name: 'Ungu', hex: DEFAULT_ACCENT },
    { name: 'Biru', hex: '#2563eb' },
    { name: 'Toska', hex: '#0f766e' },
    { name: 'Hijau', hex: '#047857' },
    { name: 'Oranye', hex: '#c2410c' },
    { name: 'Merah', hex: '#dc2626' },
    { name: 'Merah muda', hex: '#be185d' },
    { name: 'Cokelat', hex: '#92400e' },
    { name: 'Hitam', hex: '#1f2937' },
];

export function isHexColor(value: unknown): value is string {
    return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value);
}

function toRgb(hex: string): Rgb {
    return [parseInt(hex.slice(1, 3), 16), parseInt(hex.slice(3, 5), 16), parseInt(hex.slice(5, 7), 16)];
}

function toHex([r, g, b]: Rgb): string {
    return '#' + [r, g, b].map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('');
}

/** Campur `a` dengan `b`; `t` = porsi `b` (0–1). */
function mix(a: Rgb, b: Rgb, t: number): Rgb {
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t];
}

function luminance([r, g, b]: Rgb): number {
    const lin = (v: number) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

/** Kontras terhadap tulisan putih (WCAG). 4,5 ke atas = terbaca. */
function contrastWithWhite(rgb: Rgb): number {
    return 1.05 / (luminance(rgb) + 0.05);
}

/**
 * Warna tombol: warna pilihan toko, digelapkan seperlunya sampai tulisan putih di atasnya terbaca.
 * Kuning atau hijau muda, misalnya, otomatis menjadi lebih tua.
 */
export function buttonColor(hex: string): string {
    let rgb = toRgb(hex);
    for (let i = 0; i < 40 && contrastWithWhite(rgb) < 4.5; i++) {
        rgb = mix(rgb, HITAM, 0.05);
    }
    return toHex(rgb);
}

/** true bila warna pilihan terlalu terang sehingga tombolnya digelapkan otomatis. */
export function isAdjusted(hex: string): boolean {
    return isHexColor(hex) && buttonColor(hex).toLowerCase() !== hex.toLowerCase();
}

/**
 * Variabel CSS untuk dipasang sebagai `:style` pada pembungkus halaman.
 * Tanpa warna (atau warna tidak sah) hasilnya kosong, sehingga ungu bawaan dipakai.
 */
export function accentStyle(hex: string | null | undefined): Record<string, string> {
    if (!isHexColor(hex)) {
        return {};
    }
    const utama = toRgb(buttonColor(hex));
    const terang = (t: number) => toHex(mix(utama, PUTIH, t));
    const gelap = (t: number) => toHex(mix(utama, HITAM, t));

    return {
        '--color-violet-50': terang(0.94),
        '--color-violet-100': terang(0.88),
        '--color-violet-200': terang(0.76),
        '--color-violet-300': terang(0.58),
        '--color-violet-400': terang(0.36),
        '--color-violet-500': terang(0.16),
        '--color-violet-600': toHex(utama),
        '--color-violet-700': gelap(0.14),
        '--color-violet-800': gelap(0.28),
        '--color-violet-900': gelap(0.42),
    };
}
