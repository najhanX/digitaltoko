export async function readQrisImage(file: File): Promise<string> {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        throw new Error('Gunakan gambar PNG, JPG atau WebP maksimal 5 MB.');
    }
    const url = URL.createObjectURL(file);
    try {
        const image = new Image();
        image.src = url;
        await image.decode();
        if (!image.naturalWidth || !image.naturalHeight || image.naturalWidth * image.naturalHeight > 40_000_000) {
            throw new Error('Ukuran gambar terlalu besar. Gunakan gambar QRIS yang lebih kecil.');
        }
        const scale = Math.min(1, 2048 / Math.max(image.naturalWidth, image.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d', {willReadFrequently: true});
        if (!context) throw new Error('Browser belum dapat membaca gambar.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
        const {default: jsQR} = await import('jsqr');
        const result = jsQR(pixels.data, pixels.width, pixels.height, {inversionAttempts: 'attemptBoth'});
        if (!result || !result.data.startsWith('000201') || result.data.length > 4096) {
            throw new Error('QRIS tidak terbaca. Pilih gambar yang jelas dan pastikan kode QR terlihat utuh.');
        }
        return result.data;
    } catch (error) {
        if (error instanceof Error && error.name === 'EncodingError') {
            throw new Error('Gambar tidak dapat dibuka. Pilih PNG atau JPG yang valid.');
        }
        throw error;
    } finally {
        URL.revokeObjectURL(url);
    }
}
