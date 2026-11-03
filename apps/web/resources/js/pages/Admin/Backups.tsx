import { Link, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Archive, Download, History, Info, LoaderCircle, RotateCcw, Upload } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';

type Item = {id:string;name:string;bytes?:number;created_at:string;state?:string;database?:string;directory?:string;message?:string};
type Props = {items:Item[];pagination:PageMeta;filters:ListFilters;tab:string;restoreBusy:boolean;uploadLimitMb:number;retention:number;backup:{busy:boolean;configured:boolean;message:string}};
const partials=['items','pagination','filters','tab','backup','restoreBusy'];
const states:Record<string,string>={queued:'Dalam antrean',processing:'Memulihkan…',completed:'Selesai',failed:'Perlu diperiksa'};
const time=(value:string)=>new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(value))+' WIB';
export default function Backups({items,pagination,filters,tab,backup,restoreBusy,uploadLimitMb,retention}:Props){
    const list=useServerList(filters,partials);
    const [backupOpen,setBackupOpen]=useState(false);
    const [restoreOpen,setRestoreOpen]=useState(false);
    const [detail,setDetail]=useState<Item|null>(null);
    const selectedDetail=items.find(item=>item.id===detail?.id)??detail;
    const backupForm=useForm({backup:''});
    const restore=useForm<{snapshot:string;file:File|null;confirmation:string;backup:string}>({snapshot:'',file:null,confirmation:'',backup:''});
    const {start,stop}=usePoll(3000,{only:partials},{autoStart:false,mode:'rest',background:'pause'});
    useEffect(()=>{if(backup.busy||restoreBusy)start();else stop();return stop;},[backup.busy,restoreBusy,start,stop]);
    function openRestore(snapshot=''){restore.reset();restore.clearErrors();restore.setData('snapshot',snapshot);setRestoreOpen(true);}
    const submitRestore=()=>{restore.transform(data=>({...data,snapshot:data.snapshot||null}));restore.post('/admin/backups/restore',{forceFormData:true,preserveScroll:true,onSuccess:()=>{setRestoreOpen(false);restore.reset();}});};
    return <WorkspaceLayout role="admin" title="Backup & pemulihan" description="Simpan snapshot dan pulihkan cadangan melalui satu tempat." actions={<><button className="button secondary" disabled={!backup.configured||restoreBusy} onClick={()=>openRestore()}><Upload size={17}/>Unggah & pulihkan</button><button className="button" disabled={!backup.configured||backup.busy} onClick={()=>{backupForm.clearErrors();setBackupOpen(true);}}><Archive size={17}/>{backup.busy?'Membuat backup…':'Backup sekarang'}</button></>}>
        <div className="backup-summary" role="status"><Archive size={20}/><div><strong>{backup.busy?'Backup sedang diproses':backup.configured?'Cadangan terenkripsi':'Konfigurasi backup belum siap'}</strong><p>{backup.message}</p></div>{(backup.busy||restoreBusy)&&<LoaderCircle size={18} className="is-refreshing"/>}</div>
        <section className="panel-card backup-history">
            <nav className="panel-tabs" aria-label="Riwayat backup"><Link className={tab==='snapshots'?'active':''} href="/admin/backups" preserveScroll><Archive size={17}/>Snapshot</Link><Link className={tab==='restores'?'active':''} href="/admin/backups?tab=restores" preserveScroll><History size={17}/>Riwayat pemulihan</Link></nav>
            <header className="backup-list-heading"><h2>{tab==='snapshots'?'Snapshot tersimpan':'Proses pemulihan'}</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy}/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder={tab==='snapshots'?'Cari nama snapshot…':'Cari nama arsip atau ID pemulihan…'}/>
            <div className="table-scroll"><table className="backup-table"><thead><tr><th>{tab==='snapshots'?'Snapshot':'Arsip sumber'}</th><th>Waktu</th><th>{tab==='snapshots'?'Ukuran':'Status'}</th><th>Tindakan</th></tr></thead><tbody>{items.map(item=><tr key={item.id}><td><strong>{item.name}</strong>{tab==='restores'&&<small>{item.id}</small>}</td><td className="table-cell-center">{time(item.created_at)}</td><td className="table-cell-center">{tab==='snapshots'?`${((item.bytes??0)/1048576).toLocaleString('id-ID',{maximumFractionDigits:1})} MB`:<span className="pill">{states[item.state??'']??item.state}</span>}</td><td className="table-cell-center"><div className="backup-row-actions">{tab==='snapshots'?<><a className="button secondary small" href={'/admin/backups/download/'+encodeURIComponent(item.name)}><Download size={15}/>Unduh</a><button className="button small" disabled={!backup.configured||restoreBusy} onClick={()=>openRestore(item.name)}><RotateCcw size={15}/>Pulihkan</button></>:<button className="button secondary small" onClick={()=>setDetail(item)}><Info size={15}/>Rincian</button>}</div></td></tr>)}</tbody></table></div>
            {!items.length&&<div className="empty-state"><Archive size={28}/><h3>{tab==='snapshots'?'Belum ada snapshot yang cocok':'Belum ada pemulihan yang cocok'}</h3><p>{tab==='snapshots'?'Buat backup untuk menyimpan cadangan pertama.':'Pilih snapshot atau unggah arsip ZIP yang pernah diunduh.'}</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
            <p className="field-help">{tab==='snapshots'?`${retention} snapshot terbaru disimpan di server. Unduh untuk menyimpan cadangan lebih lama. Arsip memerlukan kunci backup yang sama saat dipulihkan.`:'Pemulihan membuat database dan folder terpisah. Toko aktif tetap berjalan; pengalihan ke hasil pemulihan dilakukan operator setelah ditinjau.'}</p>
        </section>
        <Modal open={backupOpen} title="Buat snapshot backup?" description="Database, media dan sesi WhatsApp DigitalToko." busy={backupForm.processing} onClose={()=>setBackupOpen(false)} footer={<><button className="button secondary" disabled={backupForm.processing} onClick={()=>setBackupOpen(false)}>Batal</button><button className="button" disabled={backupForm.processing||backup.busy} onClick={()=>backupForm.post('/admin/system-health/backup',{preserveScroll:true,onSuccess:()=>setBackupOpen(false)})}><Archive size={16}/>Mulai backup</button></>}><p>Proses berjalan di background. Snapshot akan muncul di daftar setelah selesai diverifikasi.</p>{backupForm.errors.backup&&<p role="alert" className="health-alert-text">{backupForm.errors.backup}</p>}</Modal>
        <Modal open={restoreOpen} title={restore.data.snapshot?'Pulihkan snapshot?':'Unggah backup & pulihkan'} description="Pulihkan ke database dan folder baru yang terpisah dari toko aktif." busy={restore.processing} onClose={()=>setRestoreOpen(false)} footer={<><button className="button secondary" disabled={restore.processing} onClick={()=>setRestoreOpen(false)}>Batal</button><button className="button" disabled={restore.processing||restoreBusy||restore.data.confirmation!=='PULIHKAN'||(!restore.data.snapshot&&!restore.data.file)} onClick={submitRestore}><RotateCcw size={16}/>{restore.processing?'Mengunggah…':'Mulai pemulihan'}</button></>}>
            {restore.data.snapshot?<p className="backup-filename">{restore.data.snapshot}</p>:<label className="field"><span>Arsip backup ZIP</span><input type="file" accept=".zip,application/zip" onChange={event=>restore.setData('file',event.target.files?.[0]??null)}/><small>Maksimal {uploadLimitMb} MB. Gunakan arsip asli DigitalToko dengan kunci backup yang sama.</small></label>}
            <p className="field-help">Arsip diperiksa integritas dan enkripsinya sebelum dipulihkan. Proses ini tidak mengaktifkan bot, pembayaran atau antrean dari cadangan.</p>
            <label className="field"><span>Ketik PULIHKAN untuk konfirmasi</span><input autoComplete="off" value={restore.data.confirmation} onChange={event=>restore.setData('confirmation',event.target.value)}/></label>
            {restore.progress&&<p role="status">Mengunggah {restore.progress.percentage}%</p>}{Object.entries(restore.errors).map(([key,value])=><p key={key} className="health-alert-text" role="alert">{value}</p>)}
        </Modal>
        <Modal open={!!detail} title="Rincian pemulihan" onClose={()=>setDetail(null)} footer={<button className="button secondary" onClick={()=>setDetail(null)}>Tutup</button>}>
            {selectedDetail&&<div className="backup-recovery-detail"><span className="pill">{states[selectedDetail.state??'']}</span><p>{selectedDetail.message}</p><dl><dt>ID pemulihan</dt><dd>{selectedDetail.id}</dd><dt>Database tujuan</dt><dd>{selectedDetail.database}</dd><dt>Folder hasil · di dalam BACKUP_DIRECTORY</dt><dd>{selectedDetail.directory}</dd></dl><p className="field-help">Operator meninjau database, media dan .env.recovery pada folder hasil sebelum pengalihan layanan. Jangan jalankan bot atau worker hasil pemulihan bersama layanan aktif.</p></div>}
        </Modal>
    </WorkspaceLayout>;
}
