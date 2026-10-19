import StorefrontSearch from './StorefrontSearch';
import useStorefrontHeaderSize from './useStorefrontHeaderSize';
import StorefrontAccountMenu from './StorefrontAccountMenu';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Calculator, ChevronDown, Gamepad2, ReceiptText, Search, ShieldCheck, Trophy, Newspaper } from 'lucide-react';
import NebulaEngagement from './NebulaEngagement';
import useStorefrontReveal from './useStorefrontReveal';
import type { StoreBranding } from './storefrontDesign';

type Store = { name:string;slug:string;branding:StoreBranding|null;preview?:boolean };
export default function NebulaLayout({store,children,home=false,preview=false,previewHref}:{store:Store;children:ReactNode;home?:boolean;preview?:boolean;previewHref?:(page:'home'|'catalog'|'order'|'profile'|'lookup')=>string}) {
    const header = useStorefrontHeaderSize('--ng-header-height');
    const [scrolled,setScrolled]=useState(false);
    const pageUrl=usePage().url;
    const url=pageUrl.split('?')[0];
    const content=useRef<HTMLDivElement>(null);
    useStorefrontReveal(content,url+(url.startsWith('/preview/')?'?page='+new URLSearchParams(pageUrl.split('?')[1]).get('page'):''));
    useEffect(()=>{const update=()=>setScrolled(window.scrollY>16);update();window.addEventListener('scroll',update,{passive:true});return()=>window.removeEventListener('scroll',update);},[]);
    const isPreview=preview || !!store.preview;
    const href=(page:string)=>'/preview/storefront?template=nebula&page='+page;
    const root=isPreview?previewHref?.('home') ?? href('home'):'/';
    const account=isPreview?previewHref?.('profile') ?? href('profile'):'/account';
    const lookup=isPreview?previewHref?.('lookup') ?? href('lookup'):'/cek-pesanan';
    const leaderboard=isPreview?href('leaderboard'):'/leaderboard';
    const calculator=isPreview?href('calculator'):'/calculator';
    const brand=<Link href={root} className="sf-brand ng-brand">{store.branding?.design?.logo?<img src={store.branding.design.logo} alt="" width={43} height={43} className="sf-custom-logo"/>:<span className="ng-brand-symbol"><Gamepad2 size={24}/></span>}<span>{store.name}<small>YOUR GAMING DESTINATION</small></span></Link>;
    return <><NebulaEngagement slug={store.slug} home={home} ad={store.branding?.design?.welcome_ad} service={store.branding?.design?.customer_service}/>
        <div className="ng-header-space"/>
        <header ref={header} className={'ng-header ng-header-fixed'+(scrolled?' ng-header-scrolled':'')}>
            <div className="sf-container ng-header-main">{brand}<StorefrontSearch preview={isPreview} template="nebula"/><div className="ng-header-actions"><span className="ng-currency"><span/>ID / IDR</span><StorefrontAccountMenu href={account} preview={isPreview}/></div></div>
            <div className="ng-nav-border"><nav className="sf-container ng-nav" aria-label="Navigasi toko game"><Link className={home || url.startsWith('/kategori/')?'selected':''} href={root}><Gamepad2 size={17}/>Top up</Link><Link className={url==='/cek-pesanan'?'selected':''} href={lookup}><ReceiptText size={17}/>Cek pesanan</Link><Link className={url==='/leaderboard'?'selected':''} href={leaderboard}><Trophy size={17}/>Leaderboard</Link>{!isPreview && <Link className={url.startsWith('/artikel')?'selected':''} href="/artikel"><Newspaper size={17}/>Artikel</Link>}<details className="ng-calculator-menu"><summary className={url.startsWith('/calculator')?'selected':''}><Calculator size={17}/>Kalkulator<ChevronDown size={14}/></summary><div>{[['winrate','Win Rate'],['magic-wheel','Magic Wheel'],['zodiac','Zodiac']].map(([key,label])=><Link key={key} href={isPreview?href('calculator/'+key):'/calculator/'+key} onClick={event=>event.currentTarget.closest('details')?.removeAttribute('open')}>{label}</Link>)}</div></details><span><ShieldCheck size={14}/>Pembayaran aman</span></nav></div>
        </header>
        <div ref={content} className="ng-reveal-content"><main className="sf-container ng-main">{children}</main>
        <footer className="sf-footer ng-footer"><div className="sf-container ng-footer-grid"><div>{brand}<p>Tempat singgah sebelum petualangan berikutnya. Top up game dan voucher pilihan, dalam satu toko.</p><span className="ng-footer-badge"><ShieldCheck size={15}/>Harga jelas. Pesanan terpantau.</span></div><div><strong>Jelajahi</strong><Link href={root}>Top up game</Link><Link href={leaderboard}>Leaderboard</Link><Link href={calculator}>Kalkulator game</Link>{!isPreview && <Link href="/artikel">Artikel & berita</Link>}</div><div><strong>Ruang pemain</strong><Link href={lookup}>Cek pesanan</Link><Link href={account}>Akun & saldo</Link><p>Simpan nomor pesanan untuk bantuan dari toko.</p></div><div className="ng-footer-payments"><strong>Pembayaran toko</strong><div><span>QRIS</span><span>GATEWAY</span></div><p>Metode yang tersedia mengikuti pengaturan pembayaran toko.</p></div></div><div className="sf-container sf-footer-bottom"><span>© {new Date().getFullYear()} {store.name}</span><span>Powered by <strong>DigitalToko</strong></span></div></footer>
        </div>
    </>;
}
