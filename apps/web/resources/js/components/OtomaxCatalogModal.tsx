import {router} from '@inertiajs/react';
import {useEffect,useRef,useState} from 'react';
import {CheckCircle2,RefreshCw,Link2} from 'lucide-react';
import Modal from './Modal';
import CatalogSelect from './CatalogSelect';
import {rupiah} from './CatalogForms';

type Profile={headers:{sku:string;name:string;cost:string;status:string};open_values:string;closed_values:string;service:string};
type Config={url:string;automatic:boolean;interval:number;profile:Profile};
type Sample={sku:string;name:string;category:string;cost:number;available:boolean};
type Preview={id:string;summary:{valid:number;new:number;price_changed:number;missing:number;unavailable:number;needs_confirmation:boolean};sample:Sample[];observed_at:string};
const defaultProfile:Profile={headers:{sku:'Kode',name:'Keterangan',cost:'Harga',status:'Status'},open_values:'Open,Aktif,Ready,Tersedia',closed_values:'Close,Closed,Gangguan,Nonaktif,Kosong,Habis',service:'Produk digital'};
async function read(url:string,body?:object){
 const csrf=document.cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='));
 const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json','X-XSRF-TOKEN':csrf?decodeURIComponent(csrf.slice(11)):''}:{})},body:body?JSON.stringify(body):undefined});
 const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message||'Daftar harga belum dapat diperiksa.');return data;
}
export default function OtomaxCatalogModal({base,connection,onClose}:{base:string;connection:{id:string;name?:string}|null;onClose:()=>void}){
 const [config,setConfig]=useState<Config>({url:'',automatic:true,interval:15,profile:defaultProfile});
 const [configured,setConfigured]=useState(false),[host,setHost]=useState(''),[busy,setBusy]=useState(false),[loading,setLoading]=useState(false),[error,setError]=useState(''),[preview,setPreview]=useState<Preview|null>(null),[acceptMissing,setAcceptMissing]=useState(false),[copyOption,setCopyOption]=useState<{id:string;name:string}|null>(null);
 const revision=useRef(0);
 const endpoint=base+'/connections/'+connection?.id+'/catalog';
 useEffect(()=>{if(!connection)return;const current=++revision.current;setPreview(null);setError('');setLoading(true);setAcceptMissing(false);setCopyOption(null);setConfig({url:'',automatic:true,interval:15,profile:defaultProfile});
  void read(endpoint).then(data=>{if(current!==revision.current)return;setConfigured(data.configured);setHost(data.host??'');setConfig({url:'',automatic:data.configured?data.automatic:true,interval:data.interval,profile:data.profile});}).catch(e=>{if(current===revision.current)setError((e as Error).message);}).finally(()=>{if(current===revision.current)setLoading(false);});
  return()=>{revision.current++;};
 },[connection?.id,endpoint]);
 function change(patch:Partial<Config>){setConfig(previous=>({...previous,...patch}));setPreview(null);setAcceptMissing(false);setError('');}
 async function test(){setBusy(true);setError('');setPreview(null);try{setPreview(await read(endpoint+'/preview',config));}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 async function copy(value:{id:string;name:string}){setCopyOption(value);setBusy(true);setError('');try{const data=await read(base+'/connections/'+value.id+'/catalog');change({profile:data.profile});}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 function save(){if(!preview)return;setBusy(true);setError('');router.post(endpoint,{preview_id:preview.id,accept_missing:acceptMissing},{preserveScroll:true,onSuccess:onClose,onError:errors=>setError(Object.values(errors).join(' ')),onFinish:()=>setBusy(false)});}
 function pause(){setBusy(true);router.post(endpoint+'/pause',{}, {preserveScroll:true,onSuccess:onClose,onError:errors=>setError(Object.values(errors).join(' ')),onFinish:()=>setBusy(false)});}
 return <Modal open={!!connection} title="Daftar harga supplier" description={connection?.name} wide busy={busy||loading} onClose={onClose} footer={<>
  {configured&&<button type="button" className="button secondary" disabled={busy||loading} onClick={pause}>Hentikan otomatis</button>}
  <button type="button" className="button secondary" disabled={busy||loading||(!config.url&&!configured)} onClick={()=>void test()}><RefreshCw size={16} className={busy?'spin':undefined}/>{busy?'Memproses…':'Uji URL'}</button>
  <button type="button" className="button" disabled={busy||loading||!preview||(preview.summary.needs_confirmation&&!acceptMissing)} onClick={save}><CheckCircle2 size={16}/>Simpan & sinkronkan</button>
 </>}>
  {loading?<p role="status">Memuat pengaturan daftar harga…</p>:<div className="form-stack otomax-url-settings">
   {error&&<p role="alert" className="feedback error">{error}</p>}
   <label><span className="otomax-url-label"><Link2 size={16}/>URL daftar ALL produk</span><input type="url" autoComplete="off" spellCheck={false} placeholder={configured?'Kosong = pertahankan URL tersimpan':'https://supplier.example/harga.js.php?id=…'} value={config.url} disabled={busy} onChange={e=>change({url:e.target.value})}/>{configured&&<small className="field-help">URL tersimpan: {host} · parameter akses disembunyikan.</small>}</label>
   <div className="otomax-form-grid"><label className="otomax-check"><input type="checkbox" checked={config.automatic} disabled={busy} onChange={e=>change({automatic:e.target.checked})}/>Sinkronisasi otomatis</label><label>Interval pembaruan<select value={config.interval} disabled={busy} onChange={e=>change({interval:Number(e.target.value)})}>{[5,15,30,60].map(value=><option key={value} value={value}>{value} menit</option>)}</select></label></div>
   <p className="field-help">Produk yang hilang dari daftar valid menjadi tidak tersedia. Harga jual manual dan pengaturan katalog toko tetap dipertahankan.</p>
   <details className="otomax-details"><summary>Format pembacaan · Webreport HTML</summary><div className="form-stack">
    <CatalogSelect label="Salin format dari supplier lain" value={copyOption} url={base+'/options?type=connection'} onChange={value=>void copy(value)} disabled={busy}/>
    <div className="otomax-form-grid">{Object.entries({sku:'Kolom kode',name:'Kolom nama/keterangan',cost:'Kolom harga beli',status:'Kolom status'}).map(([field,label])=><label key={field}>{label}<input maxLength={50} value={config.profile.headers[field as keyof Profile['headers']]} disabled={busy} onChange={e=>change({profile:{...config.profile,headers:{...config.profile.headers,[field]:e.target.value}}})}/></label>)}
     <label>Status tersedia<input value={config.profile.open_values} disabled={busy} onChange={e=>change({profile:{...config.profile,open_values:e.target.value}})}/></label><label>Status tidak tersedia<input value={config.profile.closed_values} disabled={busy} onChange={e=>change({profile:{...config.profile,closed_values:e.target.value}})}/></label><label>Layanan sumber bawaan<input maxLength={100} value={config.profile.service} disabled={busy} onChange={e=>change({profile:{...config.profile,service:e.target.value}})}/></label>
    </div></div></details>
   {preview&&<section className="otomax-url-preview"><header><h3>Hasil pembacaan</h3><span className="field-help">{new Date(preview.observed_at).toLocaleString('id-ID')}</span></header>
    <div className="otomax-sync-stats">{Object.entries({'Produk':preview.summary.valid,'Baru':preview.summary.new,'Harga berubah':preview.summary.price_changed,'Hilang':preview.summary.missing}).map(([label,count])=><div key={label}><span>{label}</span><strong>{count}</strong></div>)}</div>
    {preview.summary.needs_confirmation&&<label className="feedback warning otomax-check"><input type="checkbox" checked={acceptMissing} onChange={e=>setAcceptMissing(e.target.checked)}/>Saya sudah memeriksa penurunan jumlah produk dan menyetujui {preview.summary.missing} produk hilang menjadi tidak tersedia.</label>}
    <div className="table-scroll otomax-url-sample"><table><thead><tr><th>Produk / kode</th><th>Kelompok</th><th>Harga beli</th><th>Status</th></tr></thead><tbody>{preview.sample.map(row=><tr key={row.sku}><td><strong>{row.name}</strong><small>{row.sku}</small></td><td>{row.category}</td><td className="table-cell-center">{rupiah(row.cost)}</td><td className="table-cell-center"><span className={'store-status '+(row.available?'active':'expired')}>{row.available?'Tersedia':'Tidak tersedia'}</span></td></tr>)}</tbody></table></div>
    <p className="field-help">Pratinjau maksimal 50 produk. Data belum diterapkan sebelum Anda menyimpan.</p>
   </section>}
  </div>}
 </Modal>;
}
