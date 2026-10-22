import { usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';

export default function StorefrontMobileMenu({children}: {children:ReactNode}) {
    const menu=useRef<HTMLDetailsElement>(null);
    const {url}=usePage();
    useEffect(()=>{menu.current?.removeAttribute('open');},[url]);
    useEffect(()=>{
        const outside=(event:PointerEvent)=>{if(event.target instanceof Node && !menu.current?.contains(event.target))menu.current?.removeAttribute('open');};
        const escape=(event:KeyboardEvent)=>{if(event.key==='Escape' && menu.current?.open){menu.current.removeAttribute('open');menu.current.querySelector('summary')?.focus();}};
        document.addEventListener('pointerdown',outside);document.addEventListener('keydown',escape);
        return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',escape);};
    },[]);
    return <details className="sf-mobile-menu" ref={menu}>
        <summary aria-label="Buka atau tutup navigasi toko"><Menu className="sf-menu-open" size={20}/><X className="sf-menu-close" size={20}/><span>Menu</span></summary>
        <div className="sf-mobile-menu-panel" onClick={event=>{if(event.target instanceof Element && event.target.closest('a'))menu.current?.removeAttribute('open');}}>{children}</div>
    </details>;
}
