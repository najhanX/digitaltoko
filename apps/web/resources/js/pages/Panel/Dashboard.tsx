import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Plus, ShieldCheck, Store, CreditCard } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import { CreateStoreButton } from '../../components/CreateStoreModal';
import SupplierBalances from '../../components/SupplierBalances';
import { FilterBar, Pagination, useServerList, serviceOptions, planOptions, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packageName } from '../../components/packageCatalog';
import { storeStatuses } from '../../components/StoreJourney';
import { StoreCard } from '../../components/StoreJourney';
import type { StoreSummary, WorkspaceContext } from '../../types';

type Props = {role:'admin'|'owner'|'staff';preview:boolean;viewer:{name:string};stores:StoreSummary[];counts:{stores?:number;owners?:number;active?:number;setup?:number;subscribed?:number};permissions?:string[];ownerStatus?:string;listing?:boolean;storeScope?:boolean;pagination?:PageMeta;filters?:ListFilters};
export default function Dashboard({role,preview,viewer,stores,counts,ownerStatus,permissions = [],listing = false,storeScope = false,pagination,filters = {q:'',status:'',category:'',plan:'',package:'',per_page:10}}:Props) {
    const list = useServerList(filters,['stores','pagination','filters','counts']);
    const controls = pagination && !preview ? <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari nama toko atau subdomain…" choices={[{key:'status',label:'Status',options:storeStatuses},{key:'category',label:'Layanan',options:serviceOptions},{key:'package',label:'Paket',options:planOptions}]}/> : null;
    const topPager = pagination && !preview ? <Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas toko"/> : null;
    const pager = pagination && !preview ? <Pagination meta={pagination} onPage={list.go} busy={list.busy}/> : null;
    const context = usePage().props.workspace as WorkspaceContext | null;
    const canCreate = context?.canCreate ?? false;
    const verified = ownerStatus === 'approved';
    const admin = role === 'admin';
    const active = counts.active ?? stores.filter(store => store.status === 'active').length;
    const setup = counts.setup ?? stores.filter(store => ['draft','provisioning'].includes(store.status)).length;
    const title = admin?'Ringkasan platform':storeScope && stores[0]?stores[0].name:listing?'Toko saya':'Ringkasan';
    const description = storeScope?'Masa aktif, pengaturan, dan langganan khusus toko ini.':listing?'Pilih toko untuk melanjutkan pengaturan atau pembelian paket.':'Pantau toko Anda dan lanjutkan langkah yang belum selesai.';
    return <WorkspaceLayout role={role} title={title} description={description} preview={preview} eyebrow={storeScope?'RINGKASAN TOKO':'SELAMAT DATANG'}
        actions={!preview && role === 'owner' && canCreate && !storeScope?<CreateStoreButton><Plus size={17}/>Buat toko</CreateStoreButton>:undefined}>
        {!admin && !storeScope && !listing && <section className="card getting-started">
            <div className="getting-started-copy"><span className="eyebrow">LANGKAH BERIKUTNYA</span><h2>{!verified?'Lengkapi verifikasi Anda':(counts.stores ?? stores.length) === 0?'Buat toko pertama Anda':setup?'Lanjutkan persiapan toko':'Toko siap dikelola'}</h2><p>{!verified?(ownerStatus === 'pending'?'Data KTP sedang diperiksa administrator. Setelah disetujui, Anda dapat membuat toko dan membeli paket.':ownerStatus === 'rejected'?'Buka profil untuk melihat alasan penolakan dan mengajukan data kembali.':'Verifikasi akun dan lengkapi data KTP sebelum membuat toko.'):((counts.stores ?? stores.length) === 0?'Mulai dari identitas toko. Setelah itu pilih layanan, paket, dan durasi sewanya.':'Setiap toko memiliki langganan sendiri. Pilih toko di bawah untuk melihat langkah selanjutnya.')}</p>
                {!preview && role === 'owner' && (!verified || !canCreate) && <Link className="button" href="/owner/profile"><ShieldCheck size={17}/>Buka profil & verifikasi</Link>}
                {!preview && role === 'owner' && verified && (counts.stores ?? stores.length) > 0 && <Link className="text-link" href="/owner/stores">Lihat semua toko <ArrowRight size={15}/></Link>}
            </div>
            <ol className="account-checklist"><li className={verified?'done':''}><span>1</span>Verifikasi owner</li><li className={(counts.stores ?? stores.length)?'done':''}><span>2</span>Buat toko</li><li className={(counts.subscribed ?? stores.some(store => store.expires_at || store.status === 'provisioning'))?'done':''}><span>3</span>Pilih paket & bayar</li><li className={active?'done':''}><span>4</span>Siapkan & aktifkan</li></ol>
        </section>}
        {!listing && !storeScope && <div className="cards three stats"><article className="card"><span>{admin?'Total toko':'Jumlah toko'}</span><strong>{counts.stores ?? stores.length}</strong><small>{storeScope?'Dalam ruang akses Anda':'Toko terdaftar'}</small></article><article className="card"><span>{admin?'Akun owner':'Toko aktif'}</span><strong>{admin?(counts.owners ?? 0):active}</strong><small>{admin?'Terdaftar di platform':'Masa sewa berjalan'}</small></article><article className="card"><span>Menunggu persiapan</span><strong>{setup}</strong><small>Paket atau aktivasi belum selesai</small></article></div>}
        {stores.filter(store => store.status === 'grace').map(store => <div className="feedback warning" key={store.id}><strong>{store.name} dalam masa tenggang</strong><p>Transaksi masih tersedia sampai tujuh hari setelah masa sewa habis. Perpanjang agar layanan tetap berjalan.</p>{!preview && (role === 'owner' || permissions.includes('subscription.view')) && <Link className="text-link" href={'/stores/'+store.id+'/subscription'}>Buka langganan <ArrowRight size={15}/></Link>}</div>)}
        {storeScope && !admin && stores[0] && <section className="scoped-store-overview"><StoreCard store={stores[0]} role={role} permissions={permissions} preview={preview} scoped/></section>}
        {!admin && !listing && <SupplierBalances staff={role === 'staff'}/>}
        {!admin && !storeScope && <section className="stores-section"><div className="section-toolbar"><div><h2>{storeScope?'Kelola toko ini':'Toko Anda'}</h2><p>{storeScope?'Lanjutkan dari status toko saat ini.':'Langganan dan data pelanggan terpisah pada setiap toko.'}</p></div>{!preview && !listing && !storeScope && stores.length > 3 && <Link className="text-link" href="/owner/stores">Semua toko <ArrowRight size={15}/></Link>}</div>{controls}{topPager}<div className="tenant-store-grid">{stores.map(store => <StoreCard key={store.id} store={store} role={role} permissions={permissions} preview={preview}/>)}</div>
            {pager}{!stores.length && <div className="card empty-state"><Store size={36}/><h3>{(counts.stores ?? 0)>0?"Tidak ada toko yang cocok":"Belum ada toko"}</h3><p>{(counts.stores ?? 0)>0?'Ubah atau reset filter untuk melihat toko Anda.':canCreate?'Buat toko, lalu pilih paket dan durasi sewa pada langkah berikutnya.':'Selesaikan verifikasi profil terlebih dahulu untuk membuka pembuatan toko.'}</p>{canCreate && <CreateStoreButton><Plus size={17}/>Buat toko</CreateStoreButton>}</div>}
        </section>}
        {admin && <section className="table-card"><div className="table-heading"><h2>Toko penyewa</h2><span className="pill">{pagination?.total ?? stores.length} toko</span></div>{controls}{topPager}<div className="table-scroll"><table><thead><tr><th>Toko</th><th>Layanan</th><th>Paket</th><th>Status</th></tr></thead><tbody>{stores.map(store => <tr key={store.id}><td><strong>{store.name}</strong><small>{store.slug}</small></td><td>{serviceOptions[store.category as keyof typeof serviceOptions] ?? store.category}</td><td>{packageName(store.category,store.plan)}</td><td>{store.status}</td></tr>)}</tbody></table></div>{!stores.length && <div className="empty-state">Tidak ada toko yang cocok dengan filter.</div>}{pager}<div className="card-actions table-footer"><Link className="button secondary" href="/admin/plans"><CreditCard size={16}/>Paket & harga</Link></div></section>}
    </WorkspaceLayout>;
}
