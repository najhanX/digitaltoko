import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { ArrowUpRight, Layers, LoaderCircle, Search, ShoppingBag, X } from 'lucide-react';

type Result={id:string;name:string;subtitle:string;image?:string|null;url:string;price?:number;available?:boolean};
type Results={categories:Result[];products:Result[];pagination:{page:number;has_more:boolean}};
type SearchState={pages:Results[];page:number;hasMore:boolean};
const money=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);

export default function StorefrontSearch({preview=false,template='nebula',compact=false}: {preview?:boolean;template?:string;compact?:boolean}) {
    const page=usePage();
    const id=useId();
    const root=useRef<HTMLDivElement>(null),input=useRef<HTMLInputElement>(null),trigger=useRef<HTMLButtonElement>(null);
    const cache=useRef(new Map<string,{at:number;data:SearchState}>());
    const scroll=useRef<HTMLDivElement>(null),sentinel=useRef<HTMLDivElement>(null);
    const moreRequest=useRef<AbortController|null>(null);
    const scrollAnchor=useRef<{key:string;top:number}|null>(null);
    const [loadingMore,setLoadingMore]=useState(false),[moreError,setMoreError]=useState('');
    const [query,setQuery]=useState(''),[open,setOpen]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
    const [result,setResult]=useState<{term:string;data:SearchState}|null>(null),[active,setActive]=useState(-1);
    const term=query.trim();
    const ready=result?.term===term;
    const data=ready?result.data:{pages:[],page:0,hasMore:false};
    const categories=data.pages.flatMap(batch=>batch.categories),products=data.pages.flatMap(batch=>batch.products);
    const entries=[...categories,...products];
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
        setActive(-1);scrollAnchor.current=null;setError('');setMoreError('');setLoadingMore(false);moreRequest.current?.abort();moreRequest.current=null;
        scroll.current?.scrollTo({top:0});
        if(term.length<2){setBusy(false);setResult(null);return;}
        const controller=new AbortController();
        setBusy(true);
        const timer=setTimeout(async()=>{
            try {
                const saved=cache.current.get(term);
                let data:SearchState;
                if(saved && Date.now()-saved.at<30000)data=saved.data;
                else {
                    const batch=await fetchPage(term,1,controller.signal);
                    data={pages:[batch],page:1,hasMore:batch.pagination.has_more};
                }
                if(controller.signal.aborted)return;
                if(cache.current.size>=12)cache.current.delete(cache.current.keys().next().value!);
                cache.current.set(term,{at:Date.now(),data});
                setResult({term,data});
            }catch(exception){if(!controller.signal.aborted)setError(exception instanceof Error?exception.message:'Pencarian gagal. Coba lagi.');}
            finally{if(!controller.signal.aborted)setBusy(false);}
        },300);
        return()=>{clearTimeout(timer);controller.abort();moreRequest.current?.abort();moreRequest.current=null;};
    },[term,preview,template]);
    async function fetchPage(search:string,number:number,signal:AbortSignal):Promise<Results> {
        if(preview){
            const names=['Mobile Legends','Free Fire','PUBG Mobile','Telkomsel','Paket Data','Canva Pro','Spotify Premium'];
            const matches=names.filter(name=>name.toLowerCase().includes(search.toLowerCase()));
            return {categories:matches.slice((number-1)*5,number*5).map(name=>({id:'preview-'+names.indexOf(name),name,subtitle:'Kategori contoh',url:`/preview/storefront?template=${template}&page=catalog`})),products:[],pagination:{page:number,has_more:matches.length>number*5}};
        }
        const response=await fetch('/search?'+new URLSearchParams({q:search,page:String(number)}),{credentials:'same-origin',signal,headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error(response.status===429?'Pencarian terlalu sering. Tunggu sebentar, lalu coba lagi.':'Pencarian belum tersedia. Silakan coba lagi.');
        const batch=await response.json() as Results;
        if(!Array.isArray(batch.categories) || !Array.isArray(batch.products) || batch.pagination?.page!==number || typeof batch.pagination.has_more!=='boolean')throw new Error('Hasil pencarian belum dapat dibaca.');
        return batch;
    }
    async function loadMore(){
        if(!open || busy || !ready || !data.hasMore || moreRequest.current || moreError)return;
        const controller=new AbortController();moreRequest.current=controller;
        setLoadingMore(true);
        try {
            const [batch]=await Promise.all([fetchPage(term,data.page+1,controller.signal),new Promise(resolve=>window.setTimeout(resolve,300))]);
            if(controller.signal.aborted)return;
            const categoryIds=new Set(data.pages.flatMap(page=>page.categories.map(item=>item.id)));
            const productIds=new Set(data.pages.flatMap(page=>page.products.map(item=>item.id)));
            const unique={...batch,categories:batch.categories.filter(item=>!categoryIds.has(item.id)),products:batch.products.filter(item=>!productIds.has(item.id))};
            const updated={pages:[...data.pages,unique],page:batch.pagination.page,hasMore:batch.pagination.has_more};
            const container=scroll.current;
            if(container){
                const top=container.getBoundingClientRect().top;
                const visible=Array.from(container.querySelectorAll<HTMLElement>('[data-search-key]')).find(item=>item.getBoundingClientRect().bottom>top);
                if(visible)scrollAnchor.current={key:visible.dataset.searchKey!,top:visible.getBoundingClientRect().top};
            }
            setActive(previous=>{
                const selected=entries[previous];
                if(!selected)return -1;
                const next=[...updated.pages.flatMap(page=>page.categories),...updated.pages.flatMap(page=>page.products)];
                return next.findIndex(item=>item.id===selected.id && item.price===selected.price);
            });
            cache.current.set(term,{at:Date.now(),data:updated});
            setResult({term,data:updated});
        }catch(exception){if(!controller.signal.aborted)setMoreError(exception instanceof Error?exception.message:'Hasil berikutnya gagal dimuat.');}
        finally{if(moreRequest.current===controller){moreRequest.current=null;setLoadingMore(false);}}
    }
    useEffect(()=>{
        const target=sentinel.current,container=scroll.current;
        if(!open || busy || !ready || !data.hasMore || loadingMore || moreError || !target || !container)return;
        const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting))void loadMore();},{root:container,rootMargin:'0px 0px 64px 0px'});
        observer.observe(target);return()=>observer.disconnect();
    },[open,busy,ready,data.page,data.hasMore,loadingMore,moreError,term]);
    useLayoutEffect(()=>{
        const anchor=scrollAnchor.current,container=scroll.current;
        scrollAnchor.current=null;
        if(!anchor || !container)return;
        const item=Array.from(container.querySelectorAll<HTMLElement>('[data-search-key]')).find(item=>item.dataset.searchKey===anchor.key);
        if(item)container.scrollTop+=item.getBoundingClientRect().top-anchor.top;
    },[result]);
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
    function group(label:string,items:Result[],offset:number,key:string){return items.length>0 && <div key={key} role="group" aria-label={label} className="sf-navbar-search-group"><div className="sf-navbar-search-group-title">{label}<span>{items.length}</span></div>{items.map((item,index)=><Link key={label+item.id} role="option" data-search-key={label+':'+item.id} id={id+'-'+(offset+index)} aria-selected={active===offset+index} tabIndex={-1} className={'sf-navbar-search-result'+(active===offset+index?' selected':'')} href={item.url} onMouseEnter={()=>setActive(offset+index)} onClick={()=>setOpen(false)}>
        <span className="sf-navbar-search-art">{item.image?<img loading="lazy" src={item.image} alt="" width={36} height={36}/>:item.price!==undefined?<ShoppingBag size={18}/>:<Layers size={18}/>}</span>
        <span className="sf-navbar-search-copy"><strong>{item.name}</strong><small>{item.subtitle}{item.price!==undefined?' · '+money(item.price):''}{item.available===false?' · Tidak tersedia':''}</small></span><ArrowUpRight size={15} aria-hidden="true"/>
    </Link>)}</div>;}
    return <div ref={root} className={'sf-navbar-search'+(compact?' sf-navbar-search-compact':'')} onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget))setOpen(false);}}>
        {compact?<button ref={trigger} type="button" className="sf-navbar-search-trigger" aria-label="Buka pencarian toko" aria-expanded={open} onClick={()=>setOpen(value=>!value)}><Search size={18}/></button>:field}
        {open && <div className="sf-navbar-search-dropdown">
            {compact && field}
            <div ref={scroll} id={id+'-results'} role="listbox" aria-label="Hasil pencarian toko" aria-busy={busy || loadingMore} className="sf-navbar-search-results">
                {term.length<2?<p className="sf-navbar-search-status">Ketik minimal 2 karakter untuk mencari kategori atau produk.</p>:error?<p className="sf-navbar-search-status" role="alert">{error}</p>:busy || !ready?<p className="sf-navbar-search-status" role="status"><LoaderCircle size={17} className="sf-search-spinner"/>Mencari…</p>:entries.length?<>{group('Kategori',categories,0,'categories')}{group('Produk',products,categories.length,'products')}</>:<p className="sf-navbar-search-status" role="status">Tidak ada hasil untuk “{term}”. Coba kata kunci lain.</p>}
                {ready && !busy && !error && data.hasMore && <div ref={sentinel} className="sf-search-load-more" role="presentation">
                    {moreError?<div role="alert"><span>{moreError}</span><button type="button" onClick={()=>setMoreError('')}>Coba lagi</button></div>:<p role="status">{loadingMore && <LoaderCircle size={18} className="sf-search-spinner"/>}{loadingMore?'Memuat hasil lainnya…':'Gulir untuk hasil lainnya'}</p>}
                </div>}
                {ready && !busy && entries.length>0 && !data.hasMore && <p className="sf-search-end" role="status">Semua hasil yang cocok sudah ditampilkan.</p>}
            </div>
            <div className="sf-navbar-search-footnote">Pilih hasil untuk membuka kategori atau produk.</div>
        </div>}
    </div>;
}
