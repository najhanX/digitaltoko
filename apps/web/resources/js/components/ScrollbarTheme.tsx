import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { validColor, type StoreBranding } from './storefrontDesign';

/** Mirror the storefront palette onto viewport scrollbars without altering panel theme tokens. */
export default function ScrollbarTheme({initial}: {initial:{component:string;props:Record<string,unknown>}}) {
    const [page,setPage]=useState(initial);
    useEffect(()=>router.on('navigate',event=>setPage(event.detail.page)),[]);
    const store=page.props.store as {template?:string;branding?:StoreBranding|null}|undefined;
    const storefront=page.component.startsWith('Storefront/');
    const dark=['nebula','promo'].includes(store?.template ?? '');
    const accent=store?.branding?.design?.accent;
    const surface=store?.branding?.design?.colors?.surface;
    useEffect(()=>{
        const root=document.documentElement;
        if(storefront){
            root.style.setProperty('--viewport-scroll-accent',accent && validColor(accent)?accent:dark?'#9c80f6':'#4f46e5');
            root.style.setProperty('--viewport-scroll-surface',surface && validColor(surface)?surface:dark?'#171d2d':'#ffffff');
        }
        return()=>{root.style.removeProperty('--viewport-scroll-accent');root.style.removeProperty('--viewport-scroll-surface');};
    },[storefront,dark,accent,surface]);
    return null;
}
