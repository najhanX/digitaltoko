import { useLayoutEffect, useId, useRef, useState, type ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';

export default function PickerDropdown({label,summary,children}:{label:string;summary:string;children:ReactNode}) {
    const id=useId(),trigger=useRef<HTMLButtonElement>(null),panel=useRef<HTMLDivElement>(null);
    const [open,setOpen]=useState(false),[position,setPosition]=useState({left:0,top:0,width:320,maxHeight:360});
    useLayoutEffect(()=>{
        if(!open)return;
        function place(){
            const rect=trigger.current?.getBoundingClientRect();if(!rect)return;
            const width=Math.min(Math.max(rect.width,280),window.innerWidth-24),below=window.innerHeight-rect.bottom-18,above=rect.top-18;
            const down=below>=220 || below>=above,maxHeight=Math.max(100,Math.min(360,down?below:above));
            const next={left:Math.max(12,Math.min(rect.left,window.innerWidth-width-12)),top:down?rect.bottom+6:Math.max(12,rect.top-maxHeight-6),width,maxHeight};
            setPosition(previous=>JSON.stringify(previous)===JSON.stringify(next)?previous:next);
        }
        place();panel.current?.querySelector('input')?.focus({preventScroll:true});
        window.addEventListener('resize',place);window.addEventListener('scroll',place,true);
        return()=>{window.removeEventListener('resize',place);window.removeEventListener('scroll',place,true);};
    },[open]);
    return <div className="picker-dropdown">
        <button ref={trigger} type="button" className="picker-dropdown-trigger" aria-expanded={open} aria-controls={id} aria-haspopup="dialog" onClick={()=>{const element=panel.current;if(!element)return;if(element.matches(':popover-open'))element.hidePopover();else element.showPopover();}}><span><strong>{label}</strong><small>{summary}</small></span><ChevronDown size={17}/></button>
        <div ref={panel} id={id} popover="auto" role="dialog" aria-label={label} className="picker-dropdown-panel" style={position} onToggle={event=>setOpen(event.newState==='open')} onChange={event=>{if(event.target instanceof HTMLInputElement && event.target.type==='radio')panel.current?.hidePopover();}}>
            {open && <div className="picker-dropdown-scroll">{children}</div>}
        </div>
    </div>;
}
