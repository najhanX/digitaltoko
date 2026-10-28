import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Search, RotateCcw, ChevronLeft, ChevronRight, SlidersHorizontal } from 'lucide-react';

export type PageMeta = {current_page:number;last_page:number;per_page:number;total:number;from:number|null;to:number|null};
export type ListFilters = Record<string,string|number>;
export type FilterChoice = {key:string;label:string;options:Record<string,string>};
export const serviceOptions = {bot:'Bot WhatsApp',web:'Web Topup',full:'Web + Bot'};
export { packageOptions as planOptions } from './packageCatalog';
export const invoiceStatuses = {pending:'Menunggu pembayaran',paid:'Lunas / terjadwal',applied:'Paket diterapkan',expired:'Kedaluwarsa',review:'Perlu pemeriksaan',refunded:'Dikembalikan'};

export function useServerList(filters:ListFilters, only:string[], prefix = '') {
    const page = usePage();
    const [busy,setBusy] = useState(false);
    function visit(values:ListFilters, number = 1) {
        const [path,query] = page.url.split('?');
        const data:Record<string,string|number> = Object.fromEntries(new URLSearchParams(query ?? ''));
        for(const [key,value] of Object.entries(values)) data[prefix+key] = value;
        data[prefix+'page'] = number;
        router.get(path,data,{only,preserveState:true,preserveScroll:true,onStart:()=>setBusy(true),onFinish:()=>setBusy(false)});
    }
    return {busy,apply:(values:ListFilters)=>visit(values),go:(number:number)=>visit(filters,number)};
}

export function FilterBar({filters,onApply,choices = [],placeholder = 'Cari data…',busy = false,sizes = [5,10,20,50],embedded=false,storefront=false,collapsibleChoices=false}:{
    filters:ListFilters;onApply:(filters:ListFilters)=>void;choices?:FilterChoice[];placeholder?:string;busy?:boolean;sizes?:number[];embedded?:boolean;storefront?:boolean;collapsibleChoices?:boolean;
}) {
    const [draft,setDraft] = useState(filters);
    const [mobileFiltersOpen,setMobileFiltersOpen] = useState(false);
    const serialized = JSON.stringify(filters);
    useEffect(()=>setDraft(JSON.parse(serialized)),[serialized]);
    function reset() {const empty:ListFilters={q:'',per_page:filters.per_page};for(const item of choices)empty[item.key]='';setDraft(empty);onApply(empty);}
    const choiceFields=choices.map(choice=><label className="list-filter-choice" key={choice.key}><span>{choice.label}</span><select aria-label={choice.label} value={draft[choice.key] ?? ''} onChange={event=>setDraft({...draft,[choice.key]:event.target.value})}><option value="">Semua</option>{Object.entries(choice.options).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>);
    const activeChoices=choices.filter(choice=>String(draft[choice.key]??'')!=='').length;
    const Container=embedded?'div':'form';
    const apply=()=>{if(!busy)onApply({...draft,q:String(draft.q ?? '').trim()});};
    return <Container className={'list-filters'+(storefront?' sf-catalog-filters':' panel-list-filters')+(mobileFiltersOpen?' mobile-filters-open':'')} role="search" onKeyDown={event=>{if(embedded && event.key==='Enter'){event.preventDefault();event.stopPropagation();apply();}}} onSubmit={event=>{event.preventDefault();apply();}}>
        {!storefront && <button type="button" className="button secondary mobile-filter-toggle" aria-expanded={mobileFiltersOpen} onClick={()=>setMobileFiltersOpen(value=>!value)}><SlidersHorizontal size={17}/>Filter{activeChoices>0 && <span className="mobile-filter-count">{activeChoices}</span>}</button>}
        <label className="list-search"><span>Cari</span><div><Search size={17}/><input type="search" aria-label={placeholder} placeholder={placeholder} maxLength={120} value={draft.q ?? ''} onChange={event=>setDraft({...draft,q:event.target.value})}/></div></label>
        {collapsibleChoices&&choices.length>0?<details className="list-extra-filters" open={mobileFiltersOpen || undefined}><summary>Filter lainnya{activeChoices>0&&<span className="pill">{activeChoices}</span>}</summary><div className="list-extra-fields">{choiceFields}</div></details>:choiceFields}
        <label className="list-page-size"><span>{storefront?'Tampilkan':'Per halaman'}</span><select aria-label="Per halaman" value={draft.per_page} onChange={event=>setDraft({...draft,per_page:Number(event.target.value)})}>{sizes.map(size=><option key={size} value={size}>{size}</option>)}</select></label>
        <div className="filter-actions"><button type={embedded?'button':'submit'} onClick={embedded?apply:undefined} className="button" disabled={busy}>{storefront && <Search size={16}/>}<span>{busy?'Memuat…':storefront?'Cari':'Terapkan'}</span></button>{(!storefront || !!String(draft.q ?? '') || !!String(filters.q ?? '')) && <button type="button" className="button secondary" aria-label={storefront?'Hapus pencarian':undefined} title={storefront?'Hapus pencarian':undefined} disabled={busy} onClick={reset}><RotateCcw size={15}/>{!storefront && 'Reset'}</button>}</div>
    </Container>;
}

export function Pagination({meta,onPage,busy = false,label = 'Halaman daftar',compact = false}:{meta:PageMeta;onPage:(number:number)=>void;busy?:boolean;label?:string;compact?:boolean}) {
    const first = Math.max(1,Math.min(meta.current_page-2,meta.last_page-4));
    const numbers = Array.from({length:Math.min(5,meta.last_page)},(_,index)=>first+index);
    return <div className={'list-pagination '+(compact?'pagination-top':'')} aria-busy={busy}><p role={compact?undefined:'status'}>{compact ? `Halaman ${meta.current_page} dari ${meta.last_page} · ${meta.total} data` : meta.total ? `${meta.from}–${meta.to} dari ${meta.total} data` : '0 data'}</p>
        <nav aria-label={label}><button type="button" aria-label="Halaman sebelumnya" disabled={busy || meta.current_page<=1} onClick={()=>onPage(meta.current_page-1)}><ChevronLeft size={16}/>{compact && 'Sebelumnya'}</button>
            {!compact && <>{first>1 && <><button type="button" disabled={busy} onClick={()=>onPage(1)}>1</button>{first>2 && <span>…</span>}</>}
                {numbers.map(number=><button type="button" key={number} className={number===meta.current_page?'selected':''} aria-current={number===meta.current_page?'page':undefined} disabled={busy} onClick={()=>onPage(number)}>{number}</button>)}
                {numbers.at(-1)!<meta.last_page && <>{numbers.at(-1)!<meta.last_page-1 && <span>…</span>}<button type="button" disabled={busy} onClick={()=>onPage(meta.last_page)}>{meta.last_page}</button></>}
            </>}
            <button type="button" aria-label="Halaman berikutnya" disabled={busy || meta.current_page>=meta.last_page} onClick={()=>onPage(meta.current_page+1)}>{compact && 'Berikutnya'}<ChevronRight size={16}/></button>
        </nav>
    </div>;
}

export function useLocalList<T>(items:T[],matches:(item:T,filters:ListFilters)=>boolean,defaultSize = 5) {
    const [filters,setFilters] = useState<ListFilters>({q:'',status:'',per_page:defaultSize});
    const [page,setPage] = useState(1);
    const filtered = useMemo(()=>items.filter(item=>matches(item,filters)),[items,filters,matches]);
    const size = Number(filters.per_page);
    const last = Math.max(1,Math.ceil(filtered.length/size));
    const current = Math.min(page,last);
    return {filters,apply:(values:ListFilters)=>{setFilters(values);setPage(1);},go:setPage,
        rows:filtered.slice((current-1)*size,current*size),meta:{current_page:current,last_page:last,per_page:size,total:filtered.length,from:filtered.length?(current-1)*size+1:null,to:filtered.length?Math.min(current*size,filtered.length):null}};
}
