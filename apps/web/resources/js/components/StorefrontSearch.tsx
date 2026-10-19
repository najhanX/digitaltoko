import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { ArrowUpRight, Layers, LoaderCircle, Search, ShoppingBag, X } from 'lucide-react';

type Result={id:string;name:string;subtitle:string;image?:string|null;url:string;price?:number;available?:boolean};
type Results={categories:Result[];products:Result[]};
const money=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);

export default function StorefrontSearch({preview=false,template='nebula',compact=false}: {preview?:boolean;template?:string;compact?:boolean}) {
    const page=usePage();
    const id=useId();
    const root=useRef<HTMLDivElement>(null),input=useRef<HTMLInputElement>(null),trigger=useRef<HTMLButtonElement>(null);
    const cache=useRef(new Map<string,{at:number;data:Results}>());
    const [query,setQuery]=useState(''),[open,setOpen]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
    const [result,setResult]=useState<{term:string;data:Results}|null>(null),[active,setActive]=useState(-1);
    const term=query.trim();
    const ready=result?.term===term;
    const data=ready?result.data:{categories:[],products:[]};
    const entries=[...data.categories,...data.products];
    useEffect(()=>{setOpen(false);setQuery('');setActive(-1);},[page.url]);
    useEffect(()=>{
        if(open && compact)input.current?.focus();
    },[open,compact]);
    useEffect(()=>{
        const outside=(event:PointerEvent)=>{if(event.target instanceof Node && !root.current?.contains(event.target))setOpen(false);};
        document.addEventListener('pointerdown',outside);
        return()=>document.removeEventListener('pointerdown',outside);
    },[]);
    useEffect(()=>{
        setActive(-1);setError('');
        if(term.length<2){setBusy(false);setResult(null);return;}
        const controller=new AbortController();
        setBusy(true);
        const timer=setTimeout(async()=>{
            try {
                const saved=cache.current.get(term);
                let data:Results;
                if(saved && Date.now()-saved.at<30000)data=saved.data;
                else if(preview) {
                    const names=['Mobile Legends','Free Fire','PUBG Mobile','Telkomsel','Paket Data','Canva Pro','Spotify Premium'];
                    data={categories:names.filter(name=>name.toLowerCase().includes(term.toLowerCase())).slice(0,5).map((name,index)=>({id:'preview-'+index,name,subtitle:'Kategori contoh',url:`/preview/storefront?template=${template}&page=catalog`})),products:[]};
                } else {
                    const response=await fetch('/search?'+new URLSearchParams({q:term}),{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
                    if(!response.ok)throw new Error(response.status===429?'Pencarian terlalu sering. Tunggu sebentar, lalu coba lagi.':'Pencarian belum tersedia. Silakan coba lagi.');
                    data=await response.json();
                    if(!Array.isArray(data.categories) || !Array.isArray(data.products))throw new Error('Hasil pencarian belum dapat dibaca.');
                }
                if(controller.signal.aborted)return;
                if(cache.current.size>=12)cache.current.delete(cache.current.keys().next().value!);
                cache.current.set(term,{at:Date.now(),data});
                setResult({term,data});
            }catch(exception){if(!controller.signal.aborted)setError(exception instanceof Error?exception.message:'Pencarian gagal. Coba lagi.');}
            finally{if(!controller.signal.aborted)setBusy(false);}
        },300);
        return()=>{clearTimeout(timer);controller.abort();};
    },[term,preview,template]);
    useEffect(()=>{root.current?.querySelector('[role=option][aria-selected=true]')?.scrollIntoView({block:'nearest'});},[active]);
    function close(){setOpen(false);setActive(-1);if(compact)trigger.current?.focus();}
    const field=<form className="sf-search sf-navbar-search-field" role="search" onSubmit={event=>{event.preventDefault();setOpen(true);input.current?.focus();}}>
        <Search size={17} aria-hidden="true"/>
        <input ref={input} value={query} maxLength={80} type="search" role="combobox" aria-label="Cari kategori atau produk" aria-expanded={open} aria-autocomplete="list" aria-controls={id+'-results'} aria-activedescendant={open && active>=0?id+'-'+active:undefined} autoComplete="off" placeholder="Cari kategori atau produk…" onFocus={()=>setOpen(true)} onChange={event=>{setQuery(event.target.value);setOpen(true);}} onKeyDown={event=>{
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();}
            else if(event.key==='ArrowDown' || event.key==='ArrowUp'){
                event.preventDefault();setOpen(true);if(entries.length)setActive(value=>event.key==='ArrowDown'?Math.min(value+1,entries.length-1):Math.max(value-1,0));
            }else if(event.key==='Enter' && open && active>=0 && entries[active]){event.preventDefault();router.visit(entries[active].url);setOpen(false);}
        }}/>
        {query && <button type="button" className="sf-navbar-search-clear" aria-label="Hapus pencarian" onClick={()=>{setQuery('');input.current?.focus();}}><X size={15}/></button>}
        <button type="submit" className="sf-navbar-search-submit" aria-label="Tampilkan hasil pencarian">Cari</button>
    </form>;
    function group(label:string,items:Result[],offset:number){return items.length>0 && <div role="group" aria-label={label} className="sf-navbar-search-group"><div className="sf-navbar-search-group-title">{label}<span>{items.length}</span></div>{items.map((item,index)=><Link key={label+item.id} role="option" id={id+'-'+(offset+index)} aria-selected={active===offset+index} tabIndex={-1} className={'sf-navbar-search-result'+(active===offset+index?' selected':'')} href={item.url} onMouseEnter={()=>setActive(offset+index)} onClick={()=>setOpen(false)}>
        <span className="sf-navbar-search-art">{item.image?<img loading="lazy" src={item.image} alt="" width={36} height={36}/>:item.price!==undefined?<ShoppingBag size={18}/>:<Layers size={18}/>}</span>
        <span className="sf-navbar-search-copy"><strong>{item.name}</strong><small>{item.subtitle}{item.price!==undefined?' · '+money(item.price):''}{item.available===false?' · Tidak tersedia':''}</small></span><ArrowUpRight size={15} aria-hidden="true"/>
    </Link>)}</div>;}
    return <div ref={root} className={'sf-navbar-search'+(compact?' sf-navbar-search-compact':'')} onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget))setOpen(false);}}>
        {compact?<button ref={trigger} type="button" className="sf-navbar-search-trigger" aria-label="Buka pencarian toko" aria-expanded={open} onClick={()=>setOpen(value=>!value)}><Search size={18}/></button>:field}
        {open && <div className="sf-navbar-search-dropdown">
            {compact && field}
            <div id={id+'-results'} role="listbox" aria-label="Hasil pencarian toko" aria-busy={busy} className="sf-navbar-search-results">
                {term.length<2?<p className="sf-navbar-search-status">Ketik minimal 2 karakter untuk mencari kategori atau produk.</p>:error?<p className="sf-navbar-search-status" role="alert">{error}</p>:busy || !ready?<p className="sf-navbar-search-status" role="status"><LoaderCircle size={17} className="sf-search-spinner"/>Mencari…</p>:entries.length?<>{group('Kategori',data.categories,0)}{group('Produk',data.products,data.categories.length)}</>:<p className="sf-navbar-search-status" role="status">Tidak ada hasil untuk “{term}”. Coba kata kunci lain.</p>}
            </div>
            <div className="sf-navbar-search-footnote">Pilih hasil untuk membuka kategori atau produk.</div>
        </div>}
    </div>;
}
