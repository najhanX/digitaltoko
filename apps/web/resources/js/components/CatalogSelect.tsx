import { useEffect, useLayoutEffect, useId, useRef, useState } from 'react';
import { ChevronDown, LoaderCircle, Search } from 'lucide-react';

type Option={id:string;name:string;quick_order_service?:string|null};
export default function CatalogSelect({url,label,value,onChange,disabled=false}:{url:string;label:string;value:Option|null;onChange:(value:Option)=>void;disabled?:boolean}) {
    const id=useId(),input=useRef<HTMLInputElement>(null),control=useRef<HTMLDivElement>(null),panel=useRef<HTMLDivElement>(null),root=useRef<HTMLDivElement>(null);
    const loading=useRef(false),listbox=useRef<HTMLDivElement>(null);
    const [freshIds,setFreshIds]=useState<string[]>([]);
    const [offset,setOffset]=useState(0),[nextOffset,setNextOffset]=useState<number|null>(null);
    const [position,setPosition]=useState({left:0,top:0,width:280,maxHeight:260});
    const [open,setOpen]=useState(false),[search,setSearch]=useState(''),[rows,setRows]=useState<Option[]>([]),[total,setTotal]=useState(0),[busy,setBusy]=useState(false),[error,setError]=useState(false),[retry,setRetry]=useState(0),[active,setActive]=useState(-1);
    useLayoutEffect(()=>{
        const element=panel.current;
        if(!open || disabled){if(element?.matches(':popover-open'))element.hidePopover();return;}
        if(element && !element.matches(':popover-open'))element.showPopover();
        function place(){
            const rect=control.current?.getBoundingClientRect();if(!rect)return;
            const width=Math.min(rect.width,window.innerWidth-24),below=window.innerHeight-rect.bottom-18,above=rect.top-18;
            const down=below>=220 || below>=above,maxHeight=Math.max(60,Math.min(260,down?below:above));
            const height=Math.min(panel.current?.scrollHeight ?? maxHeight,maxHeight);
            const next={left:Math.max(12,Math.min(rect.left,window.innerWidth-width-12)),top:down?rect.bottom+6:Math.max(12,rect.top-height-6),width,maxHeight};
            setPosition(previous=>JSON.stringify(previous)===JSON.stringify(next)?previous:next);
        }
        place();window.addEventListener('resize',place);window.addEventListener('scroll',place,true);
        return()=>{window.removeEventListener('resize',place);window.removeEventListener('scroll',place,true);};
    },[open,disabled,rows,busy,error]);
    function close(){panel.current?.hidePopover();setOpen(false);}
    useEffect(()=>{
        if(!open)return;
        const dismiss=()=>{panel.current?.hidePopover();setOpen(false);};
        const outside=(event:PointerEvent)=>{if(event.target instanceof Node && !root.current?.contains(event.target))dismiss();};
        const dialog=root.current?.closest('dialog');
        document.addEventListener('pointerdown',outside);dialog?.addEventListener('close',dismiss);
        return()=>{document.removeEventListener('pointerdown',outside);dialog?.removeEventListener('close',dismiss);};
    },[open]);
    useEffect(()=>{setOffset(0);setNextOffset(null);setRows([]);},[url]);
    useEffect(()=>{
        if(!open || disabled)return;
        const controller=new AbortController();loading.current=true;setBusy(true);setError(false);
        if(offset===0){setFreshIds([]);setRows([]);setNextOffset(null);setActive(-1);if(listbox.current)listbox.current.scrollTop=0;}
        let revealTimer:ReturnType<typeof setTimeout>|undefined;
        let finishReveal:(()=>void)|undefined;
        const started=performance.now();
        const timer=setTimeout(()=>{
            const query=new URLSearchParams({q:search.trim(),offset:String(offset),per_page:offset===0?'5':'10'});
            fetch(url+(url.includes('?')?'&':'?')+query,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
                .then(async response=>{if(!response.ok)throw new Error();return response.json();})
                .then(async data=>{
                    const wait=offset>0?Math.max(0,500-(performance.now()-started)):0;
                    if(wait && !controller.signal.aborted)await new Promise<void>(resolve=>{finishReveal=resolve;revealTimer=setTimeout(resolve,wait);});
                    if(!controller.signal.aborted){setFreshIds(data.items.filter((item:Option)=>offset===0 || !rows.some(row=>row.id===item.id)).map((item:Option)=>item.id));setRows(previous=>offset===0?data.items:[...previous,...data.items.filter((item:Option)=>!previous.some(row=>row.id===item.id))]);setTotal(data.pagination.total);setNextOffset(data.pagination.next_offset ?? null);}})
                .catch(()=>{if(!controller.signal.aborted)setError(true);})
                .finally(()=>{if(!controller.signal.aborted){loading.current=false;setBusy(false);}});
        },offset===0?250:0);
        return()=>{clearTimeout(timer);clearTimeout(revealTimer);finishReveal?.();controller.abort();};
    },[open,disabled,url,search,offset,retry]);
    useEffect(()=>{if(open && active>=0)document.getElementById(id+'-option-'+active)?.scrollIntoView({block:'nearest'});},[open,active,id]);
    function show(){setSearch('');setOffset(0);setNextOffset(null);setActive(-1);setOpen(true);}
    function loadMore(){if(loading.current || error || nextOffset===null)return;loading.current=true;setBusy(true);setOffset(nextOffset);}
    function choose(option:Option){input.current?.focus();onChange(option);close();}
    return <div ref={root} className="catalog-select" onBlur={event=>{if(!event.currentTarget.contains(event.relatedTarget))close();}}>
        <label htmlFor={id}>{label}</label>
        <div ref={control} className="catalog-select-control"><Search size={15}/><input ref={input} id={id} role="combobox" aria-expanded={open && !disabled} aria-controls={id+'-options'} aria-autocomplete="list" aria-activedescendant={open && active>=0?id+'-option-'+active:undefined} autoComplete="off" disabled={disabled} placeholder={'Pilih / cari '+label.toLowerCase()} value={open?search:value?.name ?? ''} onFocus={show} onClick={()=>{if(!open)show();}} onChange={event=>{loading.current=true;setSearch(event.target.value);setOffset(0);setNextOffset(null);setOpen(true);}} onKeyDown={event=>{
            if(event.key==='Escape' && open){event.preventDefault();event.stopPropagation();close();}
            else if(event.key==='ArrowDown' || event.key==='ArrowUp'){event.preventDefault();if(!open)show();else setActive(index=>rows.length?(event.key==='ArrowDown'?(index+1)%rows.length:(index<=0?rows.length-1:index-1)):-1);}
            else if(event.key==='Enter' && open){event.preventDefault();if(active>=0 && rows[active])choose(rows[active]);}
        }}/><button type="button" className="icon-button" disabled={disabled} aria-label={'Buka pilihan '+label.toLowerCase()} aria-expanded={open && !disabled} onClick={()=>{if(open)close();else{input.current?.focus();show();}}}><ChevronDown size={15}/></button></div>
        <div ref={panel} popover="manual" className="catalog-select-results" style={position} onToggle={event=>setOpen(event.newState==='open')}>
            {open && !disabled && <>
            <div ref={listbox} onScroll={event=>{const element=event.currentTarget;if(element.scrollTop>0 && element.scrollHeight-element.scrollTop-element.clientHeight<16)loadMore();}} id={id+'-options'} role="listbox" aria-label={'Pilihan '+label.toLowerCase()} aria-busy={busy}>{rows.map((option,index)=><button key={option.id} id={id+'-option-'+index} type="button" role="option" aria-selected={value?.id===option.id} className={(active===index?'active ':'')+(freshIds.includes(option.id)?'catalog-option-reveal':'')} style={freshIds.includes(option.id)?{animationDelay:Math.min(freshIds.indexOf(option.id),5)*35+'ms'}:undefined} onMouseDown={event=>event.preventDefault()} onClick={()=>choose(option)}>{option.name}</button>)}</div>
            {busy?<small className="catalog-select-status" role="status"><LoaderCircle className="catalog-select-spinner" size={13} aria-hidden="true"/>{rows.length?'Memuat pilihan berikutnya…':'Memuat pilihan…'}</small>:error?<small role="alert">Pilihan belum dapat dimuat. <button type="button" className="button secondary small" onClick={()=>setRetry(count=>count+1)}>Coba lagi</button></small>:!rows.length?<small>Tidak ada hasil yang cocok.</small>:rows.length?<small>{rows.length} dari {total} pilihan{nextOffset!==null?' · Scroll untuk memuat lagi':' · Semua hasil ditampilkan'}</small>:null}
            </>}
        </div>
    </div>;
}
