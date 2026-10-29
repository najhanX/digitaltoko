import CatalogHelp from '../../components/CatalogHelp';
import SupplierWhitelistIp from '../../components/SupplierWhitelistIp';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Download, Plug, RefreshCw, Unplug, Package, Copy, Eye, EyeOff, KeyRound } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import CatalogSelect from '../../components/CatalogSelect';
import ConfirmDialog from '../../components/ConfirmDialog';
import { FilterBar, Pagination, useServerList, type ListFilters, type PageMeta } from '../../components/ListControls';
import { FormErrors, rupiah, sourceLabels, kindLabels, type CatalogStore } from '../../components/CatalogForms';
type Connection={name?:string;id:string;kind:string;enabled:boolean;verified_at:string|null;synced_at:string|null;last_error:string|null;webhook_configured:boolean};
type Item={id:string;connection_id:string;sku:string;mode:string;name:string;kind:string;service:string;category:string;cost:number;billing?:{adjustment?:number;admin?:number;commission?:number}|null;available:boolean;availability_reason?:string|null;quantity:number|null;observed_at:string};
type Props={role:'owner'|'staff';store:CatalogStore;connections:Connection[];features:string[];canManage:boolean;items:Item[];filters:ListFilters;pagination:PageMeta;digiflazzWebhookUrl:string;supplierOutboundIp:string|null};
export default function Sources({role,store,connections,features,canManage,items,filters,pagination,digiflazzWebhookUrl,supplierOutboundIp}:Props) {
    const connectionById = new Map(connections.map(connection=>[connection.id,connection]));
    const [webhookConnection,setWebhookConnection]=useState<Connection|null>(null);
    const webhook=useForm({webhook_secret:''});
    const [showWebhookSecret,setShowWebhookSecret]=useState(false),[webhookCopyMessage,setWebhookCopyMessage]=useState('');
    const [loadingWebhookSecret,setLoadingWebhookSecret]=useState(false);
    const secretRequest=useRef<AbortController|null>(null);
    useEffect(()=>()=>{secretRequest.current?.abort();},[webhookConnection?.id]);
    function closeWebhook(){secretRequest.current?.abort();webhook.reset();webhook.clearErrors();setShowWebhookSecret(false);setWebhookCopyMessage('');setLoadingWebhookSecret(false);setWebhookConnection(null);}
    async function loadWebhookSecret(){
        if(!webhookConnection || loadingWebhookSecret)return;
        const controller=new AbortController();secretRequest.current=controller;setLoadingWebhookSecret(true);webhook.clearErrors();
        try{
            const cookie=document.cookie.split('; ').find(item=>item.startsWith('XSRF-TOKEN='));
            const response=await fetch('/stores/'+store.id+'/sources/'+webhookConnection.id+'/webhook-secret',{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-XSRF-TOKEN':cookie?decodeURIComponent(cookie.slice(11)):''}});
            if(!response.ok)throw new Error('Secret belum dapat dimuat. Periksa sesi login owner lalu coba kembali.');
            const data=await response.json() as {secret:string};
            if(controller.signal.aborted)return;
            webhook.setData('webhook_secret',data.secret);setShowWebhookSecret(true);setWebhookCopyMessage('Ini secret yang tersimpan. Salin persis ke kolom Secret ID pada webhook Digiflazz yang memakai URL callback di atas.');
        }catch{
            if(!controller.signal.aborted)webhook.setError('webhook_secret','Secret belum dapat dimuat. Periksa sesi login owner lalu coba kembali.');
        }finally{if(!controller.signal.aborted)setLoadingWebhookSecret(false);}
    }
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
    return <WorkspaceLayout contentClassName="catalog-workspace" role={role} title="Sumber produk" description={'Hubungkan pemasok dan impor produknya ke katalog '+store.name+'.'}
        actions={<>{features.includes('otomax')&&<Link className="button secondary" href={base+'/otomax'}><Plug size={16}/>Kelola supplier OtomaX</Link>}<Link className="button secondary" href={base+'/products'}><Package size={16}/>Katalog toko</Link></>}>
        {store.sandbox && <CatalogHelp title="Panduan katalog pengujian Digiflazz"><p>Sinkronisasi menyediakan SKU uji resmi: xld10 (prabayar) dan pln (pascabayar). Saldo pemasok yang tampil adalah simulasi Rp1.000.000. Key development baru diverifikasi saat transaksi uji.</p><p>XL: tujuan 087800001230 berhasil, 087800001232 gagal. PLN: 530000000001 berhasil. Hanshop hanya membaca katalog; pemesanan disimulasikan.</p></CatalogHelp>}
        <CatalogHelp title="Mulai dari koneksi → sinkronisasi → impor produk"><p>Sinkronisasi dan impor hanya menyiapkan katalog. Pembelian serta pemotongan saldo dilakukan pada tahap transaksi.</p></CatalogHelp>
        <div className="supplier-setup-grid">{['digiflazz','hanshop'].map(kind=>{
            const current=connections.find(item=>item.kind===kind),supported=features.includes(kind);
            return <article className="card onboarding-card catalog-supplier-card" key={kind}><div className="section-toolbar"><h2><span className="catalog-supplier-icon"><Plug size={19}/></span>{sourceLabels[kind]}</h2><span className={'store-status '+(current?.enabled && supported?'active':'expired')}>{!supported?'Tidak termasuk paket':current?.enabled?'Terhubung':'Belum terhubung'}</span></div><p>{store.sandbox?(kind==='digiflazz'?'Gunakan key development. Katalog memakai SKU uji resmi; transaksi dikirim dengan testing aktif.':'Katalog dibaca dari Hanshop. Pembelian dan saldo pemasok disimulasikan.'):(kind==='digiflazz'?'Akun Digiflazz milik toko. Daftarkan IP publik VPS pada whitelist Digiflazz.':'Menggunakan akun Hanshop owner yang sudah tertaut. Saldo mengikuti akun yang sama.')}</p>
                {current?.synced_at && <small>Snapshot terakhir: {new Date(current.synced_at).toLocaleString('id-ID')}</small>}{current?.last_error && <p className="danger-text">{current.last_error}</p>}
                <div className="card-actions">{role==='owner' && current?.kind==='digiflazz' && <button type="button" className="button secondary small" onClick={()=>{webhook.reset();webhook.clearErrors();setShowWebhookSecret(false);setWebhookCopyMessage('');setWebhookConnection(current);}}>Atur webhook{current.webhook_configured?' · siap':''}</button>}{role==='owner' && supported && <button type="button" className="button secondary small" onClick={()=>edit(kind)}><Plug size={15}/>{current?.enabled?'Atur koneksi':'Hubungkan'}</button>}{canManage && current?.enabled && supported && <button type="button" className="button small" onClick={()=>{sync.reset();sync.clearErrors();setSyncing(current);}}><RefreshCw size={15}/>Sinkronkan</button>}{canManage && current?.enabled && supported && <button type="button" className="button secondary small" onClick={()=>openImportAll(current)}><Download size={15}/>Impor produk</button>}{role==='owner' && current?.enabled && <button type="button" className="icon-button danger-text" aria-label={'Putuskan '+sourceLabels[kind]} onClick={()=>setRemoving(current)}><Unplug size={17}/></button>}</div>
            </article>;
        })}</div>
        <section className="table-card data-list-table catalog-data-card"><header className="data-table-header"><h2>Produk pemasok</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy}/></header>
            <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari produk pemasok…" choices={[{key:'connection',label:'Sumber',options:Object.fromEntries(connections.map(item=>[item.id,item.name || sourceLabels[item.kind]]))},{key:'mode',label:'Jenis katalog',options:{prepaid:'Prabayar / Hanshop',pasca:'Pascabayar'}},{key:'available',label:'Ketersediaan',options:{'1':'Tersedia','0':'Tidak tersedia'}}]}/>
            {canManage && <div className="catalog-bulk-toolbar"><span>{selected.length} dipilih</span>{connections.find(c=>c.id===filters.connection&&c.kind==='otomax')&&<button type="button" className="button secondary small" onClick={()=>openImportAll(connections.find(c=>c.id===filters.connection)!)}>Impor semua / layanan / kategori OtomaX</button>}<button type="button" className="button small" disabled={!selected.length} onClick={()=>{importer.clearErrors();importer.setData('ids',selected);setImporting(true);}}><Download size={15}/>Impor pilihan</button></div>}
            <FormErrors errors={importer.errors}/>
            <div className="table-scroll source-products-table"><table><thead><tr>{canManage && <th className="catalog-selection-cell"><input type="checkbox" aria-label="Pilih semua produk pada halaman" checked={items.length>0 && selected.length===items.length} onChange={event=>setSelected(event.target.checked?items.map(item=>item.id):[])}/></th>}<th>Produk / kode</th><th>Sumber</th><th>Layanan / kategori</th><th>Harga beli</th><th>Ketersediaan</th></tr></thead><tbody>{items.map(item=><tr key={item.id} className={selected.includes(item.id)?'catalog-row-selected':undefined}>{canManage && <td className="table-cell-center"><input type="checkbox" aria-label={'Pilih '+item.name} checked={selected.includes(item.id)} onChange={event=>setSelected(values=>event.target.checked?[...values,item.id]:values.filter(id=>id!==item.id))}/></td>}<td className="source-product-name"><strong>{item.name}</strong><small>{item.sku} · {kindLabels[item.kind]}</small></td><td className="table-cell-center"><strong>{sourceLabels[connectionById.get(item.connection_id)?.kind ?? ""] ?? "Pemasok"}</strong><small>{connectionById.get(item.connection_id)?.name || "Akun toko"}</small></td><td>{item.service}<small>{item.category}</small></td><td className="table-cell-center">{item.billing?.adjustment!==undefined?<>{rupiah(item.billing.adjustment)}<small>{item.billing.adjustment<0?'Komisi pascabayar':item.billing.adjustment===0?'Cek / bayar: perlu pasangan':'Penyesuaian pascabayar'}</small></>:<>{item.kind==='postpaid'?'Admin layanan ':''}{rupiah(item.cost)}{item.billing?.commission!==undefined&&<small>Komisi {rupiah(item.billing.commission)}</small>}</>}</td><td className="table-cell-center"><span className={'store-status '+(item.available?'active':'expired')}>{item.available?'Tersedia':'Tidak tersedia'}</span>{!item.available&&item.availability_reason&&<small>{item.availability_reason}</small>}<small>{item.quantity===null?(connections.find(c=>c.id===item.connection_id)?.kind==='otomax'?'Stok tidak dilaporkan':'Stok tidak terbatas'):item.quantity+' stok'}</small></td></tr>)}</tbody></table>{!items.length && <div className="empty-state"><Package size={28}/><h3>Belum ada produk yang cocok</h3><p>Hubungkan pemasok, sinkronkan katalog, atau ubah filter.</p></div>}</div>
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <Modal open={!!webhookConnection} title="Webhook Digiflazz" onClose={closeWebhook} busy={webhook.processing || loadingWebhookSecret} footer={<><button type="button" className="button secondary" onClick={closeWebhook} disabled={webhook.processing || loadingWebhookSecret}>Tutup</button><button type="submit" form="supplier-webhook-form" className="button" disabled={webhook.processing || loadingWebhookSecret}>Simpan secret</button></>}>
            <form id="supplier-webhook-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(webhookConnection)webhook.post(base+'/sources/'+webhookConnection.id+'/webhook',{preserveScroll:true,onSuccess:closeWebhook});}}>
                <FormErrors errors={webhook.errors}/><div className="catalog-inline-note">Salin URL ke <strong>Payload URL</strong> dan secret ke <strong>Secret ID</strong> di panel webhook Digiflazz.</div>
                <label>URL callback → Payload URL Digiflazz<input readOnly value={digiflazzWebhookUrl} onFocus={event=>event.target.select()}/></label>
                <label>Secret webhook → Secret ID Digiflazz<input type={showWebhookSecret?'text':'password'} minLength={16} maxLength={255} required autoComplete="new-password" value={webhook.data.webhook_secret} disabled={loadingWebhookSecret} onChange={event=>{webhook.setData('webhook_secret',event.target.value);setWebhookCopyMessage('');}} spellCheck={false} autoCapitalize="none"/></label>
                <div className="row-actions">{webhookConnection?.webhook_configured && <button type="button" className="button secondary small" disabled={webhook.processing || loadingWebhookSecret || !!webhook.data.webhook_secret} onClick={loadWebhookSecret}><KeyRound size={14}/>{loadingWebhookSecret?'Memuat secret…':'Muat secret tersimpan'}</button>}<button type="button" className="button secondary small" disabled={webhook.processing || loadingWebhookSecret} onClick={generateWebhookSecret}><KeyRound size={14}/>Generate secret</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} aria-pressed={showWebhookSecret} onClick={()=>setShowWebhookSecret(value=>!value)}>{showWebhookSecret?<EyeOff size={14}/>:<Eye size={14}/>} {showWebhookSecret?'Sembunyikan':'Tampilkan'}</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} onClick={copyWebhookSecret}><Copy size={14}/>Salin secret</button></div>
                {webhookCopyMessage && <small role="status">{webhookCopyMessage}</small>}
                <small>Minimal 16 karakter. Secret tersimpan dapat dimuat oleh owner.</small><CatalogHelp title="Kapan secret perlu diganti?"><p>Jika hanya URL ngrok berubah, pertahankan secret yang sama. Penggantian secret menunggu transaksi berjalan selesai. Secret webhook berbeda dari API key.</p></CatalogHelp>
                <small>Gunakan URL HTTPS publik. Satu akun Digiflazz pada beberapa toko memakai URL dan secret yang sama.</small>
                <small>{webhookConnection?.webhook_configured?'Secret sudah tersimpan. Status pengiriman callback dapat diperiksa pada panel Digiflazz.':'Webhook belum dikonfigurasi. Pemeriksaan berkala tetap berjalan sebagai cadangan.'}</small>
            </form>
        </Modal>
        <Modal open={!!editing} title={'Hubungkan '+sourceLabels[editing ?? 'digiflazz']} onClose={close} busy={connection.processing} footer={<><button className="button secondary" type="button" disabled={connection.processing} onClick={close}>Batal</button><button className="button" type="submit" form="supplier-connect-form" disabled={connection.processing}>{connection.processing?'Menyimpan…':store.sandbox?'Simpan key development':'Periksa & simpan'}</button></>}>
            <form id="supplier-connect-form" className="form-stack" onSubmit={event=>{event.preventDefault();connection.post(base+'/sources',{preserveScroll:true,onSuccess:close});}}><FormErrors errors={connection.errors}/>
                {editing==='digiflazz'?<><SupplierWhitelistIp key={editing} ip={supplierOutboundIp} supplier="Digiflazz"/><p>Credential disimpan terenkripsi. Pada koneksi yang sudah ada, kolom kosong mempertahankan credential lama.</p><label>Username Digiflazz<input autoComplete="off" value={connection.data.username} onChange={event=>connection.setData('username',event.target.value)}/></label><label>{store.sandbox?'API key development':'API key produksi'}<input type="password" autoComplete="new-password" value={connection.data.api_key} onChange={event=>connection.setData('api_key',event.target.value)}/></label></>:<p>Koneksi menggunakan akun Hanshop owner. Tidak perlu menyalin API key akun ke panel ini.</p>}
                <label className="checkbox-label"><input type="checkbox" checked={connection.data.enabled} onChange={event=>connection.setData('enabled',event.target.checked)}/>Aktifkan sumber</label>
            </form>
        </Modal>
        <Modal open={!!syncing} title="Sinkronkan katalog pemasok" onClose={()=>setSyncing(null)} busy={sync.processing} footer={<><button className="button secondary" type="button" onClick={()=>setSyncing(null)} disabled={sync.processing}>Batal</button><button type="submit" form="supplier-sync-form" className="button" disabled={sync.processing}>{sync.processing?'Menyinkronkan…':'Sinkronkan'}</button></>}>
            <form id="supplier-sync-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(syncing)sync.post(base+'/sources/'+syncing.id+'/sync',{preserveScroll:true,onSuccess:()=>setSyncing(null)});}}><FormErrors errors={sync.errors}/><p>Perbarui katalog dari pemasok. Produk yang hilang dari respons valid akan dinonaktifkan.</p>{syncing?.kind==='digiflazz' && <label>Jenis katalog<select value={sync.data.mode} onChange={event=>sync.setData('mode',event.target.value)}><option value="prepaid">Prabayar / PPOB</option><option value="pasca">Pascabayar — admin layanan</option></select></label>}</form>
        </Modal>
        <Modal open={!!importAll} title={'Impor produk '+sourceLabels[importAll?.kind ?? 'digiflazz']} busy={allBusy || importer.processing} onClose={()=>setImportAll(null)} footer={<><button type="button" className="button secondary" disabled={allBusy || importer.processing} onClick={()=>setImportAll(null)}>{allDone?'Tutup':'Kembali'}</button>{allDone?<Link className="button" href={base+'/products'}>Lihat katalog toko</Link>:<button type="button" className="button" disabled={allBusy || importer.processing || !importReady} onClick={runImportAll}>{allBusy || importer.processing?'Mengimpor…':allError?'Lanjutkan impor':importScope==='all'?'Impor semua produk':'Impor produk sesuai pilihan'}</button>}</>}>
            <div className="form-stack">
                <label>Metode impor<select value={importScope} disabled={allBusy || importer.processing || allDone} onChange={event=>{setImportScope(event.target.value as typeof importScope);resetImportProgress();setImportService(null);setImportCategory(null);}}><option value="all">Semua produk</option><option value="service">Per layanan</option><option value="category">Per kategori</option></select></label>
                {(importScope==='service' || importScope==='category') && <CatalogSelect label="Layanan pemasok" url={base+'/sources/'+importAll?.id+'/import-options?type=services'} value={importService} disabled={allBusy || importer.processing || allDone} onChange={value=>{setImportService(value);setImportCategory(null);resetImportProgress();}}/>}
                {importScope==='category' && <CatalogSelect key={importService?.id ?? ''} label="Kategori pemasok" url={base+'/sources/'+importAll?.id+'/import-options?type=categories&service='+encodeURIComponent(importService?.id ?? '')} value={importCategory} disabled={!importService || allBusy || importer.processing || allDone} onChange={value=>{setImportCategory(value);resetImportProgress();}}/>}
            </div>
            <p>{importScope==='all'?'Seluruh produk':importScope==='service'?'Semua produk dalam layanan yang dipilih':'Semua produk dalam kategori dan layanan yang dipilih'} dari sumber ini akan diimpor, termasuk yang sedang tidak tersedia. Tidak dibatasi halaman, pencarian, atau filter tabel. Hanya katalog yang sudah disinkronkan.</p>
            <FormErrors errors={importer.errors}/>
            <CatalogHelp title="Harga dan produk yang sudah pernah diimpor"><p>Produk yang sudah diparsing dilewati. Harga serta statusnya tetap. Produk baru mengikuti markup kategori/layanan; tanpa aturan, gunakan modal pemasok. Impor hanya menyiapkan katalog.</p></CatalogHelp>
            <div role="status" aria-live="polite">{allDone?<p><strong>Impor selesai.</strong> {allProgress?.total ?? 0} produk sumber sudah memiliki parsing di katalog toko. {allProgress?.imported ?? 0} produk baru ditambahkan dalam proses ini.</p>:allProgress?<><p>{allProgress.total-allProgress.remaining} dari {allProgress.total} produk sumber sudah diparsing. Tersisa {allProgress.remaining}.</p><progress max={Math.max(1,allProgress.total)} value={allProgress.total-allProgress.remaining} aria-label="Progres impor semua"/></>:allBusy?<p>Menyiapkan katalog dan mengimpor produk…</p>:null}</div>
            {allBusy && <p>Biarkan halaman ini terbuka sampai selesai. Produk diproses bertahap agar katalog besar tidak dimuat sekaligus.</p>}
            {allError && <p role="alert" className="danger-text">{allError}</p>}
        </Modal>
        <ConfirmDialog open={importing} title={'Impor '+selected.length+' produk'} confirmLabel="Impor produk" busy={importer.processing} onCancel={()=>setImporting(false)} onConfirm={()=>{importer.transform(()=>({ids:selected}));importer.post(base+'/products/import',{preserveScroll:true,onSuccess:()=>{setImporting(false);setSelected([]);}});}}><FormErrors errors={importer.errors}/><p>Produk ditambahkan dengan parsing sumber terpilih. Harga awal mengikuti markup kategori/layanan; tanpa aturan, harga awal sama dengan modal. Atur harga jual melalui katalog toko. Produk yang sudah diparsing tidak dibuat ulang.</p></ConfirmDialog>
        <ConfirmDialog open={!!removing} title="Putuskan sumber pemasok?" confirmLabel="Putuskan koneksi" danger busy={deletion.processing} onCancel={()=>setRemoving(null)} onConfirm={()=>{if(removing)deletion.delete(base+'/sources/'+removing.id,{preserveScroll:true,onSuccess:()=>setRemoving(null)});}}><p>Credential koneksi dihapus. Produk yang menggunakan sumber ini menjadi tidak tersedia sampai sumber dihubungkan dan disinkronkan kembali.</p></ConfirmDialog>
    </WorkspaceLayout>;
}
