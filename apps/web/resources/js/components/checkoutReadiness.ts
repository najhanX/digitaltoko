type CheckoutState={product:{available:boolean;kind:string}|null;phone:string;destination:string;premium:boolean;columns:number;server:string;requiresGameVerification:boolean;gameVerification:string;hasInquiry:boolean;payment:string;gateway:string|null;loggedIn:boolean};

export function checkoutBlockers(state:CheckoutState):string[] {
    const reasons:string[]=[];
    if(!state.product)reasons.push('Pilih produk atau nominal terlebih dahulu.');
    else if(!state.product.available)reasons.push('Produk sedang tidak tersedia. Pilih nominal lainnya.');
    if(state.payment==='gateway' && !state.gateway)reasons.push(state.loggedIn?'Pembayaran QRIS belum tersedia. Pilih saldo pelanggan atau hubungi CS toko.':'Toko belum mengaktifkan pembayaran QRIS. Hubungi CS toko, atau masuk jika sudah memiliki saldo pelanggan.');
    if(state.payment==='wallet' && !state.loggedIn)reasons.push('Masuk ke akun pelanggan untuk membayar dengan saldo.');
    if(!state.phone.trim())reasons.push('Isi nomor WhatsApp penerima bukti.');
    if(!state.destination.trim())reasons.push(state.premium?'Isi nomor WhatsApp untuk tujuan pesanan.':'Lengkapi data tujuan pesanan.');
    if(!state.premium && state.columns===2 && !state.requiresGameVerification && !state.server.trim())reasons.push('Lengkapi zona / server tujuan.');
    if(state.requiresGameVerification && !state.gameVerification)reasons.push('Tunggu hasil pengecekan nickname, atau periksa kembali ID dan server.');
    if(state.product?.kind==='postpaid' && !state.hasInquiry)reasons.push('Periksa tagihan sebelum melanjutkan pesanan.');
    return reasons;
}
