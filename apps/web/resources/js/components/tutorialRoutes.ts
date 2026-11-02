export function tutorialForPath(path: string, tab?: string|null): string {
    if (path.startsWith('/admin')) return 'admin';
    if (tab === 'staff') return 'staff';
    const menu = path.split('/').filter(Boolean).at(-1) ?? '';
    return ({sources:'katalog',digiflazz:'digiflazz',hanshop:'hanshop',otomax:'otomax',tester:'otomax',payments:'midtrans',products:'katalog','catalog-structure':'katalog',stock:'stok',bot:'whatsapp',notifications:'notifikasi',articles:'artikel',vouchers:'voucher',subscription:'langganan',orders:'transaksi',customers:'pelanggan',closure:'penutupan',manage:'tampilan'} as Record<string,string>)[menu]
        ?? (path.includes('/otomax/')?'otomax':path.includes('/orders/')?'transaksi':path.includes('/customers/')?'pelanggan':'mulai');
}

