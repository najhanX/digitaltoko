import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Download, Plug, RefreshCw, Unplug, Package, Copy, Eye, EyeOff, KeyRound } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import CatalogSelect from '../../components/CatalogSelect';
import ConfirmDialog from '../../components/ConfirmDialog';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';
import { FormErrors, rupiah, sourceLabels, kindLabels, type CatalogStore } from '../../components/CatalogForms';
type Connection={id:string;kind:string;enabled:boolean;verified_at:string|null;synced_at:string|null;last_error:string|null;webhook_configured:boolean};
type Item={id:string;connection_id:string;sku:string;mode:string;name:string;kind:string;service:string;category:string;cost:number;available:boolean;quantity:number|null;observed_at:string};
type Props={role:'owner'|'staff';store:CatalogStore;connections:Connection[];features:string[];canManage:boolean;items:Item[];filters:ListFilters;pagination:PageMeta;digiflazzWebhookUrl:string};
export default function Sources({role,store,connections,features,canManage,items,filters,pagination,digiflazzWebhookUrl}:Props) {
    const [webhookConnection,setWebhookConnection]=useState<Connection|null>(null);
    const webhook=useForm({webhook_secret:''});
    const [showWebhookSecret,setShowWebhookSecret]=useState(false),[webhookCopyMessage,setWebhookCopyMessage]=useState('');
    function generateWebhookSecret(){const bytes=crypto.getRandomValues(new Uint8Array(32));webhook.setData('webhook_secret',Array.from(bytes,byte=>byte.toString(16).padStart(2,'0')).join(''));webhook.clearErrors('webhook_secret');setShowWebhookSecret(true);setWebhookCopyMessage('Secret baru dibuat. Salin ke kolom Secret ID di Digiflazz, lalu simpan secret di sini.');}
    async function copyWebhookSecret(){try{await navigator.clipboard.writeText(webhook.data.webhook_secret);setWebhookCopyMessage('Secret disalin. Tempel ke kolom Secret ID di Digiflazz.');}catch{setShowWebhookSecret(true);setWebhookCopyMessage('Salin secret secara manual dari kolom di atas.');}}
    const base='/stores/'+store.id;
    const list=useServerList(filters,['items','filters','pagination']);
    const [editing,setEditing]=useState<string|null>(null),[syncing,setSyncing]=useState<Connection|null>(null),[removing,setRemoving]=useState<Connection|null>(null),[importing,setImporting]=useState(false),[selected,setSelected]=useState<string[]>([]);
    const connection=useForm({kind:'digiflazz',username:'',api_key:'',enabled:true}),sync=useForm({mode:'prepaid'}),importer=useForm({ids:[] as string[]}),deletion=useForm({});
    const [importAll,setImportAll]=useState<Connection|null>(null),[allBusy,setAllBusy]=useState(false),[allError,setAllError]=useState(''),[allDone,setAllDone]=useState(false);
    const [allProgress,setAllProgress]=useState<{total:number;remaining:number;imported:number;through:string|null}|null>(null);
    const [importScope,setImportScope]=useState<'all'|'service'|'category'>('all'),[importService,setImportService]=useState<{id:string;name:string}|null>(null),[importCategory,setImportCategory]=useState<{id:string;name:string}|null>(null);
    const importReady=importScope==='all' || (!!importService && (importScope!=='category' || !!importCategory));
    function resetImportProgress(){setAllProgress(null);setAllError('');setAllDone(false);importer.clearErrors();}
    const allRequest=useRef<AbortController|null>(null);
    useEffect(()=>()=>allRequest.current?.abort(),[]);
    function openImportAll(current:Connection){setImportAll(current);resetImportProgress();setImportScope('all');setImportService(null);setImportCategory(null);}
    async function runImportAll(){
        if(!importAll || allBusy || importer.processing || !importReady)return;
        const controller=new AbortController();allRequest.current=controller;setAllBusy(true);setAllError('');
        let through=allProgress?.through ?? null,imported=allProgress?.imported ?? 0;
        try{
            while(!controller.signal.aborted){
                const cookie=document.cookie.split('; ').find(item=>item.startsWith('XSRF-TOKEN='));
                const response=await fetch(base+'/sources/'+importAll.id+'/import-all',{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''},body:JSON.stringify({through,scope:importScope,service:importService?.id,category:importCategory?.id})});
                if(!response.ok){
                    if(response.status===422)throw new Error('Layanan/kategori tidak ditemukan pada sumber ini. Pilih ulang dari katalog pemasok.');
                    if(response.status===403)throw new Error('Impor ditolak. Periksa izin kelola produk, masa aktif toko, paket, dan koneksi pemasok.');
                    if(response.status===419 || response.status===401)throw new Error('Sesi berakhir. Muat ulang halaman lalu mulai impor kembali. Produk yang sudah diparsing tidak diduplikasi.');
                    throw new Error('Impor terhenti. Produk yang sudah masuk tetap tersimpan; coba lanjutkan kembali.');
                }
                const result=await response.json() as {total:number;remaining:number;imported:number;through:string|null};
                through=result.through;imported+=result.imported;setAllProgress({...result,imported});
                if(result.remaining===0){setAllDone(true);setSelected([]);break;}
                if(result.imported===0)throw new Error('Impor tidak dapat dilanjutkan. Muat ulang halaman dan periksa sumber produk.');
            }
        }catch(error){if(!controller.signal.aborted)setAllError(error instanceof Error?error.message:'Koneksi terputus. Coba lanjutkan impor kembali.');}
        finally{if(!controller.signal.aborted)setAllBusy(false);}
    }
    const rowKey=items.map(item=>item.id).join(',');
    useEffect(()=>setSelected([]),[rowKey]);
    function edit(kind:string){connection.clearErrors();connection.setData({kind,username:'',api_key:'',enabled:true});setEditing(kind);}
    function close(){setEditing(null);connection.reset();connection.clearErrors();}
    return <WorkspaceLayout role={role} title="Sumber produk" description={'Hubungkan sumber '+store.name+', sinkronkan katalog, lalu impor pilihan atau seluruh produknya.'}
        actions={<Link className="button secondary" href={base+'/products'}><Package size={16}/>Katalog toko</Link>}>
        {store.sandbox && <div className="summary-note"><strong>Katalog pengujian Digiflazz</strong><p>Sinkronisasi menyediakan SKU uji resmi: xld10 (prabayar) dan pln (pascabayar). Saldo pemasok yang tampil adalah simulasi Rp1.000.000. Key development baru diverifikasi saat transaksi uji.</p><p>XL: tujuan 087800001230 berhasil, 087800001232 gagal. PLN: 530000000001 berhasil. Hanshop hanya membaca katalog; pemesanan disimulasikan.</p></div>}
        <div className="summary-note"><strong>1. Hubungkan sumber → 2. Sinkronkan → 3. Impor produk</strong><p>Sinkronisasi dan impor hanya menyiapkan katalog. Pembelian serta pemotongan saldo dilakukan pada tahap transaksi.</p></div>
        <div className="supplier-setup-grid">{['digiflazz','hanshop'].map(kind=>{
            const current=connections.find(item=>item.kind===kind),supported=features.includes(kind);
            return <article className="card onboarding-card" key={kind}><div className="section-toolbar"><h2>{sourceLabels[kind]}</h2><span className="pill">{!supported?'Tidak termasuk paket':current?.enabled?'Terhubung':'Belum terhubung'}</span></div><p>{store.sandbox?(kind==='digiflazz'?'Gunakan key development. Katalog memakai SKU uji resmi; transaksi dikirim dengan testing aktif.':'Katalog dibaca dari Hanshop. Pembelian dan saldo pemasok disimulasikan.'):(kind==='digiflazz'?'Akun Digiflazz milik toko. Daftarkan IP publik VPS pada whitelist Digiflazz.':'Menggunakan akun Hanshop owner yang sudah tertaut. Saldo mengikuti akun yang sama.')}</p>
                {current?.synced_at && <small>Snapshot terakhir: {new Date(current.synced_at).toLocaleString('id-ID')}</small>}{current?.last_error && <p className="danger-text">{current.last_error}</p>}
                <div className="card-actions">{role==='owner' && current?.kind==='digiflazz' && <button type="button" className="button secondary small" onClick={()=>{webhook.reset();webhook.clearErrors();setShowWebhookSecret(false);setWebhookCopyMessage('');setWebhookConnection(current);}}>Atur webhook{current.webhook_configured?' · siap':''}</button>}{role==='owner' && supported && <button type="button" className="button secondary small" onClick={()=>edit(kind)}><Plug size={15}/>{current?.enabled?'Atur koneksi':'Hubungkan'}</button>}{canManage && current?.enabled && supported && <button type="button" className="button small" onClick={()=>{sync.reset();sync.clearErrors();setSyncing(current);}}><RefreshCw size={15}/>Sinkronkan</button>}{canManage && current?.enabled && supported && <button type="button" className="button secondary small" onClick={()=>openImportAll(current)}><Download size={15}/>Impor produk</button>}{role==='owner' && current?.enabled && <button type="button" className="icon-button danger-text" aria-label={'Putuskan '+sourceLabels[kind]} onClick={()=>setRemoving(current)}><Unplug size={17}/></button>}</div>
            </article>;
        })}</div>
        <section className="table-card data-list-table"><header className="data-table-header"><h2>Produk pemasok</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy}/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari produk pemasok…" choices={[{key:'connection',label:'Sumber',options:Object.fromEntries(connections.map(item=>[item.id,sourceLabels[item.kind]]))},{key:'mode',label:'Jenis katalog',options:{prepaid:'Prabayar / Hanshop',pasca:'Pascabayar'}},{key:'available',label:'Ketersediaan',options:{'1':'Tersedia','0':'Tidak tersedia'}}]}/>
            {canManage && <div className="catalog-bulk-toolbar"><span>{selected.length} dipilih</span><button type="button" className="button small" disabled={!selected.length} onClick={()=>{importer.clearErrors();importer.setData('ids',selected);setImporting(true);}}><Download size={15}/>Impor pilihan</button></div>}
            <FormErrors errors={importer.errors}/>
            <div className="table-scroll"><table><thead><tr>{canManage && <th><input type="checkbox" aria-label="Pilih semua produk pada halaman" checked={items.length>0 && selected.length===items.length} onChange={event=>setSelected(event.target.checked?items.map(item=>item.id):[])}/></th>}<th>Produk / kode</th><th>Layanan / kategori</th><th>Harga beli</th><th>Ketersediaan</th></tr></thead><tbody>{items.map(item=><tr key={item.id}>{canManage && <td className="table-cell-center"><input type="checkbox" aria-label={'Pilih '+item.name} checked={selected.includes(item.id)} onChange={event=>setSelected(values=>event.target.checked?[...values,item.id]:values.filter(id=>id!==item.id))}/></td>}<td><strong>{item.name}</strong><small>{item.sku} · {sourceLabels[connections.find(c=>c.id===item.connection_id)?.kind ?? '']} · {kindLabels[item.kind]}</small></td><td>{item.service}<small>{item.category}</small></td><td className="table-cell-center">{item.kind==='postpaid'?'Admin ':''}{rupiah(item.cost)}</td><td className="table-cell-center"><span className={'store-status '+(item.available?'active':'expired')}>{item.available?'Tersedia':'Tidak tersedia'}</span><small>{item.quantity===null?'Stok pemasok tidak dibatasi':item.quantity+' stok'}</small></td></tr>)}</tbody></table>{!items.length && <div className="empty-state"><Package size={28}/><h3>Belum ada produk yang cocok</h3><p>Hubungkan pemasok, sinkronkan katalog, atau ubah filter.</p></div>}</div>
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <Modal open={!!webhookConnection} title="Webhook Digiflazz" onClose={()=>setWebhookConnection(null)} busy={webhook.processing} footer={<><button type="button" className="button secondary" onClick={()=>setWebhookConnection(null)} disabled={webhook.processing}>Tutup</button><button type="submit" form="supplier-webhook-form" className="button" disabled={webhook.processing}>Simpan secret</button></>}>
            <form id="supplier-webhook-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(webhookConnection)webhook.post(base+'/sources/'+webhookConnection.id+'/webhook',{preserveScroll:true,onSuccess:()=>{setWebhookConnection(null);webhook.reset();}});}}>
                <FormErrors errors={webhook.errors}/><p>Di panel Digiflazz, buka Atur Koneksi → API → Webhook. Masukkan URL callback di bawah ke kolom Payload URL dan masukkan secret yang sama ke kolom Secret ID. Simpan pengaturan webhook di Digiflazz dan secret di DigitalToko.</p>
                <label>URL callback → Payload URL Digiflazz<input readOnly value={digiflazzWebhookUrl} onFocus={event=>event.target.select()}/></label>
                <label>Secret webhook → Secret ID Digiflazz<input type={showWebhookSecret?'text':'password'} minLength={16} maxLength={255} required autoComplete="new-password" value={webhook.data.webhook_secret} onChange={event=>{webhook.setData('webhook_secret',event.target.value);setWebhookCopyMessage('');}} spellCheck={false} autoCapitalize="none"/></label>
                <div className="row-actions"><button type="button" className="button secondary small" disabled={webhook.processing} onClick={generateWebhookSecret}><KeyRound size={14}/>Generate secret</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} aria-pressed={showWebhookSecret} onClick={()=>setShowWebhookSecret(value=>!value)}>{showWebhookSecret?<EyeOff size={14}/>:<Eye size={14}/>} {showWebhookSecret?'Sembunyikan':'Tampilkan'}</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} onClick={copyWebhookSecret}><Copy size={14}/>Salin secret</button></div>
                {webhookCopyMessage && <small role="status">{webhookCopyMessage}</small>}
                <small>Minimal 16 karakter. Secret berbeda dari API key dan disimpan terenkripsi. Secret lama tidak ditampilkan; penggantian menunggu transaksi berjalan selesai.</small>
                <p>URL harus bisa diakses melalui HTTPS publik. Digiflazz tidak dapat mengirim callback ke localhost atau 127.0.0.1. Jika akun Digiflazz dipakai di beberapa toko, gunakan URL dan secret yang sama untuk semua koneksi akun tersebut.</p>
                <small>{webhookConnection?.webhook_configured?'Secret sudah tersimpan. Status pengiriman callback dapat diperiksa pada panel Digiflazz.':'Webhook belum dikonfigurasi. Pemeriksaan berkala tetap berjalan sebagai cadangan.'}</small>
            </form>
        </Modal>
        <Modal open={!!editing} title={'Hubungkan '+sourceLabels[editing ?? 'digiflazz']} onClose={close} busy={connection.processing} footer={<><button className="button secondary" type="button" disabled={connection.processing} onClick={close}>Batal</button><button className="button" type="submit" form="supplier-connect-form" disabled={connection.processing}>{connection.processing?'Menyimpan…':store.sandbox?'Simpan key development':'Periksa & simpan'}</button></>}>
            <form id="supplier-connect-form" className="form-stack" onSubmit={event=>{event.preventDefault();connection.post(base+'/sources',{preserveScroll:true,onSuccess:close});}}><FormErrors errors={connection.errors}/>
                {editing==='digiflazz'?<><p>Credential disimpan terenkripsi. Pada koneksi yang sudah ada, kolom kosong mempertahankan credential lama.</p><label>Username Digiflazz<input autoComplete="off" value={connection.data.username} onChange={event=>connection.setData('username',event.target.value)}/></label><label>{store.sandbox?'API key development':'API key produksi'}<input type="password" autoComplete="new-password" value={connection.data.api_key} onChange={event=>connection.setData('api_key',event.target.value)}/></label></>:<p>Koneksi menggunakan akun Hanshop owner. Tidak perlu menyalin API key akun ke panel ini.</p>}
                <label className="checkbox-label"><input type="checkbox" checked={connection.data.enabled} onChange={event=>connection.setData('enabled',event.target.checked)}/>Aktifkan sumber</label>
            </form>
        </Modal>
        <Modal open={!!syncing} title="Sinkronkan katalog pemasok" onClose={()=>setSyncing(null)} busy={sync.processing} footer={<><button className="button secondary" type="button" onClick={()=>setSyncing(null)} disabled={sync.processing}>Batal</button><button type="submit" form="supplier-sync-form" className="button" disabled={sync.processing}>{sync.processing?'Menyinkronkan…':'Sinkronkan'}</button></>}>
            <form id="supplier-sync-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(syncing)sync.post(base+'/sources/'+syncing.id+'/sync',{preserveScroll:true,onSuccess:()=>setSyncing(null)});}}><FormErrors errors={sync.errors}/><p>Snapshot segar memakai cache Redis bersama per akun selama sekitar dua menit. Produk yang hilang dinonaktifkan setelah respons katalog valid diterima.</p>{syncing?.kind==='digiflazz' && <label>Jenis katalog<select value={sync.data.mode} onChange={event=>sync.setData('mode',event.target.value)}><option value="prepaid">Prabayar / PPOB</option><option value="pasca">Pascabayar — biaya admin</option></select></label>}</form>
        </Modal>
        <Modal open={!!importAll} title={'Impor produk '+sourceLabels[importAll?.kind ?? 'digiflazz']} busy={allBusy || importer.processing} onClose={()=>setImportAll(null)} footer={<><button type="button" className="button secondary" disabled={allBusy || importer.processing} onClick={()=>setImportAll(null)}>{allDone?'Tutup':'Kembali'}</button>{allDone?<Link className="button" href={base+'/products'}>Lihat katalog toko</Link>:<button type="button" className="button" disabled={allBusy || importer.processing || !importReady} onClick={runImportAll}>{allBusy || importer.processing?'Mengimpor…':allError?'Lanjutkan impor':importScope==='all'?'Impor semua produk':'Impor produk sesuai pilihan'}</button>}</>}>
            <div className="form-stack">
                <label>Metode impor<select value={importScope} disabled={allBusy || importer.processing || allDone} onChange={event=>{setImportScope(event.target.value as typeof importScope);resetImportProgress();setImportService(null);setImportCategory(null);}}><option value="all">Semua produk</option><option value="service">Per layanan</option><option value="category">Per kategori</option></select></label>
                {(importScope==='service' || importScope==='category') && <CatalogSelect label="Layanan pemasok" url={base+'/sources/'+importAll?.id+'/import-options?type=services'} value={importService} disabled={allBusy || importer.processing || allDone} onChange={value=>{setImportService(value);setImportCategory(null);resetImportProgress();}}/>}
                {importScope==='category' && <CatalogSelect key={importService?.id ?? ''} label="Kategori pemasok" url={base+'/sources/'+importAll?.id+'/import-options?type=categories&service='+encodeURIComponent(importService?.id ?? '')} value={importCategory} disabled={!importService || allBusy || importer.processing || allDone} onChange={value=>{setImportCategory(value);resetImportProgress();}}/>}
            </div>
            <p>{importScope==='all'?'Seluruh produk':importScope==='service'?'Semua produk dalam layanan yang dipilih':'Semua produk dalam kategori dan layanan yang dipilih'} dari sumber ini akan diimpor, termasuk yang sedang tidak tersedia. Tidak dibatasi halaman, pencarian, atau filter tabel. Hanya katalog yang sudah disinkronkan.</p>
            <FormErrors errors={importer.errors}/>
            <p>Produk yang sudah diparsing dilewati; harga dan status produk lama tidak diubah. Produk baru mengikuti markup kategori/layanan, atau harga modal jika belum ada aturan. Impor tidak memotong saldo pemasok.</p>
            <div role="status" aria-live="polite">{allDone?<p><strong>Impor selesai.</strong> {allProgress?.total ?? 0} produk sumber sudah memiliki parsing di katalog toko. {allProgress?.imported ?? 0} produk baru ditambahkan dalam proses ini.</p>:allProgress?<><p>{allProgress.total-allProgress.remaining} dari {allProgress.total} produk sumber sudah diparsing. Tersisa {allProgress.remaining}.</p><progress max={Math.max(1,allProgress.total)} value={allProgress.total-allProgress.remaining} aria-label="Progres impor semua"/></>:allBusy?<p>Menyiapkan katalog dan mengimpor produk…</p>:null}</div>
            {allBusy && <p>Biarkan halaman ini terbuka sampai selesai. Produk diproses bertahap agar katalog besar tidak dimuat sekaligus.</p>}
            {allError && <p role="alert" className="danger-text">{allError}</p>}
        </Modal>
        <ConfirmDialog open={importing} title={'Impor '+selected.length+' produk'} confirmLabel="Impor produk" busy={importer.processing} onCancel={()=>setImporting(false)} onConfirm={()=>{importer.transform(()=>({ids:selected}));importer.post(base+'/products/import',{preserveScroll:true,onSuccess:()=>{setImporting(false);setSelected([]);}});}}><FormErrors errors={importer.errors}/><p>Produk ditambahkan dengan parsing sumber terpilih. Harga awal mengikuti markup kategori/layanan; tanpa aturan, harga awal sama dengan modal. Atur harga jual melalui katalog toko. Produk yang sudah diparsing tidak dibuat ulang.</p></ConfirmDialog>
        <ConfirmDialog open={!!removing} title="Putuskan sumber pemasok?" confirmLabel="Putuskan koneksi" danger busy={deletion.processing} onCancel={()=>setRemoving(null)} onConfirm={()=>{if(removing)deletion.delete(base+'/sources/'+removing.id,{preserveScroll:true,onSuccess:()=>setRemoving(null)});}}><p>Credential koneksi dihapus. Produk yang menggunakan sumber ini menjadi tidak tersedia sampai sumber dihubungkan dan disinkronkan kembali.</p></ConfirmDialog>
    </WorkspaceLayout>;
}
