import {useId, useLayoutEffect, useState, type ReactNode} from 'react';
import {ChevronDown, type LucideIcon} from 'lucide-react';

export default function SidebarSection({label, icon: Icon, links, storageKey, collapsed, onExpand, children}: {label:string; icon:LucideIcon; links:{href:string;active:boolean}[]; storageKey:string; collapsed:boolean; onExpand:()=>void; children:ReactNode}) {
    const id = useId();
    const activeHref = links.find(link => link.active)?.href;
    const [open, setOpen] = useState(Boolean(activeHref));
    useLayoutEffect(() => {
        if (activeHref) {setOpen(true);return;}
        try {setOpen(sessionStorage.getItem(storageKey) === 'open');} catch {setOpen(false);}
    }, [activeHref, storageKey]);
    function toggle() {
        const next = collapsed || !open;
        if (collapsed) onExpand();
        setOpen(next);
        try { sessionStorage.setItem(storageKey, next ? 'open' : 'closed'); } catch { /* Navigation remains usable without browser storage. */ }
    }
    return <div className={'sidebar-section '+(activeHref?'has-active':'')}>
        <button type="button" className="sidebar-section-toggle" aria-label={label} title={label} aria-expanded={!collapsed && open} aria-controls={id} onClick={toggle}>
            <Icon size={17} aria-hidden="true"/><span>{label}</span><ChevronDown size={15} aria-hidden="true" className={open?'is-open':''}/>
        </button>
        <div id={id} className="sidebar-section-links" hidden={collapsed || !open}>{children}</div>
    </div>;
}
