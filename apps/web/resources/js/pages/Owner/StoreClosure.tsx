import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, Check, CircleCheck, ClipboardList, LockKeyhole, Store as StoreIcon, Wallet } from 'lucide-react';
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
        <div className="closure-page">
            <section className="card onboarding-card closure-overview">
                <div className="closure-status-icon">{finalized?<LockKeyhole size={23}/>:<StoreIcon size={23}/>}</div>
                <div className="closure-overview-copy"><div className="closure-heading-line"><h2>{finalized?'Toko telah ditutup':closing?'Proses penutupan berjalan':'Persiapan penutupan toko'}</h2><span className={'store-status '+(finalized?'expired':closing?'grace':'active')}>{finalized?'Ditutup':closing?'Dalam penyelesaian':'Belum dimulai'}</span></div><p>{finalized?'Layanan '+store.name+' telah dihentikan.':closing?'Pesanan dan topup baru dihentikan. Invoice yang sudah terbit tetap diperiksa dan transaksi berjalan tetap diselesaikan.':'Hentikan transaksi baru, tuntaskan kewajiban pelanggan, lalu konfirmasi penutupan '+store.name+'.'}</p></div>
            </section>
            <ol className="closure-steps" aria-label="Tahapan penutupan toko">{[
                {title:'Hentikan transaksi baru',description:'Mulai proses penutupan agar pesanan dan topup baru berhenti.',done:closing || finalized,current:!closing && !finalized},
                {title:'Selesaikan kewajiban',description:'Tuntaskan saldo, invoice, dan pesanan pelanggan.',done:finalized || closing && !blocked,current:closing && blocked && !finalized},
                {title:'Konfirmasi penutupan',description:'Tutup layanan setelah seluruh kewajiban selesai.',done:finalized,current:closing && !blocked && !finalized},
            ].map((step,index)=><li key={step.title} className={(step.done?'complete':'')+(step.current?' current':'')} aria-current={step.current?'step':undefined}><span className="closure-step-number">{step.done?<Check size={15}/>:index+1}</span><div><strong>{step.title}</strong><p>{step.description}</p></div></li>)}</ol>
            <section className="card onboarding-card closure-obligations">
                <header className="closure-section-heading"><div><h2>Kewajiban pelanggan</h2><p>Semua angka harus nol sebelum toko dapat ditutup.</p></div><span className={'store-status '+(blocked?'grace':'active')}>{blocked?'Perlu diselesaikan':'Sudah tuntas'}</span></header>
                <div className="table-scroll"><table><thead><tr><th>Kewajiban</th><th>Sisa</th><th>Kelola</th></tr></thead><tbody>
                    <tr><td><div className="closure-obligation-title"><Wallet size={18}/><div><strong>Saldo pelanggan</strong><small>Saldo pelanggan yang masih tersimpan di toko.</small></div></div></td><td className="table-cell-center"><span className={'closure-value'+(obligations.balance>0?' pending':'')}>{money(obligations.balance)}</span></td><td className="table-cell-center"><Link className="button secondary small" href={'/stores/'+store.id+'/customers'}>Kelola saldo<ArrowRight size={13}/></Link></td></tr>
                    <tr><td><div className="closure-obligation-title"><ClipboardList size={18}/><div><strong>Invoice & pesanan berjalan</strong><small>Invoice, transaksi pending, dan pesanan yang perlu diperiksa.</small></div></div></td><td className="table-cell-center"><span className={'closure-value'+(obligations.orders>0?' pending':'')}>{obligations.orders} pesanan</span></td><td className="table-cell-center"><Link className="button secondary small" href={'/stores/'+store.id+'/orders'}>Lihat transaksi<ArrowRight size={13}/></Link></td></tr>
                    <tr><td><div className="closure-obligation-title"><CircleCheck size={18}/><div><strong>Penyelesaian pesanan guest</strong><small>Pesanan guest sudah dibayar, tetapi gagal dan belum ditindaklanjuti.</small></div></div></td><td className="table-cell-center"><span className={'closure-value'+(obligations.guests>0?' pending':'')}>{obligations.guests} pesanan</span></td><td className="table-cell-center"><Link className="button secondary small" href={'/stores/'+store.id+'/orders'}>Tindak lanjuti<ArrowRight size={13}/></Link></td></tr>
                </tbody></table></div>
                <p className="closure-footnote">Kondisi saldo dan pesanan akan diperiksa kembali oleh sistem saat penutupan dikonfirmasi.</p>
            </section>
            {!finalized && <section className={'card onboarding-card closure-next-step'+(closing && !blocked?' ready':'')}>
                <div><span className="closure-next-label">TAHAP BERIKUTNYA</span><h2>{!closing?'Mulai proses penutupan':blocked?'Selesaikan kewajiban terlebih dahulu':'Toko siap ditutup'}</h2><p>{!closing?'Pelanggan yang memiliki saldo akan menerima notifikasi untuk menghubungi toko.':blocked?'Gunakan tombol Kelola di atas. Penutupan final tersedia setelah semua angka menjadi nol.':'Seluruh kewajiban sudah tuntas. Anda dapat mengonfirmasi penutupan layanan toko.'}</p></div>
                <div className="closure-actions">{closing?<><button type="button" className="button secondary" disabled={form.processing} onClick={()=>choose('resume')}>Batalkan penutupan</button><button type="button" className="button danger" disabled={blocked || form.processing} onClick={()=>choose('finalize')}><LockKeyhole size={15}/>Tutup toko secara final</button></>:<button type="button" className="button danger" disabled={form.processing} onClick={()=>choose('stop')}>Mulai proses penutupan<ArrowRight size={15}/></button>}</div>
            </section>}
        </div>
        <ConfirmDialog open={action!==null} title={action==='finalize'?'Tutup toko secara final?':action==='resume'?'Batalkan proses penutupan?':'Mulai proses penutupan?'} confirmLabel={action==='finalize'?'Ya, tutup toko':action==='resume'?'Ya, batalkan penutupan':'Hentikan transaksi baru'} busy={form.processing} danger={action!=='resume'} onCancel={()=>setAction(null)} onConfirm={()=>form.post('/stores/'+store.id+'/closure',{preserveScroll:true,onSuccess:()=>setAction(null)})}>
            <p>{action==='finalize'?'Layanan toko akan dihentikan setelah sistem memastikan tidak ada kewajiban yang tersisa.':action==='resume'?'Pesanan dan topup baru dapat diterima kembali selama langganan toko masih berlaku.':'Pesanan dan topup baru dihentikan. Pelanggan yang masih memiliki saldo akan menerima notifikasi untuk menghubungi toko. Transaksi yang sudah berjalan tetap diproses.'}</p>
            <FormErrors errors={form.errors}/>
        </ConfirmDialog>
    </WorkspaceLayout>;
}
