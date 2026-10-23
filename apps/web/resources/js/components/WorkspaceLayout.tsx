import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { LayoutDashboard, ShieldCheck, Store, Package, LogOut, CreditCard, Settings2, Users, Menu, X, ChevronDown, Smartphone, PanelLeftClose, PanelLeftOpen, type LucideIcon } from 'lucide-react';
import Brand from './Brand';
import TutorialLink from './TutorialLink';
import SidebarSection from './SidebarSection';
import ThemeToggle from './ThemeToggle';
import PreviewBar from './PreviewBar';
import NotificationBell, { type NotificationSummary } from './PanelNotifications';
import type { WorkspaceContext } from '../types';

const sidebarOffsets = new Map<string, number>();
const sidebarCollapsedStates = new Map<string, boolean>();

type NavSection = 'catalog' | 'sales' | 'channels' | 'management';
type NavLink = { href: string; label: string; icon: LucideIcon; active: boolean; section?: NavSection };

export default function WorkspaceLayout({ title, description, role = 'owner', canCreate: _canCreate, children, actions, eyebrow, breadcrumb, preview = false, contentClassName = '' }: {
    title: string; description?: string; role?: 'owner'|'admin'|'staff'; canCreate?: boolean;
    children: ReactNode; actions?: ReactNode; eyebrow?: string; breadcrumb?: string; preview?: boolean; contentClassName?: string;
}) {
    const page = usePage();
    const context = page.props.workspace as WorkspaceContext | null;
    const viewer = context?.viewer ?? page.props.viewer as {name: string} | undefined;
    const stores = context?.stores ?? [];
    const store = stores.find(item => item.id === context?.currentStoreId);
    const permissions = context?.permissions ?? [];
    const [mobileOpen, setMobileOpen] = useState(false);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => sidebarCollapsedStates.get(role) ?? false);
    const [sidebarReady, setSidebarReady] = useState(false);
    const collapseKey = 'digitaltoko.sidebar.collapsed.'+role;
    useLayoutEffect(() => {
        setSidebarReady(false);
        let collapsed = sidebarCollapsedStates.get(role) ?? false;
        try { collapsed = localStorage.getItem(collapseKey) === '1'; } catch { /* Keep the in-memory preference when storage is unavailable. */ }
        sidebarCollapsedStates.set(role, collapsed);
        setSidebarCollapsed(collapsed);
        let readyFrame = 0;
        const restoredFrame = requestAnimationFrame(() => {
            readyFrame = requestAnimationFrame(() => setSidebarReady(true));
        });
        return () => { cancelAnimationFrame(restoredFrame); cancelAnimationFrame(readyFrame); };
    }, [collapseKey, role]);
    function changeSidebarCollapsed(next: boolean) {
        saveSidebarScroll();
        sidebarCollapsedStates.set(role, next);
        setSidebarCollapsed(next);
        try { localStorage.setItem(collapseKey, next?'1':'0'); } catch { /* The current page can still change its sidebar width. */ }
    }
    const navigationRef = useRef<HTMLElement>(null);
    const menuButtonRef = useRef<HTMLButtonElement>(null);
    const path = page.url.split('?')[0];
    const scoped = path.startsWith('/stores/') || (path.startsWith('/owner/stores/') && path !== '/owner/stores/create') || role === 'staff';
    const tab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
    const notificationSummary = page.props.notificationSummary as NotificationSummary|null;
    const sidebarScrollKey = 'digitaltoko.sidebar.'+role+'.'+(preview?'preview':notificationSummary?.recipient ?? viewer?.name ?? 'panel');
    function saveSidebarScroll() {
        const sidebar = navigationRef.current;
        if (!sidebar || sidebar.clientHeight === 0) return;
        const key = sidebarScrollKey+(window.innerWidth <= 700?'.mobile':'.desktop'+(sidebarCollapsed?'.collapsed':''));
        sidebarOffsets.set(key, sidebar.scrollTop);
        try { sessionStorage.setItem(key, String(sidebar.scrollTop)); } catch { /* In-memory restoration remains available when storage is blocked. */ }
    }
    useLayoutEffect(() => {
        const sidebar = navigationRef.current;
        if (!sidebar || sidebar.clientHeight === 0) return;
        const key = sidebarScrollKey+(window.innerWidth <= 700?'.mobile':'.desktop'+(sidebarCollapsed?'.collapsed':''));
        let offset = sidebarOffsets.get(key);
        if (offset === undefined) {
            try { const saved = sessionStorage.getItem(key); if (saved !== null) offset = Number(saved); } catch { /* Storage can be unavailable in private browser settings. */ }
        }
        if (offset !== undefined && Number.isFinite(offset) && offset >= 0) sidebar.scrollTop = offset;
    }, [sidebarScrollKey, page.url, mobileOpen, sidebarCollapsed]);
    useEffect(() => { setMobileOpen(false); }, [page.url]);
    useEffect(() => {
        if (!mobileOpen) return;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        navigationRef.current?.querySelector<HTMLButtonElement>('button')?.focus({preventScroll:true});
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
        return () => {document.body.style.overflow = previousOverflow;window.removeEventListener('keydown', close);window.removeEventListener('resize',resize);menuButtonRef.current?.focus({preventScroll:true});};
    }, [mobileOpen]);
    const globalLinks: NavLink[] = role === 'admin' ? [
        {href:'/admin',label:'Ringkasan',icon:LayoutDashboard,active:path === '/admin'},
        {href:'/admin/stores',label:'Kelola toko',icon:Store,active:path.startsWith('/admin/stores')},
        {href:'/admin/verifications',label:'Verifikasi owner',icon:ShieldCheck,active:path === '/admin/verifications'},
        {href:'/admin/plans',label:'Paket & harga',icon:Package,active:path === '/admin/plans'},
        {href:'/admin/billing',label:'Pembayaran sewa',icon:CreditCard,active:path === '/admin/billing'},
        {href:'/admin/vouchers',label:'Voucher promo',icon:CreditCard,active:path.startsWith('/admin/vouchers')},
        {href:'/admin/commerce',label:'Modul pembayaran',icon:CreditCard,active:path === '/admin/commerce'},
    ] : role === 'owner' ? [
        {href:'/owner',label:'Ringkasan',icon:LayoutDashboard,active:path === '/owner'},
        {href:'/owner/stores',label:'Toko saya',icon:Store,active:path === '/owner/stores' || path === '/owner/stores/create'},
        {href:'/owner/profile',label:'Profil & verifikasi',icon:ShieldCheck,active:path === '/owner/profile'},
    ] : [];
    const scopedLinks: NavLink[] = [];
    if (store) {
        const overview = role === 'owner' ? '/owner/stores/'+store.id : '/staff/'+store.id+'/dashboard';
        if (role === 'owner' || permissions.includes('dashboard.view')) scopedLinks.push({href:overview,label:'Ringkasan toko',icon:LayoutDashboard,active:path === overview});
        if (role === 'owner' || permissions.includes('subscription.view')) scopedLinks.push({href:'/stores/'+store.id+'/subscription',label:'Langganan & aktivasi',section:'management',icon:CreditCard,active:path === '/stores/'+store.id+'/subscription'});
        if (role === 'owner' || permissions.some(value=>['products.view','products.manage','pricing.manage','suppliers.manage','suppliers.responses','suppliers.import','suppliers.balance','suppliers.deposit'].includes(value))) {
            scopedLinks.push({href:'/stores/'+store.id+'/otomax',label:'Supplier OtomaX',section:'catalog',icon:Package,active:path.startsWith('/stores/'+store.id+'/otomax')});
            scopedLinks.push({href:'/stores/'+store.id+'/sources',label:'Sumber produk',section:'catalog',icon:Package,active:path === '/stores/'+store.id+'/sources'});
            scopedLinks.push({href:'/stores/'+store.id+'/catalog-structure',label:'Layanan & kategori',section:'catalog',icon:Package,active:path === '/stores/'+store.id+'/catalog-structure'});
            scopedLinks.push({href:'/stores/'+store.id+'/products',label:'Katalog & harga',section:'catalog',icon:Package,active:path === '/stores/'+store.id+'/products'});
        }
        if(role==='owner' || permissions.some(value=>['vouchers.view','vouchers.manage'].includes(value)))scopedLinks.push({href:'/stores/'+store.id+'/vouchers',label:'Voucher diskon',section:'sales',icon:CreditCard,active:path.startsWith('/stores/'+store.id+'/vouchers')});
        if(role==='owner' || permissions.some(value=>['orders.view','orders.manage','orders.resend','guests.resolve'].includes(value)))scopedLinks.push({href:'/stores/'+store.id+'/orders',label:'Transaksi pelanggan',section:'sales',icon:CreditCard,active:path.startsWith('/stores/'+store.id+'/orders')});
        if(role==='owner' || permissions.some(value=>['customers.view','customers.contacts','wallet.view','wallet.settlement'].includes(value)))scopedLinks.push({href:'/stores/'+store.id+'/customers',label:'Pelanggan & saldo',section:'sales',icon:Users,active:path.startsWith('/stores/'+store.id+'/customers')});
        if(role==='owner' || permissions.some(value=>['payments.view','payments.policy','checkout.manage'].includes(value)))scopedLinks.push({href:'/stores/'+store.id+'/payments',label:'Pembayaran pelanggan',section:'sales',icon:CreditCard,active:path.startsWith('/stores/'+store.id+'/payments')});
        if (role === 'owner' || permissions.some(value=>['stock.view','stock.manage','stock.credentials'].includes(value))) scopedLinks.push({href:'/stores/'+store.id+'/stock',label:'Stok premium',section:'catalog',icon:Package,active:path === '/stores/'+store.id+'/stock'});
        if (role === 'owner' || permissions.includes('bot.view') || permissions.includes('bot.manage')) scopedLinks.push({href:'/stores/'+store.id+'/bot',label:store.category==='web'?'WhatsApp notifikasi':'Koneksi WhatsApp',section:'channels',icon:Smartphone,active:path === '/stores/'+store.id+'/bot'});
        if (role === 'owner' || permissions.some(value=>['notifications.view','notifications.manage'].includes(value))) scopedLinks.push({href:'/stores/'+store.id+'/notifications',label:'Notifikasi & email',section:'channels',icon:Settings2,active:path === '/stores/'+store.id+'/notifications'});
        if (store.category !== 'bot' && (role === 'owner' || permissions.some(value=>['articles.view','articles.manage'].includes(value)))) scopedLinks.push({href:'/stores/'+store.id+'/articles',label:'Artikel & berita',section:'channels',icon:Package,active:path.startsWith('/stores/'+store.id+'/articles')});
        const settings = role === 'owner' ? '/owner/stores/'+store.id+'/manage' : '/staff/'+store.id+'/settings';
        if (role === 'owner' || permissions.includes('web.manage')) scopedLinks.push({href:settings,label:'Tampilan & pengaturan',section:'channels',icon:Settings2,active:path === settings && tab !== 'staff'});
        if (role === 'owner') scopedLinks.push({href:'/stores/'+store.id+'/closure',label:'Penutupan toko',section:'management',icon:Store,active:path === '/stores/'+store.id+'/closure'});
        if (role === 'owner') scopedLinks.push({href:settings+'?tab=staff',label:'Staff & hak akses',section:'management',icon:Users,active:path === settings && tab === 'staff'});
    }
    const sections: {id:NavSection;label:string;icon:LucideIcon}[] = [
        {id:'sales',label:'Penjualan',icon:CreditCard},
        {id:'catalog',label:'Produk & harga',icon:Package},
        {id:'channels',label:'Kanal & tampilan',icon:Smartphone},
        {id:'management',label:'Administrasi toko',icon:Settings2},
    ];
    function navigation(links: NavLink[]) {
        return links.map(({href,label,icon:Icon,active}) => preview ?
            <div key={label} className={'nav-item '+(label === 'Ringkasan'?'selected':'')}><Icon size={18}/><span>{label}</span></div> :
            <Link key={label} onBefore={saveSidebarScroll} href={href} className={'nav-item '+(active?'selected':'')} aria-label={label} title={label} aria-current={active?'page':undefined}><Icon size={18}/><span>{label}</span></Link>);
    }
    return <>
        <Head title={title}/>
        {preview && <PreviewBar/>}
        <div data-sidebar-ready={sidebarReady?'true':'false'} className={'panel-layout panel-surface workspace-shell '+(role!=='admin'?'compact-navigation ':'')+(mobileOpen?'nav-open ':'')+(sidebarCollapsed?'sidebar-collapsed':'')}>
            {mobileOpen && <button type="button" className="sidebar-backdrop" aria-label="Tutup menu navigasi" tabIndex={-1} onClick={() => setMobileOpen(false)}/>}
            <aside ref={navigationRef} onScroll={saveSidebarScroll} className="sidebar workspace-sidebar" id="workspace-navigation" aria-label="Navigasi panel" role={mobileOpen?'dialog':undefined} aria-modal={mobileOpen?true:undefined}>
                <div className="sidebar-brand"><Brand/><Link className="sidebar-mini-brand" href="/" aria-label="DigitalToko" title="DigitalToko"><img src="/brand/digitaltoko-mark.png" alt="" width={48} height={48}/></Link><button type="button" className="icon-button mobile-only" aria-label="Tutup menu" onClick={() => setMobileOpen(false)}><X size={20}/></button></div>
                <div className="sidebar-label">{role === 'admin'?'ADMIN PLATFORM':role === 'staff'?'RUANG STAFF':'RUANG OWNER'}</div>
                <nav className="workspace-nav">{navigation(globalLinks)}</nav>
                {role !== 'admin' && !preview && <>
                    <div className="sidebar-label">{role === 'owner'?'RUANG TOKO':'TOKO ANDA'}</div>
                    {role === 'owner' && stores.length > 0 && <button type="button" className="icon-button sidebar-store-shortcut" aria-label={store?'Pilih toko: '+store.name:'Pilih toko'} title={store?.name ?? 'Pilih toko'} onClick={() => changeSidebarCollapsed(false)}><Store size={19}/></button>}
                    {role === 'owner' && stores.length > 0 && <label className="store-switcher"><span className="sr-only">Pilih toko</span><select aria-label="Pilih toko" value={store?.id ?? ''} onChange={event => {saveSidebarScroll();if(event.target.value === '__browse')router.get('/owner/stores');else if(event.target.value)router.get('/owner/stores/'+event.target.value);}}><option value="">Pilih toko</option>{stores.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}<option value="__browse">Cari di semua toko…</option></select></label>}
                    {role === 'staff' && store && <div className="sidebar-store" title={store.name}><Store size={18}/><strong>{store.name}</strong></div>}
                    {store ? <nav className="workspace-nav workspace-store-nav" aria-label="Menu toko">
                        {navigation(scopedLinks.filter(link => !link.section))}
                        {sections.map(section => {
                            const links = scopedLinks.filter(link => link.section === section.id);
                            return links.length > 0 && <SidebarSection key={store.id+':'+section.id} label={section.label} icon={section.icon} links={links} collapsed={sidebarCollapsed && !mobileOpen} onExpand={() => changeSidebarCollapsed(false)} storageKey={sidebarScrollKey+'.groups.'+store.id+'.'+section.id}>{navigation(links)}</SidebarSection>;
                        })}
                    </nav> : <p className="sidebar-hint">{stores.length?'Pilih toko untuk membuka langganan dan pengaturannya.':'Toko yang Anda buat akan muncul di sini.'}</p>}
                </>}
                <div className="sidebar-bottom" title={viewer?.name}><span className="avatar">{viewer?.name?.[0] ?? 'D'}</span><div><strong>{viewer?.name ?? 'DigitalToko'}</strong><small>{role === 'staff'?'Staff toko':role === 'admin'?'Administrator':'Pemilik toko'}</small></div></div>
            </aside>
            <div className="panel-main">
                <header className="panel-top workspace-topbar">
                    <div className="topbar-location"><button type="button" className="icon-button sidebar-collapse-toggle" aria-label={sidebarCollapsed?'Perluas sidebar':'Perkecil sidebar'} title={sidebarCollapsed?'Perluas sidebar':'Perkecil sidebar'} aria-controls="workspace-navigation" aria-expanded={!sidebarCollapsed} onClick={() => changeSidebarCollapsed(!sidebarCollapsed)}>{sidebarCollapsed?<PanelLeftOpen size={20}/>:<PanelLeftClose size={20}/>}</button><button ref={menuButtonRef} type="button" className="icon-button mobile-only" aria-label="Buka menu" aria-controls="workspace-navigation" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)}><Menu size={21}/></button><span>{role === 'owner'?'Panel owner':role === 'staff'?'Panel staff':'Panel admin'} <span className="breadcrumb-separator">/</span> <strong>{breadcrumb ?? (scoped?store?.name:null) ?? 'DigitalToko'}</strong></span></div>
                    <div className="panel-top-right"><ThemeToggle/><NotificationBell summary={notificationSummary} preview={preview}/>{!preview && <details className="account-menu"><summary aria-label="Menu akun"><span className="avatar">{viewer?.name?.[0] ?? 'D'}</span><span className="account-name">{viewer?.name ?? 'Akun'}</span><ChevronDown size={14}/></summary><div><Link href="/logout" method="post" as="button"><LogOut size={16}/>Keluar</Link></div></details>}</div>
                </header>
                <main className={'workspace-content panel-content '+(role==='admin'?'catalog-workspace admin-workspace ':'')+contentClassName}>
                    <div className="page-heading"><div><div className="eyebrow">{eyebrow ?? (scoped?'RUANG TOKO':'DIGITALTOKO')}</div><h1>{title}</h1>{description && <p>{description}</p>}</div><div className="page-heading-actions"><TutorialLink path={path} tab={tab}/>{actions}</div></div>
                    {role !== 'admin' && store?.admin_suspended_at && <div className="feedback warning" role="status"><strong>Toko ditangguhkan administrator</strong><p>{store.admin_suspension_reason}</p><p>Layanan bot dan web berhenti. Hubungi administrator untuk tindak lanjut. Masa sewa tetap berjalan.</p></div>}
                    {store?.sandbox && <div className="feedback warning" role="status"><strong>Sandbox · Mode pengujian</strong><p>Saldo uji terpisah. Pesanan Hanshop dan stok premium disimulasikan; Digiflazz memakai key development. Sewa toko tetap berlaku.</p></div>}
                    {children}
                </main>
            </div>
        </div>
    </>;
}
