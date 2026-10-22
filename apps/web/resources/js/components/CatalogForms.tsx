import { useEffect, useRef, useState } from 'react';
import { type PageMeta } from './ListControls';
import PickerDropdown from './PickerDropdown';
export const rupiah=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
export const sourceLabels:Record<string,string>={otomax:'OtomaX',digiflazz:'Digiflazz',hanshop:'Hanshop',stock:'Stok sendiri'};
export const kindLabels:Record<string,string>={ppob:'Prabayar / PPOB',premium:'Aplikasi premium',postpaid:'Tagihan pascabayar'};
export type CatalogStore={id:string;name:string;status:string;plan?:string;sandbox?:boolean};
export type MappingOption={id:string;name:string;sku:string;mode:string;cost:number;kind:string;source:string;allowed:boolean};
export function FormErrors({errors}:{errors:Record<string,string|undefined>}) {
    const values=Object.values(errors).filter(Boolean);
    return values.length?<div className="feedback error" role="alert">{[...new Set(values)].map(message=><p key={message}>{message}</p>)}</div>:null;
}
type Option={id:string;name:string;code?:string;sku?:string;cost?:number;source?:string;allowed?:boolean;kind?:string;mode?:string};
type PickerProps={url:string;enabled:boolean;selected:Option[];onToggle:(option:Option)=>void;multiple?:boolean;kind?:string;searchLabel?:string;emptyText?:string;maxSelected?:number;perPage?:number};
export function CatalogPicker(props:PickerProps) {
    return props.enabled?<PickerDropdown label={props.searchLabel ?? 'Pilih produk'} summary={props.multiple===false?(props.selected[0]?.name ?? 'Belum ada pilihan'):props.selected.length+' dipilih · Klik untuk memilih'}><CatalogPickerList key={JSON.stringify([props.url,props.kind,props.perPage])} {...props}/></PickerDropdown>:null;
}
function CatalogPickerList({url,selected,onToggle,multiple=true,kind,searchLabel='Cari pilihan produk',emptyText='Tidak ada pilihan yang cocok.',maxSelected=20,perPage=10}:PickerProps) {
    const [query,setQuery]=useState(''),[search,setSearch]=useState(''),[page,setPage]=useState(1);
    const [rows,setRows]=useState<Option[]>([]),[meta,setMeta]=useState<PageMeta|null>(null),[busy,setBusy]=useState(true),[error,setError]=useState(''),[retry,setRetry]=useState(0);
    const sentinel=useRef<HTMLDivElement>(null);
    useEffect(()=>{
        const controller=new AbortController();setBusy(true);setError('');
        const parameters=new URLSearchParams({q:search,page:String(page),per_page:String(perPage),...(kind?{kind}:{})});
        fetch(url+(url.includes('?')?'&':'?')+parameters,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
            .then(async response=>{if(!response.ok)throw new Error();return response.json();})
            .then(data=>{if(controller.signal.aborted)return;setRows(previous=>page===1?data.items:[...previous,...data.items.filter((item:Option)=>!previous.some(row=>row.id===item.id))]);setMeta(data.pagination);})
            .catch(()=>{if(!controller.signal.aborted)setError('Pilihan belum dapat dimuat. Coba kembali.');})
            .finally(()=>{if(!controller.signal.aborted)setBusy(false);});
        return()=>controller.abort();
    },[url,search,page,kind,retry,perPage]);
    useEffect(()=>{
        const node=sentinel.current;if(!node || busy || error || !meta || meta.current_page>=meta.last_page)return;
        const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){observer.disconnect();setBusy(true);setPage(meta.current_page+1);}},{root:node.closest('.picker-dropdown-scroll'),rootMargin:'0px 0px 60px 0px'});
        observer.observe(node);return()=>observer.disconnect();
    },[busy,error,meta]);
    function find(){setRows([]);setMeta(null);setBusy(true);setSearch(query.trim());setPage(1);setRetry(value=>value+1);}
    return <div className="catalog-picker"><div className="catalog-picker-search"><input aria-label={searchLabel} placeholder={searchLabel} value={query} onChange={event=>setQuery(event.target.value)} onKeyDown={event=>{if(event.key==='Enter'){event.preventDefault();find();}}}/><button className="button secondary small" type="button" onClick={find}>Cari</button></div>
        {multiple && selected.length>0 && <details className="picker-selected"><summary>Terpilih ({selected.length})</summary>{selected.map(option=><div key={option.id}><span>{option.name}<small>{option.code ?? option.sku}</small></span><button type="button" className="button secondary small" onClick={()=>onToggle(option)}>Lepas</button></div>)}</details>}
        {meta && <small className="catalog-picker-total">{rows.length} dari {meta.total} pilihan</small>}
        <div className="catalog-picker-options" aria-busy={busy}>{rows.map(option=><label key={option.id}><input type={multiple?'checkbox':'radio'} name={multiple?undefined:'catalog-product-choice'} checked={selected.some(item=>item.id===option.id)} disabled={option.allowed===false || (multiple && selected.length>=maxSelected && !selected.some(item=>item.id===option.id))} onChange={()=>onToggle(option)}/><span><strong>{option.name}</strong><small>{option.code ?? option.sku} {option.source?'· '+sourceLabels[option.source]:''} {option.mode==='pasca'?'· Pascabayar':''}{option.cost!==undefined?' · '+rupiah(option.cost):''}{option.allowed===false?' · Tidak termasuk paket':''}</small></span></label>)}{!rows.length && !busy && !error && <p>{emptyText}</p>}</div>
        <div ref={sentinel} className="catalog-picker-load" role="status">{error?<><span>{error}</span><button type="button" className="button secondary small" onClick={()=>{setBusy(true);setRetry(value=>value+1);}}>Coba kembali</button></>:busy?'Memuat pilihan…':meta && meta.current_page<meta.last_page?'Scroll untuk memuat pilihan lainnya.':'Semua hasil sudah ditampilkan.'}</div>
    </div>;
}
