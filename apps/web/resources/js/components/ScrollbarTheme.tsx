import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { storefrontViewport, validColor, type StoreBranding } from './storefrontDesign';

/** Keep the browser canvas and scrollbars in sync with the active page palette. */
export default function ScrollbarTheme({initial}: {initial:{component:string;props:Record<string,unknown>}}) {
    const [page,setPage]=useState(initial);
    useEffect(()=>router.on('navigate',event=>setPage(event.detail.page)),[]);
    const store=page.props.store as {template?:string;branding?:StoreBranding|null}|undefined;
    const storefront=page.component.startsWith('Storefront/');
    const dark=['nebula','promo'].includes(store?.template ?? '');
    const accent=store?.branding?.design?.accent;
    const surface=store?.branding?.design?.colors?.surface;
    const background=store?.branding?.design?.colors?.background;
    useEffect(()=>{
        const root=document.documentElement;
        const applyViewport=()=>{
            const palette=storefront?storefrontViewport(store?.template,background):{
                background:root.dataset.panelTheme==='dark'?'#0c1323':'#f3f5fc',
                colorScheme:root.dataset.panelTheme==='dark'?'dark':'light',
            };
            const pageSurface=!storefront?document.querySelector('.panel-surface:not(header):not(.public-header), .dt-home, .pricing-site, .tutorial-site'):null;
            const surfaceColor=pageSurface?getComputedStyle(pageSurface).backgroundColor:null;
            const color=surfaceColor && surfaceColor!=='rgba(0, 0, 0, 0)'?surfaceColor:palette.background;
            root.style.backgroundColor=color;
            root.style.colorScheme=palette.colorScheme;
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content',color);
        };
        applyViewport();
        const observer=new MutationObserver(applyViewport);
        observer.observe(root,{attributes:true,attributeFilter:['data-panel-theme']});
        if(storefront){
            root.style.setProperty('--viewport-scroll-accent',accent && validColor(accent)?accent:dark?'#9c80f6':'#4f46e5');
            root.style.setProperty('--viewport-scroll-surface',surface && validColor(surface)?surface:dark?'#171d2d':'#ffffff');
        }
        return()=>{observer.disconnect();root.style.removeProperty('--viewport-scroll-accent');root.style.removeProperty('--viewport-scroll-surface');};
    },[page.component,storefront,store?.template,dark,accent,surface,background]);
    return null;
}
