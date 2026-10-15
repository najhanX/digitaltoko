import { Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, ReceiptText } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import AdminStoreActions, { adminActions, type ManagedStore } from '../../components/AdminStoreActions';
import { FilterBar, Pagination, useServerList, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packages, packageName } from '../../components/packageCatalog';
import { storeStatuses, date } from '../../components/StoreJourney';

type Event = {id:number;admin_name:string|null;action:string;reason:string;created_at:string;before:Record<string,string|null>;after:Record<string,string|null>};
const labels:Record<string,string> = {package_name:'Paket',category:'Layanan',plan:'Sumber produk',name:'Nama toko',template:'Template',status:'Status langganan',expires_at:'Masa aktif',admin_suspended_at:'Ditangguhkan sejak',admin_suspension_reason:'Alasan penangguhan'};
function display(key:string,value:string|null) {return !value?'—':key.endsWith('_at')?date(value):key==='status'?(storeStatuses[value] ?? value):key==='category'?({bot:'Bot WhatsApp',web:'Web Topup',full:'Web + Bot'}[value] ?? value):key==='plan'?({orbit:'Digiflazz sendiri',space:'Stok premium sendiri',galaxy:'Stok premium + Digiflazz sendiri'}[value] ?? value):value;}
export default function StoreDetail({store,bot,counts,events,filters,pagination}:{store:ManagedStore;bot:string;counts:{staff:number;products:number;stock:number;suppliers:number};events:Event[];filters:ListFilters;pagination:PageMeta}) {
    const list = useServerList(filters,['events','filters','pagination']);
    const info = packages.find(item=>item.category===store.category && item.plan===store.plan);
    return <WorkspaceLayout role="admin" title={store.name} breadcrumb="Kelola toko" description="Kelola layanan satu toko. Semua tindakan administratif memerlukan alasan dan tersimpan dalam riwayat."
        actions={<Link className="button secondary" href="/admin/stores"><ArrowLeft size={16}/>Daftar toko</Link>}>
        {store.admin_suspended_at && <div className="feedback warning"><strong>Ditangguhkan sejak {date(store.admin_suspended_at)}</strong><p>{store.admin_suspension_reason}</p><p>Masa sewa tetap berjalan. Layanan berhenti hingga admin mencabut penangguhan.</p></div>}
        <section className="card admin-store-overview">
            <div className="section-toolbar"><div><span className="eyebrow">IDENTITAS & LANGGANAN</span><h2>{store.name}</h2><p>{store.slug}</p></div><span className={'store-status '+(store.admin_suspended_at?'suspended_manual':store.status)}>{store.admin_suspended_at?'Ditangguhkan admin':storeStatuses[store.status] ?? store.status}</span></div>
            <dl className="admin-detail-grid">
                <div><dt>Owner</dt><dd>{store.owner.name}<small>{store.owner.phone ?? 'WA belum diisi'} · {store.owner.email ?? 'Email belum diisi'}</small><Link className="text-link" href={'/admin/stores?owner='+store.owner.id}>Semua toko owner ini</Link></dd></div>
                <div><dt>Paket</dt><dd>{store.is_trial?'Trial ':''}{packageName(store.category,store.plan)}<small>{info?.description}</small></dd></div>
                <div><dt>Mulai / berakhir</dt><dd>{date(store.starts_at)}<small>Sampai {date(store.expires_at)}</small></dd></div>
                <div><dt>Status langganan</dt><dd>{storeStatuses[store.status] ?? store.status}<small>Template {store.template}</small></dd></div>
                <div><dt>Alamat web</dt><dd><a className="text-link" href={store.url} target="_blank" rel="noreferrer">{store.slug}<ExternalLink size={13}/></a><small>{store.domain?store.domain.hostname+' · '+store.domain.status:'Subdomain platform'}</small></dd></div>
                <div><dt>Koneksi WhatsApp</dt><dd>{store.category==='web'?'Tidak termasuk paket':bot}<small>Terdaftar {date(store.created_at)}</small></dd></div>
            </dl>
            <AdminStoreActions store={store}/>
            <div className="admin-detail-links"><Link className="text-link" href={'/admin/billing?q='+encodeURIComponent(store.id)}><ReceiptText size={15}/>Riwayat pembayaran toko</Link></div>
        </section>
        <div className="admin-store-counts">{[['Staff',counts.staff],['Produk',counts.products],['Stok siap',counts.stock],['Pemasok aktif',counts.suppliers]].map(([label,total])=><div className="card" key={label}><span>{label}</span><strong>{total}</strong></div>)}</div>
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari alasan atau nama admin…" choices={[{key:'action',label:'Tindakan',options:adminActions}]}/>
        <section className="table-card data-list-table"><header className="data-table-header"><h2>Riwayat tindakan admin</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas riwayat"/></header>
            <div className="table-scroll"><table><thead><tr><th>Waktu / admin</th><th>Tindakan</th><th>Perubahan</th><th>Alasan</th></tr></thead><tbody>{events.map(event=><tr key={event.id}><td>{date(event.created_at)}<small>{event.admin_name ?? 'Akun admin sudah dihapus'}</small></td><td>{adminActions[event.action]}</td><td className="admin-audit-change">{Object.keys(event.after).filter(key=>event.before[key]!==event.after[key]).map(key=><div key={key}><strong>{labels[key] ?? key}</strong><small>{display(key,event.before[key])} → {display(key,event.after[key])}</small></div>)}</td><td className="catalog-note">{event.reason}</td></tr>)}</tbody></table></div>
            {!events.length && <div className="empty-state"><h3>Belum ada tindakan yang ditampilkan</h3><p>Perubahan oleh admin akan tercatat beserta alasan dan nilai sebelum/sesudahnya.</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy} label="Halaman riwayat admin"/>
        </section>
    </WorkspaceLayout>;
}
