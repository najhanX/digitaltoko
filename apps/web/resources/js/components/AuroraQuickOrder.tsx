import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowRight, Check, LoaderCircle, Smartphone, Ticket, Wifi, Zap } from 'lucide-react';
import { mobileNumber, mobileProvider, validMobileNumber, type MobileProvider } from './mobileProviders';
import type { PageMeta } from './ListControls';
import PickerDropdown from './PickerDropdown';

type Product={id:string;name:string;price:number;available:boolean};
const services=[{id:'pulsa',label:'Pulsa',icon:Smartphone},{id:'data',label:'Paket data',icon:Wifi},{id:'voucher',label:'Voucher data',icon:Ticket}];
const money=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
export default function AuroraQuickOrder({providers,preview=false,available=true,title}:{title?:string;providers:MobileProvider[];preview?:boolean;available?:boolean}) {
    const [phone,setPhone]=useState(''),[manual,setManual]=useState(''),[service,setService]=useState('pulsa'),[selected,setSelected]=useState(''),[retry,setRetry]=useState(0);
    const [result,setResult]=useState<{key:string;items:Product[];meta:PageMeta}|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
    const detected=mobileProvider(phone,providers),provider=manual || detected?.id || '',key=provider+':'+service;
    const providerName=providers.find(item=>item.id===provider)?.name ?? '';
    const moreRequest=useRef<AbortController|null>(null);
    const [loadingMore,setLoadingMore]=useState(false),[moreError,setMoreError]=useState('');
    const form=useForm({product_id:'',destination:''});
    useEffect(()=>{
        setSelected('');setError('');setMoreError('');setLoadingMore(false);moreRequest.current?.abort();moreRequest.current=null;
        if(!provider || !available){setBusy(false);return;}
        const controller=new AbortController();setBusy(true);
        const timer=setTimeout(async()=>{
            try{
                const data=await fetchProducts(1,controller.signal);
                if(!controller.signal.aborted)setResult({key,items:data.items,meta:data.pagination});
            }catch(reason){if(!controller.signal.aborted)setError(reason instanceof Error?reason.message:'Produk belum dapat dimuat.');}
            finally{if(!controller.signal.aborted)setBusy(false);}
        },250);
        return()=>{clearTimeout(timer);controller.abort();moreRequest.current?.abort();moreRequest.current=null;};
    },[key,provider,service,preview,available,retry,providerName]);
    const items=result?.key===key?result.items:[],meta=result?.key===key?result.meta:null,product=items.find(item=>item.id===selected);
    async function fetchProducts(page:number,signal:AbortSignal):Promise<{items:Product[];pagination:PageMeta}>{
        if(preview){
            const amounts=[5000,10000,15000,20000,25000,30000,40000,50000,60000,75000,100000,150000];
            const from=(page-1)*5;
            return {items:amounts.slice(from,from+5).map((amount,index)=>({id:'preview-'+(from+index),name:service==='pulsa'?providerName+' '+amount.toLocaleString('id-ID'):providerName+(service==='voucher'?' Voucher ':' ')+(from+index+1)*2+' GB',price:amount+1000,available:true})),pagination:{current_page:page,last_page:Math.ceil(amounts.length/5),per_page:5,total:amounts.length,from:from+1,to:Math.min(from+5,amounts.length)}};
        }
        const response=await fetch('/quick-order/products?'+new URLSearchParams({provider,service,page:String(page),per_page:'5'}),{credentials:'same-origin',headers:{Accept:'application/json'},signal});
        if(!response.ok)throw new Error('Pilihan produk belum dapat dimuat. Coba kembali.');
        const data=await response.json();
        if(!Array.isArray(data.items) || data.pagination?.current_page!==page)throw new Error('Pilihan produk belum dapat dibaca.');
        return data;
    }
    async function loadMore(retry=false){
        if(!meta || meta.current_page>=meta.last_page || busy || moreRequest.current || form.processing || (moreError && !retry))return;
        const controller=new AbortController();moreRequest.current=controller;
        setLoadingMore(true);setMoreError('');
        try{
            const [data]=await Promise.all([fetchProducts(meta.current_page+1,controller.signal),new Promise(resolve=>window.setTimeout(resolve,500))]);
            if(controller.signal.aborted)return;
            setResult(previous=>{
                if(previous?.key!==key)return previous;
                const ids=new Set(previous.items.map(item=>item.id));
                return {key,items:[...previous.items,...data.items.filter(item=>!ids.has(item.id))],meta:data.pagination};
            });
        }catch(reason){if(!controller.signal.aborted)setMoreError(reason instanceof Error?reason.message:'Produk berikutnya belum dapat dimuat.');}
        finally{if(moreRequest.current===controller){moreRequest.current=null;setLoadingMore(false);}}
    }
    const valid=available && validMobileNumber(phone) && product?.available && !busy;
    function number(value:string){if(mobileNumber(value).slice(0,4)!==mobileNumber(phone).slice(0,4)){setManual('');}setPhone(value);form.clearErrors();}
    return <section className="au-quick-order" id="quick-order" aria-label="Quick order produk digital">
        <div className="au-quick-heading"><Zap size={19}/><h2>{title || 'Pesan cepat'}</h2></div>
        <form onSubmit={event=>{event.preventDefault();if(!valid || !product)return;if(preview){router.visit('/preview/storefront?template=aurora&page=order');return;}form.transform(()=>({product_id:product.id,destination:mobileNumber(phone)}));form.post('/quick-order/checkout');}}>
            <div className="au-quick-tabs" aria-label="Layanan quick order">{services.map(({id,label,icon:Icon})=><button key={id} type="button" aria-pressed={service===id} disabled={form.processing} className={service===id?'selected':''} onClick={()=>{setService(id);form.clearErrors();}}><Icon size={16}/>{label}</button>)}</div>
            <div className="au-quick-inputs">
                <div><label className="au-quick-phone-label" htmlFor="aurora-quick-phone">Nomor HP tujuan</label><div className="au-quick-phone"><Smartphone size={17}/><input id="aurora-quick-phone" type="tel" autoComplete="tel" inputMode="tel" maxLength={30} value={phone} onChange={event=>number(event.target.value)} placeholder="08xxxxxxxxxx" required disabled={form.processing}/></div></div>
                <label className="au-quick-provider"><span>Provider</span><select aria-label="Provider quick order" value={provider} disabled={form.processing} onChange={event=>{setManual(event.target.value);form.clearErrors();}}><option value="">Otomatis</option>{providers.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            </div>
            <div className="au-quick-products" aria-live="polite">
                {!available?<p className="au-quick-empty">Toko belum menerima pesanan.</p>:busy?<p className="au-quick-empty"><LoaderCircle size={19} className="game-identity-spinner"/>Memuat pilihan produk...</p>:error?<p className="au-quick-empty">{error}<button type="button" className="button secondary small" onClick={()=>setRetry(value=>value+1)}>Coba lagi</button></p>:!provider?<p className="au-quick-empty">Isi nomor untuk melihat pilihan produk.</p>:!items.length?<p className="au-quick-empty">Belum ada produk {services.find(item=>item.id===service)?.label.toLowerCase()} untuk provider ini.</p>:<div className="au-quick-product-picker"><PickerDropdown label="Produk / nominal" summary={product?product.name+' · '+money(product.price):'Pilih produk'}>
                    <div className="au-quick-options" role="radiogroup" aria-label="Produk quick order" aria-busy={loadingMore} onScroll={event=>{const list=event.currentTarget;if(list.scrollHeight-list.scrollTop-list.clientHeight<=24)void loadMore();}}>
                        {items.map(item=><label className={'au-quick-option'+(selected===item.id?' selected':'')+(!item.available?' unavailable':'')} key={item.id}><input type="radio" name="quick-order-product" checked={selected===item.id} disabled={!item.available || form.processing} onChange={()=>{setSelected(item.id);form.clearErrors();}}/><span><strong>{item.name}</strong><small>{item.available?money(item.price):'Tidak tersedia'}</small></span>{selected===item.id && <Check size={16}/>}</label>)}
                        {loadingMore?<div className="au-quick-load-more" role="status"><LoaderCircle size={17} className="sf-search-spinner"/>Memuat produk lainnya…</div>:moreError?<div className="au-quick-load-more" role="alert"><span>{moreError}</span><button type="button" className="button secondary small" onClick={()=>void loadMore(true)}>Coba lagi</button></div>:<div className="au-quick-load-more">{meta && meta.current_page<meta.last_page?'Gulir untuk produk lainnya':items.length+' produk ditampilkan'}</div>}
                    </div>
                </PickerDropdown></div>}
            </div>
            {Object.values(form.errors).map(message=><p className="au-quick-error" role="alert" key={message}>{message}</p>)}
            {phone && !validMobileNumber(phone) && <small className="au-quick-hint">Lengkapi nomor tujuan sebelum melanjutkan.</small>}
            <div className="au-quick-footer">{product && <div className="au-quick-total"><span>Total produk</span><strong>{money(product.price)}</strong></div>}<button type="submit" className="au-quick-submit" disabled={!valid || form.processing}>{form.processing?'Menyiapkan...':'Lanjutkan'}<ArrowRight size={16}/></button></div>
        </form>
    </section>;
}
