/**
 * Perkecil foto dari kamera sebelum diunggah. Foto tablet/HP bisa 3–8 MB; untuk bukti
 * cukup sisi terpanjang ~1600px berformat JPEG, biasanya di bawah 400 KB.
 * Bila browser tidak bisa memprosesnya, berkas asli dikembalikan apa adanya.
 */
export async function downscaleImage(file: File, maxSide = 1600, quality = 0.8): Promise<File> {
    if (!file.type.startsWith('image/')) return file;

    try {
        const bitmap = await loadImage(file);
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(bitmap, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob || blob.size >= file.size) return file;

        const name = file.name.replace(/\.[^.]+$/, '') || 'foto';
        return new File([blob], `${name}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
    } catch {
        return file;
    }
}

function loadImage(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Gambar tidak bisa dibaca'));
        };
        img.src = url;
    });
}
