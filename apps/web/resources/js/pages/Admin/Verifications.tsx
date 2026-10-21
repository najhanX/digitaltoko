import { useForm } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Eye } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import { date } from '../../components/StoreJourney';
import { FilterBar, Pagination, useServerList, type PageMeta, type ListFilters } from '../../components/ListControls';

type Owner = {id:number;name:string;legal_name:string;birth_place:string|null;birth_date:string|null;phone:string;email:string;address:string;verification_status:string;rejection_reason:string|null;has_document:boolean;submitted_at:string|null;submission_reference:string};
const statuses = {pending:'Sedang diperiksa',approved:'Disetujui',rejected:'Ditolak'};
function ReviewModal({owner,onClose}:{owner:Owner;onClose:()=>void}) {
    const formId = useId();
    const form = useForm({decision:'approved',reason:'',reference:owner.submission_reference});
    const pending = owner.verification_status === 'pending';
    return <Modal open title={pending?'Periksa verifikasi owner':'Detail verifikasi owner'} description={owner.legal_name || owner.name} wide busy={form.processing} onClose={onClose}
        footer={<><button data-modal-focus type="button" className="button secondary" disabled={form.processing} onClick={onClose}>Tutup</button>{pending && <button type="submit" form={formId} className="button" disabled={form.processing}>{form.processing?'Menyimpan…':'Simpan hasil pemeriksaan'}</button>}</>}>
        <dl className="identity-readonly admin-identity-details"><dt>Nama lengkap</dt><dd>{owner.legal_name || '—'}</dd><dt>Nama akun</dt><dd>{owner.name}</dd><dt>WhatsApp</dt><dd>{owner.phone || '—'}</dd><dt>Email</dt><dd>{owner.email || '—'}</dd><dt>Tempat lahir</dt><dd>{owner.birth_place || '—'}</dd><dt>Tanggal lahir</dt><dd>{owner.birth_date || '—'}</dd><dt>Alamat</dt><dd>{owner.address || '—'}</dd><dt>Diajukan</dt><dd>{owner.submitted_at?date(owner.submitted_at):'—'}</dd></dl>
        {owner.has_document && <a className="button secondary small" href={'/identity/'+owner.id+'/document?reference='+owner.submission_reference} target="_blank" rel="noreferrer">Lihat KTP privat</a>}
        {owner.rejection_reason && <div className="feedback warning"><strong>Alasan penolakan</strong><p>{owner.rejection_reason}</p></div>}
        {pending && <form className="form-stack admin-verification-form" id={formId} onSubmit={event=>{event.preventDefault();if(!form.processing)form.post('/admin/verifications/'+owner.id,{preserveScroll:true,onSuccess:onClose});}}>
            <label>Keputusan pemeriksaan<select aria-label="Keputusan pemeriksaan" value={form.data.decision} onChange={event=>form.setData('decision',event.target.value)} disabled={form.processing}><option value="approved">Setujui KTP</option><option value="rejected">Tolak KTP</option></select></label>
            {form.data.decision === 'rejected' && <label>Alasan penolakan<textarea value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)} minLength={5} maxLength={1000} required disabled={form.processing}/></label>}
            {Object.values(form.errors).map(message=><p className="form-error" role="alert" key={message}>{message}</p>)}
        </form>}
    </Modal>;
}
export default function Verifications({owners,filters}:{owners:PageMeta & {data:Owner[]};filters:ListFilters}) {
    const list = useServerList(filters,['owners','filters']);
    const [selectedId,setSelectedId] = useState<number|null>(null);
    const selected = owners.data.find(owner=>owner.id === selectedId);
    return <WorkspaceLayout role="admin" title="Verifikasi owner" description="Pemeriksaan KTP dilakukan manual. Persetujuan berlaku untuk semua toko owner.">
        <section className="table-card data-list-table catalog-data-card"><header className="data-table-header"><h2>Pengajuan owner</h2><Pagination compact meta={owners} onPage={list.go} busy={list.busy} label="Navigasi atas pengajuan"/></header>
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari nama akun, WhatsApp, atau email…" choices={[{key:'status',label:'Status KTP',options:statuses}]}/>
            <div className="table-scroll"><table><thead><tr><th scope="col">Owner</th><th scope="col">Kontak</th><th scope="col">Status KTP</th><th scope="col">Diajukan</th><th scope="col" className="action-cell">Tindakan</th></tr></thead>
                <tbody>{owners.data.map(owner=><tr key={owner.id}><td><strong>{owner.legal_name || owner.name}</strong><small>{owner.name}</small></td><td className="table-cell-center"><span>{owner.phone || '—'}</span><small>{owner.email || '—'}</small></td><td className="table-cell-center"><span className={'store-status '+(owner.verification_status === 'approved'?'active':owner.verification_status === 'rejected'?'expired':'pending')}>{statuses[owner.verification_status as keyof typeof statuses] ?? owner.verification_status}</span></td><td className="table-cell-center">{owner.submitted_at?date(owner.submitted_at):'—'}</td><td className="table-cell-center"><div className="row-actions"><button type="button" className="button secondary small" aria-label={'Detail verifikasi '+owner.name} onClick={()=>setSelectedId(owner.id)}><Eye size={14}/>{owner.verification_status === 'pending'?'Periksa':'Detail'}</button></div></td></tr>)}</tbody>
            </table></div>
            {!owners.data.length && <div className="empty-state"><h3>Tidak ada pengajuan yang ditampilkan</h3><p>Ubah atau reset filter untuk memeriksa pengajuan lainnya.</p></div>}
            <Pagination meta={owners} onPage={list.go} busy={list.busy} label="Halaman pengajuan"/>
        </section>
        {selected && <ReviewModal key={selected.id+':'+selected.submission_reference} owner={selected} onClose={()=>setSelectedId(null)}/>}
    </WorkspaceLayout>;
}
