import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Wallet, ArrowDownLeft, RefreshCw } from 'lucide-react';
import type { WorkspaceContext } from '../types';
import { rupiah } from './CatalogForms';
type Balance={simulated?:boolean;status:string;amount?:number;observed_at?:string;message?:string};
export default function SupplierBalances({staff}:{staff:boolean}) {
    const workspace=usePage().props.workspace as WorkspaceContext|null;
    const storeId=workspace?.currentStoreId;
    const sandbox=workspace?.stores.find(store=>store.id===storeId)?.sandbox;
    const allowed=!staff || (workspace?.permissions ?? []).includes('suppliers.balance');
    const [balances,setBalances]=useState<Record<string,Balance>>({}),[busy,setBusy]=useState(false),[refresh,setRefresh]=useState(0);
    useEffect(()=>{
        setBalances({});
        if(!storeId || !allowed)return;
        const controller=new AbortController();
        async function load(){
            setBusy(true);
            try {
                const response=await fetch('/stores/'+storeId+'/balances',{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
                if(!response.ok)throw new Error();
                const result=await response.json();if(!controller.signal.aborted)setBalances(result.data);
            } catch {if(!controller.signal.aborted)setBalances({digiflazz:{status:'error'},hanshop:{status:staff?'restricted':'error'}});}
            finally {if(!controller.signal.aborted)setBusy(false);}
        }
        void load();const timer=setInterval(()=>{if(!document.hidden)void load();},120000);
        return()=>{controller.abort();clearInterval(timer);};
    },[storeId,sandbox,allowed,refresh,staff]);
    const labels:Record<string,string>={connected:'Terhubung',disconnected:'Belum terhubung',restricted:'Akses dibatasi',inactive:'Paket tidak aktif',unsupported:'Tidak termasuk paket',error:'Belum dapat dibaca'};
    return <section className="supplier-balances" aria-label="Saldo pemasok">{['digiflazz','hanshop'].map(kind=>{
        const restricted=staff && (kind==='hanshop' || !allowed);
        const balance=balances[kind];
        const status=restricted?'restricted':!storeId?'choose':balance?.status ?? 'disconnected';
        return <article className={'supplier-balance-card '+(kind==='hanshop'?'hanshop':'')} key={kind}>
            <div className="supplier-balance-heading"><span className="supplier-icon"><Wallet size={21}/></span><div><h2>Saldo {kind==='digiflazz'?'Digiflazz':'Hanshop'}</h2><small>{kind==='digiflazz'?'Saldo pemasok milik toko':'Saldo integrasi akun owner'}</small></div><span className="pill">{!restricted && busy?'Memuat…':balance?.simulated?'Saldo simulasi':status==='choose'?'Pilih toko':labels[status]}</span></div>
            <strong className="supplier-amount">{status==='connected' && balance?.amount!==undefined?rupiah(balance.amount):'—'}</strong>
            <p>{status==='choose'?'Pilih toko pada sidebar untuk melihat sumber dan saldonya.':status==='restricted'?kind==='hanshop'?'Saldo Hanshop bersama hanya ditampilkan kepada owner.':'Owner belum memberi hak melihat saldo Digiflazz.':balance?.simulated?'Saldo uji, bukan saldo asli pemasok. Tidak digunakan untuk transaksi produksi.':balance?.message ?? (status==='connected'?(kind==='hanshop'?'Saldo akun Hanshop owner, dipakai bersama pada toko yang terhubung.':'Saldo dari akun Digiflazz toko.'):'Hubungkan sumber melalui menu Sumber produk pada toko ini.')}</p>
            <span className="supplier-caption"><ArrowDownLeft size={15}/>{kind==='digiflazz'?'Sumber transaksi PPOB':'Sumber produk API Hanshop'}</span>
            {balance?.observed_at && status==='connected' && <small>Snapshot: {new Date(balance.observed_at).toLocaleString('id-ID')}</small>}
            {!restricted && storeId && <button type="button" className="text-link" disabled={busy} onClick={()=>setRefresh(value=>value+1)}><RefreshCw size={13}/>Perbarui saldo</button>}
        </article>;
    })}</section>;
}
