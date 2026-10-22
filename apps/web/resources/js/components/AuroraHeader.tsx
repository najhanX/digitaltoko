import StorefrontMobileMenu from './StorefrontMobileMenu';
import StorefrontSearch from './StorefrontSearch';
import useStorefrontHeaderSize from './useStorefrontHeaderSize';
import { usePage } from '@inertiajs/react';
import StorefrontAccountMenu from './StorefrontAccountMenu';
import { useEffect, useState, type ReactNode } from 'react';
export default function AuroraHeader({brand,navigation,account}:{brand:ReactNode;navigation:ReactNode;account?:ReactNode}) {
    const header = useStorefrontHeaderSize('--au-header-height');
    const page=usePage();
    const [scrolled,setScrolled]=useState(false);
    useEffect(()=>{const update=()=>setScrolled(window.scrollY>20);update();window.addEventListener('scroll',update,{passive:true});return()=>window.removeEventListener('scroll',update);},[]);
    return <><div className="au-header-space"/><header ref={header} className={'sf-header au-fixed-header'+(scrolled?' is-scrolled':'')}>{brand}<StorefrontMobileMenu>{navigation}</StorefrontMobileMenu><StorefrontSearch compact template="aurora" preview={page.url.startsWith('/preview/')}/>{account ?? <StorefrontAccountMenu/>}</header></>;
}
