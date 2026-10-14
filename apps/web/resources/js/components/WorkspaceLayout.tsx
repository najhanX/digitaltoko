import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { LayoutDashboard, ShieldCheck, Store, Package, LogOut, CreditCard, Settings2, Users, Menu, X, ChevronDown, Smartphone, type LucideIcon } from 'lucide-react';
import Brand from './Brand';
import ThemeToggle from './ThemeToggle';
import PreviewBar from './PreviewBar';
import NotificationBell, { type NotificationSummary } from './PanelNotifications';
import type { WorkspaceContext } from '../types';

type NavLink = { href: string; label: string; icon: LucideIcon; active: boolean };
export default function WorkspaceLayout({ title, description, role = 'owner', canCreate: _canCreate, children, actions, eyebrow, breadcrumb, preview = false }: {
    title: string; description?: string; role?: 'owner'|'admin'|'staff'; canCreate?: boolean;
    children: ReactNode; actions?: ReactNode; eyebrow?: string; breadcrumb?: string; preview?: boolean;
}) {
    const page = usePage();
    const context = page.props.workspace as WorkspaceContext | null;
    const viewer = context?.viewer ?? page.props.viewer as {name: string} | undefined;
    const stores = context?.stores ?? [];
    const store = stores.find(item => item.id === context?.currentStoreId);
    const permissions = context?.permissions ?? [];
    const [mobileOpen, setMobileOpen] = useState(false);
    const navigationRef = useRef<HTMLElement>(null);
    const menuButtonRef = useRef<HTMLButtonElement>(null);
    const path = page.url.split('?')[0];
    const scoped = path.startsWith('/stores/') || (path.startsWith('/owner/stores/') && path !== '/owner/stores/create') || role === 'staff';
    const tab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
    const notificationSummary = page.props.notificationSummary as NotificationSummary|null;
    useEffect(() => { setMobileOpen(false); }, [page.url]);
    useEffect(() => {
        if (!mobileOpen) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        navigationRef.current?.querySelector<HTMLButtonElement>('button')?.focus();
        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {setMobileOpen(false);return;}
            if (event.key !== 'Tab') return;
            const controls = Array.from(navigationRef.current?.querySelectorAll<HTMLElement>('a[href],button,select') ?? []).filter(item => item.offsetParent !== null);
            const first = controls[0], last = controls[controls.length-1];
            if (!first || !last) return;
            if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
            else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
        };
        window.addEventListener('keydown', close);
        const resize = () => {if(window.innerWidth > 700)setMobileOpen(false);};
        window.addEventListener('resize',resize);
        return () => {document.body.style.overflow = previousOverflow;window.removeEventListener('keydown', close);window.removeEventListener('resize',resize);menuButtonRef.current?.focus();};
    }, [mobileOpen]);
    const globalLinks: NavLink[] = role === 'admin' ? [
        {href:'/admin',label:'Ringkasan',icon:LayoutDashboard,active:path === '/admin'},
        {href:'/admin/verifications',label:'Verifikasi owner',icon:ShieldCheck,active:path === '/admin/verifications'},
        {href:'/admin/plans',label:'Paket & harga',icon:Package,active:path === '/admin/plans'},
        {href:'/admin/billing',label:'Pembayaran sewa',icon:CreditCard,active:path === '/admin/billing'},
    ] : role === 'owner' ? [
        {href:'/owner',label:'Ringkasan',icon:LayoutDashboard,active:path === '/owner'},
        {href:'/owner/stores',label:'Toko saya',icon:Store,active:path === '/owner/stores' || path === '/owner/stores/create'},
        {href:'/owner/profile',label:'Profil & verifikasi',icon:ShieldCheck,active:path === '/owner/profile'},
    ] : [];
    const scopedLinks: NavLink[] = [];
    if (store) {
        const overview = role === 'owner' ? '/owner/stores/'+store.id : '/staff/'+store.id+'/dashboard';
        if (role === 'owner' || permissions.includes('dashboard.view')) scopedLinks.push({href:overview,label:'Ringkasan toko',icon:LayoutDashboard,active:path === overview});
        if (role === 'owner' || permissions.includes('subscription.view')) scopedLinks.push({href:'/stores/'+store.id+'/subscription',label:'Langganan & aktivasi',icon:CreditCard,active:path === '/stores/'+store.id+'/subscription'});
        if (role === 'owner' || permissions.some(value=>['products.view','products.manage','pricing.manage'].includes(value))) {
            scopedLinks.push({href:'/stores/'+store.id+'/sources',label:'Sumber produk',icon:Package,active:path === '/stores/'+store.id+'/sources'});
            scopedLinks.push({href:'/stores/'+store.id+'/products',label:'Katalog & harga',icon:Package,active:path === '/stores/'+store.id+'/products'});
        }
        if (role === 'owner' || permissions.some(value=>['stock.view','stock.manage','stock.credentials'].includes(value))) scopedLinks.push({href:'/stores/'+store.id+'/stock',label:'Stok premium',icon:Package,active:path === '/stores/'+store.id+'/stock'});
        if (store.category !== 'web' && (role === 'owner' || permissions.includes('bot.view') || permissions.includes('bot.manage'))) scopedLinks.push({href:'/stores/'+store.id+'/bot',label:'Koneksi WhatsApp',icon:Smartphone,active:path === '/stores/'+store.id+'/bot'});
        const settings = role === 'owner' ? '/owner/stores/'+store.id+'/manage' : '/staff/'+store.id+'/settings';
        if (role === 'owner' || permissions.includes('web.manage')) scopedLinks.push({href:settings,label:'Pengaturan toko',icon:Settings2,active:path === settings && tab !== 'staff'});
        if (role === 'owner') scopedLinks.push({href:settings+'?tab=staff',label:'Staff & hak akses',icon:Users,active:path === settings && tab === 'staff'});
    }
    function navigation(links: NavLink[]) {
        return links.map(({href,label,icon:Icon,active}) => preview ?
            <div key={label} className={'nav-item '+(label === 'Ringkasan'?'selected':'')}><Icon size={18}/><span>{label}</span></div> :
            <Link key={label} href={href} className={'nav-item '+(active?'selected':'')} aria-current={active?'page':undefined}><Icon size={18}/><span>{label}</span></Link>);
    }
    return <>
        <Head title={title}/>
        {preview && <PreviewBar/>}
        <div className={'panel-layout panel-surface workspace-shell '+(mobileOpen?'nav-open':'')}>
            {mobileOpen && <button type="button" className="sidebar-backdrop" aria-label="Tutup menu navigasi" tabIndex={-1} onClick={() => setMobileOpen(false)}/>}
            <aside ref={navigationRef} className="sidebar workspace-sidebar" id="workspace-navigation" aria-label="Navigasi panel" role={mobileOpen?'dialog':undefined} aria-modal={mobileOpen?true:undefined}>
                <div className="sidebar-brand"><Brand/><button type="button" className="icon-button mobile-only" aria-label="Tutup menu" onClick={() => setMobileOpen(false)}><X size={20}/></button></div>
                <div className="sidebar-label">{role === 'admin'?'ADMIN PLATFORM':role === 'staff'?'RUANG STAFF':'RUANG OWNER'}</div>
                <nav className="workspace-nav">{navigation(globalLinks)}</nav>
                {role !== 'admin' && !preview && <>
                    <div className="sidebar-label">{role === 'owner'?'RUANG TOKO':'TOKO ANDA'}</div>
                    {role === 'owner' && stores.length > 0 && <label className="store-switcher"><span className="sr-only">Pilih toko</span><select aria-label="Pilih toko" value={store?.id ?? ''} onChange={event => {if(event.target.value === '__browse')router.get('/owner/stores');else if(event.target.value)router.get('/owner/stores/'+event.target.value);}}><option value="">Pilih toko</option>{stores.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}<option value="__browse">Cari di semua toko…</option></select></label>}
                    {role === 'staff' && store && <div className="sidebar-store"><Store size={18}/><strong>{store.name}</strong></div>}
                    {store ? <nav className="workspace-nav">{navigation(scopedLinks)}</nav> : <p className="sidebar-hint">{stores.length?'Pilih toko untuk membuka langganan dan pengaturannya.':'Toko yang Anda buat akan muncul di sini.'}</p>}
                </>}
                <div className="sidebar-bottom"><span className="avatar">{viewer?.name?.[0] ?? 'D'}</span><div><strong>{viewer?.name ?? 'DigitalToko'}</strong><small>{role === 'staff'?'Staff toko':role === 'admin'?'Administrator':'Pemilik toko'}</small></div></div>
            </aside>
            <div className="panel-main">
                <header className="panel-top workspace-topbar">
                    <div className="topbar-location"><button ref={menuButtonRef} type="button" className="icon-button mobile-only" aria-label="Buka menu" aria-controls="workspace-navigation" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)}><Menu size={21}/></button><span>{role === 'owner'?'Panel owner':role === 'staff'?'Panel staff':'Panel admin'} <span className="breadcrumb-separator">/</span> <strong>{breadcrumb ?? (scoped?store?.name:null) ?? 'DigitalToko'}</strong></span></div>
                    <div className="panel-top-right"><ThemeToggle/><NotificationBell summary={notificationSummary} preview={preview}/>{!preview && <details className="account-menu"><summary aria-label="Menu akun"><span className="avatar">{viewer?.name?.[0] ?? 'D'}</span><span className="account-name">{viewer?.name ?? 'Akun'}</span><ChevronDown size={14}/></summary><div><Link href="/logout" method="post" as="button"><LogOut size={16}/>Keluar</Link></div></details>}</div>
                </header>
                <main className="workspace-content panel-content">
                    <div className="page-heading"><div><div className="eyebrow">{eyebrow ?? (scoped?'RUANG TOKO':'DIGITALTOKO')}</div><h1>{title}</h1>{description && <p>{description}</p>}</div>{actions && <div className="page-heading-actions">{actions}</div>}</div>
                    {children}
                </main>
            </div>
        </div>
    </>;
}
