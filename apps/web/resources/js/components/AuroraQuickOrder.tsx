import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowRight, Check, ChevronLeft, ChevronRight, LoaderCircle, Smartphone, Ticket, Wifi, Zap } from 'lucide-react';
import { mobileNumber, mobileProvider, validMobileNumber, type MobileProvider } from './mobileProviders';
import type { PageMeta } from './ListControls';

type Product={id:string;name:string;price:number;available:boolean};
const services=[{id:'pulsa',label:'Pulsa',icon:Smartphone},{id:'data',label:'Paket data',icon:Wifi},{id:'voucher',label:'Voucher data',icon:Ticket}];
const money=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
export default function AuroraQuickOrder({providers,preview=false,available=true,title}:{title?:string;providers:MobileProvider[];preview?:boolean;available?:boolean}) {
    const [phone,setPhone]=useState(''),[manual,setManual]=useState(''),[service,setService]=useState('pulsa'),[page,setPage]=useState(1),[selected,setSelected]=useState(''),[retry,setRetry]=useState(0);
    const [result,setResult]=useState<{key:string;items:Product[];meta:PageMeta}|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
    const detected=mobileProvider(phone,providers),provider=manual || detected?.id || '',key=provider+':'+service+':'+page;
    const providerName=providers.find(item=>item.id===provider)?.name ?? '';
    const form=useForm({product_id:'',destination:''});
    useEffect(()=>{
        setSelected('');setError('');
        if(!provider || !available){setBusy(false);return;}
        const controller=new AbortController();setBusy(true);
        const timer=setTimeout(async()=>{
            try{
                if(preview){
                    const name=providerName;
                    setResult({key,items:[10000,20000,25000,50000,100000].map((amount,index)=>({id:'preview-'+index,name:service==='pulsa'?name+' '+amount.toLocaleString('id-ID'):service==='data'?name+' '+[2,5,10,20,40][index]+' GB':name+' Voucher '+[2,5,10,20,40][index]+' GB',price:amount+1000,available:true})),meta:{current_page:1,last_page:1,per_page:5,total:5,from:1,to:5}});
                }else{
                    const response=await fetch('/quick-order/products?'+new URLSearchParams({provider,service,page:String(page),per_page:'5'}),{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
                    if(!response.ok)throw new Error('Pilihan produk belum dapat dimuat. Coba kembali.');
                    const data=await response.json();if(!controller.signal.aborted)setResult({key,items:data.items,meta:data.pagination});
                }
            }catch(reason){if(!controller.signal.aborted)setError(reason instanceof Error?reason.message:'Produk belum dapat dimuat.');}
            finally{if(!controller.signal.aborted)setBusy(false);}
        },250);
        return()=>{clearTimeout(timer);controller.abort();};
    },[key,provider,service,page,preview,available,retry,providerName]);
    const items=result?.key===key?result.items:[],meta=result?.key===key?result.meta:null,product=items.find(item=>item.id===selected);
    const valid=available && validMobileNumber(phone) && product?.available && !busy;
    function number(value:string){if(mobileNumber(value).slice(0,4)!==mobileNumber(phone).slice(0,4)){setManual('');setPage(1);}setPhone(value);form.clearErrors();}
    return <section className="au-quick-order" id="quick-order" aria-label="Quick order produk digital">
        <div className="au-quick-heading"><span><Zap size={17}/>QUICK ORDER</span><h2>{title || <>Isi kebutuhanmu,<br/>mulai dari nomor tujuan.</>}</h2><p>Pilih layanan. Produk sesuai provider langsung tampil.</p></div>
        <form onSubmit={event=>{event.preventDefault();if(!valid || !product)return;if(preview){router.visit('/preview/storefront?template=aurora&page=order');return;}form.transform(()=>({product_id:product.id,destination:mobileNumber(phone)}));form.post('/quick-order/checkout');}}>
            <div className="au-quick-tabs" aria-label="Layanan quick order">{services.map(({id,label,icon:Icon})=><button key={id} type="button" aria-pressed={service===id} disabled={form.processing} className={service===id?'selected':''} onClick={()=>{setService(id);setPage(1);form.clearErrors();}}><Icon size={16}/>{label}</button>)}</div>
            <label className="au-quick-phone-label" htmlFor="aurora-quick-phone">Nomor HP tujuan</label><div className="au-quick-phone"><Smartphone size={19}/><input id="aurora-quick-phone" type="tel" autoComplete="tel" inputMode="tel" maxLength={30} value={phone} onChange={event=>number(event.target.value)} placeholder="08xxxxxxxxxx atau +62" required disabled={form.processing}/>{detected && !manual && <span><Check size={12}/>{detected.name}</span>}</div>
            <div className="au-quick-provider"><small>{manual?'Provider dipilih manual':detected?'Terdeteksi dari awalan nomor':'Provider akan terdeteksi otomatis'}</small><label><span className="sr-only">Provider quick order</span><select value={provider} disabled={form.processing} onChange={event=>{setManual(event.target.value);setPage(1);form.clearErrors();}}><option value="">Pilih provider</option>{providers.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></label></div>
            <div className="au-quick-products" aria-live="polite">
                {!available?<p className="au-quick-empty">Toko belum menerima pesanan.</p>:busy?<p className="au-quick-empty"><LoaderCircle size={19} className="game-identity-spinner"/>Memuat pilihan produk...</p>:error?<p className="au-quick-empty">{error}<button type="button" className="text-link" onClick={()=>setRetry(value=>value+1)}>Coba lagi</button></p>:!provider?<p className="au-quick-empty"><Smartphone size={23}/>Masukkan nomor atau pilih provider untuk melihat produk.</p>:!items.length?<p className="au-quick-empty">Belum ada produk {services.find(item=>item.id===service)?.label.toLowerCase()} untuk provider ini.</p>:<><div className="au-quick-nominals">{items.map(item=><button key={item.id} type="button" aria-pressed={selected===item.id} disabled={!item.available || form.processing} className={selected===item.id?'selected':''} onClick={()=>{setSelected(item.id);form.clearErrors();}}><strong>{item.name}</strong><span>{item.available?money(item.price):'Tidak tersedia'}{selected===item.id && <Check size={13}/>}</span></button>)}</div>{meta && meta.last_page>1 && <div className="au-quick-paging"><small>{meta.from}–{meta.to} dari {meta.total} produk</small><button type="button" aria-label="Produk quick order sebelumnya" disabled={meta.current_page<=1 || form.processing} onClick={()=>setPage(meta.current_page-1)}><ChevronLeft size={15}/></button><button type="button" aria-label="Produk quick order berikutnya" disabled={meta.current_page>=meta.last_page || form.processing} onClick={()=>setPage(meta.current_page+1)}><ChevronRight size={15}/></button></div>}</>}
            </div>
            {Object.values(form.errors).map(message=><p className="au-quick-error" role="alert" key={message}>{message}</p>)}
            {phone && !validMobileNumber(phone) && <small className="au-quick-hint">Lengkapi nomor tujuan sebelum melanjutkan.</small>}
            <div className="au-quick-total"><span>{preview?'Contoh total produk':'Harga produk'}</span><strong>{product?money(product.price):'—'}</strong></div><button type="submit" className="au-quick-submit" disabled={!valid || form.processing}>{form.processing?'Menyiapkan...':'Lanjutkan pesanan'}<ArrowRight size={16}/></button><small className="au-quick-hint">{preview?'Pratinjau dengan produk contoh.':'Nomor tujuan otomatis terisi di checkout. Kontak WhatsApp dan pembayaran dilengkapi pada langkah berikutnya.'}</small>
        </form>
    </section>;
}
