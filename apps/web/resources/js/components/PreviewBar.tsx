import { Link } from '@inertiajs/react';

export default function PreviewBar() {
    return <div className="preview-bar"><strong>Pratinjau lokal</strong><span>Data contoh. Transaksi belum diaktifkan.</span><nav>{['admin','owner','staff','storefront'].map(role=><Link key={role} href={`/preview/${role}`}>{role}</Link>)}</nav></div>;
}
