import { Head, Link } from '@inertiajs/react';
import { LayoutDashboard, Store, Package, Users, CreditCard, Settings2, MessageCircle, ArrowUpRight, Bell, Globe2, type LucideIcon } from 'lucide-react';
import Brand from '../../components/Brand';
import PreviewBar from '../../components/PreviewBar';
import SupplierBalances from '../../components/SupplierBalances';
import type { StoreSummary } from '../../types';
import type { ReactNode } from 'react';

type Props={role:'admin'|'owner'|'staff';preview:boolean;viewer:{name:string};stores:StoreSummary[];counts:{stores?:number;owners?:number};permissions?:string[];ownerStatus?:string};
const categories:Record<string,string>={bot:'Bot WhatsApp',web:'Web Topup',full:'Web + Bot'};
function NavItem({href,children,selected}:{href:string|null;children:ReactNode;selected:boolean}) {
    const className='nav-item '+(selected?'selected':'');
    return href?<Link className={className} href={href}>{children}</Link>:<div className={className}>{children}</div>;
}
export default function Dashboard({role,preview,viewer,stores,counts,ownerStatus}:Props) {
    const admin=role==='admin';
    const menu:[LucideIcon,string][]=admin
        ?[[LayoutDashboard,'Ringkasan'],[Store,'Toko penyewa'],[Users,'Verifikasi owner'],[Package,'Paket & harga'],[CreditCard,'Pembayaran sewa'],[Settings2,'Pengaturan']]
        :[[LayoutDashboard,'Ringkasan'],...(role==='owner'?[[Users,'Profil & verifikasi'] as [LucideIcon,string]]:[]),[Package,'Produk & parsing'],[Store,'Stok premium'],[CreditCard,'Transaksi'],[Users,'Pelanggan'],[Globe2,'Tampilan web'],[MessageCircle,'Koneksi WhatsApp'],[Settings2,'Pengaturan toko']];
    const navTarget=(label:string):string|null=>{
        if(preview)return null;
        if(label==='Ringkasan')return admin?'/admin':role==='owner'?'/owner':'/staff/'+stores[0]?.id+'/dashboard';
        if(admin&&label==='Verifikasi owner')return '/admin/verifications';
        if(role==='owner'&&label==='Profil & verifikasi')return '/owner/profile';
        return null;
    };
    return <><Head title={'Panel '+role}/>{preview&&<PreviewBar/>}<div className="panel-layout">
        <aside className="sidebar"><Brand/><div className="sidebar-label">{admin?'ADMIN PLATFORM':role==='staff'?'RUANG STAFF':'RUANG OWNER'}</div>
            <nav>{menu.map(([Icon,label],i)=><NavItem href={navTarget(label)} selected={i===0} key={label}><Icon size={19}/><span>{label}</span>{i>0&&!navTarget(label)&&<small>Segera</small>}</NavItem>)}</nav>
            <div className="sidebar-bottom"><div className="avatar">{viewer.name[0]}</div><div><strong>{viewer.name}</strong><small>{role==='staff'?'Akses hanya toko ini':admin?'Administrator':'Pemilik toko'}</small></div></div>
        </aside>
        <div className="panel-main"><header className="panel-top"><span>Ruang kerja / <strong>Ringkasan</strong></span><div className="panel-top-right"><Bell size={19}/><span>{viewer.name}</span></div></header>
            <main className="panel-content"><div className="section-heading"><div><div className="eyebrow">SELAMAT DATANG</div><h1>{admin?'Kelola platform Anda.':role==='staff'?'Ruang kerja toko.':'Semua toko, satu tempat.'}</h1><p>{admin?'Pantau toko penyewa dan kesiapan layanan.':role==='staff'?'Akses dibatasi sesuai toko dan permission dari owner.':'Setiap toko memiliki masa aktif dan pengaturan sendiri.'}</p></div></div>
                {!preview&&<div className="panel-actions">{admin?<Link className="button secondary" href="/admin/verifications">Verifikasi owner</Link>:role==='owner'?<><Link className="button secondary" href="/owner/profile">Profil & verifikasi</Link>{ownerStatus==='approved'&&<Link className="button" href="/owner/stores/create">Buat toko</Link>}</>:null}<Link className="panel-logout" href="/logout" method="post" as="button">Keluar</Link></div>}
                {role==='owner'&&!preview&&ownerStatus!=='approved'&&<div className="onboarding-banner"><div><strong>Lengkapi verifikasi owner</strong><p>{ownerStatus==='pending'?'KTP sedang diperiksa admin.':ownerStatus==='rejected'?'KTP ditolak. Lihat alasan dan ajukan kembali.':'Verifikasi WhatsApp, email, dan KTP sebelum membuat toko.'}</p></div><Link className="text-link" href="/owner/profile">Buka profil</Link></div>}
                <div className="notice"><strong>Tahap pengembangan</strong><p>Onboarding, verifikasi KTP, toko draft, dan akun staff tersedia. Pembayaran, katalog, serta transaksi pemasok masih dalam pembangunan.</p></div>
                <div className="cards three stats"><article className="card"><span>{admin?'Total toko':'Toko Anda'}</span><strong>{counts.stores??stores.length}</strong><small>Dalam ruang akses Anda</small></article><article className="card"><span>{admin?'Akun owner':'Toko aktif'}</span><strong>{admin?(counts.owners??0):stores.filter(s=>s.status==='active').length}</strong><small>{admin?'Terdaftar di platform':'Status tersimpan saat ini'}</small></article><article className="card"><span>Perlu disiapkan</span><strong>{stores.filter(s=>s.status==='draft').length}</strong><small>Belum menerima transaksi</small></article></div>
                {!admin&&<SupplierBalances staff={role==='staff'}/>}
                <section className="table-card"><div className="table-heading"><div><h2>{admin?'Toko penyewa':'Daftar toko'}</h2><p>{admin?'Maksimal 50 toko terbaru pada fondasi awal.':'Katalog dan pelanggan terpisah per toko.'}</p></div><span className="pill">{stores.length} toko</span></div><div className="table-scroll"><table><thead><tr><th>Toko</th><th>Layanan</th><th>Paket</th><th>Status</th><th>Masa aktif</th><th/></tr></thead><tbody>{stores.map(s=><tr key={s.id}><td><strong>{s.name}</strong><small>{s.slug}</small></td><td>{categories[s.category]??s.category}</td><td className="capitalize">{s.plan}</td><td><span className={'status '+(s.status==='active'?'active':'')}>{s.status}</span></td><td>{s.expires_at?new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeZone:'Asia/Jakarta'}).format(new Date(s.expires_at)):'Belum dimulai'}</td><td>{role==='owner'&&!preview?<Link className="text-link" href={'/owner/stores/'+s.id+'/manage'}>Kelola <ArrowUpRight size={16}/></Link>:s.category!=='bot'?preview?<Link className="text-link" href={s.url}>Web toko <ArrowUpRight size={16}/></Link>:<a className="text-link" href={s.url}>Web toko <ArrowUpRight size={16}/></a>:null}</td></tr>)}</tbody></table></div>{!stores.length&&<div className="empty-state"><Store size={32}/><h3>Belum ada toko</h3><p>Lengkapi verifikasi owner, lalu buat toko draft pertama Anda.</p></div>}</section>
            </main>
        </div>
    </div></>;
}
