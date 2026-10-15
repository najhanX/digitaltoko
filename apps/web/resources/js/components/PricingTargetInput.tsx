import { useEffect, useId, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import type { PageMeta } from './ListControls';

type Option = {id:string;name:string};
export default function PricingTargetInput({storeId,scope,value,onChange}:{storeId:string;scope:string;value:string;onChange:(value:string)=>void}) {
    const id=useId(), input=useRef<HTMLInputElement>(null), dropdown=useRef<HTMLDivElement>(null), sentinel=useRef<HTMLDivElement>(null);
    const [open,setOpen]=useState(false),[search,setSearch]=useState(value),[page,setPage]=useState(1),[active,setActive]=useState(-1);
    const [placement,setPlacement]=useState({above:false,height:240});
    const [rows,setRows]=useState<Option[]>([]),[meta,setMeta]=useState<PageMeta|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(false),[retry,setRetry]=useState(0);
    useEffect(()=>{
        if(!open)return;
        const controller=new AbortController();
        setBusy(true);setError(false);
        if(page===1){setRows([]);setMeta(null);setActive(-1);if(dropdown.current)dropdown.current.scrollTop=0;}
        const timer=setTimeout(()=>{
            const parameters=new URLSearchParams({type:scope==='service'?'services':'pricing_categories',q:search,page:String(page)});
            fetch('/stores/'+storeId+'/catalog-structure/options?'+parameters,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
                .then(async response=>{if(!response.ok)throw new Error();return response.json();})
                .then(data=>{if(!controller.signal.aborted){setRows(current=>page===1?data.items:[...current,...data.items.filter((option:Option)=>!current.some(item=>item.id===option.id))]);setMeta(data.pagination);}})
                .catch(()=>{if(!controller.signal.aborted)setError(true);})
                .finally(()=>{if(!controller.signal.aborted)setBusy(false);});
        },200);
        return()=>{clearTimeout(timer);controller.abort();};
    },[open,scope,storeId,search,page,retry]);
    useEffect(()=>{
        if(!open || busy || error || !meta || meta.current_page!==page || meta.current_page>=meta.last_page || !sentinel.current || !dropdown.current)return;
        const observer=new IntersectionObserver(entries=>{
            if(entries.some(entry=>entry.isIntersecting))setPage(current=>current===meta.current_page?current+1:current);
        },{root:dropdown.current,rootMargin:'0px 0px 32px 0px'});
        observer.observe(sentinel.current);
        return()=>observer.disconnect();
    },[open,busy,error,meta,page]);
    useEffect(()=>{if(open && active>=0)document.getElementById(id+'-option-'+active)?.scrollIntoView({block:'nearest'});},[open,active,id]);
    function show(){
        const field=input.current?.getBoundingClientRect(),body=input.current?.closest('.modal-body')?.getBoundingClientRect();
        if(field && body){
            const below=body.bottom-field.bottom-12,above=field.top-body.top-36;
            const useAbove=above>below && below<180;
            setPlacement({above:useAbove,height:Math.max(100,Math.min(280,useAbove?above:below))});
        }
        setSearch('');setPage(1);setOpen(true);
    }
    function choose(option:Option){onChange(option.name);input.current?.focus();setOpen(false);setActive(-1);}
    return <div className="pricing-target" onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget)){setOpen(false);setActive(-1);}}}>
        <label htmlFor={id}>{scope==='service'?'Nama layanan':'Nama kategori utama'}</label>
        <div className="pricing-target-control"><input ref={input} id={id} role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls={id+'-options'} aria-activedescendant={open && active>=0?id+'-option-'+active:undefined} autoComplete="off" required maxLength={100} placeholder={scope==='service'?'Pilih atau cari layanan…':'Pilih atau cari kategori…'} value={value} onFocus={show} onClick={()=>{if(!open)show();}} onChange={event=>{onChange(event.target.value);setSearch(event.target.value);setPage(1);setActive(-1);setOpen(true);}} onKeyDown={event=>{
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();setOpen(false);}
            else if(event.key==='ArrowDown' || event.key==='ArrowUp'){event.preventDefault();if(!open)show();else setActive(index=>rows.length?(event.key==='ArrowDown'?(index+1)%rows.length:(index<=0?rows.length-1:index-1)):-1);}
            else if(event.key==='Enter' && open){event.preventDefault();if(active>=0 && rows[active])choose(rows[active]);}
        }}/><button type="button" className="icon-button" aria-label={open?'Tutup pilihan':'Buka pilihan'} aria-expanded={open} onClick={()=>{if(open)setOpen(false);else {input.current?.focus();show();}}}><ChevronDown size={17}/></button></div>
        {open && <div ref={dropdown} className="pricing-target-dropdown" style={{maxHeight:placement.height,...(placement.above?{top:'auto',bottom:'100%',marginBottom:6}:{})}}>
            {error && <p role="alert">Pilihan belum dapat dimuat. <button type="button" className="text-link" onClick={()=>setRetry(count=>count+1)}>Coba kembali</button></p>}
            <div id={id+'-options'} role="listbox" aria-label={scope==='service'?'Pilihan layanan':'Pilihan kategori utama'} aria-busy={busy}>{rows.map((option,index)=><button type="button" role="option" id={id+'-option-'+index} aria-selected={value.toLocaleLowerCase()===option.name.toLocaleLowerCase()} className={index===active?'active':''} key={option.id} onMouseDown={event=>event.preventDefault()} onClick={()=>choose(option)}>{option.name}</button>)}</div>
            {busy && <p role="status">Memuat pilihan…</p>}
            {!busy && !error && !rows.length && <p>Tidak ada {scope==='service'?'layanan':'kategori'} yang cocok.</p>}
            <div ref={sentinel} className="pricing-target-sentinel" aria-hidden="true"/>
        </div>}
    </div>;
}
