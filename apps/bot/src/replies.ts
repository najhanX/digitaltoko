export const inline = (value:string) => value.replace(/[\r\n*_~`]/g, ' ').replace(/\s+/g, ' ').trim().slice(0,150);
export const divider = '┊┈┈┈┈┈┈┈┈┈┈┈┈┈┈';
export function shopReply(storeName:string,title:string,lines:readonly string[]):string {
    return [`╭────〔 *${inline(storeName) || 'Toko Digital'}* 〕`, `┊ *${title}*`, divider,
        ...lines.map(line=>line ? `┊ ${line}` : '┊'), '╰┈┈┈┈┈┈┈┈┈┈┈┈┈┈', '_Powered by DigitalToko_'].join('\n');
}
export function menuText(storeName:string):string {
    return shopReply(storeName,'MENU UTAMA',[
        'Selamat datang! Silakan pilih menu toko.', '',
        '*🛍️ KATALOG PRODUK*', 'Format : #list', 'Ket : Layanan → kategori utama → produk.', 'Cari langsung : #produk canva', '',
        '*💳 SALDO PELANGGAN*', 'Format : #saldo', 'Ket : Informasi fitur saldo toko.', '',
        '*🧾 STATUS PESANAN*', 'Format : #status', 'Ket : Informasi pengecekan pesanan.', '',
        'Klik tombol di bawah atau ketik perintahnya.', 'Kembali ke menu : #menu',
    ]);
}
export function featureText(storeName:string,action:'balance'|'status'):string {
    return action==='balance'
        ? shopReply(storeName,'SALDO PELANGGAN',[
            'Fitur saldo pelanggan belum diaktifkan.', '',
            'Saldo pelanggan nantinya berlaku khusus', 'untuk transaksi di toko ini.',
            'Hubungi admin toko jika perlu bantuan.', '', 'Lihat katalog : #produk', 'Menu utama : #menu'])
        : shopReply(storeName,'STATUS PESANAN',[
            'Pengecekan pesanan belum diaktifkan.', '',
            'Jika Anda sudah bertransaksi dengan toko,', 'hubungi admin dan sertakan bukti pesanan.', '',
            'Lihat katalog : #produk', 'Menu utama : #menu']);
}
export function expiredMenuText(storeName:string):string {
    return shopReply(storeName,'MENU PERLU DIPERBARUI',[
        'Tombol sudah dipakai atau tidak berlaku lagi.', 'Silakan buka menu baru melalui tombol',
        'di bawah atau ketik #menu.', '', 'Perintah ketik tetap dapat digunakan.']);
}
export function catalogErrorText(storeName:string):string {
    return shopReply(storeName,'KATALOG BELUM TERSEDIA',[
        'Katalog sedang belum dapat dimuat.', 'Silakan coba kembali beberapa saat lagi.', '',
        'Coba lagi : #produk', 'Menu utama : #menu']);
}
