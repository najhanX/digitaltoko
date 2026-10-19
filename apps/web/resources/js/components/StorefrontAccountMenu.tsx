import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { ArrowDownUp, ChevronDown, LogOut, Plus, ReceiptText, UserRound, Wallet } from 'lucide-react';

const money=(value:number)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
type Viewer={id:string;name:string;balance:number;can_topup:boolean};
export default function StorefrontAccountMenu({href='/account',preview=false}:{href?:string;preview?:boolean}) {
    const page=usePage();
    const viewer=preview?null:page.props.storeCustomer as Viewer|null|undefined;
    const menu=useRef<HTMLDetailsElement>(null);
    useEffect(()=>{menu.current?.removeAttribute('open');},[page.url]);
    useEffect(()=>{
        const close=(event:PointerEvent)=>{if(event.target instanceof Node && !menu.current?.contains(event.target))menu.current?.removeAttribute('open');};
        const escape=(event:KeyboardEvent)=>{if(event.key==='Escape' && menu.current?.open){menu.current.removeAttribute('open');menu.current.querySelector('summary')?.focus();}};
        document.addEventListener('pointerdown',close);document.addEventListener('keydown',escape);
        return()=>{document.removeEventListener('pointerdown',close);document.removeEventListener('keydown',escape);};
    },[]);
    if(!viewer)return <Link className="sf-customer-trigger" href={href}><UserRound size={17}/><span>Akun</span></Link>;
    return <details ref={menu} className="sf-customer-menu">
        <summary className="sf-customer-trigger" aria-label={'Menu akun '+viewer.name}><UserRound size={17}/><span>{viewer.name}</span><ChevronDown size={14}/></summary>
        <nav className="sf-customer-dropdown" aria-label="Menu akun pelanggan">
            <div className="sf-customer-menu-heading"><strong>{viewer.name}</strong><small>Akun pelanggan toko</small></div>
            <section className="sf-customer-menu-wallet" aria-label="Saldo pelanggan toko">
                <span><Wallet size={14} aria-hidden="true"/>Saldo toko</span><strong>{money(viewer.balance)}</strong>
                {viewer.can_topup?<Link href="/account?tab=wallet&topup=1" preserveState={false} onClick={()=>menu.current?.removeAttribute('open')}><Plus size={14} aria-hidden="true"/>Topup saldo</Link>:<small>Topup sedang tidak tersedia.</small>}
            </section>
            <Link href="/account?tab=profile" onClick={()=>menu.current?.removeAttribute('open')}><UserRound size={16} aria-hidden="true"/>Profil</Link>
            <Link href="/account?tab=wallet" onClick={()=>menu.current?.removeAttribute('open')}><ArrowDownUp size={16} aria-hidden="true"/>Mutasi saldo</Link>
            <Link href="/account" onClick={()=>menu.current?.removeAttribute('open')}><ReceiptText size={16} aria-hidden="true"/>Riwayat transaksi</Link>
            <Link className="sf-customer-menu-logout" href="/account/logout" method="post" as="button" onClick={()=>menu.current?.removeAttribute('open')}><LogOut size={16} aria-hidden="true"/>Keluar</Link>
        </nav>
    </details>;
}
