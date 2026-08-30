/**
 * Encode teks struk menjadi byte ESC/POS dasar (thermal).
 * Asumsi printer menerima UTF-8 teks; hindari simbol di luar BMP jika printer lawas.
 *
 * Elemen bertipe Uint8Array dikirim apa adanya (raw), dipakai untuk perintah
 * gambar raster seperti logo. Elemen bertipe string dikirim sebagai satu baris teks.
 */
export type EscPosLine = string | Uint8Array;

export function receiptLinesToEscPos(lines: EscPosLine[]): Uint8Array {
    const out: number[] = [0x1b, 0x40]; // ESC @ — init
    const enc = new TextEncoder();
    for (const line of lines) {
        if (line instanceof Uint8Array) {
            out.push(...line);
            continue;
        }
        out.push(...enc.encode(line), 0x0a);
    }
    // Feed + potong kertas (umum di printer ESC/POS; sesuaikan manual jika printer tidak mendukung)
    out.push(0x0a, 0x0a, 0x1d, 0x56, 0x01);
    return new Uint8Array(out);
}

/** Lebar cetak efektif dalam dot untuk kertas thermal umum. */
export function printableDotsFor(paperWidthMm: 58 | 80): number {
    return paperWidthMm === 58 ? 384 : 576;
}

/** ESC 3 n — atur jarak antar baris (satuan dot vertikal; default printer ±30). */
export function escPosLineSpacing(dots: number): Uint8Array {
    return new Uint8Array([0x1b, 0x33, Math.max(0, Math.min(255, Math.round(dots)))]);
}

/** ESC 2 — kembalikan jarak antar baris ke bawaan printer. */
export function escPosDefaultLineSpacing(): Uint8Array {
    return new Uint8Array([0x1b, 0x32]);
}

function loadImage(url: string): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const img = new Image();
        // Cegah canvas tainted bila logo dilayani dari origin lain
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = url;
    });
}

/**
 * Ubah gambar (logo) menjadi perintah raster ESC/POS `GS v 0`.
 * Gambar diperkecil agar muat lebar cetak, lalu di-threshold jadi hitam-putih
 * karena printer thermal hanya mengenal dot menyala / mati.
 *
 * Mengembalikan null bila gambar gagal dimuat atau canvas tidak bisa dibaca.
 */
export async function imageUrlToEscPosRaster(
    url: string,
    maxWidthDots: number,
    maxHeightDots = 160,
): Promise<Uint8Array | null> {
    if (typeof document === 'undefined') {
        return null;
    }
    const img = await loadImage(url);
    if (!img || !img.naturalWidth || !img.naturalHeight) {
        return null;
    }

    const scale = Math.min(maxWidthDots / img.naturalWidth, maxHeightDots / img.naturalHeight, 1);
    // Lebar dibulatkan ke kelipatan 8 agar pas dengan batas byte pada baris raster
    const width = Math.max(8, Math.floor((img.naturalWidth * scale) / 8) * 8);
    const height = Math.max(1, Math.round(img.naturalHeight * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return null;
    }
    // Latar putih supaya area transparan tidak jadi blok hitam
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);

    let pixels: Uint8ClampedArray;
    try {
        pixels = ctx.getImageData(0, 0, width, height).data;
    } catch {
        // Canvas tainted (logo dari origin lain tanpa header CORS)
        return null;
    }

    const bytesPerRow = width / 8;
    const raster = new Uint8Array(bytesPerRow * height);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = (y * width + x) * 4;
            const alpha = pixels[i + 3];
            const lum =
                alpha < 128
                    ? 255
                    : 0.299 * pixels[i] + 0.587 * pixels[i + 1] + 0.114 * pixels[i + 2];
            if (lum < 160) {
                raster[y * bytesPerRow + (x >> 3)] |= 0x80 >> (x & 7);
            }
        }
    }

    const header = [
        0x1b, 0x61, 0x01, // ESC a 1 — rata tengah
        0x1d, 0x76, 0x30, 0x00, // GS v 0, mode normal
        bytesPerRow & 0xff,
        (bytesPerRow >> 8) & 0xff,
        height & 0xff,
        (height >> 8) & 0xff,
    ];
    const footer = [0x0a, 0x1b, 0x61, 0x00]; // feed + ESC a 0 (kembali rata kiri)

    const out = new Uint8Array(header.length + raster.length + footer.length);
    out.set(header, 0);
    out.set(raster, header.length);
    out.set(footer, header.length + raster.length);
    return out;
}
