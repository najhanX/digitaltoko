export const inline = (value:string) => value.replace(/[\r\n*_~`]/g, ' ').replace(/\s+/g, ' ').trim().slice(0,150);
export function shopReply(storeName:string,title:string,lines:readonly string[]):string {
    const heading=inline(title).replace(/^[^\p{L}\p{N}]+/u,'');
    return [`*${(inline(storeName) || 'Toko Digital').toUpperCase()}*`,heading,'',...lines,'','_Powered by DigitalToko_'].join('\n');
}
export function menuText(storeName:string):string {
    return shopReply(storeName,'MENU UTAMA',[
        'Selamat datang. Pilih menu di bawah.', '',
        'Produk · Format : #list', 'Saldo · #saldo', 'Pesanan · #status', '',
        'Cari produk : #produk canva',
    ]);
}
export function expiredMenuText(storeName:string):string {
    return shopReply(storeName,'MENU PERLU DIPERBARUI',[
        'Pilihan ini sudah dipakai atau tidak berlaku lagi.', 'Buka menu baru atau ketik #menu.']);
}
export function catalogErrorText(storeName:string):string {
    return shopReply(storeName,'KATALOG BELUM TERSEDIA',[
        'Silakan coba kembali beberapa saat lagi.', 'Coba lagi : #produk']);
}
export function topupText(storeName:string):string {
    return shopReply(storeName,'ISI SALDO TOKO',[
        'Ketik nominal, contoh: #topup 25000', 'Minimal Rp5.000.', '',
        'Saldo masuk setelah pembayaran terverifikasi.', 'Biaya atau kode unik tertera di tagihan.',
    ]);
}

export function unknownCommandText(storeName:string):string {
    return shopReply(storeName,'PERINTAH TIDAK DIKENAL',[
        'Periksa kembali penulisan perintah Anda.',
        'Ketik #menu atau pilih tombol di bawah untuk melihat menu toko.',
    ]);
}
