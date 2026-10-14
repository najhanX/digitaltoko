import { packageName } from '../../components/packageCatalog';
import WorkspaceLayout from '../../components/WorkspaceLayout';
import { invoiceLabel, type Invoice } from '../Owner/Subscription';
import { categories, money, date } from '../../components/StoreJourney';
import { FilterBar, Pagination, useServerList, invoiceStatuses, type PageMeta, type ListFilters } from '../../components/ListControls';
const purposes:Record<string,string> = {initial:'Pembelian awal',renew:'Perpanjangan',change:'Perubahan paket',downgrade:'Downgrade terjadwal'};
export default function Billing({invoices,filters}:{invoices:PageMeta & {data:Invoice[]};filters:ListFilters}) {
    const list = useServerList(filters,['invoices','filters']);
    return <WorkspaceLayout role="admin" title="Pembayaran sewa" description="Status pembayaran diperiksa melalui gateway Hanshop. Aktivasi diterapkan sekali untuk setiap invoice.">
        <FilterBar filters={filters} onApply={list.apply} busy={list.busy} placeholder="Cari ID invoice, toko, atau nama paket…" choices={[{key:'status',label:'Status pembayaran',options:invoiceStatuses},{key:'purpose',label:'Jenis pembelian',options:purposes}]}/>
        <section className="table-card data-list-table"><header className="data-table-header"><h2>Invoice sewa</h2><Pagination compact meta={invoices} onPage={list.go} busy={list.busy} label="Navigasi atas pembayaran"/></header>
            <div className="table-scroll"><table><thead><tr><th scope="col">Invoice / toko</th><th scope="col">Paket</th><th scope="col">Jenis</th><th scope="col">Total bayar</th><th scope="col">Status</th><th scope="col">Dibayar</th></tr></thead>
                <tbody>{invoices.data.map(invoice=><tr key={invoice.id}><td className="identifier-cell"><strong>{invoice.id}</strong><small>Toko {invoice.store_id}</small></td><td><strong>{packageName(invoice.snapshot.category,invoice.snapshot.plan)}</strong><small>Penawaran: {invoice.snapshot.name}</small><small>{categories[invoice.snapshot.category]} · {invoice.snapshot.duration_days} hari</small></td><td>{purposes[invoice.purpose] ?? invoice.purpose}</td><td className="amount-cell">{invoice.total !== null ? money(invoice.total) : 'Menunggu nominal'}</td><td><span className={'store-status '+invoice.status}>{invoiceLabel(invoice)}</span></td><td>{invoice.paid_at?date(invoice.paid_at):'Belum dibayar'}</td></tr>)}</tbody>
            </table></div>
            {!invoices.data.length && <div className="empty-state"><h3>Tidak ada invoice yang ditampilkan</h3><p>Ubah atau reset filter untuk melihat pembayaran lainnya.</p></div>}
            <Pagination meta={invoices} onPage={list.go} busy={list.busy} label="Halaman pembayaran"/>
        </section>
    </WorkspaceLayout>;
}
