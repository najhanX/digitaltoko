import { Link } from '@inertiajs/react';
import { ArrowRight, Store, ShieldOff, CircleCheck } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import { FilterBar, Pagination, useServerList, serviceOptions, planOptions, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packageName } from '../../components/packageCatalog';
import { storeStatuses, date } from '../../components/StoreJourney';
import type { ManagedStore } from '../../components/AdminStoreActions';

type Props = {stores:(ManagedStore & {retention_state?:string|null})[];pagination:PageMeta;filters:ListFilters;counts:{total:number;operating:number;suspended:number};selectedOwner:{id:number;name:string}|null};
export default function Stores({stores,pagination,filters,counts,selectedOwner}:Props) {
    const list = useServerList(filters,['stores','filters','pagination','counts','selectedOwner']);
    return <WorkspaceLayout role="admin" title="Kelola toko" description="Pantau toko, masa aktif, dan permohonan penutupan." contentClassName="admin-store-directory">
        <div className="admin-store-metrics" aria-label="Ringkasan toko"><div><span className="admin-metric-icon"><Store size={19}/></span><span>Seluruh toko<strong>{counts.total}</strong></span></div><div><span className="admin-metric-icon active"><CircleCheck size={19}/></span><span>Layanan berjalan<strong>{counts.operating}</strong></span></div><div><span className="admin-metric-icon warning"><ShieldOff size={19}/></span><span>Ditangguhkan<strong>{counts.suspended}</strong></span></div></div>
        {selectedOwner && <div className="summary-note admin-owner-filter"><span>Toko milik <strong>{selectedOwner.name}</strong></span><Link className="text-link" href="/admin/stores">Lihat semua owner</Link></div>}
        <section className="table-card data-list-table catalog-data-card admin-store-list"><header className="data-table-header"><h2>Toko penyewa</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas toko"/></header>
        <FilterBar collapsibleChoices filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari toko, subdomain, nama/WA/email owner…" choices={[{key:'setup',label:'Penyiapan toko',options:{attention:'Perlu penanganan / refund'}},{key:'status',label:'Status langganan',options:storeStatuses},{key:'retention',label:'Retensi',options:{requested:'Permohonan penghapusan',scheduled:'Menunggu perpanjangan',hold:'Penghapusan ditunda',ready:'Siap ditinjau',purging:'Sedang dihapus',error:'Penghapusan bermasalah'}},{key:'suspension',label:'Penangguhan',options:{yes:'Ditangguhkan',no:'Tidak ditangguhkan'}}]}/>
            <div className="table-scroll"><table><thead><tr><th>Toko</th><th>Owner</th><th>Paket</th><th>Status</th><th>Masa aktif</th><th>Tindakan</th></tr></thead><tbody>{stores.map(store=><tr key={store.id}>
                <td className="admin-store-name-cell"><Link className="text-link" href={'/admin/stores/'+store.id}>{store.name}</Link><small>{store.domain?.hostname ?? store.slug}</small></td>
                <td className="admin-store-owner-cell"><Link className="text-link" href={'/admin/stores?owner='+store.owner.id}>{store.owner.name}</Link><small>{store.owner.phone ?? store.owner.email ?? 'Belum ada kontak'}</small></td>
                <td><strong>{store.starts_at||store.expires_at||store.status==='provisioning'?(store.is_trial?'Trial lama · ':'')+packageName(store.category,store.plan):'Belum berlangganan'}</strong><small>{serviceOptions[store.category as keyof typeof serviceOptions]}</small></td>
                <td className="table-cell-center"><span className={'store-status '+(store.admin_suspended_at?'suspended_manual':store.status)}>{store.admin_suspended_at?'Ditangguhkan admin':storeStatuses[store.status] ?? store.status}</span>{store.retention_state&&!(store.retention_state==='requested'&&store.status==='suspended_manual')&&['requested','hold','ready','error'].includes(store.retention_state)&&<small>{{requested:'Menunggu persetujuan penghapusan',hold:'Penghapusan ditunda',ready:'Siap ditinjau',error:'Penghapusan bermasalah'}[store.retention_state]}</small>}{store.admin_suspended_at && <small>Langganan: {storeStatuses[store.status]}</small>}</td>
                <td className="table-cell-center admin-store-date-cell">{store.expires_at?date(store.expires_at):<span className="field-help">Belum aktif</span>}</td><td className="table-cell-center"><Link className="button small" href={'/admin/stores/'+store.id}>Kelola <ArrowRight size={14}/></Link></td>
            </tr>)}</tbody></table></div>
            {!stores.length && <div className="empty-state"><Store size={32}/><h3>Tidak ada toko yang cocok</h3><p>Ubah kata pencarian atau reset filter.</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <p className="admin-help"><ShieldOff size={15}/>Penangguhan dan penghapusan hanya berlaku untuk toko yang dipilih.</p>
    </WorkspaceLayout>;
}
