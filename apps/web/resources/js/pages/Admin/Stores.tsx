import { Link } from '@inertiajs/react';
import { ArrowRight, Store, ShieldOff } from 'lucide-react';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import { FilterBar, Pagination, useServerList, serviceOptions, planOptions, type PageMeta, type ListFilters } from '../../components/ListControls';
import { packageName } from '../../components/packageCatalog';
import { storeStatuses, date } from '../../components/StoreJourney';
import type { ManagedStore } from '../../components/AdminStoreActions';

type Props = {stores:ManagedStore[];pagination:PageMeta;filters:ListFilters;counts:{total:number;operating:number;suspended:number};selectedOwner:{id:number;name:string}|null};
export default function Stores({stores,pagination,filters,counts,selectedOwner}:Props) {
    const list = useServerList(filters,['stores','filters','pagination','counts','selectedOwner']);
    return <WorkspaceLayout role="admin" title="Kelola toko" description="Pantau seluruh toko per owner, periksa masa aktif, dan tindak lanjuti layanan toko.">
        <div className="cards three stats"><article className="card"><span>Seluruh toko</span><strong>{counts.total}</strong><small>Lintas owner</small></article><article className="card"><span>Layanan berjalan</span><strong>{counts.operating}</strong><small>Aktif atau masa tenggang</small></article><article className="card"><span>Ditangguhkan admin</span><strong>{counts.suspended}</strong><small>Memerlukan tindak lanjut</small></article></div>
        {selectedOwner && <div className="summary-note admin-owner-filter"><span>Toko milik <strong>{selectedOwner.name}</strong></span><Link className="text-link" href="/admin/stores">Lihat semua owner</Link></div>}
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari toko, subdomain, nama/WA/email owner…" choices={[{key:'status',label:'Status langganan',options:storeStatuses},{key:'category',label:'Layanan',options:serviceOptions},{key:'package',label:'Paket',options:planOptions},{key:'suspension',label:'Penangguhan',options:{yes:'Ditangguhkan',no:'Tidak ditangguhkan'}}]}/>
        <section className="table-card data-list-table"><header className="data-table-header"><h2>Toko penyewa</h2><Pagination compact meta={pagination} onPage={list.go} busy={list.busy} label="Navigasi atas toko"/></header>
            <div className="table-scroll"><table><thead><tr><th>Toko</th><th>Owner</th><th>Paket</th><th>Status</th><th>Masa aktif sampai</th><th>Tindakan</th></tr></thead><tbody>{stores.map(store=><tr key={store.id}>
                <td><Link className="text-link" href={'/admin/stores/'+store.id}>{store.name}</Link><small>{store.slug}</small><small>{store.domain?.hostname ?? 'Subdomain platform'}</small></td>
                <td><Link className="text-link" href={'/admin/stores?owner='+store.owner.id}>{store.owner.name}</Link><small>{store.owner.phone ?? store.owner.email ?? 'Belum ada kontak'}</small></td>
                <td><strong>{store.is_trial?'Trial ':''}{packageName(store.category,store.plan)}</strong><small>{serviceOptions[store.category as keyof typeof serviceOptions]}</small></td>
                <td className="table-cell-center"><span className={'store-status '+(store.admin_suspended_at?'suspended_manual':store.status)}>{store.admin_suspended_at?'Ditangguhkan admin':storeStatuses[store.status] ?? store.status}</span>{store.admin_suspended_at && <small>Langganan: {storeStatuses[store.status]}</small>}</td>
                <td>{date(store.expires_at)}</td><td className="table-cell-center"><Link className="button secondary" href={'/admin/stores/'+store.id}>Kelola <ArrowRight size={14}/></Link></td>
            </tr>)}</tbody></table></div>
            {!stores.length && <div className="empty-state"><Store size={32}/><h3>Tidak ada toko yang cocok</h3><p>Ubah kata pencarian atau reset filter.</p></div>}
            <Pagination meta={pagination} onPage={list.go} busy={list.busy}/>
        </section>
        <p className="admin-help"><ShieldOff size={15}/>Penangguhan berlaku per toko. Toko lain milik owner yang sama tetap mengikuti langganannya sendiri.</p>
    </WorkspaceLayout>;
}
