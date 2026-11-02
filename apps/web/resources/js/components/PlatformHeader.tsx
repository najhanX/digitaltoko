import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowUpRight, Menu, X } from 'lucide-react';
import Brand from './Brand';
import ThemeToggle from './ThemeToggle';

const navigation = [
    {label:'Layanan',href:'/#layanan',section:true},
    {label:'Tampilan toko',href:'/#tampilan',section:true},
    {label:'Cara mulai',href:'/#cara-mulai',section:true},
    {label:'Panduan',href:'/panduan',section:false},
    {label:'Paket & harga',href:'/paket',section:false},
];

/** One public navigation for the homepage, pricing, guides and owner sign-in. */
export default function PlatformHeader() {
    const {url} = usePage();
    const pathname = url.split(/[?#]/)[0];
    const [open,setOpen] = useState(false);
    const toggle = useRef<HTMLButtonElement>(null);
    useEffect(()=>{setOpen(false);},[url]);

    return <header className="public-header panel-surface" onKeyDown={event=>{
        if(event.key==='Escape'&&open){setOpen(false);toggle.current?.focus();}
    }}>
        <div className="public-header-inner">
            <Brand/>
            <div className="public-header-actions"><ThemeToggle compactOnMobile/>
            <button ref={toggle} className="public-header-toggle" type="button" aria-label={open?'Tutup navigasi':'Buka navigasi'} aria-expanded={open} aria-controls="platform-navigation" onClick={()=>setOpen(!open)}>{open?<X size={22}/>:<Menu size={22}/>}</button></div>
            <nav id="platform-navigation" className={'public-header-nav'+(open?' is-open':'')} aria-label="Navigasi utama" onClick={()=>setOpen(false)}>
                {navigation.map(item=>{
                    const active = !item.section&&(pathname===item.href||pathname.startsWith(item.href+'/'));
                    return item.section&&pathname==='/'?<a key={item.href} href={item.href.slice(1)}>{item.label}</a>:<Link key={item.href} href={item.href} aria-current={active?'page':undefined}>{item.label}</Link>;
                })}
                <Link className="public-header-cta" href="/login" aria-current={pathname==='/login'?'page':undefined}>Masuk panel<ArrowUpRight size={16}/></Link>
            </nav>
        </div>
    </header>;
}
