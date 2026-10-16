import { useEffect, useState } from 'react';
import { Pagination, type PageMeta } from './ListControls';
export const rupiah=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
export const sourceLabels:Record<string,string>={digiflazz:'Digiflazz',hanshop:'Hanshop',stock:'Stok sendiri'};
export const kindLabels:Record<string,string>={ppob:'Prabayar / PPOB',premium:'Aplikasi premium',postpaid:'Tagihan pascabayar'};
export type CatalogStore={id:string;name:string;status:string;plan?:string};
export type MappingOption={id:string;name:string;sku:string;mode:string;cost:number;kind:string;source:string;allowed:boolean};
export function FormErrors({errors}:{errors:Record<string,string|undefined>}) {
    const values=Object.values(errors).filter(Boolean);
    return values.length?<div className="feedback error" role="alert">{[...new Set(values)].map(message=><p key={message}>{message}</p>)}</div>:null;
}
type Option={id:string;name:string;code?:string;sku?:string;cost?:number;source?:string;allowed?:boolean;kind?:string;mode?:string};
export function CatalogPicker({url,enabled,selected,onToggle,multiple=true,kind,searchLabel='Cari pilihan produk',emptyText='Tidak ada pilihan yang cocok.',maxSelected=20}:{url:string;enabled:boolean;selected:Option[];onToggle:(option:Option)=>void;multiple?:boolean;kind?:string;searchLabel?:string;emptyText?:string;maxSelected?:number}) {
    const [query,setQuery]=useState(''),[search,setSearch]=useState(''),[page,setPage]=useState(1);
    const [rows,setRows]=useState<Option[]>([]),[meta,setMeta]=useState<PageMeta|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[retry,setRetry]=useState(0);
    useEffect(()=>{
        if(!enabled)return;
        const controller=new AbortController();setBusy(true);setError('');
        const parameters=new URLSearchParams({q:search,page:String(page),...(kind?{kind}:{})});
        fetch(url+(url.includes('?')?'&':'?')+parameters,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
            .then(async response=>{if(!response.ok)throw new Error();return response.json();})
            .then(data=>{setRows(data.items);setMeta(data.pagination);})
            .catch(()=>{if(!controller.signal.aborted)setError('Pilihan belum dapat dimuat. Coba kembali.');})
            .finally(()=>{if(!controller.signal.aborted)setBusy(false);});
        return()=>controller.abort();
    },[url,enabled,search,page,kind,retry]);
    useEffect(()=>setPage(1),[kind]);
    function find(){setSearch(query.trim());setPage(1);}
    return <div className="catalog-picker"><div className="catalog-picker-search"><input aria-label={searchLabel} placeholder={searchLabel} value={query} onChange={event=>setQuery(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();find();}}}/><button className="button secondary small" type="button" disabled={busy} onClick={find}>Cari</button></div>
        {meta && <Pagination compact meta={meta} onPage={setPage} busy={busy} label="Navigasi atas pilihan"/>}
        {error?<div role="alert"><p>{error}</p><button type="button" className="text-link" onClick={()=>setRetry(value=>value+1)}>Coba kembali</button></div>:busy?<p role="status">Memuat pilihan…</p>:<div className="catalog-picker-options">{rows.length?rows.map(option=><label key={option.id}><input type={multiple?'checkbox':'radio'} name={multiple?undefined:'catalog-product-choice'} checked={selected.some(item=>item.id===option.id)} disabled={option.allowed===false || (multiple && selected.length>=maxSelected && !selected.some(item=>item.id===option.id))} onChange={()=>onToggle(option)}/><span><strong>{option.name}</strong><small>{option.code ?? option.sku} {option.source?'· '+sourceLabels[option.source]:''} {option.mode==='pasca'?'· Pascabayar':''}{option.cost!==undefined?' · '+rupiah(option.cost):''}{option.allowed===false?' · Tidak termasuk paket':''}</small></span></label>):<p>{emptyText}</p>}</div>}
        {meta && <Pagination compact meta={meta} onPage={setPage} busy={busy} label="Halaman pilihan produk"/>}
    </div>;
}
