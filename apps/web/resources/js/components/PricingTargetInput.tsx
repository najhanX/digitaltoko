import { useEffect, useLayoutEffect, useId, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import type { PageMeta } from './ListControls';

type Option = {id:string;name:string};
export default function PricingTargetInput({storeId,scope,value,onChange,optionsUrl,label}:{storeId:string;scope:string;value:string;onChange:(value:string)=>void;optionsUrl?:string;label?:string}) {
    const root=useRef<HTMLDivElement>(null);
    const id=useId(), input=useRef<HTMLInputElement>(null), dropdown=useRef<HTMLDivElement>(null), sentinel=useRef<HTMLDivElement>(null);
    const [open,setOpen]=useState(false),[search,setSearch]=useState(value),[page,setPage]=useState(1),[active,setActive]=useState(-1);
    const [placement,setPlacement]=useState({left:0,top:0,width:280,maxHeight:260});
    const [rows,setRows]=useState<Option[]>([]),[meta,setMeta]=useState<PageMeta|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState(false),[retry,setRetry]=useState(0);
    useEffect(()=>{
        if(!open)return;
        const controller=new AbortController();
        setBusy(true);setError(false);
        if(page===1){setRows([]);setMeta(null);setActive(-1);if(dropdown.current)dropdown.current.scrollTop=0;}
        const timer=setTimeout(()=>{
            const parameters=new URLSearchParams({type:scope==='service'?'services':'pricing_categories',q:search,page:String(page)});
            fetch((optionsUrl ?? '/stores/'+storeId+'/catalog-structure/options')+'?'+parameters,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
                .then(async response=>{if(!response.ok)throw new Error();return response.json();})
                .then(data=>{if(!controller.signal.aborted){setRows(current=>page===1?data.items:[...current,...data.items.filter((option:Option)=>!current.some(item=>item.id===option.id))]);setMeta(data.pagination);}})
                .catch(()=>{if(!controller.signal.aborted)setError(true);})
                .finally(()=>{if(!controller.signal.aborted)setBusy(false);});
        },200);
        return()=>{clearTimeout(timer);controller.abort();};
    },[open,scope,storeId,search,page,retry,optionsUrl]);
    useEffect(()=>{
        if(!open || busy || error || !meta || meta.current_page!==page || meta.current_page>=meta.last_page || !sentinel.current || !dropdown.current)return;
        const observer=new IntersectionObserver(entries=>{
            if(entries.some(entry=>entry.isIntersecting))setPage(current=>current===meta.current_page?current+1:current);
        },{root:dropdown.current,rootMargin:'0px 0px 32px 0px'});
        observer.observe(sentinel.current);
        return()=>observer.disconnect();
    },[open,busy,error,meta,page]);
    useEffect(()=>{if(open && active>=0)document.getElementById(id+'-option-'+active)?.scrollIntoView({block:'nearest'});},[open,active,id]);
    useLayoutEffect(()=>{
        if(!open || !dropdown.current)return;
        const element=dropdown.current;
        if(!element.matches(':popover-open'))element.showPopover();
        function place(){
            const field=input.current?.closest('.pricing-target-control')?.getBoundingClientRect();if(!field)return;
            const width=Math.min(field.width,window.innerWidth-24),below=window.innerHeight-field.bottom-18,above=field.top-18;
            const down=below>=220 || below>=above,maxHeight=Math.max(80,Math.min(280,down?below:above));
            const height=Math.min(element.scrollHeight,maxHeight);
            const next={left:Math.max(12,Math.min(field.left,window.innerWidth-width-12)),top:down?field.bottom+6:Math.max(12,field.top-height-6),width,maxHeight};
            setPlacement(previous=>JSON.stringify(previous)===JSON.stringify(next)?previous:next);
        }
        place();window.addEventListener('resize',place);window.addEventListener('scroll',place,true);
        return()=>{window.removeEventListener('resize',place);window.removeEventListener('scroll',place,true);};
    },[open,rows,busy,error]);
    useEffect(()=>{
        if(!open)return;
        const dismiss=()=>{setOpen(false);setActive(-1);};
        const outside=(event:PointerEvent)=>{if(event.target instanceof Node && !root.current?.contains(event.target))dismiss();};
        const dialog=root.current?.closest('dialog');
        document.addEventListener('pointerdown',outside);dialog?.addEventListener('close',dismiss);
        return()=>{document.removeEventListener('pointerdown',outside);dialog?.removeEventListener('close',dismiss);};
    },[open]);
    function show(){setSearch('');setPage(1);setOpen(true);}
    function choose(option:Option){onChange(option.name);input.current?.focus();setOpen(false);setActive(-1);}
    return <div ref={root} className="pricing-target" onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget)){setOpen(false);setActive(-1);}}}>
        <label htmlFor={id}>{label ?? (scope==='service'?'Nama layanan':'Nama kategori utama')}</label>
        <div className="pricing-target-control"><input ref={input} id={id} role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls={id+'-options'} aria-activedescendant={open && active>=0?id+'-option-'+active:undefined} autoComplete="off" required maxLength={100} placeholder={scope==='service'?'Pilih atau cari layanan…':'Pilih atau cari kategori…'} value={value} onFocus={show} onClick={()=>{if(!open)show();}} onChange={event=>{onChange(event.target.value);setSearch(event.target.value);setPage(1);setActive(-1);setOpen(true);}} onKeyDown={event=>{
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();setOpen(false);}
            else if(event.key==='ArrowDown' || event.key==='ArrowUp'){event.preventDefault();if(!open)show();else setActive(index=>rows.length?(event.key==='ArrowDown'?(index+1)%rows.length:(index<=0?rows.length-1:index-1)):-1);}
            else if(event.key==='Enter' && open){event.preventDefault();if(active>=0 && rows[active])choose(rows[active]);}
        }}/><button type="button" className="icon-button" aria-label={open?'Tutup pilihan':'Buka pilihan'} aria-expanded={open} onClick={()=>{if(open)setOpen(false);else {input.current?.focus();show();}}}><ChevronDown size={17}/></button></div>
        {open && <div ref={dropdown} popover="manual" className="pricing-target-dropdown pricing-target-floating" style={placement}>
            {error && <p role="alert">Pilihan belum dapat dimuat. <button type="button" className="button secondary small" onClick={()=>setRetry(count=>count+1)}>Coba kembali</button></p>}
            <div id={id+'-options'} role="listbox" aria-label={scope==='service'?'Pilihan layanan':'Pilihan kategori utama'} aria-busy={busy}>{rows.map((option,index)=><button type="button" role="option" id={id+'-option-'+index} aria-selected={value.toLocaleLowerCase()===option.name.toLocaleLowerCase()} className={index===active?'active':''} key={option.id} onMouseDown={event=>event.preventDefault()} onClick={()=>choose(option)}>{option.name}</button>)}</div>
            {busy && <p role="status">Memuat pilihan…</p>}
            {!busy && !error && !rows.length && <p>Tidak ada {scope==='service'?'layanan':'kategori'} yang cocok.</p>}
            <div ref={sentinel} className="pricing-target-sentinel" aria-hidden="true"/>
        </div>}
    </div>;
}
