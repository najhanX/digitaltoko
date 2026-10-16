import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { FormErrors, type CatalogStore } from '../../components/CatalogForms';
import { money } from '../../components/Commerce';

export default function StoreClosure({store, closing, obligations}: {store:CatalogStore; closing:boolean; obligations:{balance:number;orders:number;guests:number}}) {
    const [action, setAction] = useState<'stop'|'resume'|'finalize'|null>(null);
    const form = useForm({closing:true, finalize:false});
    const blocked = obligations.balance > 0 || obligations.orders > 0 || obligations.guests > 0;
    const finalized = store.status === 'suspended_manual';
    function choose(next:'stop'|'resume'|'finalize') {
        form.clearErrors();
        form.setData({closing:next!=='resume',finalize:next==='finalize'});
        setAction(next);
    }
    return <WorkspaceLayout role="owner" title="Penutupan toko" description={'Kelola penghentian layanan dan penyelesaian kewajiban '+store.name+'.'}>
        <section className="card onboarding-card">
            <h2>{finalized?'Toko sudah ditutup':closing?'Penyelesaian kewajiban pelanggan':'Persiapan penutupan toko'}</h2>
            <p>{finalized?'Layanan toko telah dihentikan.':closing?'Pesanan dan topup baru dihentikan. Invoice yang sudah terbit tetap diperiksa dan transaksi berjalan tetap diselesaikan.':'Mulai dengan menghentikan pesanan dan topup baru, lalu selesaikan seluruh saldo dan pesanan pelanggan sebelum menutup toko.'}</p>
            {!finalized && <div className="card-actions">
                <button type="button" className="button secondary" onClick={()=>choose(closing?'resume':'stop')}>{closing?'Batalkan proses penutupan':'Mulai proses penutupan'}</button>
                {closing && <button type="button" className="button danger" disabled={blocked} onClick={()=>choose('finalize')}>Tutup toko secara final</button>}
            </div>}
        </section>
        <section className="card onboarding-card">
            <div className="section-toolbar"><div><h2>Kewajiban yang perlu diselesaikan</h2><p>Penutupan final hanya dapat dilakukan setelah seluruh kewajiban selesai. Kondisi diperiksa kembali saat konfirmasi.</p></div></div>
            <div className="table-scroll"><table><thead><tr><th>Kewajiban</th><th>Sisa</th><th>Kelola</th></tr></thead><tbody>
                <tr><td>Saldo pelanggan</td><td>{money(obligations.balance)}</td><td><Link className="text-link" href={'/stores/'+store.id+'/customers'}>Pelanggan & saldo</Link></td></tr>
                <tr><td>Invoice / pesanan belum selesai</td><td>{obligations.orders}</td><td><Link className="text-link" href={'/stores/'+store.id+'/orders'}>Transaksi pelanggan</Link></td></tr>
                <tr><td>Pesanan guest gagal yang belum diselesaikan</td><td>{obligations.guests}</td><td><Link className="text-link" href={'/stores/'+store.id+'/orders'}>Penyelesaian pesanan</Link></td></tr>
            </tbody></table></div>
        </section>
        <ConfirmDialog open={action!==null} title={action==='finalize'?'Tutup toko secara final?':action==='resume'?'Batalkan proses penutupan?':'Mulai proses penutupan?'} confirmLabel={action==='finalize'?'Ya, tutup toko':action==='resume'?'Ya, batalkan penutupan':'Hentikan transaksi baru'} busy={form.processing} danger={action!=='resume'} onCancel={()=>setAction(null)} onConfirm={()=>form.post('/stores/'+store.id+'/closure',{preserveScroll:true,onSuccess:()=>setAction(null)})}>
            <p>{action==='finalize'?'Layanan toko akan dihentikan setelah sistem memastikan tidak ada kewajiban yang tersisa.':action==='resume'?'Pesanan dan topup baru dapat diterima kembali selama langganan toko masih berlaku.':'Pesanan dan topup baru dihentikan. Pelanggan yang masih memiliki saldo akan menerima notifikasi untuk menghubungi toko. Transaksi yang sudah berjalan tetap diproses.'}</p>
            <FormErrors errors={form.errors}/>
        </ConfirmDialog>
    </WorkspaceLayout>;
}
