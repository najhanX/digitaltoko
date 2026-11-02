import CatalogHelp from '../../components/CatalogHelp';
import SupplierWhitelistIp from '../../components/SupplierWhitelistIp';
import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Plug, RefreshCw, Unplug, Copy, Eye, EyeOff, KeyRound } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import Modal from '../../components/Modal';
import ConfirmDialog from '../../components/ConfirmDialog';
import { FormErrors, sourceLabels, type CatalogStore } from '../../components/CatalogForms';

type Connection={name?:string;id:string;kind:string;enabled:boolean;verified_at:string|null;synced_at:string|null;last_error:string|null;webhook_configured:boolean};
type Props={role:'owner'|'staff';store:CatalogStore;kind:'digiflazz'|'hanshop';connections:Connection[];features:string[];canManage:boolean;digiflazzWebhookUrl:string;supplierOutboundIp:string|null};
export default function SupplierConnections({role,store,kind,connections,features,canManage,digiflazzWebhookUrl,supplierOutboundIp}:Props) {
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
            webhook.setData('webhook_secret',data.secret);setShowWebhookSecret(true);setWebhookCopyMessage('Salin secret ini ke Secret ID di Digiflazz.');
        }catch{
            if(!controller.signal.aborted)webhook.setError('webhook_secret','Secret belum dapat dimuat. Periksa sesi login owner lalu coba kembali.');
        }finally{if(!controller.signal.aborted)setLoadingWebhookSecret(false);}
    }
    function generateWebhookSecret(){const bytes=crypto.getRandomValues(new Uint8Array(32));webhook.setData('webhook_secret',Array.from(bytes,byte=>byte.toString(16).padStart(2,'0')).join(''));webhook.clearErrors('webhook_secret');setShowWebhookSecret(true);setWebhookCopyMessage('Secret baru dibuat. Salin ke kolom Secret ID di Digiflazz, lalu simpan secret di sini.');}
    async function copyWebhookSecret(){try{await navigator.clipboard.writeText(webhook.data.webhook_secret);setWebhookCopyMessage('Secret disalin. Tempel ke kolom Secret ID di Digiflazz.');}catch{setShowWebhookSecret(true);setWebhookCopyMessage('Salin secret secara manual dari kolom di atas.');}}
    const base='/stores/'+store.id;
    const [editing,setEditing]=useState<string|null>(null),[syncing,setSyncing]=useState<Connection|null>(null),[removing,setRemoving]=useState<Connection|null>(null);
    const connection=useForm({kind:kind as string,username:'',api_key:'',enabled:true}),sync=useForm({mode:'prepaid'}),deletion=useForm({});
    function edit(kind:string){connection.clearErrors();connection.setData({kind,username:'',api_key:'',enabled:true});setEditing(kind);}
    function close(){setEditing(null);connection.reset();connection.clearErrors();}
    return <WorkspaceLayout contentClassName="catalog-workspace" role={role} title={kind==='hanshop'?'API Hanshop':'Digiflazz'} description="Kelola koneksi, sinkronisasi, dan callback pemasok toko.">
        <div className="supplier-connection-overview">{[kind].map(kind=>{
            const current=connections.find(item=>item.kind===kind),supported=features.includes(kind);
            return <article className="card onboarding-card catalog-supplier-card" key={kind}><div className="section-toolbar"><h2><span className="catalog-supplier-icon"><Plug size={19}/></span>{sourceLabels[kind]}</h2><span className={'store-status '+(current?.enabled && supported?'active':'expired')}>{!supported?'Tidak termasuk paket':current?.enabled?'Terhubung':'Belum terhubung'}</span></div><p>{store.sandbox?(kind==='digiflazz'?'Gunakan key development. Katalog memakai SKU uji resmi; transaksi dikirim dengan testing aktif.':'Katalog dibaca dari Hanshop. Pembelian dan saldo pemasok disimulasikan.'):(kind==='digiflazz'?'Gunakan akun toko dan whitelist IP server.':'Memakai akun dan saldo Hanshop owner.')}</p>
                {current?.synced_at && <small>Snapshot terakhir: {new Date(current.synced_at).toLocaleString('id-ID')}</small>}{current?.last_error && <p className="danger-text">{current.last_error}</p>}
                <div className="card-actions">{role==='owner' && current?.kind==='digiflazz' && <button type="button" className="button secondary small" onClick={()=>{webhook.reset();webhook.clearErrors();setShowWebhookSecret(false);setWebhookCopyMessage('');setWebhookConnection(current);}}>Atur webhook{current.webhook_configured?' · siap':''}</button>}{role==='owner' && supported && <button type="button" className="button secondary small" onClick={()=>edit(kind)}><Plug size={15}/>{current?.enabled?'Atur koneksi':'Hubungkan'}</button>}{canManage && current?.enabled && supported && <button type="button" className="button small" onClick={()=>{sync.reset();sync.clearErrors();setSyncing(current);}}><RefreshCw size={15}/>Sinkronkan</button>}{role==='owner' && current?.enabled && <button type="button" className="icon-button danger-text" aria-label={'Putuskan '+sourceLabels[kind]} onClick={()=>setRemoving(current)}><Unplug size={17}/></button>}</div>
            </article>;
        })}</div>
        <Modal open={!!webhookConnection} title="Webhook Digiflazz" onClose={closeWebhook} busy={webhook.processing || loadingWebhookSecret} footer={<><button type="button" className="button secondary" onClick={closeWebhook} disabled={webhook.processing || loadingWebhookSecret}>Tutup</button><button type="submit" form="supplier-webhook-form" className="button" disabled={webhook.processing || loadingWebhookSecret}>Simpan secret</button></>}>
            <form id="supplier-webhook-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(webhookConnection)webhook.post(base+'/sources/'+webhookConnection.id+'/webhook',{preserveScroll:true,onSuccess:closeWebhook});}}>
                <FormErrors errors={webhook.errors}/><div className="catalog-inline-note">Salin URL ke <strong>Payload URL</strong> dan secret ke <strong>Secret ID</strong> di panel webhook Digiflazz.</div>
                <label>URL callback → Payload URL Digiflazz<input readOnly value={digiflazzWebhookUrl} onFocus={event=>event.target.select()}/></label>
                <label>Secret webhook → Secret ID Digiflazz<input type={showWebhookSecret?'text':'password'} minLength={16} maxLength={255} required autoComplete="new-password" value={webhook.data.webhook_secret} disabled={loadingWebhookSecret} onChange={event=>{webhook.setData('webhook_secret',event.target.value);setWebhookCopyMessage('');}} spellCheck={false} autoCapitalize="none"/></label>
                <div className="row-actions">{webhookConnection?.webhook_configured && <button type="button" className="button secondary small" disabled={webhook.processing || loadingWebhookSecret || !!webhook.data.webhook_secret} onClick={loadWebhookSecret}><KeyRound size={14}/>{loadingWebhookSecret?'Memuat secret…':'Muat secret tersimpan'}</button>}<button type="button" className="button secondary small" disabled={webhook.processing || loadingWebhookSecret} onClick={generateWebhookSecret}><KeyRound size={14}/>Generate secret</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} aria-pressed={showWebhookSecret} onClick={()=>setShowWebhookSecret(value=>!value)}>{showWebhookSecret?<EyeOff size={14}/>:<Eye size={14}/>} {showWebhookSecret?'Sembunyikan':'Tampilkan'}</button><button type="button" className="button secondary small" disabled={!webhook.data.webhook_secret || webhook.processing} onClick={copyWebhookSecret}><Copy size={14}/>Salin secret</button></div>
                {webhookCopyMessage && <small role="status">{webhookCopyMessage}</small>}
                <small>Minimal 16 karakter. Secret tersimpan dapat dimuat oleh owner.</small><CatalogHelp title="Kapan secret perlu diganti?"><p>Jika hanya alamat callback berubah, pertahankan secret yang sama. Penggantian secret menunggu transaksi berjalan selesai. Secret webhook berbeda dari API key.</p></CatalogHelp>
                <small>Gunakan URL HTTPS publik. Satu akun Digiflazz pada beberapa toko memakai URL dan secret yang sama.</small>
                <small>{webhookConnection?.webhook_configured?'Secret sudah tersimpan. Status pengiriman callback dapat diperiksa pada panel Digiflazz.':'Webhook belum dikonfigurasi. Pemeriksaan berkala tetap berjalan sebagai cadangan.'}</small>
            </form>
        </Modal>
        <Modal open={!!editing} title={'Hubungkan '+sourceLabels[editing ?? 'digiflazz']} onClose={close} busy={connection.processing} footer={<><button className="button secondary" type="button" disabled={connection.processing} onClick={close}>Batal</button><button className="button" type="submit" form="supplier-connect-form" disabled={connection.processing}>{connection.processing?'Menyimpan…':store.sandbox?'Simpan key development':'Periksa & simpan'}</button></>}>
            <form id="supplier-connect-form" className="form-stack" onSubmit={event=>{event.preventDefault();connection.post(base+'/sources',{preserveScroll:true,onSuccess:close});}}><FormErrors errors={connection.errors}/>
                {editing==='digiflazz'?<><SupplierWhitelistIp key={editing} ip={supplierOutboundIp} supplier="Digiflazz"/><p>Tersimpan terenkripsi. Kosongkan kolom untuk mempertahankan data lama.</p><label>Username Digiflazz<input autoComplete="off" value={connection.data.username} onChange={event=>connection.setData('username',event.target.value)}/></label><label>{store.sandbox?'API key development':'API key produksi'}<input type="password" autoComplete="new-password" value={connection.data.api_key} onChange={event=>connection.setData('api_key',event.target.value)}/></label></>:<p>Akun Hanshop owner digunakan otomatis. Tidak perlu API key.</p>}
                <label className="checkbox-label"><input type="checkbox" checked={connection.data.enabled} onChange={event=>connection.setData('enabled',event.target.checked)}/>Aktifkan sumber</label>
            </form>
        </Modal>
        <Modal open={!!syncing} title="Sinkronkan katalog pemasok" onClose={()=>setSyncing(null)} busy={sync.processing} footer={<><button className="button secondary" type="button" onClick={()=>setSyncing(null)} disabled={sync.processing}>Batal</button><button type="submit" form="supplier-sync-form" className="button" disabled={sync.processing}>{sync.processing?'Menyinkronkan…':'Sinkronkan'}</button></>}>
            <form id="supplier-sync-form" className="form-stack" onSubmit={event=>{event.preventDefault();if(syncing)sync.post(base+'/sources/'+syncing.id+'/sync',{preserveScroll:true,onSuccess:()=>setSyncing(null)});}}><FormErrors errors={sync.errors}/><p>Perbarui katalog dari pemasok. Produk yang hilang dari respons valid akan dinonaktifkan.</p>{syncing?.kind==='digiflazz' && <label>Jenis katalog<select value={sync.data.mode} onChange={event=>sync.setData('mode',event.target.value)}><option value="prepaid">Prabayar / PPOB</option><option value="pasca">Pascabayar — admin layanan</option></select></label>}</form>
        </Modal>
        <ConfirmDialog open={!!removing} title="Putuskan sumber pemasok?" confirmLabel="Putuskan koneksi" danger busy={deletion.processing} onCancel={()=>setRemoving(null)} onConfirm={()=>{if(removing)deletion.delete(base+'/sources/'+removing.id,{preserveScroll:true,onSuccess:()=>setRemoving(null)});}}><p>Credential koneksi dihapus. Produk yang menggunakan sumber ini menjadi tidak tersedia sampai sumber dihubungkan dan disinkronkan kembali.</p></ConfirmDialog>
    </WorkspaceLayout>;
}
