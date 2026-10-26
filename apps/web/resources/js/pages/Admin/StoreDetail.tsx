import ProvisioningPanel, {type ProvisioningCase} from '../../components/ProvisioningPanel';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, ReceiptText, Store, UserRound, Globe2, Package, Bot } from 'lucide-react';
import StoreRetentionPanel, {type Retention} from '../../components/StoreRetentionPanel';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import AdminStoreActions, { adminActions, type ManagedStore } from '../../components/AdminStoreActions';
import { FilterBar, Pagination, useServerList, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packageName } from '../../components/packageCatalog';
import { storeStatuses, date, categories } from '../../components/StoreJourney';

type Event = {id:number;admin_name:string|null;action:string;reason:string;created_at:string;before:Record<string,string|null>;after:Record<string,string|null>};
const labels:Record<string,string> = {package_name:'Paket',category:'Layanan',plan:'Sumber produk',name:'Nama toko',template:'Template',status:'Status langganan',expires_at:'Masa aktif',admin_suspended_at:'Ditangguhkan sejak',admin_suspension_reason:'Alasan penangguhan'};
function display(key:string,value:string|null) {return !value?'—':key.endsWith('_at')?date(value):key==='status'?(storeStatuses[value] ?? value):key==='category'?({bot:'Bot WhatsApp',web:'Web Topup',full:'Web + Bot'}[value] ?? value):key==='plan'?'Digiflazz · Hanshop · OtomaX · Stok premium':value;}
export default function StoreDetail({store,bot,counts,events,filters,pagination,retention,provisioning}:{provisioning:ProvisioningCase|null;retention:Retention;store:ManagedStore;bot:string;counts:{staff:number;products:number;stock:number;suppliers:number};events:Event[];filters:ListFilters;pagination:PageMeta}) {
    const list = useServerList(filters,['events','filters','pagination']);
    const botLabel:Record<string,string>={CONNECTED:'Terhubung',DISCONNECTED:'Terputus',CONNECTING:'Menghubungkan',RECONNECTING:'Menghubungkan ulang',PAIRING:'Menunggu penautan',EXPIRED:'Koneksi kedaluwarsa',ERROR:'Perlu diperiksa',STARTING:'Memulai',STOPPED:'Tidak aktif'};
    return <WorkspaceLayout role="admin" title="Detail toko" breadcrumb="Kelola toko" description="Identitas, langganan, dan pengelolaan toko." contentClassName="admin-store-detail"
        actions={<Link className="button secondary" href="/admin/stores"><ArrowLeft size={16}/>Daftar toko</Link>}>
        {store.admin_suspended_at && <div className="feedback warning"><strong>Ditangguhkan sejak {date(store.admin_suspended_at)}</strong><p>{store.admin_suspension_reason}</p><p>Masa sewa tetap berjalan. Layanan berhenti hingga admin mencabut penangguhan.</p></div>}
        <section className="card admin-store-overview">
            <header className="admin-store-profile"><div className="admin-store-profile-name"><span className="admin-store-avatar"><Store size={24}/></span><div><h2>{store.name}</h2><p>{store.slug}<span>Terdaftar {date(store.created_at)}</span></p></div></div><span className={'store-status '+(store.admin_suspended_at?'suspended_manual':store.status)}>{store.admin_suspended_at?'Ditangguhkan admin':storeStatuses[store.status] ?? store.status}</span></header>
            <div className="admin-store-information">
                <section><h3><UserRound size={16}/>Owner</h3><dl><div><dt>Nama</dt><dd>{store.owner.name}</dd></div><div><dt>WhatsApp</dt><dd>{store.owner.phone ?? 'Belum diisi'}</dd></div><div><dt>Email</dt><dd>{store.owner.email ?? 'Belum diisi'}</dd></div></dl><Link className="text-link" href={'/admin/stores?owner='+store.owner.id}>Toko lain owner ini<ExternalLink size={13}/></Link></section>
                <section><h3><Package size={16}/>Langganan</h3><dl><div><dt>Paket</dt><dd>{store.starts_at||store.expires_at||store.status==='provisioning'?(store.is_trial?'Trial lama · ':'')+packageName(store.category,store.plan):'Belum berlangganan'}<small>{categories[store.category]}</small></dd></div><div><dt>Mulai</dt><dd>{date(store.starts_at)}</dd></div><div><dt>Berakhir</dt><dd>{store.expires_at?date(store.expires_at):'Belum aktif'}</dd></div></dl></section>
                <section><h3><Globe2 size={16}/>Alamat & tampilan</h3><dl><div><dt>Alamat toko</dt><dd><a className="text-link" href={store.url} target="_blank" rel="noreferrer">{store.url.replace(/^https?:\/\//,'').replace(/\/$/,'')}<ExternalLink size={13}/></a></dd></div><div><dt>Domain sendiri</dt><dd>{store.domain?<>{store.domain.hostname}<small>{store.domain.status}</small></>:'Belum dihubungkan'}</dd></div><div><dt>Template</dt><dd className="admin-template-name">{store.template}</dd></div></dl></section>
                <section><h3><Bot size={16}/>WhatsApp & data toko</h3><dl><div><dt>Koneksi bot</dt><dd><span className={'store-status '+(bot==='CONNECTED'?'active':'pending')}>{botLabel[bot] ?? bot}</span></dd></div></dl><div className="admin-store-resources" aria-label="Data toko">{[['Staff',counts.staff],['Produk',counts.products],['Stok siap',counts.stock],['Pemasok',counts.suppliers]].map(([label,total])=><div key={label}><strong>{total}</strong><span>{label}</span></div>)}</div></section>
            </div>
            <footer className="admin-store-actionbar"><AdminStoreActions store={store}/><Link className="button secondary small" href={'/admin/billing?q='+encodeURIComponent(store.id)}><ReceiptText size={15}/>Riwayat pembayaran</Link></footer>
        </section>
        <ProvisioningPanel value={provisioning} store={store} admin/><StoreRetentionPanel retention={retention} admin store={store}/>
        <section className="table-card data-list-table catalog-data-card admin-store-audit"><header className="data-table-header"><h2>Riwayat tindakan admin</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas riwayat"/></header>
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari alasan atau nama admin…" choices={[{key:'action',label:'Tindakan',options:adminActions}]}/>
            <div className="table-scroll"><table><thead><tr><th>Waktu / admin</th><th>Tindakan</th><th>Perubahan</th><th>Alasan</th></tr></thead><tbody>{events.map(event=><tr key={event.id}><td>{date(event.created_at)}<small>{event.admin_name ?? 'Akun admin sudah dihapus'}</small></td><td className="table-cell-center">{adminActions[event.action]}</td><td className="admin-audit-change">{Object.keys(event.after).filter(key=>event.before[key]!==event.after[key]).map(key=><div key={key}><strong>{labels[key] ?? key}</strong><small>{display(key,event.before[key])} → {display(key,event.after[key])}</small></div>)}</td><td className="catalog-note admin-store-reason">{event.reason}</td></tr>)}</tbody></table></div>
            {!events.length && <div className="empty-state"><h3>Belum ada tindakan yang ditampilkan</h3><p>Perubahan oleh admin akan tercatat beserta alasan dan nilai sebelum/sesudahnya.</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy} label="Halaman riwayat admin"/>
        </section>
    </WorkspaceLayout>;
}
